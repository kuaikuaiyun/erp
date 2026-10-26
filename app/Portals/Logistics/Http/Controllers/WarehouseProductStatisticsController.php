<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\WarehouseSkuWeightException;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsProductStatisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 仓库系统（cangku portal）下的出单产品。
 *
 * 数据源完全复用 LogisticsProductStatisticsService，仅在仓库门户上下文内可用，
 * 列表视图只保留产品信息 / 产品编号 / 规格信息 / 查看按钮四个关键区域。
 * 重量直接取 DB（logistics_order_items.sale_weight_gram）中已同步的值，
 * 不再额外调通途 API。
 */
class WarehouseProductStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsProductStatisticsService $statistics,
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

        // 附带当前 product 下已有的待处理异常记录（按 SKU 分组），前端据此回填已填值
        $existingExceptions = WarehouseSkuWeightException::query()
            ->where('product_id', (string) $validated['product_id'])
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get(['id', 'tongtu_sku', 'corrected_weight_gram', 'reject_reason', 'remark', 'physical_image_path'])
            ->groupBy('tongtu_sku')
            ->map(static function ($group) {
                $first = $group->first();

                return [
                    'id' => (int) $first->id,
                    'corrected_weight_gram' => $first->corrected_weight_gram,
                    'reject_reason' => $first->reject_reason,
                    'remark' => $first->remark,
                    'has_physical_image' => (string) $first->physical_image_path !== '',
                ];
            })
            ->all();

        return response()->json([
            ...$detail,
            'existing_exceptions' => $existingExceptions,
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
            'rows.*.remove_physical_image' => ['nullable', 'boolean'],
        ]);

        // 逐文件验证每个 SKU 的实物图
        $indexedFileMap = [];
        foreach ((array) $request->file('rows') as $idx => $rowFiles) {
            if (is_array($rowFiles) && isset($rowFiles['physical_image']) && $rowFiles['physical_image'] !== null) {
                $file = $rowFiles['physical_image'];
                if (!$file->isValid()) {
                    return response()->json(['ok' => false, 'message' => '第 ' . ($idx + 1) . ' 行实物图上传失败'], 422);
                }
                $mime = strtolower((string) $file->getMimeType());
                $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                if (!in_array($mime, $allowed, true)) {
                    return response()->json(['ok' => false, 'message' => '实物图仅支持 jpg/png/webp'], 422);
                }
                if ($file->getSize() > 5 * 1024 * 1024) {
                    return response()->json(['ok' => false, 'message' => '实物图不得超过 5MB'], 422);
                }
                $indexedFileMap[$idx] = $file;
            }
        }

        $user = $context['user'];

        // 先按 SKU 把实物图文件归好档（避免后面 filter+values 重排后索引对不上）
        $fileBySku = [];
        foreach ((array) $validated['rows'] as $idx => $row) {
            if (isset($indexedFileMap[$idx])) {
                $fileBySku[trim((string) ($row['tongtu_sku'] ?? ''))] = $indexedFileMap[$idx];
            }
        }

        // 只处理真正填了内容的行：重量 / 驳回理由 / 备注 全空视为未填写
        $rows = collect($validated['rows'])->filter(function (array $row) use ($fileBySku) {
            $sku = trim((string) ($row['tongtu_sku'] ?? ''));
            return trim((string) ($row['reject_reason'] ?? '')) !== ''
                || trim((string) ($row['remark'] ?? '')) !== ''
                || (float) ($row['corrected_weight_gram'] ?? 0) > 0
                || isset($fileBySku[$sku])
                || !empty($row['remove_physical_image']);
        })->values();

        if ($rows->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => '请至少为一个 SKU 填写重量、驳回理由、备注或上传实物图',
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

            // 实物图处理：按 SKU 从预先整理好的 map 里取
            $physicalPath = (string) ($existing?->physical_image_path ?? '');
            $removePhysical = !empty($row['remove_physical_image']);
            $newFile = $fileBySku[$sku] ?? null;

            if ($newFile !== null) {
                // 有新图：存新图，删旧图
                $ext = strtolower((string) ($newFile->getClientOriginalExtension() ?: 'jpg'));
                $path = 'warehouse-exceptions/'.Str::uuid().'.'.$ext;
                Storage::disk('local')->put($path, file_get_contents($newFile->getRealPath()));
                if ($physicalPath !== '' && $physicalPath !== $path) {
                    Storage::disk('local')->delete($physicalPath);
                }
                $physicalPath = $path;
            } elseif ($removePhysical && $physicalPath !== '') {
                Storage::disk('local')->delete($physicalPath);
                $physicalPath = '';
            }

            $payload = [
                'shopify_sku' => trim((string) ($row['shopify_sku'] ?? '')) ?: null,
                'tongtu_product_code' => trim((string) ($validated['tongtu_product_code'] ?? '')) ?: null,
                'tongtu_product_name' => trim((string) ($validated['tongtu_product_name'] ?? '')) ?: null,
                'product_image_url' => trim((string) ($validated['product_image_url'] ?? '')) ?: null,
                'original_weight_gram' => (float) ($row['original_weight_gram'] ?? 0),
                'corrected_weight_gram' => (float) ($row['corrected_weight_gram'] ?? 0) ?: null,
                'reject_reason' => trim((string) ($row['reject_reason'] ?? '')) ?: null,
                'remark' => trim((string) ($row['remark'] ?? '')) ?: null,
                'physical_image_path' => $physicalPath !== '' ? $physicalPath : null,
            ];

            if ($existing) {
                // 只覆盖本次真正填写过的字段，避免把之前已填的驳回理由/备注清空
                $changes = array_filter($payload, static function ($value): bool {
                    if (is_int($value) || is_float($value)) {
                        return $value > 0;
                    }

                    return $value !== null && $value !== '';
                });
                // 实物图路径如果是明确变更过（null 也需要写入），强制带上
                if ($newFile !== null || $removePhysical) {
                    $changes['physical_image_path'] = $payload['physical_image_path'];
                }
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

    /** 实物图访问：本地文件直接流式输出，不受 portal 限制 */
    public function physicalImage(Request $request, int $id): Response
    {
        $exception = WarehouseSkuWeightException::query()->find($id);
        abort_if($exception === null, 404);

        $path = (string) ($exception->physical_image_path ?? '');
        abort_if($path === '' || !Storage::disk('local')->exists($path), 404);

        $local = Storage::disk('local')->path($path);
        $mime = mime_content_type($local) ?: 'image/jpeg';

        return response()->file($local, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
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
