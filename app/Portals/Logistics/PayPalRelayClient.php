<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsPayPalAccount;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Narrow read-only client for the logistics/payments PayPal VPS relay.
 *
 * This deliberately does not use the finance relay client or finance account
 * models. The two portals have separate data boundaries and credentials.
 */
final class PayPalRelayClient
{
    public const PATH = '/relay/v1/paypal/read';

    /** @return array{payload:array<string,mixed>,raw:string,status:int} */
    public function read(LogisticsPayPalAccount $account, string $operation, array $query = []): array
    {
        $this->assertAccount($account);
        $this->assertOperation($operation, $query);
        $parts = parse_url((string) $account->relay_url);
        $secret = $this->secret($account);
        $timestamp = (string) now()->timestamp;
        $nonce = str_replace('-', '', (string) Str::uuid());
        $body = json_encode(['operation' => $operation, 'query' => (object) $query], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "POST\n".self::PATH."\n$timestamp\n$nonce\n".hash('sha256', $body), $secret);

        try {
            $response = Http::withOptions([
                'verify' => config('logistics.paypal.relay.ca_bundle') ?: true,
                'proxy' => '',
                'allow_redirects' => false,
                'stream' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'stream_context' => ['ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => filter_var($parts['host'], FILTER_VALIDATE_IP) === false,
                    'allow_self_signed' => true,
                    'peer_fingerprint' => ['sha1' => strtolower((string) $account->relay_certificate_thumbprint_sha1)],
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'SNI_enabled' => filter_var($parts['host'], FILTER_VALIDATE_IP) === false,
                ]],
            ])->setHandler(new StreamHandler())
                ->timeout($this->bounded('timeout', 60, 1, 300))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                    'X-Relay-Key' => $account->relay_hmac_key_id,
                    'X-Relay-Timestamp' => $timestamp,
                    'X-Relay-Nonce' => $nonce,
                    'X-Relay-Signature' => $signature,
                ])->withBody($body, 'application/json')->post($account->relay_url);

            $stream = $response->toPsrResponse()->getBody();
            $raw = '';
            $limit = $this->bounded('max_response_bytes', 20 * 1024 * 1024, 1024, 100 * 1024 * 1024);
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '' && ! $stream->eof()) {
                    throw new PayPalRelayException('transport');
                }
                $raw .= $chunk;
                if (strlen($raw) > $limit) {
                    throw new PayPalRelayException('size');
                }
            }
            $stream->close();
        } catch (PayPalRelayException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new PayPalRelayException('transport');
        }

        $responseTimestamp = (string) $response->header('X-Relay-Response-Timestamp');
        $responseSignature = (string) $response->header('X-Relay-Response-Signature');
        if ($responseTimestamp === '' || $responseSignature === '') {
            throw new PayPalRelayException(match ($response->status()) {
                401 => 'relay_unauthorized',
                403 => 'relay_forbidden',
                default => 'response_unsigned',
            });
        }
        if (! ctype_digit($responseTimestamp)
            || abs(now()->timestamp - (int) $responseTimestamp) > $this->bounded('clock_skew_seconds', 300, 1, 3600)
            || ! preg_match('/^[a-fA-F0-9]{64}$/', $responseSignature)) {
            throw new PayPalRelayException('signature');
        }
        $expected = hash_hmac('sha256', self::responseMessage($responseTimestamp, $nonce, $response->status(), $raw), $secret);
        if (! hash_equals($expected, strtolower($responseSignature))) {
            throw new PayPalRelayException('signature');
        }
        if (! $response->successful()) {
            throw new PayPalRelayException('upstream');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PayPalRelayException('payload');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new PayPalRelayException('payload');
        }

        return ['payload' => $payload, 'raw' => $raw, 'status' => $response->status()];
    }

    public function isConfigured(LogisticsPayPalAccount $account): bool
    {
        try {
            $this->assertAccount($account);
            $this->secret($account);

            return true;
        } catch (PayPalRelayException) {
            return false;
        }
    }

    public static function responseMessage(string $timestamp, string $nonce, int $status, string $body): string
    {
        return $status."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
    }

    private function assertAccount(LogisticsPayPalAccount $account): void
    {
        if (! $account->is_enabled) {
            throw new PayPalRelayException('account');
        }
        $parts = parse_url((string) $account->relay_url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || ($parts['path'] ?? '') !== self::PATH || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
            || trim((string) $account->merchant_id) === ''
            || ! preg_match('/^[A-Fa-f0-9]{40}$/', (string) $account->relay_certificate_thumbprint_sha1)
            || ! preg_match('/^[A-Za-z0-9._-]+$/', (string) $account->relay_hmac_key_id)) {
            throw new PayPalRelayException('configuration');
        }
    }

    private function assertOperation(string $operation, array $query): void
    {
        $allowed = match ($operation) {
            'transaction_search' => ['start_date', 'end_date', 'fields', 'page_size', 'page', 'balance_affecting_records_only'],
            'dispute_search' => ['update_time_after', 'update_time_before', 'page_size', 'page'],
            'dispute_detail' => ['dispute_id'],
            default => throw new PayPalRelayException('operation'),
        };
        if (array_diff(array_keys($query), $allowed)) {
            throw new PayPalRelayException('operation');
        }
    }

    private function secret(LogisticsPayPalAccount $account): string
    {
        try {
            $encoded = $account->relay_hmac_secret;
            $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        } catch (\Throwable) {
            throw new PayPalRelayException('configuration');
        }
        if (! is_string($secret) || strlen($secret) < 32 || base64_encode($secret) !== $encoded) {
            throw new PayPalRelayException('secret_encoding');
        }

        return $secret;
    }

    private function bounded(string $key, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) config('logistics.paypal.relay.'.$key, $default)));
    }
}
