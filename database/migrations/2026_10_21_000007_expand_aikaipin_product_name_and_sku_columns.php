<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->table('aikaipin_products', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('aikaipin_products', 'sku')) {
                $table->text('sku')->nullable()->change();
            }

            if ($schema->hasColumn('aikaipin_products', 'name')) {
                $table->string('name', 255)->change();
            }
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $database = DB::connection($connection);
        $hasLongValues = $database->table('aikaipin_products')
            ->whereRaw('CHAR_LENGTH(`sku`) > 191')
            ->orWhereRaw('CHAR_LENGTH(`name`) > 191')
            ->exists();

        if ($hasLongValues) {
            throw new \RuntimeException(__('aikaipin.migrations.product_name_sku_rollback_unsafe'));
        }

        $schema->table('aikaipin_products', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('aikaipin_products', 'sku')) {
                $table->string('sku', 191)->nullable()->change();
            }

            if ($schema->hasColumn('aikaipin_products', 'name')) {
                $table->string('name', 191)->change();
            }
        });
    }
};
