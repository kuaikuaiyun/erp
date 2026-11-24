<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $mysql = in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        foreach (['shop_order_list', 'shop_order_items', 'shop_order_payloads', 'shop_order_source_map'] as $name) {
            if ($schema->hasTable($name)) {
                throw new RuntimeException("Cannot create common order tables: {$name} already exists.");
            }
        }

        $schema->create('shop_order_list', function (Blueprint $table) use ($mysql): void {
            $this->configureTable($table, $mysql, '多平台公共订单主表');

            $table->id()->comment('公共订单内部主键');
            $platform = $table->string('source_platform', 32)->comment('规范化后的来源平台');
            $domain = $table->string('source_domain', 255)->comment('规范化后的固定采集域名');
            $externalId = $table->string('external_order_id', 100)->comment('平台订单 ID');
            if ($mysql) {
                $platform->charset('ascii')->collation('ascii_bin');
                $domain->charset('ascii')->collation('ascii_bin');
                $externalId->collation('utf8mb4_bin');
            }
            $table->string('order_number', 100)->nullable()->comment('平台展示订单号');
            $table->string('source_system', 32)->comment('原业务来源，如 logistics、fashion_logistics');
            $table->unsignedBigInteger('assistant_domain_store_id')->nullable()->comment('关联的 ERP 店铺档案 ID');
            $table->string('store_name', 191)->nullable()->comment('订单记录中的店铺名称');
            $table->string('buyer_external_id', 100)->nullable()->comment('来源平台的买家 ID');
            $table->string('buyer_name', 191)->nullable()->comment('买家姓名');
            $table->string('buyer_email', 191)->nullable()->comment('买家邮箱');
            $table->string('buyer_phone', 100)->nullable()->comment('买家电话');
            $table->string('shipping_country', 100)->nullable()->comment('收货国家或地区');
            $table->string('order_status', 100)->nullable()->comment('原平台订单状态');
            $table->string('payment_status', 100)->nullable()->comment('原平台付款状态');
            $table->string('fulfillment_status', 100)->nullable()->comment('平台履约状态');
            $table->string('logistics_status', 50)->nullable()->comment('承运商运输状态');
            $table->string('subscription_type', 100)->nullable()->comment('订阅类型');
            $table->string('subscription_status', 100)->nullable()->comment('订阅状态');
            $table->string('subscription_origin_order_id', 100)->nullable()->comment('平台订阅原单 ID');
            $table->string('is_add_on', 30)->nullable()->comment('来源记录中的加购标记');
            $table->string('payment_method', 191)->nullable()->comment('付款方式');
            $table->string('payment_method_title', 191)->nullable()->comment('付款方式显示名称');
            $table->string('payment_gateway', 191)->nullable()->comment('支付网关或渠道');
            $table->string('transaction_id', 255)->nullable()->comment('支付交易号');

            $table->string('currency', 20)->nullable()->comment('平台订单金额币种');
            $table->decimal('total_amount', 18, 4)->nullable()->comment('平台订单总额，按 currency 解读');
            $table->decimal('shipping_amount', 18, 4)->nullable()->comment('平台订单原币运费');
            $table->decimal('discount_amount', 18, 4)->nullable()->comment('平台订单原币折扣');
            $table->decimal('tax_amount', 18, 4)->nullable()->comment('平台订单原币税额');
            $table->decimal('product_cost_usd', 18, 4)->nullable()->comment('商品成本，沿用旧表美元口径');
            $table->decimal('handling_fee_usd', 18, 4)->nullable()->comment('处理费，沿用旧表美元口径');
            $table->decimal('total_shipping_usd', 18, 4)->nullable()->comment('运费合计，沿用旧表美元口径');
            $table->decimal('proportional_profit_usd', 18, 4)->nullable()->comment('分摊口径利润金额');
            $table->decimal('proportional_profit_rate', 18, 4)->nullable()->comment('分摊口径利润率，沿用旧表数值单位');
            $table->decimal('financial_profit_usd', 18, 4)->nullable()->comment('财务口径利润金额');
            $table->decimal('financial_profit_rate', 18, 4)->nullable()->comment('财务口径利润率，沿用旧表数值单位');
            $table->decimal('operation_profit_usd', 18, 4)->nullable()->comment('运营口径利润金额');
            $table->decimal('operation_profit_rate', 18, 4)->nullable()->comment('运营口径利润率，沿用旧表数值单位');

            $table->dateTime('order_created_at')->nullable()->comment('客户下单时间，北京时间');
            $table->dateTime('paid_at')->nullable()->comment('客户付款时间，北京时间');
            $table->dateTime('order_modified_at')->nullable()->comment('平台最后修改订单的时间，北京时间');
            $table->dateTime('completed_at')->nullable()->comment('订单完成时间，北京时间');
            $table->dateTime('source_synced_at')->nullable()->comment('最近一次同步平台订单的时间，北京时间');
            $table->dateTime('created_at')->nullable()->comment('ERP 首次创建订单记录的时间');
            $table->dateTime('updated_at')->nullable()->comment('ERP 最近更新订单记录的时间');

            $table->unique(['source_platform', 'source_domain', 'external_order_id'], 'uq_sol_source_order');
            $table->index(['source_platform', 'order_created_at', 'id'], 'idx_sol_platform_created');
            $table->index(['assistant_domain_store_id', 'order_created_at', 'id'], 'idx_sol_store_created');
            $table->index('transaction_id', 'idx_sol_transaction');
        });

        $schema->create('shop_order_items', function (Blueprint $table) use ($mysql): void {
            $this->configureTable($table, $mysql, '公共订单商品明细');

            $table->id()->comment('订单商品行内部主键');
            $table->unsignedBigInteger('order_id')->comment('公共订单 ID');
            $lineKey = $table->string('line_key', 191)->comment('同一订单内稳定的商品行标识');
            if ($mysql) {
                $lineKey->collation('utf8mb4_bin');
            }
            $table->string('external_item_id', 255)->nullable()->comment('平台订单商品行 ID');
            $table->string('external_product_id', 255)->nullable()->comment('平台商品 ID');
            $table->string('external_variant_id', 255)->nullable()->comment('平台商品规格 ID');
            $table->string('product_name', 500)->nullable()->comment('成交时的商品名称');
            $table->string('variant_name', 255)->nullable()->comment('成交时的商品规格名称');
            $table->string('sku', 255)->nullable()->comment('来源平台 SKU');
            $table->decimal('quantity', 18, 4)->nullable()->comment('商品数量');
            $table->text('image_url')->nullable()->comment('商品图片地址');

            $table->decimal('subtotal', 18, 4)->nullable()->comment('平台商品行小计，按订单币种解读');
            $table->decimal('total_amount', 18, 4)->nullable()->comment('平台商品行总额，按订单币种解读');
            $table->decimal('tax_amount', 18, 4)->nullable()->comment('平台商品行税额，按订单币种解读');

            $table->unsignedBigInteger('tongtu_sku_mapping_id')->nullable()->comment('通途 SKU 映射记录 ID');
            $table->string('tongtu_product_id', 191)->nullable()->comment('通途商品 ID');
            $table->string('tongtu_goods_detail_id', 191)->nullable()->comment('通途商品明细 ID');
            $table->string('tongtu_sku', 191)->nullable()->comment('通途 SKU');
            $table->string('tongtu_product_code', 191)->nullable()->comment('通途商品编码');
            $table->string('tongtu_product_name', 255)->nullable()->comment('通途商品名称');
            $table->string('tongtu_product_type', 16)->nullable()->comment('通途商品类型');
            $table->decimal('tongtu_goods_weight_gram', 18, 4)->nullable()->comment('通途商品重量，克');
            $table->decimal('tongtu_package_weight_gram', 18, 4)->nullable()->comment('通途包裹重量，克');
            $table->decimal('sale_weight_gram', 18, 4)->nullable()->comment('本商品行销售重量，克');

            $table->decimal('unit_price_usd', 18, 4)->nullable()->comment('商品行单价，沿用旧表美元口径');
            $table->decimal('line_revenue_usd', 18, 4)->nullable()->comment('商品行收入，沿用旧表美元口径');
            $table->decimal('product_cost_usd', 18, 4)->nullable()->comment('商品行成本，沿用旧表美元口径');
            $table->decimal('handling_fee_usd', 18, 4)->nullable()->comment('商品行处理费，沿用旧表美元口径');
            $table->decimal('total_shipping_usd', 18, 4)->nullable()->comment('商品行运费，沿用旧表美元口径');
            $table->decimal('proportional_refund_usd', 18, 4)->nullable()->comment('商品行分摊退款，沿用旧表美元口径');
            $table->decimal('profit_usd', 18, 4)->nullable()->comment('商品行利润，沿用旧表美元口径');
            $table->decimal('profit_rate', 18, 4)->nullable()->comment('商品行利润率，沿用旧表数值单位');
            $table->dateTime('created_at')->nullable()->comment('ERP 创建商品行记录的时间');
            $table->dateTime('updated_at')->nullable()->comment('ERP 最近更新商品行记录的时间');

            $table->unique(['order_id', 'line_key'], 'uq_soi_order_line');
            $table->index('sku', 'idx_soi_sku');
            $table->foreign('order_id', 'fk_soi_order')->references('id')->on('shop_order_list')->restrictOnDelete();
        });

        $schema->create('shop_order_payloads', function (Blueprint $table) use ($mysql): void {
            $this->configureTable($table, $mysql, '公共订单原文及详情，每笔订单每种类型存一份');

            $table->unsignedBigInteger('order_id')->comment('公共订单 ID');
            $type = $table->string('payload_type', 40)->comment('报文或详情类型');
            if ($mysql) {
                $type->charset('ascii')->collation('ascii_bin');
            }
            $table->longText('payload')->comment('JSON 文本，item_metadata 按商品行 line_key 存储');
            $table->dateTime('created_at')->nullable()->comment('ERP 首次保存这份报文的时间');
            $table->dateTime('updated_at')->nullable()->comment('ERP 最近更新这份报文的时间');

            $table->primary(['order_id', 'payload_type']);
            $table->foreign('order_id', 'fk_sop_order')->references('id')->on('shop_order_list')->restrictOnDelete();
        });

        $schema->create('shop_order_source_map', function (Blueprint $table) use ($mysql): void {
            $this->configureTable($table, $mysql, '旧订单到公共订单的主键映射');

            $sourceTable = $table->string('source_table', 64)->comment('旧订单表名');
            if ($mysql) {
                $sourceTable->charset('ascii')->collation('ascii_bin');
            }
            $table->unsignedBigInteger('source_id')->comment('旧订单表主键');
            $table->unsignedBigInteger('order_id')->comment('对应的公共订单 ID');
            $table->dateTime('created_at')->nullable()->comment('ERP 建立映射的时间');

            $table->primary(['source_table', 'source_id']);
            $table->index('order_id', 'idx_sosm_order');
            $table->foreign('order_id', 'fk_sosm_order')->references('id')->on('shop_order_list')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('shop_order_source_map');
        $schema->dropIfExists('shop_order_payloads');
        $schema->dropIfExists('shop_order_items');
        $schema->dropIfExists('shop_order_list');
    }

    private function configureTable(Blueprint $table, bool $mysql, string $description): void
    {
        if ($mysql) {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
        }

        $table->comment($description);
    }
};
