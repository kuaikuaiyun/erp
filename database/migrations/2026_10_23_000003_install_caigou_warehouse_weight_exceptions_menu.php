<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PORTAL_CODE = 'caigou';

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

        $this->upsertMenu($database, $portalId, 'caigou_warehouse_weight_exceptions', [
            'parent_id' => 0,
            'title' => '仓库异常SKU数据',
            'title_key' => 'caigou.warehouse_weight_exceptions.menu',
            'path' => '/warehouse-weight-exceptions',
            'icon' => 'fa-exclamation-triangle',
            'sort' => 40,
            'is_enabled' => true,
            'updated_at' => $timestamp,
        ], $timestamp);
    }

    public function down(): void
    {
        // 保留菜单配置，rollback 不清理
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
