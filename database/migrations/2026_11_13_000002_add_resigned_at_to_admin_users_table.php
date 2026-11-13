<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = config('admin.database.users_table');
        if ($schema->hasColumn($table, 'resigned_at')) return;

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->date('resigned_at')->nullable()->after('hired_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = config('admin.database.users_table');
        if (! $schema->hasColumn($table, 'resigned_at')) return;

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('resigned_at');
        });
    }
};
