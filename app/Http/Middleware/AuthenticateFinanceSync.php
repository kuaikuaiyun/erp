<?php

namespace App\Http\Middleware;

use App\Http\Controllers\FinanceSyncController;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AuthenticateFinanceSync
{
    public const CLIENT = 'finance';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('finance_sync.enabled')) {
            abort(404);
        }
        if ((app()->isProduction() || config('finance_sync.require_https')) && ! $request->isSecure()) {
            abort(403, 'HTTPS_REQUIRED');
        }
        if (! $this->hasValidToken($request->bearerToken())) {
            abort(401, '', ['WWW-Authenticate' => 'Bearer']);
        }

        $started = hrtime(true);
        $status = 500;
        try {
            $this->enforceRate();
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();

            throw $exception;
        } finally {
            Log::info('finance_sync.request', [
                'client' => self::CLIENT,
                'route' => $request->route()?->getName(),
                'request_id' => FinanceSyncController::requestId($request),
                'status' => $status,
                'count' => $request->attributes->get('finance_sync_count', 0),
                'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            ]);
        }
    }

    private function hasValidToken(?string $token): bool
    {
        if ($token === null || ! preg_match('/^[A-Za-z0-9_\-]{43,128}$/D', $token)) {
            return false;
        }

        $hash = hash('sha256', $token);
        $now = time();
        $current = (string) config('finance_sync.token_sha256');
        $previous = (string) config('finance_sync.previous_token_sha256');
        $expires = (int) config('finance_sync.token_expires_at');
        $previousExpires = (int) config('finance_sync.previous_token_expires_at');
        $matchesCurrent = hash_equals(strtolower($current), $hash);
        $matchesPrevious = hash_equals(strtolower($previous), $hash);

        return ($matchesCurrent && ($expires === 0 || $expires > $now))
            || ($matchesPrevious && $previousExpires > $now);
    }

    private function enforceRate(): void
    {
        $key = 'finance-sync:v1:'.self::CLIENT;
        $max = max(1, (int) config('finance_sync.requests_per_minute'));
        try {
            $store = Cache::store(config('finance_sync.rate_store'));
            $limiter = new RateLimiter($store);
            // File cache increments are read-modify-write. Serialize this short
            // section so parallel requests cannot exceed the same client quota.
            $lock = $store->lock($key.':lock', 5);
            if (! $lock->get()) {
                $retry = 1;
            } else {
                try {
                    $retry = $limiter->tooManyAttempts($key, $max) ? max(1, $limiter->availableIn($key)) : 0;
                    if ($retry === 0) {
                        $limiter->hit($key, 60);
                    }
                } finally {
                    $lock->release();
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            abort(503, 'SERVICE_UNAVAILABLE', ['Retry-After' => '60']);
        }

        if ($retry > 0) {
            abort(429, '', ['Retry-After' => (string) $retry]);
        }
    }
}
