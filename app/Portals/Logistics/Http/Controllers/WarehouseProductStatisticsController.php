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
            'rejectReasonOptions' => WarehouseSkuWeightException::REJECT_REASONS,
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
            // 注意：必须按索引直接写 $detail['detail']['rows'][$idx]，
            // PHP 的 foreach + &$row 在多层数组+中间变量场景下有 COW（写时复制）
            // 陷阱，引用修改可能不生效。
            $imageMap = $weightRefresh['images'] ?? [];
            $rows = $detail['detail']['rows'] ?? [];
            foreach ($rows as $idx => $row) {
                $skuKey = mb_strtolower(trim((string) ($row['tongtu_sku'] ?? '')));
                if ($skuKey !== '' && isset($imageMap[$skuKey])) {
                    $detail['detail']['rows'][$idx]['product_image_url'] = $imageMap[$skuKey];
                }
            }

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
     * 「填写」弹窗提交：一次性提交该产品下多个 SKU 的异常数据
     * （填写重量 / 驳回理由 / 备注），按 SKU 幂等写入待处理队列。
     */
    public function fillWeightExceptions(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'max:512'],
            'tongtu_product_code' => ['nullable', 'string', 'max:191'],
            'tongtu_product_name' => ['nullable', 'string', 'max:255'],
            'product_image_url' => ['nullable', 'string', 'max:1024'],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.tongtu_sku' => ['required', 'string', 'max:191'],
            'rows.*.shopify_sku' => ['nullable', 'string', 'max:191'],
            'rows.*.original_weight_gram' => ['nullable', 'numeric'],
            'rows.*.corrected_weight_gram' => ['nullable', 'numeric', 'gt:0'],
            'rows.*.reject_reason' => ['nullable', Rule::in(array_keys(WarehouseSkuWeightException::REJECT_REASONS))],
            'rows.*.remark' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $context['user'];

        // 只处理真正填了内容的行：重量 / 驳回理由 / 备注 全空视为未填写
        $rows = collect($validated['rows'])->filter(function (array $row): bool {
            return trim((string) ($row['reject_reason'] ?? '')) !== ''
                || trim((string) ($row['remark'] ?? '')) !== ''
                || (float) ($row['corrected_weight_gram'] ?? 0) > 0;
        })->values();

        if ($rows->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => '请至少为一个 SKU 填写重量、驳回理由或备注',
            ], 422);
        }

        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $sku = trim((string) $row['tongtu_sku']);
            $existing = WarehouseSkuWeightException::query()
                ->where('status', 'pending')
                ->where('product_id', $validated['product_id'])
                ->where('tongtu_sku', $sku)
                ->first();

            $payload = [
                'shopify_sku' => trim((string) ($row['shopify_sku'] ?? '')) ?: null,
                'tongtu_product_code' => trim((string) ($validated['tongtu_product_code'] ?? '')) ?: null,
                'tongtu_product_name' => trim((string) ($validated['tongtu_product_name'] ?? '')) ?: null,
                'product_image_url' => trim((string) ($validated['product_image_url'] ?? '')) ?: null,
                'original_weight_gram' => (float) ($row['original_weight_gram'] ?? 0),
                'corrected_weight_gram' => (float) ($row['corrected_weight_gram'] ?? 0) ?: null,
                'reject_reason' => trim((string) ($row['reject_reason'] ?? '')) ?: null,
                'remark' => trim((string) ($row['remark'] ?? '')) ?: null,
            ];

            if ($existing) {
                // 只覆盖本次真正填写过的字段，避免把之前已填的驳回理由/备注清空
                $changes = array_filter($payload, static function ($value): bool {
                    if (is_int($value) || is_float($value)) {
                        return $value > 0;
                    }

                    return $value !== null && $value !== '';
                });
                if ($changes !== []) {
                    $existing->fill($changes)->save();
                    $updated++;
                }
                continue;
            }

            WarehouseSkuWeightException::query()->create([
                'product_id' => $validated['product_id'],
                'tongtu_sku' => $sku,
                ...$payload,
                'status' => 'pending',
                'reported_by_user_id' => (int) $user->getKey(),
                'reporter_name' => trim((string) $user->getAttribute('username')),
            ]);
            $created++;
        }

        return response()->json([
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'message' => sprintf('异常数据已提交：新增 %d 条，更新 %d 条', $created, $updated),
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
            // 仓库列表不展示退款原因报表，跳过重聚合（约 19s）避免列表/分页卡顿
            'skip_refund_report' => true,
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
            'fill' => '填写',
            'filledWeight' => '实际重量(g)',
            'rejectReason' => '驳回理由',
            'remark' => '备注',
        ];
    }
}
