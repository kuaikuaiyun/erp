<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LoginCaptchaService
{
    private const TENCENT_ENDPOINT = 'https://captcha.tencentcloudapi.com/';
    private const TENCENT_ACTION = 'DescribeCaptchaResult';
    private const TENCENT_VERSION = '2019-07-22';
    private const TENCENT_AID_ENCRYPTED_TTL = 300;

    public function __construct(private readonly SystemSettingService $settings)
    {
    }

    /**
     * The login page only receives the selected provider's public key.
     */
    public function publicConfiguration(): array
    {
        $settings = $this->settings->loginCaptcha();
        $provider = $settings['provider'];
        $siteKey = $provider === 'tencent_captcha'
            ? (string) $settings['tencent_captcha']['app_id']
            : (string) $settings[$provider]['site_key'];

        return [
            'enabled' => $settings['enabled'],
            'provider' => $provider,
            'site_key' => $siteKey,
        ];
    }

    /**
     * Verify a user response with the selected vendor on the server.
     */
    public function verify(Request $request): bool
    {
        $settings = $this->settings->loginCaptcha();

        if (!$settings['enabled']) {
            return true;
        }

        return $settings['provider'] === 'tencent_captcha'
            ? $this->verifyTencentCaptcha($request, $settings)
            : $this->verifyTokenCaptcha($request, $settings);
    }

    public function maxAttempts(): int
    {
        return $this->settings->loginCaptcha()['max_attempts'];
    }

    public function decaySeconds(): int
    {
        return $this->settings->loginCaptcha()['decay_seconds'];
    }

    /**
     * Build a short-lived encrypted Tencent option for the browser SDK. The
     * AppSecretKey remains on the server and is never returned to the client.
     */
    public function tencentCaptchaClientOptions(): ?array
    {
        $settings = $this->settings->loginCaptcha();

        if (!$settings['enabled'] || $settings['provider'] !== 'tencent_captcha') {
            return null;
        }

        $appId = trim((string) $settings['tencent_captcha']['app_id']);
        $appSecretKey = $this->settings->loginCaptchaSecret('tencent_captcha_app_secret_key');

        if (!preg_match('/^\d{1,20}$/', $appId) || $appSecretKey === '') {
            return null;
        }

        try {
            $timestamp = now()->timestamp;
            $plaintext = $appId.'&'.$timestamp.'&'.self::TENCENT_AID_ENCRYPTED_TTL;
            $iv = random_bytes(16);
            $ciphertext = openssl_encrypt(
                $plaintext,
                'AES-256-CBC',
                $this->tencentEncryptionKey($appSecretKey),
                OPENSSL_RAW_DATA,
                $iv,
            );

            if (!is_string($ciphertext) || $ciphertext === '') {
                return null;
            }

            return [
                'aid_encrypted' => base64_encode($iv.$ciphertext),
                'aid_encrypted_type' => 'CBC',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function verifyTokenCaptcha(Request $request, array $settings): bool
    {
        $provider = $settings['provider'];
        $siteKey = trim((string) $settings[$provider]['site_key']);
        $secret = $this->settings->loginCaptchaSecret($provider);
        $token = $this->requestString($request, $this->responseField($provider));

        if ($siteKey === '' || $secret === '' || $token === '' || mb_strlen($token) > 2048) {
            return false;
        }

        $payload = [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => (string) $request->ip(),
        ];

        if ($provider === 'turnstile') {
            $payload['idempotency_key'] = (string) Str::uuid();
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(5)
                ->post($provider === 'turnstile'
                    ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                    : 'https://www.google.com/recaptcha/api/siteverify', $payload);

            return $response->successful()
                && $response->json('success') === true
                && $this->matchesRequestHostname($response->json('hostname'), $request);
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifyTencentCaptcha(Request $request, array $settings): bool
    {
        $appId = trim((string) $settings['tencent_captcha']['app_id']);
        $appSecretKey = $this->settings->loginCaptchaSecret('tencent_captcha_app_secret_key');
        $secretId = $this->settings->loginCaptchaSecret('tencent_captcha_secret_id');
        $secretKey = $this->settings->loginCaptchaSecret('tencent_captcha_secret_key');
        $ticket = $this->requestString($request, 'tencent_captcha_ticket');
        $randstr = $this->requestString($request, 'tencent_captcha_randstr');

        if (
            !preg_match('/^\d{1,20}$/', $appId)
            || $appSecretKey === ''
            || $secretId === ''
            || $secretKey === ''
            || $ticket === ''
            || $randstr === ''
            || mb_strlen($ticket) > 4096
            || mb_strlen($randstr) > 512
        ) {
            return false;
        }

        $payload = [
            'CaptchaType' => 9,
            'Ticket' => $ticket,
            'UserIp' => (string) $request->ip(),
            'Randstr' => $randstr,
            'CaptchaAppId' => (int) $appId,
            'AppSecretKey' => $appSecretKey,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($payloadJson)) {
            return false;
        }

        $timestamp = now()->timestamp;
        $date = gmdate('Y-m-d', $timestamp);
        $scope = $date.'/captcha/tc3_request';
        $canonicalHeaders = "content-type:application/json\nhost:captcha.tencentcloudapi.com\n";
        $signedHeaders = 'content-type;host';
        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payloadJson),
        ]);
        $stringToSign = implode("\n", [
            'TC3-HMAC-SHA256',
            (string) $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
        $secretDate = hash_hmac('sha256', $date, 'TC3'.$secretKey, true);
        $secretService = hash_hmac('sha256', 'captcha', $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
        $authorization = 'TC3-HMAC-SHA256 Credential='.$secretId.'/'.$scope
            .', SignedHeaders='.$signedHeaders
            .', Signature='.$signature;

        try {
            $response = Http::withHeaders([
                'Authorization' => $authorization,
                'Content-Type' => 'application/json',
                'Host' => 'captcha.tencentcloudapi.com',
                'X-TC-Action' => self::TENCENT_ACTION,
                'X-TC-Timestamp' => (string) $timestamp,
                'X-TC-Version' => self::TENCENT_VERSION,
            ])
                ->acceptJson()
                ->timeout(5)
                ->withBody($payloadJson, 'application/json')
                ->post(self::TENCENT_ENDPOINT);

            return $response->successful() && (int) $response->json('Response.CaptchaCode') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function responseField(string $provider): string
    {
        return $provider === 'turnstile' ? 'cf-turnstile-response' : 'g-recaptcha-response';
    }

    private function requestString(Request $request, string $field): string
    {
        $value = $request->input($field);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A vendor token must have been issued for the hostname that received this
     * login request. This prevents a valid token from another allowed hostname
     * from being replayed against this administrator login page.
     */
    private function matchesRequestHostname(mixed $hostname, Request $request): bool
    {
        return is_string($hostname)
            && $hostname !== ''
            && strcasecmp($hostname, $request->getHost()) === 0;
    }

    private function tencentEncryptionKey(string $appSecretKey): string
    {
        if (strlen($appSecretKey) >= 32) {
            return substr($appSecretKey, 0, 32);
        }

        return substr(str_repeat($appSecretKey, (int) ceil(32 / strlen($appSecretKey))), 0, 32);
    }
}


