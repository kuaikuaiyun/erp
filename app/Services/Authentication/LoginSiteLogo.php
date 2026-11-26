<?php

namespace App\Services\Authentication;

use Illuminate\Support\Facades\Storage;

class LoginSiteLogo
{
    public const DIRECTORY = 'erp/login-site-logos/';

    public const FILE_PATTERN = '[A-Za-z0-9_-]{1,100}\.(?:png|jpe?g|webp)';

    public function url(?string $path): ?string
    {
        if (!$path || !str_starts_with($path, self::DIRECTORY)) {
            return null;
        }

        $file = substr($path, strlen(self::DIRECTORY));
        if (!preg_match('~\A'.self::FILE_PATTERN.'\z~', $file) || !Storage::disk('public')->exists($path)) {
            return null;
        }

        return route('enterprise-login.logo', ['file' => $file], false);
    }
}
