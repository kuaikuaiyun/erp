<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $timestamp = now();

        $yunyingId = $database->table('portals')->where('code', 'yunying')->value('id');
        if (!$yunyingId) {
            return;
        }

        $existing = $database->table('portal_menus')
            ->where('portal_id', $yunyingId)
            ->where('code', 'product_dedupe_checker')
            ->first(['id']);

        if ($existing) {
            return;
        }

        $database->table('portal_menus')->insert([
            'portal_id' => $yunyingId,
            'parent_id' => 0,
            'code' => 'product_dedupe_checker',
            'title' => (string) Lang::get('product_requests.menus.product_dedupe_checker', [], 'zh-CN'),
            'title_key' => 'product_requests.menus.product_dedupe_checker',
            'path' => '/product-request-dedupe',
            'icon' => 'fa-search-plus',
            'sort' => 1,
            'is_enabled' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);

        if ($database->getSchemaBuilder()->hasTable('portals') && $database->getSchemaBuilder()->hasTable('portal_menus')) {
            $yunyingId = $database->table('portals')->where('code', 'yunying')->value('id');
            if ($yunyingId) {
                $database->table('portal_menus')
                    ->where('portal_id', $yunyingId)
                    ->where('code', 'product_dedupe_checker')
                    ->delete();
            }
        }
    }
};
