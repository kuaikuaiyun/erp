<?php

namespace App\Portals\AssetManager;

use App\Models\AssetCodexRenewal;
use App\Models\AssetComputerEquipment;
use App\Models\AssetResource;

final class AssetManagerCatalog
{
    public const MODELS = ['computers' => AssetComputerEquipment::class, 'resources' => AssetResource::class, 'codex-renewals' => AssetCodexRenewal::class];

    public static function fields(string $kind): array
    {
        return match ($kind) {
            'computers' => ['category' => 'text', 'brand' => 'text', 'serial_number' => 'text', 'monthly_rent' => 'money', 'area' => 'text', 'user_id' => 'user', 'office' => 'text', 'project' => 'text', 'notes' => 'textarea'],
            'resources' => ['project' => 'text', 'department_id' => 'department', 'resource_name' => 'text', 'price' => 'money', 'remaining_traffic' => 'text', 'traffic_reset' => 'text', 'server_location' => 'text', 'supplier' => 'text'],
            'codex-renewals' => ['asset_type' => 'text', 'project' => 'text', 'department_id' => 'department', 'user_name' => 'text', 'account' => 'text', 'price' => 'money', 'payment_status' => 'text', 'purchaser' => 'text', 'renewal_date' => 'date', 'notes' => 'textarea'],
            'ipfoxy' => ['proxy_id' => 'text', 'account_label' => 'text', 'host' => 'text', 'port' => 'text', 'protocol' => 'text', 'country' => 'text', 'expires_at' => 'datetime'],default => abort(404)
        };
    }

    public static function labels(string $kind): array
    {
        $overrides = __('asset_manager.field_overrides.'.$kind);

        return array_replace(__('asset_manager.fields'), is_array($overrides) ? $overrides : []);
    }

    public static function model(string $kind): string
    {
        return self::MODELS[$kind] ?? abort(404);
    }

    public static function permission(string $kind): string
    {
        return 'asset-manager.'.$kind;
    }
}
