<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CAPTCHA_URI = 'configuration/captcha';
    private const CAPTCHA_PERMISSION = 'configuration.captcha';

    /**
     * Apply translated titles to databases where earlier menu migrations have
     * already executed, without changing administrator permissions or roles.
     */
    public function up(): void
    {
        $connection = $this->getConnection();
        $schema = Schema::connection($connection);
        $menuTable = config('admin.database.menu_table');
        $permissionTable = config('admin.database.permissions_table');

        if (!$schema->hasTable($menuTable) || !$schema->hasTable($permissionTable)) {
            throw new RuntimeException('Required administrator menu and permission tables do not exist.');
        }

        $parentTitle = $this->translation('configuration.menu.configuration_center');
        $captchaTitle = $this->translation('configuration.menu.login_captcha');
        $database = DB::connection($connection);
        $timestamp = now();

        $database->table($menuTable)
            ->where('parent_id', 0)
            ->where('icon', 'fa-cogs')
            ->whereNull('uri')
            ->update(['title' => $parentTitle, 'updated_at' => $timestamp]);

        $database->table($menuTable)
            ->where('uri', self::CAPTCHA_URI)
            ->update(['title' => $captchaTitle, 'updated_at' => $timestamp]);

        $database->table($permissionTable)
            ->where('slug', self::CAPTCHA_PERMISSION)
            ->update(['name' => $captchaTitle, 'updated_at' => $timestamp]);
    }

    public function down(): void
    {
        // Keep administrator-controlled menu titles on rollback.
    }

    public function getConnection()
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    private function translation(string $key): string
    {
        $translation = Lang::get($key, [], 'zh-CN');

        if ($translation === $key) {
            throw new RuntimeException("Required translation [{$key}] does not exist.");
        }

        return (string) $translation;
    }
};


