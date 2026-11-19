<?php

namespace App\Portals\AssetManager;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Only translated, deliberately selected reminder diagnostics may reach the UI or delivery history. */
class ReminderCheckException extends RuntimeException
{
    public function __construct(string $key, array $parameters = [])
    {
        parent::__construct(__('asset_reminders.'.$key, $parameters, 'zh-CN'));
    }

    public static function from(Throwable $error, array $context = []): self
    {
        if ($error instanceof self) {
            return $error;
        }
        if ($error instanceof HttpExceptionInterface) {
            if ($error->getStatusCode() === 403) {
                return new self($error->getMessage() === __('asset_reminders.company_unavailable', [], 'zh-CN') ? 'company_unavailable' : 'scope_revoked');
            }
            if ($error->getStatusCode() === 404) {
                return new self('check_config_missing');
            }
        }
        $reference = (string) Str::uuid();
        // Never log the original exception, SQL, bindings, HTTP payloads, URLs or response bodies.
        $metadata = [
            'diagnostic_id' => $reference, 'exception_class' => get_class($error),
            'file' => basename($error->getFile()), 'line' => $error->getLine(),
        ];
        if ($error instanceof QueryException) {
            $state = $error->errorInfo[0] ?? null;
            $code = $error->errorInfo[1] ?? null;
            $metadata['sqlstate'] = is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/D', $state) ? $state : null;
            $metadata['database_code'] = is_scalar($code) && preg_match('/\A[0-9]{1,12}\z/D', (string) $code) ? (string) $code : null;
        }
        Log::warning('asset_reminder.check_failed', $metadata + array_intersect_key($context, array_flip(['stage', 'rule_id', 'rule_ids'])));
        return new self('check_unexpected', ['reference' => $reference]);
    }
}
