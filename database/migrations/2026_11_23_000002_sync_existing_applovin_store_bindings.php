<?php

use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\FashionAdvertisingReport;
use App\Models\FashionAdSpendEntry;
use App\Models\OperationReport;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $bindings = AppLovinAccountStoreBinding::query()->orderBy('updated_at')->get()->keyBy('account_key');
        if ($bindings->isEmpty()) return;
        $resolved = [];

        OperationReport::query()->orderBy('id')->chunkById(100, function ($reports) use ($bindings, &$resolved): void {
            foreach ($reports as $report) foreach ($report->advertising_rows ?: [] as $row) {
                if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin') continue;
                $number = mb_strtolower(trim((string) ($row['account_number'] ?? '')));
                if ($number === '') continue;
                $key = AppLovinSpendScreenshot::accountKey((int) $report->operator_user_id, (string) ($row['agency'] ?? ''), 'AppLovin', (string) ($row['account_name'] ?? ''), (string) ($row['account_number'] ?? ''));
                $binding = $bindings->get($key);
                if (! $binding) continue;
                $binding->update(['account_number' => $number]);
                $resolved[$number] = ['store_id' => (int) $binding->assistant_domain_store_id, 'bound_by' => (int) $binding->bound_by];
            }
        });
        FashionAdvertisingReport::query()->with('adSpendEntries')->orderBy('id')->chunkById(100, function ($reports) use ($bindings, &$resolved): void {
            foreach ($reports as $report) foreach ($report->adSpendEntries as $entry) {
                if (mb_strtolower(trim((string) $entry->media)) !== 'applovin') continue;
                $number = mb_strtolower(trim((string) $entry->account_number));
                if ($number === '') continue;
                $key = AppLovinSpendScreenshot::accountKey((int) $report->submitter_id, (string) $entry->agency, 'AppLovin', (string) $entry->account_name, (string) $entry->account_number);
                $binding = $bindings->get($key);
                if (! $binding) continue;
                $binding->update(['account_number' => $number]);
                $resolved[$number] = ['store_id' => (int) $binding->assistant_domain_store_id, 'bound_by' => (int) $binding->bound_by];
            }
        });
        if ($resolved === []) return;
        $stores = AssistantDomainStore::query()->whereIn('id', collect($resolved)->pluck('store_id')->unique())->get(['id', 'domain'])->keyBy('id');

        foreach ($resolved as $number => $binding) {
            $store = $stores->get($binding['store_id']);
            if (! $store) continue;
            AppLovinAccountStoreBinding::query()->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])
                ->update(['assistant_domain_store_id' => $store->getKey(), 'bound_by' => $binding['bound_by']]);
            FashionAdSpendEntry::query()->whereRaw("LOWER(TRIM(media)) = 'applovin'")
                ->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])
                ->update(['assistant_domain_store_id' => $store->getKey(), 'store_domain' => $store->domain]);
        }
        OperationReport::query()->orderBy('id')->chunkById(100, function ($reports) use ($resolved, $stores): void {
            foreach ($reports as $report) {
                $rows = $report->advertising_rows ?: [];
                $changed = false;
                foreach ($rows as &$row) {
                    if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin') continue;
                    $number = mb_strtolower(trim((string) ($row['account_number'] ?? '')));
                    $binding = $resolved[$number] ?? null;
                    $store = $binding ? $stores->get($binding['store_id']) : null;
                    if (! $store) continue;
                    $row['assistant_domain_store_id'] = (int) $store->getKey();
                    $row['store_domain'] = (string) $store->domain;
                    $changed = true;
                }
                unset($row);
                if ($changed) $report->forceFill(['advertising_rows' => $rows])->save();
            }
        });
    }

    public function down(): void {}
};
