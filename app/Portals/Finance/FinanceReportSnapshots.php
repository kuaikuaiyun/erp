<?php

namespace App\Portals\Finance;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceReportSnapshots
{
    public function __construct(private readonly FinanceWorkbookService $workbooks) {}

    public function refreshMonth(string $month, \App\Portals\Finance\Http\Controllers\FinanceController $controller): void
    {
        $db = $this->workbooks->database();
        if (! $db->getSchemaBuilder()->hasTable('finance_report_scopes')) {
            return;
        }

        $start = \Carbon\CarbonImmutable::parse($month.'-01', 'Asia/Shanghai');
        foreach ($db->table('finance_report_scopes')->where('month', $month)
            ->where('used_at', '>=', now()->subDays(7))->cursor() as $scope) {
            $filters = json_decode($scope->filters, true, 512, JSON_THROW_ON_ERROR);
            $this->result($scope->report, $month, $filters, function () use ($controller, $scope, $month, $filters, $start) {
                if ($scope->report === 'transactions') {
                    $account = isset($filters['payment_account_id'])
                        ? \App\Models\FinancePaymentAccount::find($filters['payment_account_id']) : null;

                    return $controller->buildTransactionReport($filters['channel'], $filters, $start, $account);
                }

                return $controller->buildSettlementOverviewMonth($month, $scope->report, $filters, $filters['_include_options'], [], reuse: true);
            }, rememberScope: false);
        }
        $db->table('finance_report_snapshots')->where('created_at', '<', now()->subDays(7))->delete();
        $db->table('finance_report_scopes')->where('used_at', '<', now()->subDays(7))->delete();
    }

    public function result(string $report, string $month, array $filters, callable $build, ?string $id = null, bool $rememberScope = true): array
    {
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        if (isset($filters['payment_account_id'])) {
            $filters['payment_account_id'] = (int) $filters['payment_account_id'];
        }
        ksort($filters);
        $scope = hash('sha256', json_encode([$report, $month, $filters], JSON_THROW_ON_ERROR));
        $db = $this->workbooks->database();
        if (! $db->getSchemaBuilder()->hasTable('finance_report_snapshots')) {
            return [$build(), null];
        }

        if ($id !== null) {
            $saved = $db->table('finance_report_snapshots')->where(compact('id', 'scope'))->first();
            if (! $saved) {
                throw ValidationException::withMessages(['report_snapshot' => '报表版本已过期或筛选已变更，请重新查询后导出。']);
            }

            return [json_decode($saved->payload, true, 512, JSON_THROW_ON_ERROR), $id];
        }

        $mysql = FinanceSummaryTriggers::available($db);
        $revision = null;
        if ($mysql && ! $db->table('finance_summary_changes')->exists()) {
            $channel = $filters['channel'] ?? null;
            $periods = $db->table('finance_summary_periods')->where('month', $month)
                ->when($channel === 'paypal', fn ($query) => $query->where('channel', 'paypal'))
                ->when(filled($channel) && ! in_array($channel, ['paypal', 'combined'], true), fn ($query) => $query->where('channel', 'credit_card'))
                ->orderBy('channel')->get(['channel', 'revision']);
            $rate = $db->table('finance_monthly_exchange_rates')->where(['month' => $month, 'currency' => 'USD'])->value('cny_rate');
            $revision = hash('sha256', json_encode([$report === 'transactions' ? 5 : ($report === 'losses' ? 4 : 3), $periods, $rate], JSON_THROW_ON_ERROR));
            $saved = $db->table('finance_report_snapshots')->where(compact('scope', 'revision'))->latest('created_at')->first();
            if ($rememberScope) {
                $db->table('finance_report_scopes')->upsert([
                    compact('scope', 'month', 'report') + ['filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'used_at' => now()],
                ], ['scope'], ['used_at']);
            }
            if ($saved) {
                return [json_decode($saved->payload, true, 512, JSON_THROW_ON_ERROR), $saved->id];
            }
        }

        $result = $db->transaction($build);
        $id = (string) Str::uuid();
        $db->table('finance_report_snapshots')->insert(compact('id', 'scope') + [
            'revision' => $revision ?? hash('sha256', $id),
            'payload' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        return [$result, $id];
    }
}
