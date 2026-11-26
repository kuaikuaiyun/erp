<?php

namespace App\Console\Commands;

use App\Portals\AliExpress\OrderMatchService;
use Illuminate\Console\Command;

class MatchAliExpressProcurementOrders extends Command
{
    protected $signature = 'aliexpress:match-orders';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('aliexpress.messages.match_description', [], 'zh-CN'));
    }

    public function handle(OrderMatchService $matcher): int
    {
        app()->setLocale('zh-CN');
        $count = $matcher->match();
        $this->info($count === null
            ? __('aliexpress.messages.match_unavailable')
            : __('aliexpress.messages.match_complete', ['count' => $count]));

        return self::SUCCESS;
    }
}
