<?php

namespace Tests\Feature;

use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogisticsPayPalDisputeAnalysisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');
        Schema::create('logistics_paypal_disputes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('logistics_paypal_account_id');
            $table->unsignedBigInteger('logistics_order_id')->nullable();
            $table->string('dispute_id');
            $table->string('disputed_transaction_id')->nullable();
            $table->boolean('subscription_cancelled')->nullable();
            $table->boolean('add_on_cancelled')->nullable();
            $table->dateTime('first_handled_at')->nullable();
            $table->unsignedInteger('first_handled_by')->nullable();
            $table->string('first_handled_by_name', 100)->nullable();
            $table->timestamps();
        });
        Schema::create('logistics_paypal_dispute_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('logistics_paypal_dispute_id');
            $table->unsignedInteger('operator_id')->nullable();
            $table->string('operator_name', 100)->nullable();
            $table->string('action', 50);
            $table->longText('payload')->nullable();
            $table->timestamps();
        });
    }

    public function test_first_handling_information_is_persisted_once_and_operations_remain_auditable(): void
    {
        $firstHandledAt = CarbonImmutable::parse('2026-11-09 12:00:00');
        $dispute = LogisticsPayPalDispute::query()->create([
            'logistics_paypal_account_id' => 1,
            'dispute_id' => 'PP-DISPUTE-1',
            'subscription_cancelled' => true,
            'first_handled_at' => $firstHandledAt,
            'first_handled_by' => 7,
            'first_handled_by_name' => '测试用户',
        ]);
        LogisticsPayPalDisputeOperation::query()->create([
            'logistics_paypal_dispute_id' => $dispute->id,
            'operator_id' => 7,
            'operator_name' => '测试用户',
            'action' => 'manual_handle',
            'payload' => ['subscription_cancelled' => 'yes', 'note' => '首次处理'],
        ]);

        $dispute->forceFill(['add_on_cancelled' => false])->save();
        $dispute->refresh();

        self::assertTrue($dispute->subscription_cancelled);
        self::assertFalse($dispute->add_on_cancelled);
        self::assertSame('2026-11-09 12:00:00', $dispute->first_handled_at?->format('Y-m-d H:i:s'));
        self::assertSame('测试用户', $dispute->first_handled_by_name);
        self::assertSame('首次处理', $dispute->operations()->sole()->payload['note']);
    }
}
