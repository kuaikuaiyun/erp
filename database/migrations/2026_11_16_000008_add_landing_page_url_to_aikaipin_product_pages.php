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
        if (! $schema->hasTable('aikaipin_product_pages')) {
            return;
        }
        if (! $schema->hasColumn('aikaipin_product_pages', 'landing_page_url')) {
            $schema->table('aikaipin_product_pages', function (Blueprint $table): void {
                $table->string('landing_page_url', 2048)->nullable()->after('source_file');
            });
        }

        DB::connection($connection)->table('aikaipin_product_pages')
            ->join('aikaipin_products', 'aikaipin_products.id', '=', 'aikaipin_product_pages.aikaipin_product_id')
            ->whereNull('aikaipin_product_pages.landing_page_url')
            ->whereNotNull('aikaipin_products.landing_page_url')
            ->update(['aikaipin_product_pages.landing_page_url' => DB::raw('aikaipin_products.landing_page_url')]);
    }

    public function down(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if ($schema->hasTable('aikaipin_product_pages') && $schema->hasColumn('aikaipin_product_pages', 'landing_page_url')) {
            $schema->table('aikaipin_product_pages', function (Blueprint $table): void {
                $table->dropColumn('landing_page_url');
            });
        }
    }
};
