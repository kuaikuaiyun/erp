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
        $table = config('admin.database.users_table');
        if (Schema::hasColumn($table, 'hired_at')) return;

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->date('hired_at')->nullable()->after('status')->comment('入职时间');
        });
    }

    public function down(): void
    {
        $table = config('admin.database.users_table');
        if (! Schema::hasColumn($table, 'hired_at')) return;

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn('hired_at');
        });
    }
};
