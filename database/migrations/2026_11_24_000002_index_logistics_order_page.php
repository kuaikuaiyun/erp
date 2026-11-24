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
        $schema->table('shop_order_logistics', function (Blueprint $table): void {
            $table->index(['order_created_at', 'id', 'operator_name'], 'idx_sol_page_operator');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('shop_order_logistics', function (Blueprint $table): void {
            $table->dropIndex('idx_sol_page_operator');
        });
    }
};
