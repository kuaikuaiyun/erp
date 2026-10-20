<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 线上菜单名同步 + sort 调整：
 *   product_requests.title      需求提报 → 自己开品提报
 *   ai_product_requests.title   AI 产品需求提报 → AI 团队产品库产品提报
 *   sort 顺序：查重工具 → 自己开品提报 → AI 团队产品库产品提报 → 审核链接问题 → 仓库问题
 *
 * 本地已验证 title 正确（2026-10-20），此迁移用于线上数据库。
 */
return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        $yunyingId = DB::connection($connection)
            ->table('portals')
            ->where('code', 'yunying')
            ->value('id');

        if (!$yunyingId) {
            return;
        }

        $updates = [
            ['code' => 'product_requests',      'title' => '自己开品提报',         'sort' => 10],
            ['code' => 'ai_product_requests',   'title' => 'AI 团队产品库产品提报', 'sort' => 11],
            ['code' => 'link_review_issues',    'title' => null,                   'sort' => 20],
            ['code' => 'warehouse_issues',      'title' => null,                   'sort' => 30],
        ];

        foreach ($updates as $row) {
            DB::connection($connection)
                ->table('portal_menus')
                ->where('portal_id', $yunyingId)
                ->where('code', $row['code'])
                ->update(array_filter([
                    'title' => $row['title'],
                    'sort' => $row['sort'],
                ], fn ($v) => $v !== null));
        }
    }

    public function down(): void
    {
        // 手动回滚：按需执行
    }
};
