<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalAccount;
use App\Models\LogisticsPayPalBalanceSnapshot;
use Carbon\Carbon;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentPayPalBalanceRefreshService
{
    private const PATH = '/relay/v1/paypal/read';

    public function refresh(LogisticsPayPalAccount $account): void
    {
        $this->assertAccountIsReady($account);
        $parts = parse_url((string) $account->relay_url);
        $secret = $this->decodeSecret($account);
        $timestamp = (string) now()->timestamp;
        $nonce = str_replace('-', '', (string) Str::uuid());
        $body = json_encode(['operation' => 'balances', 'query' => (object) []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "POST\n".self::PATH."\n$timestamp\n$nonce\n".hash('sha256', $body), $secret);

        try {
            $response = Http::withOptions([
                'verify' => config('finance.relay.ca_bundle') ?: true,
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
                    throw new PaymentPayPalBalanceRefreshException('无法读取 VPS 中转服务的响应。');
                }
                $raw .= $chunk;
                if (strlen($raw) > $limit) {
                    throw new PaymentPayPalBalanceRefreshException('PayPal 余额响应过大，已拒绝保存。');
                }
            }
            $stream->close();
        } catch (PaymentPayPalBalanceRefreshException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new PaymentPayPalBalanceRefreshException('无法连接 VPS 中转服务，请检查账户配置和 VPS 状态。');
        }

        $this->assertSignedResponse(
            $response->status(),
            (string) $response->header('X-Relay-Response-Timestamp'),
            (string) $response->header('X-Relay-Response-Signature'),
            $raw,
            $timestamp,
            $nonce,
            $secret,
        );
        if (! $response->successful()) {
            throw new PaymentPayPalBalanceRefreshException('PayPal 未成功返回余额，请稍后重试。');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PaymentPayPalBalanceRefreshException('PayPal 余额响应不是有效 JSON。');
        }
        $balances = $this->validatedBalances($payload);
        $asOfTime = $this->dateTime($payload['as_of_time'] ?? null);
        $lastRefreshTime = $this->dateTime($payload['last_refresh_time'] ?? null) ?: $asOfTime ?: now();
        $batchId = 'relay-balance-'.str_replace('-', '', (string) Str::uuid());

        DB::connection(config('admin.database.connection') ?: config('database.default'))->transaction(function () use ($account, $balances, $asOfTime, $lastRefreshTime, $batchId): void {
            LogisticsPayPalBalanceSnapshot::query()
                ->where('logistics_paypal_account_id', $account->getKey())
                ->delete();

            foreach ($balances as $balance) {
                LogisticsPayPalBalanceSnapshot::query()->create([
                    'logistics_paypal_account_id' => $account->getKey(),
                    'logistics_paypal_import_id' => null,
                    'upload_id' => $batchId,
                    'merchant_id' => $account->merchant_id,
                    'currency_code' => $balance['currency'],
                    'total_balance' => $balance['total'],
                    'available_balance' => $balance['available'],
                    'withheld_balance' => $balance['withheld'],
                    'as_of_time' => $asOfTime,
                    'last_refresh_time' => $lastRefreshTime,
                    'raw_payload' => $balance['raw'],
                ]);
            }
            $account->touch();
        });
    }

    private function assertAccountIsReady(LogisticsPayPalAccount $account): void
    {
        $parts = parse_url((string) $account->relay_url);
        if (! $account->is_enabled) {
            throw new PaymentPayPalBalanceRefreshException('该 PayPal 账户已停用，无法刷新余额。');
        }
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || ($parts['path'] ?? '') !== self::PATH || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
            || ! preg_match('/^[A-Fa-f0-9]{40}$/', (string) $account->relay_certificate_thumbprint_sha1)
            || ! preg_match('/^[A-Za-z0-9._-]+$/', (string) $account->relay_hmac_key_id)) {
            throw new PaymentPayPalBalanceRefreshException('请先完整配置该账户的 VPS API 地址、证书 SHA-1 指纹和 HMAC 密钥。');
        }
    }

    private function decodeSecret(LogisticsPayPalAccount $account): string
    {
        try {
            $encoded = $account->relay_hmac_secret;
            $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        } catch (\Throwable) {
            $secret = false;
            $encoded = null;
        }
        if (! is_string($secret) || strlen($secret) < 32 || base64_encode($secret) !== $encoded) {
            throw new PaymentPayPalBalanceRefreshException('该账户的 HMAC 密钥无效，请重新保存配置。');
        }

        return $secret;
    }

    private function assertSignedResponse(int $status, string $responseTimestamp, string $responseSignature, string $raw, string $requestTimestamp, string $nonce, string $secret): void
    {
        if ($responseTimestamp === '' || $responseSignature === '') {
            throw new PaymentPayPalBalanceRefreshException(match ($status) {
                401 => 'VPS 中转服务验证失败，请检查 HMAC 密钥。',
                403 => '当前服务器 IP 未获 VPS 中转服务授权。',
                default => 'VPS 中转服务响应缺少签名，已拒绝保存。',
            });
        }
        if (! ctype_digit($responseTimestamp) || abs((int) $requestTimestamp - (int) $responseTimestamp) > (int) config('finance.relay.clock_skew', 86400)
            || ! preg_match('/^[a-fA-F0-9]{64}$/', $responseSignature)) {
            throw new PaymentPayPalBalanceRefreshException('VPS 中转服务响应签名无效，已拒绝保存。');
        }
        $expected = hash_hmac('sha256', $status."\n".$responseTimestamp."\n".$nonce."\n".hash('sha256', $raw), $secret);
        if (! hash_equals($expected, strtolower($responseSignature))) {
            throw new PaymentPayPalBalanceRefreshException('VPS 中转服务响应签名验证失败，已拒绝保存。');
        }
    }

    /** @return list<array{currency:string,total:string,available:string,withheld:string,raw:array<string,mixed>}> */
    private function validatedBalances(mixed $payload): array
    {
        if (! is_array($payload) || array_is_list($payload) || ! is_array($payload['balances'] ?? null) || $payload['balances'] === []) {
            throw new PaymentPayPalBalanceRefreshException('PayPal 未返回可保存的余额数据。');
        }
        $balances = [];
        foreach ($payload['balances'] as $item) {
            if (! is_array($item)) {
                throw new PaymentPayPalBalanceRefreshException('PayPal 余额数据格式不正确。');
            }
            $currency = strtoupper(trim((string) ($item['currency'] ?? data_get($item, 'available_balance.currency_code'))));
            $total = data_get($item, 'total_balance.value');
            $available = data_get($item, 'available_balance.value');
            $withheld = data_get($item, 'withheld_balance.value');
            if (! preg_match('/^[A-Z]{3}$/', $currency) || ! is_numeric($total) || ! is_numeric($available) || ! is_numeric($withheld)) {
                throw new PaymentPayPalBalanceRefreshException('PayPal 返回的币种或金额格式不正确。');
            }
            if (isset($balances[$currency])) {
                throw new PaymentPayPalBalanceRefreshException('PayPal 返回了重复币种，已拒绝保存。');
            }
            $balances[$currency] = [
                'currency' => $currency,
                'total' => number_format((float) $total, 8, '.', ''),
                'available' => number_format((float) $available, 8, '.', ''),
                'withheld' => number_format((float) $withheld, 8, '.', ''),
                'raw' => $item,
            ];
        }

        return array_values($balances);
    }

    private function dateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
