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
        if ($schema->hasColumn($table, 'employment_status')) return;

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->string('employment_status', 20)->default('employed')->after('hired_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = config('admin.database.users_table');
        if (! $schema->hasColumn($table, 'employment_status')) return;

        $schema->table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('employment_status');
        });
    }
};
