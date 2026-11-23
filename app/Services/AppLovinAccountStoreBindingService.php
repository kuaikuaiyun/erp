<?php

namespace App\Services;

use App\Models\AppLovinAccountStoreBinding;
use App\Models\AppLovinSpendScreenshot;
use App\Models\AssistantDomainStore;
use App\Models\FashionAdSpendEntry;
use App\Models\OperationReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AppLovinAccountStoreBindingService
{
    public static function normalizeAccountNumber(string $accountNumber): string
    {
        return mb_strtolower(trim($accountNumber));
    }

    public function storeId(string $accountNumber, ?string $legacyAccountKey = null): ?int
    {
        $number = self::normalizeAccountNumber($accountNumber);
        $storeId = $number === '' ? null : AppLovinAccountStoreBinding::query()
            ->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])
            ->latest('updated_at')->value('assistant_domain_store_id');

        if (! $storeId && $legacyAccountKey) {
            $storeId = AppLovinAccountStoreBinding::query()->where('account_key', $legacyAccountKey)->value('assistant_domain_store_id');
        }

        return $storeId ? (int) $storeId : null;
    }

    /** @return array<string,int> */
    public function numberStoreMap(): array
    {
        $map = [];
        FashionAdSpendEntry::query()->whereRaw("LOWER(TRIM(media)) = 'applovin'")
            ->whereNotNull('assistant_domain_store_id')->orderBy('id')
            ->get(['account_number', 'assistant_domain_store_id'])
            ->each(function (FashionAdSpendEntry $entry) use (&$map): void {
                $number = self::normalizeAccountNumber((string) $entry->account_number);
                if ($number !== '') $map[$number] = (int) $entry->assistant_domain_store_id;
            });
        OperationReport::query()->orderBy('id')->get(['advertising_rows'])->each(function (OperationReport $report) use (&$map): void {
            foreach ($report->advertising_rows ?: [] as $row) {
                if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin') continue;
                $number = self::normalizeAccountNumber((string) ($row['account_number'] ?? ''));
                $storeId = (int) ($row['assistant_domain_store_id'] ?? 0);
                if ($number !== '' && $storeId > 0) $map[$number] = $storeId;
            }
        });
        AppLovinAccountStoreBinding::query()->whereNotNull('account_number')->orderBy('updated_at')
            ->get(['account_number', 'assistant_domain_store_id'])
            ->each(function (AppLovinAccountStoreBinding $binding) use (&$map): void {
                $number = self::normalizeAccountNumber((string) $binding->account_number);
                if ($number !== '') $map[$number] = (int) $binding->assistant_domain_store_id;
            });

        return $map;
    }

    public function bind(string $accountNumber, int $storeId, int $boundBy): void
    {
        $number = self::normalizeAccountNumber($accountNumber);
        if ($number === '') return;
        $store = AssistantDomainStore::query()->findOrFail($storeId);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($number, $store, $boundBy): void {
            $canonicalKey = hash('sha256', 'applovin-account-number|'.$number);
            $binding = AppLovinAccountStoreBinding::query()
                ->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])->latest('updated_at')->first();
            if ($binding) {
                $binding->update(['account_number' => $number, 'assistant_domain_store_id' => $store->getKey(), 'bound_by' => $boundBy]);
            } else {
                AppLovinAccountStoreBinding::query()->updateOrCreate(['account_key' => $canonicalKey], [
                    'account_number' => $number, 'assistant_domain_store_id' => $store->getKey(), 'bound_by' => $boundBy,
                ]);
            }
            AppLovinAccountStoreBinding::query()->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])
                ->update(['assistant_domain_store_id' => $store->getKey(), 'bound_by' => $boundBy]);

            FashionAdSpendEntry::query()->whereRaw("LOWER(TRIM(media)) = 'applovin'")
                ->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])
                ->update(['assistant_domain_store_id' => $store->getKey(), 'store_domain' => $store->domain]);

            OperationReport::query()->orderBy('id')->chunkById(100, function (Collection $reports) use ($number, $store, $boundBy): void {
                foreach ($reports as $report) {
                    $rows = $report->advertising_rows ?: [];
                    $changed = false;
                    foreach ($rows as &$row) {
                        if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin'
                            || self::normalizeAccountNumber((string) ($row['account_number'] ?? '')) !== $number) continue;
                        $row['assistant_domain_store_id'] = (int) $store->getKey();
                        $row['store_domain'] = (string) $store->domain;
                        $legacyKey = AppLovinSpendScreenshot::accountKey((int) $report->operator_user_id, (string) ($row['agency'] ?? ''), 'AppLovin', (string) ($row['account_name'] ?? ''), (string) ($row['account_number'] ?? ''));
                        AppLovinAccountStoreBinding::query()->updateOrCreate(['account_key' => $legacyKey], [
                            'account_number' => $number, 'assistant_domain_store_id' => $store->getKey(), 'bound_by' => $boundBy,
                        ]);
                        $changed = true;
                    }
                    unset($row);
                    if ($changed) $report->forceFill(['advertising_rows' => $rows])->save();
                }
            });
        });
    }

    public function unbind(string $accountNumber, ?string $legacyAccountKey = null): void
    {
        $number = self::normalizeAccountNumber($accountNumber);
        if ($number === '') return;
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($number, $legacyAccountKey): void {
            AppLovinAccountStoreBinding::query()
                ->where(function ($query) use ($number, $legacyAccountKey): void {
                    $query->whereRaw('LOWER(TRIM(account_number)) = ?', [$number]);
                    if ($legacyAccountKey) $query->orWhere('account_key', $legacyAccountKey);
                })->delete();
            FashionAdSpendEntry::query()->whereRaw("LOWER(TRIM(media)) = 'applovin'")
                ->whereRaw('LOWER(TRIM(account_number)) = ?', [$number])
                ->update(['assistant_domain_store_id' => null, 'store_domain' => null]);
            OperationReport::query()->orderBy('id')->chunkById(100, function (Collection $reports) use ($number): void {
                foreach ($reports as $report) {
                    $rows = $report->advertising_rows ?: [];
                    $changed = false;
                    foreach ($rows as &$row) {
                        if (mb_strtolower(trim((string) ($row['media_source'] ?? ''))) !== 'applovin'
                            || self::normalizeAccountNumber((string) ($row['account_number'] ?? '')) !== $number) continue;
                        $row['assistant_domain_store_id'] = null;
                        $row['store_domain'] = null;
                        $changed = true;
                    }
                    unset($row);
                    if ($changed) $report->forceFill(['advertising_rows' => $rows])->save();
                }
            });
        });
    }
}
