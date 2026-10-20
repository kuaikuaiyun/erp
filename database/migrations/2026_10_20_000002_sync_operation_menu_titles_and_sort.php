<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 线上菜单名同步 + sort 调整：
 *   运营门户 yunying：
 *     product_requests.title      需求提报 → 自己开品提报
 *     ai_product_requests.title   AI 产品需求提报 → 已有产品投放连接提报
 *     sort 顺序：查重工具 → 自己开品提报 → 已有产品投放连接提报 → 审核链接问题 → 仓库问题
 *   审核门户 review：
 *     ai_product_reviews.title    AI 产品投放链接审核 → 已有产品投放连接审核
 */
return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');

        $updates = [
            'yunying' => [
                ['code' => 'product_requests',      'title' => '自己开品提报',          'sort' => 10],
                ['code' => 'ai_product_requests',   'title' => '已有产品投放连接提报',  'sort' => 11],
                ['code' => 'link_review_issues',    'title' => null,                   'sort' => 20],
                ['code' => 'warehouse_issues',      'title' => null,                   'sort' => 30],
            ],
            'caigou' => [
                ['code' => 'ai_product_reviews',    'title' => '已有产品投放连接审核',  'sort' => null],
            ],
        ];

        foreach ($updates as $portalCode => $rows) {
            $portalId = DB::connection($connection)
                ->table('portals')
                ->where('code', $portalCode)
                ->value('id');
            if (!$portalId) {
                continue;
            }

            foreach ($rows as $row) {
                DB::connection($connection)
                    ->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', $row['code'])
                    ->update(array_filter([
                        'title' => $row['title'],
                        'sort' => $row['sort'],
                    ], fn ($v) => $v !== null));
            }
        }
    }

    public function down(): void
    {
        // 手动回滚：按需执行
    }
};
