<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        // AI 产品基础数据表（暂无模拟数据，后续接入真实数据源时填充）
        if (!$schema->hasTable('ai_products')) {
            $schema->create('ai_products', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 191);
                $table->string('category', 100)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('ai_product_submissions')) {
            $schema->create('ai_product_submissions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('report_no', 64)->unique();
                $table->unsignedBigInteger('ai_product_id')->nullable()->index();
                $table->string('ai_product_name', 191)->nullable();
                $table->string('placement_link', 2048);

                $table->unsignedInteger('operation_user_id')->index();
                $table->string('operation_user_name', 100);
                $table->unsignedInteger('operation_department_id')->nullable()->index();
                $table->string('operation_display_name', 201);

                $table->string('review_status', 30)->default('pending')->index();
                $table->text('review_remark')->nullable();
                $table->unsignedInteger('reviewer_id')->nullable()->index();
                $table->string('reviewer_name', 100)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['operation_user_id', 'review_status'], 'ai_product_submission_user_status_index');
            });
        }

        $this->installMenus($connection);
    }

    public function down(): void
    {
        // 菜单与表记录保留，不回滚删除。
    }

    private function installMenus(string $connection): void
    {
        $database = DB::connection($connection);
        if (!$database->getSchemaBuilder()->hasTable('portals') || !$database->getSchemaBuilder()->hasTable('portal_menus')) {
            return;
        }

        $menus = [
            [
                'portal_code' => 'yunying',
                'code' => 'ai_product_requests',
                'title_key' => 'ai_products.menus.request',
                'path' => '/ai-product-requests',
                'icon' => 'fa-rocket',
                'sort' => 40,
            ],
            [
                'portal_code' => 'caigou',
                'code' => 'ai_product_reviews',
                'title_key' => 'ai_products.menus.review',
                'path' => '/ai-product-reviews',
                'icon' => 'fa-gavel',
                'sort' => 40,
            ],
        ];

        foreach ($menus as $menu) {
            $portalId = $database->table('portals')->where('code', $menu['portal_code'])->value('id');
            if (!$portalId) {
                continue;
            }

            $timestamp = now();
            $values = [
                'parent_id' => 0,
                'title' => (string) Lang::get($menu['title_key'], [], 'zh-CN'),
                'title_key' => $menu['title_key'],
                'path' => $menu['path'],
                'icon' => $menu['icon'],
                'sort' => $menu['sort'],
                'is_enabled' => true,
                'updated_at' => $timestamp,
            ];

            $existing = $database->table('portal_menus')
                ->where('portal_id', $portalId)
                ->where('code', $menu['code'])
                ->first(['id']);

            if ($existing) {
                $database->table('portal_menus')->where('id', $existing->id)->update($values);
            } else {
                $database->table('portal_menus')->insert([
                    'portal_id' => $portalId,
                    'code' => $menu['code'],
                    ...$values,
                    'created_at' => $timestamp,
                ]);
            }
        }
    }
};
