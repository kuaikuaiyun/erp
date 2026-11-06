<?php

namespace App\Portals\FashionLogistics;

use App\Models\Portal;

class FashionLogisticsPortalService
{
    public const PORTAL_CODE = 'fuzhuangwuliu';

    public function assertPortal(Portal $portal): void
    {
        abort_unless($portal->code === self::PORTAL_CODE, 404);
        app()->setLocale('zh-CN');
    }
}
