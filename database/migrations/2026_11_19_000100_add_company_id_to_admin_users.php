<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function getConnection(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table(config('admin.database.users_table'), function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table(config('admin.database.users_table'), function (Blueprint $table): void {
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
