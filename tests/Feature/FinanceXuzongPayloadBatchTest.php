<?php

namespace Tests\Feature;

use App\Models\FinanceRecord;
use App\Portals\Finance\FinanceLogisticsCreditOrders;
use App\Portals\Finance\FinanceSettlementService;
use App\Portals\Finance\FinanceSourceStorage;
use App\Portals\Finance\FinanceXuzongCreditMatches;
use App\Portals\Finance\FinanceXuzongCreditReport;
use App\Portals\Finance\FinanceXuzongOrderImport;
use Mockery;
use Tests\TestCase;

class FinanceXuzongPayloadBatchTest extends TestCase
{
    public function test_negative_imported_report_ids_load_payloads_once_per_batch_and_preserve_order_links(): void
    {
        $records = collect(); $documents = []; $orders = collect();
        for ($id = 1; $id <= 40; $id++) {
            $records->push((new FinanceRecord)->newFromBuilder(['id' => -$id,
                'dataset' => FinanceLogisticsCreditOrders::IMPORTED_DATASET, 'account' => 'test']));
            $documents[$id] = ['_linked_logistics_id' => 1000 + $id, '交易流水号' => 'REF-'.$id];
            $orders->put(1000 + $id, (object) ['id' => 1000 + $id]);
        }
        $storage = Mockery::mock(FinanceSourceStorage::class);
        $storage->shouldReceive('active')->once()->with('record')->andReturnTrue();
        $storage->shouldReceive('load')->once()->with('record', range(1, 40))->andReturn($documents);
        $this->app->instance(FinanceSourceStorage::class, $storage);
        $settlements = Mockery::mock(FinanceSettlementService::class);
        $settlements->shouldReceive('liveCreditReportOrders')->andReturnUsing(fn ($ids) => $orders->only($ids->all()));
        $imports = Mockery::mock(FinanceXuzongOrderImport::class);
        $imports->shouldNotReceive('matches');
        $report = new FinanceXuzongCreditReport($settlements, Mockery::mock(FinanceXuzongCreditMatches::class), $imports);
        for ($pass = 0; $pass < 2; $pass++) {
            $linked = $report->linkedOrders($records);
            foreach ($records as $record) {
                $this->assertSame(1000 + abs($record->id), $linked->get($record->id)->id);
                $this->assertSame('REF-'.abs($record->id), $record->payload['交易流水号']);
            }
        }
    }
}
