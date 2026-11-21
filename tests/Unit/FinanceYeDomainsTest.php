<?php

namespace Tests\Unit;

use App\Portals\Finance\FinanceCreditSettlementLayout;
use App\Portals\Finance\FinanceYeDomains;
use PHPUnit\Framework\TestCase;

class FinanceYeDomainsTest extends TestCase
{
    public function test_domain_cleanup_only_removes_the_expected_prefix_and_platform_label(): void
    {
        foreach ([
            'www.trztrain.com' => 'trztrain.com',
            'agenorisa.myshoplaza.com' => 'agenorisa.com',
            ' WWW.AGENORISA.MYSHOPLAZA.COM ' => 'agenorisa.com',
            'trztrain.com' => 'trztrain.com',
            'shop.trztrain.com' => 'shop.trztrain.com',
            'myshoplaza.com' => 'myshoplaza.com',
            'agenorisa.myshoplaza.com.example.org' => 'agenorisa.myshoplaza.com.example.org',
            '' => '',
        ] as $source => $expected) {
            $this->assertSame($expected, FinanceYeDomains::normalize($source));
        }
        $this->assertSame('', FinanceYeDomains::normalize(null));
    }

    public function test_ye_workbook_domains_are_cleaned_from_explicit_values_and_source_pages(): void
    {
        $values = array_fill_keys(array_keys(FinanceCreditSettlementLayout::columns('ye')), '');
        $values['date'] = '';
        foreach ([
            ['www.trztrain.com', '', 'trztrain.com'],
            ['agenorisa.myshoplaza.com', '', 'agenorisa.com'],
            ['', 'https://WWW.AGENORISA.MYSHOPLAZA.COM/checkout?order=1', 'agenorisa.com'],
            ['', 'www.trztrain.com/checkout', 'trztrain.com'],
        ] as [$domain, $source, $expected]) {
            $payload = FinanceCreditSettlementLayout::importPayload('ye', array_replace($values, [
                'domain' => $domain, 'source_page' => $source,
            ]));
            $this->assertSame($expected, $payload['域名']);
            $this->assertSame($source, $payload['来源页面']);
        }
        $payload = FinanceCreditSettlementLayout::importPayload('worldpay', array_replace($values, [
            'domain' => 'www.agenorisa.myshoplaza.com', 'date' => '',
        ]));
        $this->assertSame('www.agenorisa.myshoplaza.com', $payload['域名']);
    }
}
