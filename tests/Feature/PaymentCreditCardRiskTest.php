<?php

namespace Tests\Feature;

use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use App\Portals\Payment\PaymentCreditCardRiskService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentCreditCardRiskTest extends TestCase
{
    public function test_credit_card_summary_and_event_details_use_the_order_payment_type(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('shopify_order_number');
            $table->string('tongtu_order_number')->nullable();
            $table->string('store_name')->nullable();
            $table->string('payment_gateway');
            $table->string('payment_type')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->decimal('paid_amount_usd', 12, 2);
        });
        Schema::create('logistics_credit_card_refunds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('logistics_order_id');
            $table->string('refund_reason');
            $table->decimal('refund_amount_usd', 12, 2);
            $table->dateTime('occurred_at');
        });

        $paidAt = '2026-11-20 12:00:00';
        foreach ([
            [1, 'OtherCardGateway', 'credit_card', $paidAt, 80],
            [2, 'Fintrix', 'paypal', $paidAt, 120],
            [3, 'manual', null, $paidAt, 130],
            [4, 'FintrixV2', 'credit_card', $paidAt, 50],
            [5, 'Fintrix', 'credit_card', null, 0],
            [6, 'OtherCardGateway', 'credit_card', '2026-11-18 12:00:00', 60],
        ] as [$id, $gateway, $type, $paid, $amount]) {
            DB::table('logistics_orders')->insert([
                'id' => $id, 'shopify_order_number' => 'ORDER-'.$id, 'payment_gateway' => $gateway,
                'payment_type' => $type, 'paid_at' => $paid, 'paid_amount_usd' => $amount,
            ]);
        }
        $disputeReason = LogisticsCreditCardRefundReason::DISPUTE[0];
        foreach ([[1, '退款测试', 8], [2, '应排除的退款', 20], [3, '应排除的退款', 30], [4, $disputeReason, 7],
            [5, '应排除的退款', 5], [6, '历史订单退款', 3]] as [$orderId, $reason, $amount]) {
            DB::table('logistics_credit_card_refunds')->insert([
                'logistics_order_id' => $orderId, 'refund_reason' => $reason,
                'refund_amount_usd' => $amount, 'occurred_at' => $paidAt,
            ]);
        }

        $filters = ['period_start' => '2026-11-20', 'period_end' => '2026-11-20', 'page' => 1, 'per_page' => 50];
        $service = app(PaymentCreditCardRiskService::class);
        $order = $service->dashboard([...$filters, 'date_mode' => 'order']);
        self::assertSame(['orders' => 2, 'paid_amount' => 130.0, 'refund_orders' => 1, 'refund_amount' => 8.0,
            'dispute_orders' => 1, 'dispute_amount' => 7.0], $order['summary']);
        self::assertSame(['ORDER-4', 'ORDER-1'], $order['rows']->pluck('order_number')->all());
        self::assertSame(['FintrixV2', 'OtherCardGateway'], $order['rows']->pluck('gateway')->all());

        $event = $service->dashboard([...$filters, 'date_mode' => 'event']);
        self::assertSame(['orders' => 2, 'paid_amount' => 130.0, 'refund_orders' => 2, 'refund_amount' => 11.0,
            'dispute_orders' => 1, 'dispute_amount' => 7.0], $event['summary']);
        self::assertSame(['ORDER-4', 'ORDER-1', 'ORDER-6'], $event['rows']->pluck('order_number')->all());
        self::assertNotContains('应排除的退款', $event['reason_options']);
    }
}
