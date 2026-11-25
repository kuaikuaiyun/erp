<?php

namespace App\Http\Controllers;

use App\Services\FinanceSync\FinanceSyncFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class FinanceSyncController extends Controller
{
    public function __construct(private FinanceSyncFeed $feed) {}

    public function snapshot(Request $request, string $resource): JsonResponse
    {
        [$cursor, $limit] = $this->pagination($request);
        [$data, $meta] = $this->feed->snapshot($resource, $cursor, $limit);

        return $this->ok($request, $data, $meta);
    }

    public function changes(Request $request, string $resource): JsonResponse
    {
        [$cursor, $limit] = $this->pagination($request);
        abort_if($cursor === null, 422, 'INVALID_CURSOR');
        [$data, $meta] = $this->feed->changes($resource, $cursor, $limit);

        return $this->ok($request, $data, $meta);
    }

    public function logistics(Request $request, string $order): JsonResponse
    {
        abort_if($request->query() !== [], 422, 'INVALID_REQUEST');

        return $this->ok($request, $this->feed->logistics($this->orderId($order)), single: true);
    }

    public function logisticsLogs(Request $request, string $order): JsonResponse
    {
        [$cursor, $limit] = $this->pagination($request);
        [$data, $meta] = $this->feed->logisticsLogs($this->orderId($order), $cursor, $limit);

        return $this->ok($request, $data, $meta);
    }

    public static function requestId(Request $request): string
    {
        if (! $request->attributes->has('finance_sync_request_id')) {
            $request->attributes->set('finance_sync_request_id', (string) Str::uuid());
        }

        return $request->attributes->get('finance_sync_request_id');
    }

    public static function renderException(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1/finance-sync', 'api/v1/finance-sync/*')) {
            return null;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface
            ? array_intersect_key($exception->getHeaders(), array_flip(['Retry-After', 'WWW-Authenticate', 'Allow'])) : [];
        $code = match ($status) {
            401 => 'UNAUTHORIZED',
            403 => 'HTTPS_REQUIRED',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            422 => $exception->getMessage() === 'INVALID_CURSOR' ? 'INVALID_CURSOR' : 'INVALID_REQUEST',
            429 => 'RATE_LIMITED',
            503 => $exception->getMessage() === 'SYNC_NOT_READY' ? 'SYNC_NOT_READY' : 'SERVICE_UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };

        return self::response($request, $code, __("finance_sync.errors.$code"), null, [],
            $code === 'INTERNAL_ERROR' ? 500 : $status, $headers);
    }

    private function pagination(Request $request): array
    {
        $params = $request->query();
        abort_if(array_diff_key($params, array_flip(['cursor', 'limit'])) !== [], 422, 'INVALID_REQUEST');
        $cursor = $params['cursor'] ?? null;
        abort_if($cursor !== null && (! is_string($cursor) || $cursor === '' || strlen($cursor) > 512), 422, 'INVALID_CURSOR');

        $limit = $params['limit'] ?? config('finance_sync.page_size');
        abort_unless(is_scalar($limit) && preg_match('/^[1-9][0-9]{0,2}$/D', (string) $limit)
            && (int) $limit <= (int) config('finance_sync.max_page_size'), 422, 'INVALID_REQUEST');

        return [$cursor, (int) $limit];
    }

    private function orderId(string $order): int
    {
        abort_unless((string) (int) $order === $order, 404);

        return (int) $order;
    }

    private function ok(Request $request, array $data, array $meta = [], bool $single = false): JsonResponse
    {
        $request->attributes->set('finance_sync_count', $single ? 1 : count($data));

        return self::response($request, 'OK', null, $data, $meta);
    }

    private static function response(
        Request $request,
        string $code,
        ?string $message,
        ?array $data,
        array $meta,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => self::requestId($request)] + $meta,
        ], $status, $headers + ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
