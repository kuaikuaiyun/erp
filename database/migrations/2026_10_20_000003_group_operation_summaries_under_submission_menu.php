<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把 5 个运营门户菜单（查重工具 / 自己开品提报 / 已有产品投放连接提报 /
 * 审核链接问题 / 仓库问题）合并到一个名为「提报」的父菜单下。
 *
 * 父菜单 code: operation_submission_group，icon=fa-paper-plane，sort=5
 * 子菜单保留原 sort 顺序（同 sort 在父级下仍然有效）。
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

        // 1. 创建/更新父菜单「提报」
        $parentId = DB::connection($connection)
            ->table('portal_menus')
            ->where('portal_id', $yunyingId)
            ->where('code', 'operation_submission_group')
            ->value('id');

        $parentValues = [
            'portal_id' => $yunyingId,
            'parent_id' => 0,
            'code' => 'operation_submission_group',
            'title' => '提报',
            'title_key' => 'product_requests.menus.submission_group',
            'icon' => 'fa-paper-plane',
            'sort' => 5,
            'updated_at' => now(),
        ];

        if ($parentId) {
            DB::connection($connection)
                ->table('portal_menus')
                ->where('id', $parentId)
                ->update($parentValues);
        } else {
            $parentValues['created_at'] = now();
            DB::connection($connection)
                ->table('portal_menus')
                ->insert($parentValues);
            $parentId = DB::connection($connection)
                ->table('portal_menus')
                ->where('portal_id', $yunyingId)
                ->where('code', 'operation_submission_group')
                ->value('id');
        }

        // 2. 把 5 个子菜单 parent_id 改为父级
        $children = [
            'product_dedupe_checker',
            'product_requests',
            'ai_product_requests',
            'link_review_issues',
            'warehouse_issues',
        ];

        foreach ($children as $code) {
            DB::connection($connection)
                ->table('portal_menus')
                ->where('portal_id', $yunyingId)
                ->where('code', $code)
                ->update([
                    'parent_id' => $parentId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // 手动回滚：将 children 的 parent_id 改回 0，并删除父菜单
    }
};