<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\WarehouseSkuWeightException;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsProductStatisticsService;
use App\Portals\Logistics\TongtuOrderMatchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仓库系统（cangku portal）下的出单产品。
 *
 * 数据源完全复用 LogisticsProductStatisticsService，仅在仓库门户上下文内可用，
 * 列表视图只保留产品信息 / 产品编号 / 规格信息 / 查看按钮四个关键区域。
 * 点击规格详情时主动调通途 goodsQuery 刷新每个 SKU 的 sale_weight_gram。
 */
class WarehouseProductStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsProductStatisticsService $statistics,
        private readonly TongtuOrderMatchService $tongtu,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $data = $this->statistics->page($filters);

        return view('portals.warehouse.product-statistics.index', [
            ...$this->viewContext($context),
            'products' => $data['products'],
            'productSummary' => $data['summary'],
            'productPagination' => $data['pagination'],
            'stores' => [], // 仓库视图已移去店铺筛选
            'filters' => $filters,
            'labels' => $this->labels(),
        ]);
    }

    public function detail(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:512'],
            ...$this->filterRules(false),
            'detail_page' => ['nullable', 'integer', 'min:1'],
            'detail_per_page' => ['nullable', 'integer', Rule::in([5, 10, 20])],
            'detail_sort' => ['nullable', 'string', 'max:64'],
            'detail_direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
            'detail_keyword' => ['nullable', 'string', 'max:191'],
        ]);

        $filters = $this->normaliseFilters($validated);
        $detail = $this->statistics->detailPage(
            $filters,
            (string) $validated['product_id'],
            [
                'tab' => 'variants',
                'page' => max(1, (int) ($validated['detail_page'] ?? 1)),
                'per_page' => in_array((int) ($validated['detail_per_page'] ?? 10), [5, 10, 20], true)
                    ? (int) ($validated['detail_per_page'] ?? 10)
                    : 10,
                'sort' => trim((string) ($validated['detail_sort'] ?? '')),
                'direction' => trim((string) ($validated['detail_direction'] ?? '')),
                'keyword' => trim((string) ($validated['detail_keyword'] ?? '')),
            ],
        );

        if ($detail === null) {
            return response()->json([
                'message' => __('logistics.product_statistics.ui.detail_not_found'),
            ], 404);
        }

        // 点击"查看"时先调通途 goodsQuery，把当前 product 下所有 tongtu_sku 的
        // goodsDetail[].goodsWeight 写回 logistics_order_items.sale_weight_gram，
        // 然后重新走 detailPage 让 variantMetrics 拿到最新重量值。
        $skusToRefresh = collect($detail['detail']['rows'] ?? [])
            ->pluck('tongtu_sku')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $weightRefresh = [
            'refreshed' => 0,
            'failed' => 0,
            'not_found' => 0,
            'skipped' => 0,
        ];

        if ($skusToRefresh !== []) {
            $weightRefresh = $this->tongtu->refreshWeightsByTongtuSkus($skusToRefresh);

            $detail = $this->statistics->detailPage(
                $filters,
                (string) $validated['product_id'],
                [
                    'tab' => 'variants',
                    'page' => max(1, (int) ($validated['detail_page'] ?? 1)),
                    'per_page' => in_array((int) ($validated['detail_per_page'] ?? 10), [5, 10, 20], true)
                        ? (int) ($validated['detail_per_page'] ?? 10)
                        : 10,
                    'sort' => trim((string) ($validated['detail_sort'] ?? '')),
                    'direction' => trim((string) ($validated['detail_direction'] ?? '')),
                    'keyword' => trim((string) ($validated['detail_keyword'] ?? '')),
                ],
            );

            // 用通途 goodsImageId 临时覆盖接口返回的 product_image_url。
            // 只改 JSON 响应，不动 logistics_order_items 表里的 Shopify 原图 ——
            // 避免污染系统中其他用到 product_image_url 的页面。
            $imageMap = $weightRefresh['images'] ?? [];
            foreach (($detail['detail']['rows'] ?? []) as &$row) {
                $skuKey = mb_strtolower(trim((string) ($row['tongtu_sku'] ?? '')));
                if ($skuKey !== '' && isset($imageMap[$skuKey])) {
                    $row['product_image_url'] = $imageMap[$skuKey];
                }
            }
            unset($row);

            // 产品级大图（弹窗顶部 identity 区域）也用通途图覆盖。
            // 通途一个 product 下各 SKU 的 goodsImageId 是同一张，取 images map 的第一个即可。
            if ($imageMap !== []) {
                $firstTongtuImage = reset($imageMap);
                if ($firstTongtuImage && isset($detail['product']) && is_array($detail['product'])) {
                    $detail['product']['image_url'] = $firstTongtuImage;
                }
            }
        }

        return response()->json([
            ...($detail ?? []),
            'weight_refresh' => $weightRefresh,
        ]);
    }

    /**
     * 仓库规格 SKU 重量异常提报：提交一条 warehouse_sku_weight_exceptions 记录。
     * 同一 tongtu_sku + shopify_sku + product_id 组合若已存在 pending 记录，不重复创建。
     */
    public function reportWeightException(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:512'],
            'tongtu_sku' => ['required', 'string', 'max:191'],
            'shopify_sku' => ['nullable', 'string', 'max:191'],
            'tongtu_product_code' => ['nullable', 'string', 'max:191'],
            'tongtu_product_name' => ['nullable', 'string', 'max:255'],
            'product_image_url' => ['nullable', 'string', 'max:1024'],
            'original_weight_gram' => ['nullable', 'numeric'],
            'corrected_weight_gram' => ['required', 'numeric', 'gt:0'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $context['user'];

        // 同一 SKU + product 维度已存在 pending 记录时做幂等返回，避免重复提报
        $existing = WarehouseSkuWeightException::query()
            ->where('status', 'pending')
            ->where('product_id', $validated['product_id'])
            ->where('tongtu_sku', $validated['tongtu_sku'])
            ->where(function ($q) use ($validated): void {
                $shopify = trim((string) ($validated['shopify_sku'] ?? ''));
                if ($shopify !== '') {
                    $q->where('shopify_sku', $shopify);
                } else {
                    $q->whereNull('shopify_sku');
                }
            })
            ->first();

        if ($existing) {
            return response()->json([
                'ok' => true,
                'id' => (int) $existing->id,
                'message' => '该规格 SKU 的重量异常已在提报队列中，请勿重复提交',
                'deduplicated' => true,
            ], 200);
        }

        $record = WarehouseSkuWeightException::query()->create([
            'product_id' => $validated['product_id'],
            'tongtu_sku' => $validated['tongtu_sku'],
            'shopify_sku' => trim((string) ($validated['shopify_sku'] ?? '')),
            'tongtu_product_code' => trim((string) ($validated['tongtu_product_code'] ?? '')),
            'tongtu_product_name' => trim((string) ($validated['tongtu_product_name'] ?? '')),
            'product_image_url' => trim((string) ($validated['product_image_url'] ?? '')),
            'original_weight_gram' => (float) ($validated['original_weight_gram'] ?? 0),
            'corrected_weight_gram' => (float) $validated['corrected_weight_gram'],
            'remark' => trim((string) ($validated['remark'] ?? '')),
            'status' => 'pending',
            'reported_by_user_id' => (int) $user->getKey(),
            'reporter_name' => trim((string) $user->getAttribute('username')),
        ]);

        return response()->json([
            'ok' => true,
            'id' => (int) $record->id,
            'message' => '重量异常提报成功，入录审核系统将收到该记录',
        ], 201);
    }

    /** @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === 'cangku', 404);
        app()->setLocale('zh-CN');

        return $context;
    }

    /** @param array{portal: \App\Models\Portal, user: \App\Models\AdminUser} $context */
    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return $this->normaliseFilters($request->validate($this->filterRules(true)));
    }

    /** @return array<string,mixed> */
    private function filterRules(bool $withPage): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:191'],
            'keyword' => ['nullable', 'string', 'max:191'],
            'sort' => ['nullable', Rule::in([
                '', 'name', 'sku', 'product_code', 'variant_count', 'shop_count', 'sales', 'revenue', 'cost', 'fees',
            ])],
            'direction' => ['nullable', Rule::in(['', 'asc', 'desc'])],
        ];
        if ($withPage) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', Rule::in([5, 10, 20])];
        }

        return $rules;
    }

    /** @param array<string,mixed> $input */
    private function normaliseFilters(array $input): array
    {
        return [
            // 仓库视角已移去时间范围与店铺筛选，强制为空让 Service 不过滤
            'date_from' => '',
            'date_to' => '',
            'name' => trim((string) ($input['name'] ?? '')),
            'keyword' => trim((string) ($input['keyword'] ?? '')),
            'store' => '',
            'decision' => '',
            // 仓库只关心已通途匹配（有 product_code）的产品，过滤掉待通途匹配的数据
            'require_tongtu_match' => true,
            'sort' => trim((string) ($input['sort'] ?? '')),
            'direction' => trim((string) ($input['direction'] ?? '')),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => in_array((int) ($input['per_page'] ?? 10), [5, 10, 20], true)
                ? (int) ($input['per_page'] ?? 10)
                : 10,
        ];
    }

    /** @return array<string,string> */
    private function labels(): array
    {
        return [
            'pageTitle' => '出单产品',
            'subtitle' => '已出单产品列表数据，可以操作提报异常数据',
            'productInfo' => '产品信息',
            'productCode' => '产品编号',
            'specification' => '规格信息',
            'variantCount' => '规格数',
            'view' => '查看',
            'close' => '关闭',
            'emptyRows' => '暂无匹配的产品数据',
            'store' => '店铺',
            'sales' => '销量',
            'revenue' => '销售额 (USD)',
            'variantSku' => '规格 SKU',
            'tongtuSku' => '通途 SKU',
            'shopifySku' => 'Shopify SKU',
            'tongtuProductName' => '通途产品名',
            'shops' => '店铺数',
            'orders' => '订单数',
            'refundAmount' => '退款金额',
            'refundCount' => '退款/争议',
            'productImage' => '货品图片',
            'tongtuWeight' => '通途重量(g)',
            'report' => '提报',
            'weightProblem' => '重量问题',
            'correctWeight' => '正确重量(g)',
            'originalWeight' => '原始重量(g)',
            'remark' => '备注',
            'reportSubmit' => '提交提报',
            'reportCancel' => '取消',
        ];
    }
}
