<?php

namespace App\Portals\Finance;

use App\Models\FinancePaymentAccount;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FinanceRelayClient
{
    public const PATH = '/relay/v1/paypal/read';

    public function read(FinancePaymentAccount $account, string $operation, array $query = []): array
    {
        if ($account->account_type !== 'paypal' || $account->status !== 'enabled') {
            throw new FinanceRelayException('account');
        }
        if (! in_array($operation, ['balances', 'transaction_search'], true)) {
            throw new FinanceRelayException('operation');
        }
        $parts = parse_url((string) $account->relay_url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || ($parts['path'] ?? '') !== self::PATH || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
            || ! preg_match('/^[A-Fa-f0-9]{40}$/', (string) $account->relay_certificate_sha1)
            || ! preg_match('/^[A-Za-z0-9._-]+$/', (string) $account->relay_hmac_key_id)) {
            throw new FinanceRelayException('configuration');
        }
        $allowed = $operation === 'balances' ? [] : ['start_date', 'end_date', 'fields', 'page_size', 'page', 'balance_affecting_records_only'];
        if (array_diff(array_keys($query), $allowed)) {
            throw new FinanceRelayException('operation');
        }
        try {
            $encodedSecret = $account->relay_hmac_secret;
            $secret = is_string($encodedSecret) ? base64_decode($encodedSecret, true) : false;
        } catch (\Throwable) {
            throw new FinanceRelayException('configuration');
        }
        if (! is_string($secret) || strlen($secret) < 32 || base64_encode($secret) !== $encodedSecret) {
            throw new FinanceRelayException('secret_encoding');
        }
        $timestamp = (string) now()->timestamp;
        $nonce = str_replace('-', '', (string) Str::uuid());
        $body = json_encode(['operation' => $operation, 'query' => (object) $query], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "POST\n".self::PATH."\n$timestamp\n$nonce\n".hash('sha256', $body), $secret);
        try {
            // Use the PHP stream TLS handler so SHA-1 certificate pinning happens
            // during the handshake, before HTTP headers or credentials are sent.
            $response = Http::withOptions([
                'verify' => config('finance.relay.ca_bundle') ?: true,
                'proxy' => '',
                'allow_redirects' => false,
                'stream' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'stream_context' => ['ssl' => [
                    'verify_peer' => true,
                    // IP relays use their exact certificate pin as identity;
                    // legacy self-signed certificates may not contain an IP SAN.
                    'verify_peer_name' => filter_var($parts['host'], FILTER_VALIDATE_IP) === false,
                    'allow_self_signed' => true,
                    'peer_fingerprint' => ['sha1' => strtolower($account->relay_certificate_sha1)],
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'SNI_enabled' => filter_var($parts['host'], FILTER_VALIDATE_IP) === false,
                ]],
            ])->setHandler(new StreamHandler())->timeout((int) config('finance.relay.timeout', 20))->withHeaders([
                'Accept' => 'application/json', 'Accept-Encoding' => 'identity',
                'X-Relay-Key' => $account->relay_hmac_key_id,
                'X-Relay-Timestamp' => $timestamp, 'X-Relay-Nonce' => $nonce, 'X-Relay-Signature' => $signature,
            ])->withBody($body, 'application/json')->post($account->relay_url);
            $stream = $response->toPsrResponse()->getBody();
            $raw = '';
            $limit = (int) config('finance.relay.max_response_bytes', 20 * 1024 * 1024);
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                if ($chunk === '' && ! $stream->eof()) {
                    throw new FinanceRelayException('transport');
                }
                $raw .= $chunk;
                if (strlen($raw) > $limit) {
                    throw new FinanceRelayException('size');
                }
            }
            $stream->close();
        } catch (FinanceRelayException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FinanceRelayException('transport');
        }
        $responseTimestamp = (string) $response->header('X-Relay-Response-Timestamp');
        $responseSignature = (string) $response->header('X-Relay-Response-Signature');
        if ($responseTimestamp === '' || $responseSignature === '') {
            // TLS has already authenticated the pinned endpoint. Authentication
            // rejections may be unsigned; report their status without treating
            // their body as verified PayPal data or saving it.
            throw new FinanceRelayException(match ($response->status()) {
                401 => 'relay_unauthorized',
                403 => 'relay_forbidden',
                default => 'response_unsigned',
            });
        }
        if (! ctype_digit($responseTimestamp) || abs(now()->timestamp - (int) $responseTimestamp) > config('finance.relay.clock_skew', 300)
            || ! preg_match('/^[a-fA-F0-9]{64}$/', $responseSignature)) {
            throw new FinanceRelayException('signature');
        }
        // Match the supplied paypal_relay_balance.py wire protocol exactly.
        $expected = hash_hmac('sha256', self::responseMessage($responseTimestamp, $nonce, $response->status(), $raw), $secret);
        if (! hash_equals($expected, strtolower($responseSignature))) {
            throw new FinanceRelayException('signature');
        }
        if (! $response->successful()) {
            throw new FinanceRelayException('upstream');
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FinanceRelayException('payload');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new FinanceRelayException('payload');
        }

        return ['payload' => $payload, 'raw' => $raw, 'status' => $response->status()];
    }

    public static function responseMessage(string $timestamp, string $nonce, int $status, string $body): string
    {
        return $status."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body);
    }
}
