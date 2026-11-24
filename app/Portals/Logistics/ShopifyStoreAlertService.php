<?php

namespace App\Portals\Logistics;

use App\Models\AssistantDomainStore;
use App\Models\LogisticsShopifyConnection;

class ShopifyStoreAlertService
{
    /** @return array{not_registered:int, abnormal:int} */
    public function counts(): array
    {
        $stores = AssistantDomainStore::query()
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE);

        // Keep the same "not registered" definition as the store list filter.
        $notRegistered = (clone $stores)->where(function ($query): void {
            $query->whereDoesntHave('shopifyConnection')
                ->orWhereHas('shopifyConnection', function ($connection): void {
                    $connection->whereNull('webhook_status')->orWhereNotIn('webhook_status', ['active', 'failed']);
                });
        })->count();
        $abnormal = (clone $stores)->whereHas('shopifyConnection', function ($connection): void {
            $connection->where(function ($failures): void {
                $failures->where('status', LogisticsShopifyConnection::STATUS_FAILED)
                    ->orWhere('webhook_status', 'failed')
                    ->orWhere('last_sync_status', 'failed');
            });
        })->count();

        return ['not_registered' => $notRegistered, 'abnormal' => $abnormal];
    }
}
