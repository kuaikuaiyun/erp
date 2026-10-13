<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'configuration.captcha';

    public function up(): void
    {
        $connection = $this->getConnection();
        $permissionTable = config('admin.database.permissions_table');

        if (!Schema::connection($connection)->hasTable($permissionTable)) {
            throw new RuntimeException("Required administrator table [{$permissionTable}] does not exist.");
        }

        $database = DB::connection($connection);
        $permission = $database->table($permissionTable)->where('slug', self::PERMISSION)->first(['id']);
        $values = [
            'name' => Lang::get('configuration.menu.login_captcha', [], 'zh-CN'),
            'http_method' => 'GET,POST',
            'http_path' => "/configuration/captcha\r\n/configuration/captcha/*",
            'updated_at' => now(),
        ];

        if ($permission) {
            $database->table($permissionTable)->where('id', $permission->id)->update($values);
        } else {
            $database->table($permissionTable)->insert([
                'slug' => self::PERMISSION,
                ...$values,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep administrator permissions on rollback.
    }

    public function getConnection()
    {
        return config('admin.database.connection') ?: config('database.default');
    }
};


