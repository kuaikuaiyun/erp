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
            ['层级', 'level', self::TYPE_TEXT, 12],
            [__('logistics.product_statistics.headers.product'), 'name', self::TYPE_TEXT, 30],
            ['SKU', 'sku', self::TYPE_TEXT, 30],
            [__('logistics.product_statistics.headers.stores'), 'stores', self::TYPE_TEXT, 24],
            [__('logistics.product_statistics.headers.sales'), 'sales', self::TYPE_TEXT, 15],
            [__('logistics.product_statistics.headers.revenue'), 'revenue', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.cost'), 'cost', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.headers.fees'), 'fees', self::TYPE_MONEY, 20],
            [__('logistics.product_statistics.headers.refunds'), 'refunds', self::TYPE_MONEY, 17],
            [__('logistics.product_statistics.ui.actual_refunds'), 'actual_refunds', self::TYPE_MONEY, 18],
            [__('logistics.product_statistics.ui.actual_refund_count'), 'actual_refund_count', self::TYPE_TEXT, 16],
            [__('logistics.product_statistics.headers.profit'), 'profit', self::TYPE_MONEY, 17],
            [__('logistics.product_statistics.headers.profit_rate'), 'profit_rate', self::TYPE_RATE, 14],
            [__('logistics.product_statistics.headers.refund_rate'), 'refund_rate', self::TYPE_RATE, 14],
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
            array_map(static fn (array $column): array => [
                'label' => $column[0],
                'width' => $column[3],
            ], $columns),
            function () use ($products, $columns): iterable {
                foreach ($products as $product) {
                    yield $this->excelRow($this->parentRow($product), $columns, 'parent');
                    foreach ($product['variants'] as $variant) {
                        yield $this->excelRow($this->variantRow($variant), $columns, 'variant');
                    }
                }
            },
            false,
        );
    }

    /** @param array<string,mixed> $values
     * @param list<array{0:string,1:string,2:string,3:float|int}> $columns
     * @return array{kind:string,height:int,outline:int,cells:list<array{value:mixed,type:string,align:string,wrap:bool}>}
     */
    private function excelRow(array $values, array $columns, string $kind): array
    {
        return [
            'kind' => $kind,
            'height' => $kind === 'variant' ? 34 : 42,
            'outline' => $kind === 'variant' ? 1 : 0,
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
    private function parentRow(array $product): array
    {
        return [
            'level' => '主产品',
            'name' => (string) $product['name']."\n".(string) $product['category'],
            'sku' => (string) $product['sku'],
            'stores' => implode('、', $product['stores']),
            'sales' => $product['sales'],
            'revenue' => $product['revenue'],
            'cost' => $product['cost'],
            'fees' => (float) $product['shipping'] + (float) $product['handling'],
            'refunds' => $product['refunds'],
            'actual_refunds' => $product['actual_refunds'],
            'actual_refund_count' => $product['actual_refund_count'],
            'profit' => $product['profit'],
            'profit_rate' => $product['profit_rate'],
            'refund_rate' => $product['refund_rate'],
            'decision' => $this->decisionLabel((string) $product['decision']),
        ];
    }

    /** @param array<string,mixed> $variant
     * @return array<string,mixed>
     */
    private function variantRow(array $variant): array
    {
        return [
            'level' => '关联变体',
            'name' => (string) $variant['name']."\n".__('logistics.product_statistics.ui.ordered_variant'),
            'sku' => (string) $variant['sku'],
            'stores' => __('logistics.product_statistics.ui.ordered_variant'),
            'sales' => $variant['sales'],
            'revenue' => $variant['revenue'],
            'cost' => $variant['cost'],
            'fees' => (float) $variant['shipping'] + (float) $variant['handling'],
            'refunds' => $variant['refunds'],
            'actual_refunds' => $variant['actual_refunds'],
            'actual_refund_count' => $variant['actual_refund_count'],
            'profit' => $variant['profit'],
            'profit_rate' => $variant['profit_rate'],
            'refund_rate' => $variant['refund_rate'],
            'decision' => __('logistics.common.not_available'),
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
