<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPERATION_PORTAL = 'yunying';

    private const PROCUREMENT_PORTAL = 'caigou';

    private const WAREHOUSE_PORTAL = 'cangku';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('product_requests')) {
            $schema->create('product_requests', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('report_no', 40)->unique();
                $table->date('reported_at')->index();
                $table->string('status', 30)->default('pending')->index();
                $table->string('placement_review_status', 30)->default('unfilled')->index();

                $table->unsignedInteger('operation_user_id')->index();
                $table->string('operation_user_name', 100);
                $table->unsignedInteger('operation_department_id')->nullable()->index();
                $table->unsignedInteger('operation_root_department_id')->nullable()->index();
                $table->string('operation_department_name', 100)->nullable();
                $table->string('operation_display_name', 201);
                $table->string('city', 100);
                $table->string('reference_page_url', 2048)->nullable();
                $table->string('minimum_sale_unit', 255);
                $table->string('has_multiple_attributes', 255);
                $table->string('placement_link', 2048)->nullable();

                $table->string('standard_chinese_name', 191)->nullable()->index();
                $table->string('supplier_url', 2048)->nullable();
                $table->string('supplier_url_normalized', 2048)->nullable();
                $table->string('supplier_url_hash', 64)->nullable()->index();
                $table->string('company_spu', 191)->nullable()->index();
                $table->string('sku_sequence', 191)->nullable();
                $table->string('standard_sku', 191)->nullable();
                $table->string('stock_status', 10)->nullable();
                $table->string('minimum_order_quantity', 255)->nullable();
                $table->string('minimum_purchase_price_rmb', 100)->nullable();
                $table->string('estimated_shipping_weight_g', 100)->nullable();
                $table->string('erp_binding_status', 20)->nullable();
                $table->unsignedInteger('erp_operator_id')->nullable()->index();
                $table->string('erp_operator_name', 100)->nullable();
                $table->string('erp_operator_department_name', 100)->nullable();
                $table->string('erp_operator_display_name', 201)->nullable();
                $table->timestamp('inspected_at')->nullable();
                $table->unsignedBigInteger('reused_from_product_request_id')->nullable()->index();

                $table->string('actual_shipping_weight_g', 100)->nullable();
                $table->string('package_length_cm', 100)->nullable();
                $table->string('package_width_cm', 100)->nullable();
                $table->string('package_height_cm', 100)->nullable();
                $table->string('warehouse_acceptance_result', 30)->default('pending_review')->index();
                $table->string('warehouse_confirmation', 30)->nullable()->index();
                $table->text('warehouse_remark')->nullable();
                $table->unsignedInteger('warehouse_operator_id')->nullable()->index();
                $table->string('warehouse_operator_name', 100)->nullable();
                $table->string('warehouse_operator_department_name', 100)->nullable();
                $table->string('warehouse_operator_display_name', 201)->nullable();
                $table->timestamp('warehouse_processed_at')->nullable();
                $table->timestamps();

                $table->index(['operation_root_department_id', 'status'], 'product_request_root_status_index');
                $table->index(['operation_user_id', 'status'], 'product_request_user_status_index');
            });
        }

        if (!$schema->hasTable('product_request_attachments')) {
            $schema->create('product_request_attachments', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_request_id')->index();
                $table->string('stage', 30)->index();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->string('disk', 50)->default('local');
                $table->string('path', 500);
                $table->string('original_name', 255);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->unsignedInteger('uploaded_by')->nullable()->index();
                $table->string('uploaded_by_name', 100)->nullable();
                $table->timestamps();

                $table->index(['product_request_id', 'stage', 'sort'], 'product_request_attachment_stage_index');
            });
        }

        if (!$schema->hasTable('product_request_placement_reviews')) {
            $schema->create('product_request_placement_reviews', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_request_id')->index();
                $table->unsignedInteger('submission_user_id')->nullable()->index();
                $table->string('submission_user_name', 100)->nullable();
                $table->string('placement_link', 2048);
                $table->string('status', 30)->default('pending')->index();
                $table->text('remark')->nullable();
                $table->unsignedInteger('reviewer_id')->nullable()->index();
                $table->string('reviewer_name', 100)->nullable();
                $table->string('reviewer_department_name', 100)->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['product_request_id', 'status'], 'product_request_review_status_index');
            });
        }

        if (!$schema->hasTable('product_request_logs')) {
            $schema->create('product_request_logs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_request_id')->index();
                $table->string('event', 80)->index();
                $table->text('payload')->nullable();
                $table->unsignedInteger('actor_id')->nullable()->index();
                $table->string('actor_name', 100)->nullable();
                $table->string('actor_department_name', 100)->nullable();
                $table->timestamps();

                $table->index(['product_request_id', 'created_at'], 'product_request_log_timeline_index');
            });
        }

        $this->installMenus($connection);
    }

    public function down(): void
    {
        // Business records and their audit trail are intentionally retained.
    }

    private function installMenus(?string $connection): void
    {
        $schema = Schema::connection($connection);
        if (!$schema->hasTable('portals') || !$schema->hasTable('portal_menus')) {
            return;
        }

        $menus = [
            self::OPERATION_PORTAL => [[
                'code' => 'product_requests',
                'title_key' => 'product_requests.menus.operation_requests',
                'path' => '/product-requests',
                'icon' => 'fa-file-text-o',
                'sort' => 10,
            ]],
            self::PROCUREMENT_PORTAL => [
                [
                    'code' => 'procurement_inspections',
                    'title_key' => 'product_requests.menus.procurement_inspections',
                    'path' => '/procurement-inspections',
                    'icon' => 'fa-check-square-o',
                    'sort' => 10,
                ],
                [
                    'code' => 'placement_reviews',
                    'title_key' => 'product_requests.menus.placement_reviews',
                    'path' => '/placement-reviews',
                    'icon' => 'fa-external-link-square',
                    'sort' => 20,
                ],
            ],
            self::WAREHOUSE_PORTAL => [[
                'code' => 'warehouse_acceptances',
                'title_key' => 'product_requests.menus.warehouse_acceptances',
                'path' => '/warehouse-acceptances',
                'icon' => 'fa-cubes',
                'sort' => 10,
            ]],
        ];

        $database = DB::connection($connection);
        $timestamp = now();

        foreach ($menus as $portalCode => $items) {
            $portalId = $database->table('portals')->where('code', $portalCode)->value('id');
            if (!$portalId) {
                continue;
            }

            foreach ($items as $item) {
                $values = [
                    'parent_id' => 0,
                    'title' => (string) Lang::get($item['title_key'], [], 'zh-CN'),
                    'title_key' => $item['title_key'],
                    'path' => $item['path'],
                    'icon' => $item['icon'],
                    'sort' => $item['sort'],
                    'is_enabled' => true,
                    'updated_at' => $timestamp,
                ];

                $existing = $database->table('portal_menus')
                    ->where('portal_id', $portalId)
                    ->where('code', $item['code'])
                    ->first(['id']);

                if ($existing) {
                    $database->table('portal_menus')->where('id', $existing->id)->update($values);
                } else {
                    $database->table('portal_menus')->insert([
                        'portal_id' => $portalId,
                        'code' => $item['code'],
                        ...$values,
                        'created_at' => $timestamp,
                    ]);
                }
            }
        }
    }
};
