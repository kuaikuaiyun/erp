<?php

namespace App\Portals\Logistics;

use Symfony\Component\HttpFoundation\StreamedResponse;

class LogisticsProductStatisticsSpreadsheetService
{
    private const TYPE_TEXT = 'text';
    private const TYPE_MONEY = 'money';
    private const TYPE_RATE = 'rate';

    public function __construct(private readonly StreamedXlsxWriter $streamedWriter)
    {
    }

    /** @return list<array{0:string,1:string,2:string,3:float|int}> */
    private static function columns(): array
    {
        return [
            [__('logistics.product_statistics.headers.product'), 'name', self::TYPE_TEXT, 30],
            ['SKU', 'sku', self::TYPE_TEXT, 28],
            [__('logistics.product_statistics.headers.stores'), 'stores', self::TYPE_TEXT, 24],
            [__('logistics.product_statistics.headers.sales'), 'sales', self::TYPE_TEXT, 15],
            [__('logistics.product_statistics.headers.revenue'), 'revenue', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.cost'), 'cost', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.fees'), 'fees', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.paypal_refund_amount'), 'paypal_refund_amount', self::TYPE_MONEY, 20],
            [__('logistics.product_statistics.headers.paypal_refund_rate'), 'paypal_refund_rate', self::TYPE_RATE, 18],
            [__('logistics.product_statistics.headers.paypal_dispute_count'), 'paypal_dispute_count', self::TYPE_TEXT, 19],
            [__('logistics.product_statistics.headers.paypal_dispute_rate'), 'paypal_dispute_rate', self::TYPE_RATE, 18],
            [__('logistics.product_statistics.headers.credit_card_refund_amount'), 'credit_card_refund_amount', self::TYPE_MONEY, 21],
            [__('logistics.product_statistics.headers.credit_card_refund_rate'), 'credit_card_refund_rate', self::TYPE_RATE, 19],
            [__('logistics.product_statistics.headers.combined_refund_rate'), 'combined_refund_rate', self::TYPE_RATE, 18],
            [__('logistics.product_statistics.headers.profit'), 'actual_profit', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.profit_rate'), 'profit_rate', self::TYPE_RATE, 15],
            [__('logistics.product_statistics.headers.decision'), 'decision', self::TYPE_TEXT, 16],
        ];
    }

    /** @param iterable<array<string,mixed>> $products */
    public function exportResponse(iterable $products): StreamedResponse
    {
        $columns = self::columns();

        return $this->streamedWriter->response(
            __('logistics.product_statistics.title').'_'.now('Asia/Shanghai')->format('Ymd-His').'.xlsx',
            __('logistics.product_statistics.title'),
            array_map(static fn (array $column): array => ['label' => $column[0], 'width' => $column[3]], $columns),
            function () use ($products, $columns): iterable {
                foreach ($products as $product) {
                    yield $this->excelRow($this->productRow($product), $columns);
                }
            },
            false,
        );
    }

    /**
     * @param array<string,mixed> $values
     * @param list<array{0:string,1:string,2:string,3:float|int}> $columns
     * @return array{kind:string,height:int,outline:int,cells:list<array{value:mixed,type:string,align:string,wrap:bool}>}
     */
    private function excelRow(array $values, array $columns): array
    {
        return [
            'kind' => 'parent',
            'height' => 42,
            'outline' => 0,
            'cells' => array_map(static function (array $column) use ($values): array {
                [, $field, $type] = $column;
                $value = $values[$field] ?? null;

                return [
                    'value' => $value === null || $value === '' ? __('logistics.common.not_available') : $value,
                    'type' => $type,
                    'align' => $field === 'name' ? 'left' : 'center',
                    'wrap' => in_array($field, ['name', 'stores'], true),
                ];
            }, $columns),
        ];
    }

    /** @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function productRow(array $product): array
    {
        return [
            'name' => (string) $product['name'],
            'sku' => (string) $product['sku'],
            'stores' => implode('、', $product['stores']),
            'sales' => $product['sales'],
            'revenue' => $product['revenue'],
            'cost' => $product['cost'],
            'fees' => (float) $product['shipping'] + (float) $product['handling'],
            'paypal_refund_amount' => $product['paypal_refund_amount'],
            'paypal_refund_rate' => $product['paypal_refund_rate'],
            'paypal_dispute_count' => $product['paypal_dispute_count'],
            'paypal_dispute_rate' => $product['paypal_dispute_rate'],
            'credit_card_refund_amount' => $product['credit_card_refund_amount'],
            'credit_card_refund_rate' => $product['credit_card_refund_rate'],
            'combined_refund_rate' => $product['combined_refund_rate'],
            'actual_profit' => $product['actual_profit'],
            'profit_rate' => $product['profit_rate'],
            'decision' => $this->decisionLabel((string) $product['decision']),
        ];
    }

    private function decisionLabel(string $decision): string
    {
        return [
            'continue' => __('logistics.product_statistics.decisions.continue'),
            'optimize' => __('logistics.product_statistics.decisions.optimize'),
            'watch' => __('logistics.product_statistics.decisions.watch'),
            'stop' => __('logistics.product_statistics.decisions.stop'),
        ][$decision] ?? __('logistics.product_statistics.decisions.watch');
    }
}
