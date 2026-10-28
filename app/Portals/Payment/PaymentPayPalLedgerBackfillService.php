<?php

namespace App\Portals\Payment;

use App\Models\LogisticsPayPalImport;
use App\Portals\Logistics\PayPalSourceResponseParser;
use Illuminate\Support\Facades\Storage;

class PaymentPayPalLedgerBackfillService
{
    public function __construct(
        private readonly PayPalSourceResponseParser $sourceResponses,
        private readonly PaymentPayPalLedgerImportService $ledger,
    ) {
    }

    /** @return array{imports:int,entries:int,skipped:int,errors:int} */
    public function run(int $limit = 0): array
    {
        $result = ['imports' => 0, 'entries' => 0, 'skipped' => 0, 'errors' => 0];
        $query = LogisticsPayPalImport::query()
            ->with('account')
            ->whereIn('status', ['success', 'partial'])
            ->orderBy('id');

        $processed = 0;
        $query->chunkById(50, function ($imports) use (&$result, &$processed, $limit): bool {
            foreach ($imports as $import) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }
                $processed++;
                if (!$import->account || blank($import->storage_path) || !Storage::disk('local')->exists($import->storage_path)) {
                    $result['skipped']++;
                    continue;
                }

                try {
                    $payload = json_decode(Storage::disk('local')->get($import->storage_path), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($payload)) {
                        $result['skipped']++;
                        continue;
                    }
                    $persisted = $this->ledger->persist(
                        $import->account,
                        $import,
                        $this->sourceResponses->transactions($payload),
                    );
                    $result['imports']++;
                    $result['entries'] += $persisted['count'];
                    $result['errors'] += count($persisted['errors']);
                } catch (\Throwable) {
                    $result['errors']++;
                }
            }

            return true;
        });

        return $result;
    }
}
