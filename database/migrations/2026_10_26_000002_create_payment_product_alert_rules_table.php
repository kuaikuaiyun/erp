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

        if (!$schema->hasTable('payment_product_alert_rules')) {
            $schema->create('payment_product_alert_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->unique();
                $table->string('name', 191);
                $table->string('yellow_condition', 255);
                $table->string('red_condition', 255);
                $table->unsignedInteger('minimum_sample')->default(0);
                $table->string('action_text', 500)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->string('updated_by_name', 191)->nullable();
                $table->timestamps();
            });
        }

        $database = DB::connection($connection);
        $now = now();
        foreach ([
            ['code' => 'refund_amount', 'name' => '退款金额比', 'yellow_condition' => '≥3% 且 <5%', 'red_condition' => '≥5%', 'minimum_sample' => 30, 'action_text' => '31单起进入比例判断'],
            ['code' => 'full_refund', 'name' => '全额纠纷交易率', 'yellow_condition' => '≥0.5% 且 <1%', 'red_condition' => '≥1%', 'minimum_sample' => 30, 'action_text' => '分组去重订单，比例使用付款交易作分母'],
            ['code' => 'same_period', 'name' => '同原因短期集中', 'yellow_condition' => '7天3笔独立付款', 'red_condition' => '人工复核后升级', 'minimum_sample' => 3, 'action_text' => '避免一笔多事件误报'],
            ['code' => 'cancel_charge', 'name' => '取消生效后扣款', 'yellow_condition' => '单笔即核查', 'red_condition' => '确认异常后升级', 'minimum_sample' => 1, 'action_text' => '核对时区及取消规则'],
            ['code' => 'profit', 'name' => '预计贡献利润', 'yellow_condition' => '下行需复查', 'red_condition' => '基准估计为负', 'minimum_sample' => 30, 'action_text' => '成本未完善则标注数据缺失'],
            ['code' => 'missing_data', 'name' => '数据覆盖不足', 'yellow_condition' => '数据待核查', 'red_condition' => '不直接判定红灯', 'minimum_sample' => 0, 'action_text' => '未采集完整前不作健康结论'],
        ] as $rule) {
            $database->table('payment_product_alert_rules')->updateOrInsert(
                ['code' => $rule['code']],
                [...$rule, 'is_enabled' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->dropIfExists('payment_product_alert_rules');
    }
};
