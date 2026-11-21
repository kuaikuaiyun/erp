<?php

namespace App\Portals\Finance;

class FinanceYeDomains
{
    public static function normalize(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('/^www\./', '', $domain);

        return preg_replace('/\.myshoplaza(?=\.com$)/', '', $domain);
    }
}
