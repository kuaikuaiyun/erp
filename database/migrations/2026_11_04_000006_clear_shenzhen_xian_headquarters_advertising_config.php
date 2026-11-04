<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('assistant_advertising_media')
            || ! $schema->hasTable('assistant_advertising_agencies')) return;

        $database = DB::connection($connection);
        $departmentId = $database->table(config('admin.database.roles_table'))
            ->where('name', '深圳西安等总部')->value('id');
        if (! $departmentId) return;

        $database->transaction(function () use ($database, $departmentId): void {
            $database->table('assistant_advertising_agencies')
                ->where('assistant_department_id', $departmentId)->delete();
            $database->table('assistant_advertising_media')
                ->where('assistant_department_id', $departmentId)->delete();
        });
    }

    public function down(): void
    {
        // Department advertising configuration is user-maintained data and
        // cannot be reconstructed reliably after it has been cleared.
    }
};
