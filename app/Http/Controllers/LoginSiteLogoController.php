<?php

namespace App\Http\Controllers;

use App\Models\ErpLoginSite;
use App\Services\Authentication\LoginSiteLogo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoginSiteLogoController extends Controller
{
    public function __invoke(string $file): StreamedResponse
    {
        $path = LoginSiteLogo::DIRECTORY.$file;
        abort_unless(Schema::hasTable('erp_login_sites') && ErpLoginSite::query()->where('logo_path', $path)->exists(), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);
        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        };

        return $disk->response($path, $file, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
