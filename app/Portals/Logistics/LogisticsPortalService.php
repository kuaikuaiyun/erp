<?php

namespace App\Portals\Logistics;

use App\Models\Portal;

class LogisticsPortalService
{
    public const PORTAL_CODE = 'wuliu';

    public function assertLogisticsPortal(Portal $portal): void
    {
        abort_unless($portal->code === self::PORTAL_CODE, 404);

        // 物流门户当前仅维护中文语言包，避免受主系统默认 en 语言影响而显示翻译键。
        app()->setLocale('zh-CN');
    }
}
