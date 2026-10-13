<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'wuliu';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $database = DB::connection($connection);
        $portalId = $database->table('portals')->where('code', self::PORTAL_CODE)->value('id');
        if (!$portalId) {
            return;
        }

        $timestamp = now();
        $parentId = $this->upsertMenu($database, $portalId, 'logistics_reports', [
            'parent_id' => 0,
            'title' => '统计报表',
            'title_key' => 'logistics.reports.menu',
            'path' => '/product-statistics',
            'icon' => 'fa-bar-chart',
            'sort' => 30,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ], $timestamp);

        $this->upsertMenu($database, $portalId, 'logistics_product_statistics', [
            'parent_id' => $parentId,
            'title' => '产品统计',
            'title_key' => 'logistics.product_statistics.menu',
            'path' => '/product-statistics',
            'icon' => 'fa-cubes',
            'sort' => 10,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ], $timestamp);
    }

    public function down(): void
    {
        // Keep the menu configuration when rolling back a UI-only release.
    }

    private function upsertMenu(\Illuminate\Database\Connection $database, int $portalId, string $code, array $values, \DateTimeInterface $timestamp): int
    {
        $existing = $database->table('portal_menus')
            ->where('portal_id', $portalId)
            ->where('code', $code)
            ->first(['id']);

        if ($existing) {
            $database->table('portal_menus')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) $database->table('portal_menus')->insertGetId([
            'portal_id' => $portalId,
            'code' => $code,
            ...$values,
            'created_at' => $timestamp,
        ]);
    }
};
