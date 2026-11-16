<?php

namespace App\Services\DingTalk;

use Symfony\Component\HttpKernel\Exception\HttpException;

class CallbackCrypto
{
    private ?\App\Models\DingTalkCompany $company = null;

    public function forCompany(\App\Models\DingTalkCompany $company): static
    {
        $copy = clone $this; $copy->company = $company; return $copy;
    }

    private function setting(string $key): mixed
    {
        if (!$this->company) throw new HttpException(503, __('dingtalk.errors.not_configured'));
        return $this->company->getAttribute($key);
    }

    // Wire protocol: https://github.com/open-dingtalk/DingTalk-Callback-Crypto
    private function key(): string
    {
        $key = base64_decode((string) $this->setting('callback_aes_key').'=', true);
        if ($key === false || strlen($key) !== 32 || !$this->setting('callback_token') || !$this->setting('callback_owner_key')) {
            throw new HttpException(503, __('dingtalk.errors.not_configured'));
        }
        return $key;
    }

    private function signature(string $timestamp, string $nonce, string $encrypted): string
    {
        $parts = [(string) $this->setting('callback_token'), $timestamp, $nonce, $encrypted];
        sort($parts, SORT_STRING);
        return sha1(implode('', $parts));
    }

    public function decrypt(string $signature, string $timestamp, string $nonce, string $encrypted): string
    {
        $key = $this->key();
        if (!hash_equals($this->signature($timestamp, $nonce, $encrypted), $signature)) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $cipher = base64_decode($encrypted, true);
        $plain = $cipher === false ? false : openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
        if ($plain === false || strlen($plain) < 32) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $padding = ord(substr($plain, -1));
        if ($padding < 1 || $padding > 32 || !hash_equals(str_repeat(chr($padding), $padding), substr($plain, -$padding))) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $plain = substr($plain, 0, -$padding);
        if (strlen($plain) < 20) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $length = unpack('Nlength', substr($plain, 16, 4))['length'];
        if ($length > strlen($plain) - 20 || !hash_equals((string) $this->setting('callback_owner_key'), substr($plain, 20 + $length))) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        return substr($plain, 20, $length);
    }

    public function encrypt(string $message): array
    {
        $key = $this->key();
        $plain = random_bytes(16).pack('N', strlen($message)).$message.$this->setting('callback_owner_key');
        $padding = 32 - strlen($plain) % 32;
        $encrypted = openssl_encrypt($plain.str_repeat(chr($padding), $padding), 'AES-256-CBC', $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
        if ($encrypted === false) {
            throw new HttpException(403, __('dingtalk.errors.bad_callback'));
        }
        $encrypted = base64_encode($encrypted);
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(16));
        return ['msg_signature' => $this->signature($timestamp, $nonce, $encrypted), 'timeStamp' => $timestamp,
            'nonce' => $nonce, 'encrypt' => $encrypted];
    }
}
