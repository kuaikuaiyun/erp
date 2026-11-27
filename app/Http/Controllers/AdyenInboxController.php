<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdyenInboxController extends Controller
{
    private const TABLE = 'adyen_webhook_inbox';
    private const MAX_REPORT_BYTES = 100 * 1024 * 1024;

    public function receive(Request $request): Response
    {
        $settings = config('adyen_inbox');
        $merchant = $settings['merchant'];
        $username = $settings['username'];
        $password = $settings['password'];
        $keys = $settings['hmac_keys'];
        if (!$merchant || !$username || !$password || !$keys) {
            return $this->failure('Inbox unavailable.', 503);
        }
        if (!hash_equals((string) $username, (string) $request->getUser()) || !hash_equals((string) $password, (string) $request->getPassword())) {
            return $this->failure('Authentication required.', 401);
        }
        if (!$request->isJson()) {
            return $this->failure('JSON required.', 415);
        }
        $body = $request->getContent();
        if (strlen($body) > 1024 * 1024) {
            return $this->failure('Payload too large.', 413);
        }
        try {
            $envelope = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $this->failure('Invalid JSON.', 400);
        }
        $items = $envelope['notificationItems'] ?? null;
        if (!in_array($envelope['live'] ?? null, [true, 'true'], true) || !is_array($items) || !array_is_list($items) || !$items || count($items) > 100) {
            return $this->failure('Invalid live notification batch.', 400);
        }
        foreach ($items as $wrapper) {
            $item = is_array($wrapper) ? ($wrapper['NotificationRequestItem'] ?? null) : null;
            if (!is_array($item) || ($item['merchantAccountCode'] ?? null) !== $merchant || !$this->hasValidSignature($item, $keys)) {
                return $this->failure('Merchant or signature verification failed.', 401);
            }
        }

        try {
            DB::table(self::TABLE)->upsert([[
                'merchant' => $merchant,
                'digest' => hash('sha256', $body),
                'payload' => Crypt::encryptString($body),
                'received_at' => now('UTC'),
            ]], ['merchant', 'digest'], ['digest']);
        } catch (\Throwable $error) {
            Log::error('Unable to persist Adyen notification', ['type' => $error::class]);

            return $this->failure('Inbox unavailable.', 503);
        }

        return response('', 202)->header('Cache-Control', 'no-store');
    }

    public function pending(Request $request): Response
    {
        if (!$this->canPull($request)) {
            return $this->failure('Authentication required.', 401);
        }
        try {
            $delivery = DB::table(self::TABLE)->where('merchant', config('adyen_inbox.merchant'))
                ->whereNull('acknowledged_at')->oldest('id')->first(['id', 'digest', 'payload']);
            if (!$delivery) {
                return response('', 204)->header('Cache-Control', 'private, no-store');
            }
            $payload = Crypt::decryptString($delivery->payload);
        } catch (\Throwable $error) {
            Log::error('Unable to read Adyen inbox delivery', ['type' => $error::class]);

            return $this->failure('Inbox unavailable.', 503);
        }

        return response($payload, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-store',
            'X-Adyen-Delivery-Id' => (string) $delivery->id,
            'X-Adyen-Delivery-Digest' => $delivery->digest,
        ]);
    }

    public function acknowledge(Request $request, string $delivery): Response
    {
        if (!$this->canPull($request)) {
            return $this->failure('Authentication required.', 401);
        }
        $digest = $request->header('X-Adyen-Delivery-Digest', '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            return $this->failure('Invalid delivery digest.', 400);
        }
        try {
            $row = DB::table(self::TABLE)->where('merchant', config('adyen_inbox.merchant'))
                ->where('id', $delivery)->where('digest', $digest);
            if (!$row->exists()) {
                return $this->failure('Delivery not found.', 404);
            }
            $row->whereNull('acknowledged_at')->update(['acknowledged_at' => now('UTC'), 'payload' => null]);
        } catch (\Throwable $error) {
            Log::error('Unable to acknowledge Adyen inbox delivery', ['type' => $error::class]);

            return $this->failure('Inbox unavailable.', 503);
        }

        return response('', 204)->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $merchant, string $filename): Response
    {
        if (!$this->canPull($request)) {
            return $this->failure('Authentication required.', 401);
        }
        if ($merchant !== config('adyen_inbox.merchant')) {
            return $this->failure('Report not found.', 404);
        }

        $key = config('adyen_inbox.report_api_key');
        if (!is_string($key) || $key === '') {
            return $this->failure('Report source unavailable.', 503);
        }

        $file = tmpfile();
        if ($file === false) {
            return $this->failure('Report source unavailable.', 503);
        }

        try {
            $upstream = Http::withHeaders(['X-API-Key' => $key])->connectTimeout(5)->timeout(90)->withoutRedirecting()
                ->withOptions([
                    'sink' => $file,
                    'progress' => static function ($total, $downloaded): void {
                        if (max($total, $downloaded) > self::MAX_REPORT_BYTES) {
                            throw new \LengthException('Adyen report exceeds the transfer limit.');
                        }
                    },
                ])->get('https://ca-live.adyen.com/reports/download/MerchantAccount/'.$merchant.'/'.$filename);
            if (!$upstream->ok()) {
                fclose($file);
                return match ($upstream->status()) {
                    404 => $this->failure('Report not available yet.', 404),
                    429 => $this->failure('Report source rate limited.', 429),
                    default => $this->failure('Report source unavailable.', 502),
                };
            }

            $size = fstat($file)['size'] ?? 0;
            if ($size < 1 || $size > self::MAX_REPORT_BYTES || !rewind($file)) {
                fclose($file);
                return $this->failure('Invalid upstream report.', 502);
            }
        } catch (\Throwable $error) {
            fclose($file);
            Log::warning('Unable to read Adyen report', ['type' => $error::class]);

            return $this->failure('Report source unavailable.', 502);
        }

        return response()->stream(static function () use ($file): void {
            try {
                fpassthru($file);
            } finally {
                fclose($file);
            }
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function canPull(Request $request): bool
    {
        $token = config('adyen_inbox.pull_token');

        return filled(config('adyen_inbox.merchant')) && is_string($token) && strlen($token) >= 32
            && hash_equals($token, (string) $request->bearerToken());
    }

    private function hasValidSignature(array $item, array $keys): bool
    {
        foreach (['pspReference' => 512, 'eventCode' => 100] as $field => $max) {
            if (!is_string($item[$field] ?? null) || $item[$field] === '' || mb_strlen($item[$field]) > $max) {
                return false;
            }
        }
        foreach (['originalReference', 'merchantReference'] as $field) {
            if (isset($item[$field]) && (!is_string($item[$field]) || mb_strlen($item[$field]) > 512)) {
                return false;
            }
        }
        $amount = $item['amount'] ?? [];
        $additional = $item['additionalData'] ?? [];
        if (!is_array($amount) || !is_array($additional)) {
            return false;
        }
        if (isset($amount['value']) && ((!is_int($amount['value']) && !is_string($amount['value']))
            || !preg_match('/^-?[0-9]{1,18}$/D', (string) $amount['value']))) {
            return false;
        }
        if (isset($amount['currency']) && (!is_string($amount['currency']) || !preg_match('/^[A-Z]{3}$/D', $amount['currency']))) {
            return false;
        }
        if (isset($item['eventDate']) && (!is_string($item['eventDate'])
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $item['eventDate']))) {
            return false;
        }
        $signature = $additional['hmacSignature'] ?? null;
        if (!is_string($signature) || strlen($signature) !== 44) {
            return false;
        }
        $success = $item['success'] ?? null;
        if (!in_array($success, [true, false, 'true', 'false'], true)) {
            return false;
        }
        $values = [$item['pspReference'], $item['originalReference'] ?? '', $item['merchantAccountCode'],
            $item['merchantReference'] ?? '', $amount['value'] ?? '', $amount['currency'] ?? '', $item['eventCode'],
            is_bool($success) ? ($success ? 'true' : 'false') : $success];
        $signed = implode(':', array_map(fn ($value) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $value), $values));
        foreach ($keys as $key) {
            if (preg_match('/^[a-f0-9]{64}$/iD', $key)
                && hash_equals(base64_encode(hash_hmac('sha256', $signed, hex2bin($key), true)), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function failure(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status)->header('Cache-Control', 'private, no-store');
    }
}
