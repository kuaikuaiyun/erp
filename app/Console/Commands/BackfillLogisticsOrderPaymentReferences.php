<?php

namespace App\Console\Commands;

use App\Models\LogisticsOrder;
use App\Portals\Logistics\LogisticsOrderPaymentReferenceService;
use App\Portals\Logistics\PayPalOrderAssociationService;
use Illuminate\Console\Command;

class BackfillLogisticsOrderPaymentReferences extends Command
{
    protected $signature = 'logistics:payment-references:backfill {--limit=0 : Maximum orders to process; 0 processes all}';

    protected $description = 'Backfill Shopify primary and upsell payment transaction references from stored order payloads';

    public function handle(
        LogisticsOrderPaymentReferenceService $references,
        PayPalOrderAssociationService $paypalAssociations,
    ): int {
        $limit = max(0, (int) $this->option('limit'));
        $query = LogisticsOrder::query()->select(['id', 'bill_number', 'payment_gateway_order_id', 'shopify_payload']);

        $processed = 0;
        $referencesStored = 0;
        $paypalMatched = 0;

        $query->orderBy('id')->chunkById(200, function ($orders) use ($references, $paypalAssociations, $limit, &$processed, &$referencesStored, &$paypalMatched): bool {
            if ($limit > 0 && $processed >= $limit) {
                return false;
            }

            foreach ($orders as $order) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $payload = json_decode((string) $order->shopify_payload, true);
                $referencesStored += $references->syncFromShopifyPayload($order, is_array($payload) ? $payload : []);
                $paypalMatched += $paypalAssociations->reconcileOrder($order);
                $processed++;
            }

            return true;
        });

        $this->info("Processed {$processed} order(s); stored {$referencesStored} reference value(s); reconciled {$paypalMatched} PayPal transaction(s).");

        return self::SUCCESS;
    }
}
