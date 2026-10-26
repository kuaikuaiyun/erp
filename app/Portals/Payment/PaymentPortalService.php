<?php

namespace App\Portals\Payment;

use App\Models\Portal;

class PaymentPortalService
{
    public const PORTAL_CODE = 'ppay';

    private const PAYMENT_MENU_CODES = [
        'payment_account_risk',
        'payment_product_risk_profit',
        'payment_product_alerts',
        'payment_orders',
    ];

    public function isPaymentPortal(Portal $portal): bool
    {
        if (strtolower(trim((string) $portal->code)) === self::PORTAL_CODE) {
            return true;
        }

        if ($portal->relationLoaded('menus')) {
            return $portal->menus->contains(
                static fn ($menu): bool => in_array($menu->code, self::PAYMENT_MENU_CODES, true),
            );
        }

        return $portal->menus()->whereIn('code', self::PAYMENT_MENU_CODES)->exists();
    }

    public function assertPaymentPortal(Portal $portal): void
    {
        abort_unless($this->isPaymentPortal($portal), 404);

        app()->setLocale('zh-CN');
    }
}
