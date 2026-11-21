<?php

namespace App\Portals\Assistant\Http\Middleware;

use App\Portals\Assistant\DomainStoreSaveDiagnostic;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class DiagnoseDomainStoreSave
{
    public function __construct(private readonly ?string $logPath = null) {}

    public function handle(Request $request, Closure $next): Response
    {
        $trace = new DomainStoreSaveDiagnostic($request, $this->logPath);
        $trace->registerShutdown();
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $expected = $error instanceof ValidationException
                || $error instanceof AuthorizationException
                || $error instanceof AuthenticationException
                || $error instanceof ModelNotFoundException
                || $error instanceof HttpResponseException
                || ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500);
            $trace->exception($error, $expected);
            if ($expected) {
                $response = app(ExceptionHandler::class)->render($request, $error);
            } else {
                $message = __('assistant.domain_stores.validation.save_diagnostic_'.$this->outcome($trace));
                $data = [
                    'message' => $message,
                    'diagnostic_id' => $trace->id,
                    'save_outcome' => $this->outcome($trace),
                    'diagnostic_log_written' => $trace->writable(),
                ];
                $response = $request->expectsJson() || $request->boolean('modal')
                    ? response()->json($data, 500)
                    : response($message.' '.__('assistant.domain_stores.validation.diagnostic_reference', ['id' => $trace->id]), 500)
                        ->header('Content-Type', 'text/plain; charset=UTF-8');
            }
        }
        $trace->finish($response->getStatusCode());
        $response->headers->set('X-Domain-Store-Diagnostic-Id', $trace->id);

        return $response;
    }

    private function outcome(DomainStoreSaveDiagnostic $trace): string
    {
        return match ($trace->state()) {
            'committed' => 'saved',
            'not_started', 'rolled_back' => 'not_saved',
            default => 'unconfirmed',
        };
    }
}
