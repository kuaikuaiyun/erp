<?php

namespace Tests\Feature;

use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalDisputeOperation;
use App\Portals\Logistics\Http\Controllers\PayPalDisputeAnalysisController;
use Illuminate\Http\Request;
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

    public function test_note_filter_matches_only_latest_non_empty_note_and_combines_with_account(): void
    {
        $matching = $this->disputeWithNotes(['旧备注', '给客服发邮件联系', null, '   ']);
        $this->disputeWithNotes(['给客服发邮件联系', '已提交材料申诉']);
        $this->disputeWithNotes([null, '']);
        $this->disputeWithNotes(['给客服发邮件联系'], 2);

        self::assertSame([$matching->id], $this->filteredDisputeIds(['note' => ' 邮件 ', 'account_id' => '1']));
        self::assertCount(4, $this->filteredDisputeIds(['note' => '   ']));
        self::assertSame([], $this->filteredDisputeIds(['note' => '不存在的关键词']));
    }

    public function test_note_filter_treats_wildcards_and_escape_characters_as_literal_text(): void
    {
        $literal = $this->disputeWithNotes(['完成100%_!\\材料']);
        $this->disputeWithNotes(['完成100ABC材料']);
        foreach (['%', '_', '!', '\\', '100%_!\\'] as $keyword) {
            self::assertSame([$literal->id], $this->filteredDisputeIds(['note' => $keyword]));
        }
        $zero = $this->disputeWithNotes(['0', null]);
        self::assertContains($zero->id, $this->filteredDisputeIds(['note' => '0']));
    }

    private function disputeWithNotes(array $notes, int $accountId = 1): LogisticsPayPalDispute
    {
        $dispute = LogisticsPayPalDispute::query()->create([
            'logistics_paypal_account_id' => $accountId,
            'dispute_id' => 'PP-'.(LogisticsPayPalDispute::query()->count() + 1),
        ]);
        foreach ($notes as $note) {
            $dispute->operations()->create(['action' => 'manual_handle', 'payload' => ['note' => $note]]);
        }
        return $dispute;
    }

    private function filteredDisputeIds(array $input): array
    {
        $controller = app(PayPalDisputeAnalysisController::class);
        $filters = new \ReflectionMethod($controller, 'filters');
        $query = new \ReflectionMethod($controller, 'query');
        return $query->invoke($controller, $filters->invoke($controller, Request::create('/', 'GET', $input)))
            ->orderBy('id')->pluck('id')->all();
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
