<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ProductRequestLinkSku;
use App\Models\WarehouseSkuWeightException;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\LogisticsProductStatisticsService;
use App\Portals\Logistics\TongtuSkuMappingService;
use Illuminate\Http\JsonResponse;
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
        private readonly TongtuSkuMappingService $tongtuMappings,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $filters = $this->applyWarehouseReviewFilter($filters);
        $data = $this->statistics->page($filters);

        // 批量匹配 PCL SKU 管理（product_request_link_skus）中的实拍图
        $this->enrichLinkSkuImages($data['products']);

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
                $physicalPath = (string) $first->physical_image_path;
                $physicalUrl = '';
                if ($physicalPath !== '' && Storage::disk('local')->exists($physicalPath)) {
                    $physicalUrl = route('warehouse.weight-exceptions.physical-image', ['id' => $first->id]);
                }

                return [
                    'id' => (int) $first->id,
                    'corrected_weight_gram' => $first->corrected_weight_gram,
                    'reject_reason' => $first->reject_reason,
                    'remark' => $first->remark,
                    'physical_image_url' => $physicalUrl,
                ];
            })
            ->all();

        return response()->json([
            ...$detail,
            'existing_exceptions' => $existingExceptions,
        ]);
    }

    /**
     * 缺重量的 SKU 手动补抓：调用通途 goodsQuery 把 goodsWeight 同步回
     * logistics_tongtu_sku_mappings.goods_weight_gram 与订单行 sale_weight_gram。
     */
    public function refreshWeights(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'skus' => ['required', 'array', 'min:1', 'max:200'],
            'skus.*' => ['required', 'string', 'max:191'],
        ]);

        try {
            $result = $this->tongtuMappings->refreshWeightsByTongtuSkus($validated['skus']);
        } catch (\Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => '抓取失败：'.$exception->getMessage(),
            ], 422);
        }

        $refreshed = (int) ($result['refreshed'] ?? 0);

        return response()->json([
            'ok' => $refreshed > 0,
            'result' => $result,
            'message' => $refreshed > 0
                ? sprintf('已抓取 %d 个 SKU 的重量', $refreshed)
                : '通途未返回可用的重量数据，请稍后重试或检查 SKU 是否已在通途建档',
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
            'rows.*.reject_reason' => [
                'nullable',
                'string',
                'max:64',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $reasons = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)))));
                    if (array_diff($reasons, array_keys(WarehouseSkuWeightException::REJECT_REASONS)) !== []) {
                        $fail('驳回理由包含无效选项。');
                    }
                },
            ],
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

        $pclCode = trim((string) ($validated['tongtu_product_code'] ?? ''));
        if ($pclCode !== '') {
            ProductRequestLinkSku::query()
                ->whereRaw('LOWER(TRIM(pcl_product_no)) = ?', [mb_strtolower($pclCode)])
                ->update([
                    'warehouse_review_status' => ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_PENDING,
                    'warehouse_reviewed_at' => null,
                ]);
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
            'warehouse_status' => ['nullable', Rule::in(['', 'pending_review', 'pending', 'resolved', 'approved'])],
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
            'warehouse_status' => trim((string) ($input['warehouse_status'] ?? '')),
            'store' => '',
            'decision' => '',
            // 所有真实出单商品均进入验收列表；未匹配通途/PCL 的商品也不能遗漏。
            'require_tongtu_match' => false,
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

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function applyWarehouseReviewFilter(array $filters): array
    {
        $status = (string) ($filters['warehouse_status'] ?? '');
        if ($status === '') return $filters;

        $approvedCodes = ProductRequestLinkSku::query()
            ->where('warehouse_review_status', ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED)
            ->whereNotNull('pcl_product_no')->pluck('pcl_product_no')
            ->map(fn ($code): string => mb_strtolower(trim((string) $code)))->filter()->unique()->values();
        $pendingCodes = WarehouseSkuWeightException::query()->where('status', 'pending')
            ->whereNotNull('tongtu_product_code')->pluck('tongtu_product_code')
            ->map(fn ($code): string => mb_strtolower(trim((string) $code)))->filter()->unique()->values();
        $resolvedCodes = WarehouseSkuWeightException::query()->where('status', 'resolved')
            ->whereNotNull('tongtu_product_code')->pluck('tongtu_product_code')
            ->map(fn ($code): string => mb_strtolower(trim((string) $code)))->filter()->unique()->values()
            ->diff($pendingCodes)->diff($approvedCodes)->values();

        if ($status === 'approved') $filters['include_product_codes'] = $approvedCodes->all();
        elseif ($status === 'pending') $filters['include_product_codes'] = $pendingCodes->all();
        elseif ($status === 'resolved') $filters['include_product_codes'] = $resolvedCodes->all();
        else $filters['exclude_product_codes'] = $approvedCodes->merge($pendingCodes)->merge($resolvedCodes)->unique()->values()->all();

        return $filters;
    }

    /** @return array<string,string> */
    private function labels(): array
    {
        return [
            'pageTitle' => '出单产品验收',
            'subtitle' => '已出单产品验收列表，可操作提报异常数据',
            'productInfo' => '产品信息',
            'productCode' => 'PCL 编号',
            'specification' => 'SKU 与重量',
            'variantCount' => 'SKU 数',
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
            'fill' => '异常反馈',
            'filledWeight' => '实际重量(g)',
            'rejectReason' => '驳回理由',
            'remark' => '备注',
            'reviewPending' => '待审核',
            'reviewApproved' => '已审核',
            'approve' => '审核通过',
            'uploadImage' => '上传实拍图',
        ];
    }

    /**
     * 批量匹配 PCL SKU 管理的实拍图，注入到出单产品列表数据。
     * 按 LOWER(TRIM(product_code)) = LOWER(TRIM(pcl_product_no)) 匹配。
     *
     * @param list<array<string,mixed>> $products
     */
    private function enrichLinkSkuImages(array &$products): void
    {
        $codes = collect($products)
            ->pluck('product_code')
            ->filter()
            ->map(static fn ($code): string => mb_strtolower(trim((string) $code)))
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            foreach ($products as &$product) {
                $product['link_sku_id'] = null;
                $product['link_sku_images'] = [];
                $product['link_sku_images_json'] = '[]';
            }
            unset($product);
            return;
        }

        $linkSkus = ProductRequestLinkSku::query()
            ->where('is_runnable', true)
            ->whereRaw('LOWER(TRIM(pcl_product_no)) IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes)
            ->get(['id', 'pcl_product_no', 'images', 'warehouse_review_status']);

        $linkSkuMap = [];
        foreach ($linkSkus as $linkSku) {
            $key = mb_strtolower(trim((string) $linkSku->pcl_product_no));
            $rawImages = array_values(array_filter((array) ($linkSku->images ?? [])));
            // 同时记录审核状态（每条 link_sku 一条）
            if (!isset($linkSkuMap[$key])) {
                $linkSkuMap[$key] = [
                    'link_sku_id' => (int) $linkSku->getKey(),
                    'warehouse_review_status' => ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_PENDING,
                    'image_refs' => [],
                ];
            }
            if ($linkSku->warehouse_review_status === ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED) {
                $linkSkuMap[$key]['warehouse_review_status'] = ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED;
            }
            if ($rawImages === []) {
                continue;
            }
            // 每张图绑定自己的 link_sku_id + index，这样 route 才能正确解析
            $imageRefs = [];
            foreach ($rawImages as $idx => $path) {
                $imageRefs[] = [
                    'link_sku_id' => (int) $linkSku->getKey(),
                    'path' => $path,
                    'index' => $idx,
                ];
            }
            $linkSkuMap[$key]['image_refs'] = array_merge($linkSkuMap[$key]['image_refs'], $imageRefs);
        }

        $exceptionStatusMap = [];
        WarehouseSkuWeightException::query()
            ->whereRaw('LOWER(TRIM(tongtu_product_code)) IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes)
            ->get(['tongtu_product_code', 'status'])
            ->each(function (WarehouseSkuWeightException $exception) use (&$exceptionStatusMap): void {
                $key = mb_strtolower(trim((string) $exception->tongtu_product_code));
                if ($exception->status === 'pending') {
                    $exceptionStatusMap[$key] = 'pending';
                } elseif (!isset($exceptionStatusMap[$key])) {
                    $exceptionStatusMap[$key] = 'resolved';
                }
            });

        foreach ($products as &$product) {
            $code = mb_strtolower(trim((string) ($product['product_code'] ?? '')));
            $entry = $linkSkuMap[$code] ?? null;
            if ($entry !== null && $entry['image_refs'] !== []) {
                $product['link_sku_id'] = $entry['link_sku_id'];
                $rawImages = array_column($entry['image_refs'], 'path');
                $product['link_sku_images'] = $rawImages;
                $product['link_sku_images_urls'] = array_map(
                    static fn (array $ref): string => route('product-requests.link-skus.image', ['linkSku' => $ref['link_sku_id'], 'index' => $ref['index']]),
                    $entry['image_refs'],
                );
                $product['warehouse_review_status'] = $entry['warehouse_review_status'];
            } elseif ($entry !== null) {
                $product['link_sku_id'] = $entry['link_sku_id'];
                $product['link_sku_images'] = [];
                $product['link_sku_images_urls'] = [];
                $product['warehouse_review_status'] = $entry['warehouse_review_status'];
            } else {
                $product['link_sku_id'] = null;
                $product['link_sku_images'] = [];
                $product['link_sku_images_urls'] = [];
                $product['warehouse_review_status'] = ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_PENDING;
            }
            $product['warehouse_display_status'] = $product['warehouse_review_status'] === ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED
                ? 'approved'
                : ($exceptionStatusMap[$code] ?? 'pending_review');
            $product['link_sku_images_json'] = json_encode($product['link_sku_images'], JSON_UNESCAPED_UNICODE);
        }
        unset($product);
    }

    /** 获取某 PCL 的 link_sku 实拍图列表（JSON） */
    public function linkSkuImages(Request $request): JsonResponse
    {
        $this->portalContext($request);

        $validated = $request->validate([
            'pcl_code' => ['required', 'string', 'max:255'],
        ]);
        $pclCode = mb_strtolower(trim((string) $validated['pcl_code']));

        $linkSku = ProductRequestLinkSku::query()
            ->whereRaw('LOWER(TRIM(pcl_product_no)) = ?', [$pclCode])
            ->first(['id', 'pcl_product_no', 'warehouse_images']);

        return response()->json([
            'link_sku_id' => $linkSku?->getKey(),
            'pcl_product_no' => $linkSku?->pcl_product_no ?? trim((string) $validated['pcl_code']),
            'images' => $linkSku ? array_values(array_filter((array) ($linkSku->warehouse_images ?? []))) : [],
        ]);
    }

    /** 上传实拍图（POST multipart）：追加到 images 数组；若无 link_sku 记录则自动创建 */
    public function linkSkuImageUpload(Request $request): JsonResponse
    {
        $this->portalContext($request);

        $validated = $request->validate([
            'pcl_code' => ['required', 'string', 'max:255'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $pclCode = mb_strtolower(trim((string) $validated['pcl_code']));

        $linkSku = ProductRequestLinkSku::query()
            ->whereRaw('LOWER(TRIM(pcl_product_no)) = ?', [$pclCode])
            ->first();

        if ($linkSku === null) {
            $linkSku = ProductRequestLinkSku::query()->create([
                'pcl_product_no' => trim((string) $validated['pcl_code']),
                'warehouse_images' => [],
                'source' => 'import',
                'review_status' => 'done',
                'supplier_url_hash' => 'wgh-' . substr(bin2hex(random_bytes(8)), 0, 16),
            ]);
        }

        $path = $validated['image']->store('warehouse-link-sku-images', 'local');
        $images = array_values(array_filter((array) ($linkSku->warehouse_images ?? [])));
        $images[] = $path;
        $linkSku->update(['warehouse_images' => $images]);

        return response()->json([
            'link_sku_id' => $linkSku->getKey(),
            'images' => $images,
        ]);
    }

    /** 删除指定 index 的实拍图 */
    public function linkSkuImageDelete(Request $request): JsonResponse
    {
        $this->portalContext($request);

        $validated = $request->validate([
            'pcl_code' => ['required', 'string', 'max:255'],
            'index' => ['required', 'integer', 'min:0'],
        ]);
        $pclCode = mb_strtolower(trim((string) $validated['pcl_code']));
        $index = (int) $validated['index'];

        $linkSku = ProductRequestLinkSku::query()
            ->whereRaw('LOWER(TRIM(pcl_product_no)) = ?', [$pclCode])
            ->first();

        if ($linkSku === null) {
            return response()->json(['message' => '未找到对应的 PCL 记录'], 404);
        }

        $images = array_values(array_filter((array) ($linkSku->warehouse_images ?? [])));
        if (!isset($images[$index])) {
            return response()->json(['message' => '图片索引越界'], 404);
        }

        $removedPath = $images[$index];
        unset($images[$index]);
        $images = array_values($images);
        $linkSku->update(['warehouse_images' => $images]);

        // 删除存储文件（忽略不存在的错误）
        try {
            if ($removedPath !== '' && Storage::disk('local')->exists($removedPath)) {
                Storage::disk('local')->delete($removedPath);
            }
        } catch (\Throwable) {
        }

        return response()->json([
            'link_sku_id' => $linkSku->getKey(),
            'images' => $images,
        ]);
    }

    /** 仓库异常反馈实拍图，与 PCL SKU 产品实拍图分开存储。 */
    public function warehouseImage(Request $request, ProductRequestLinkSku $linkSku, int $index): Response
    {
        $this->portalContext($request);

        $images = array_values(array_filter((array) ($linkSku->warehouse_images ?? [])));
        $path = (string) ($images[$index] ?? '');
        abort_if($path === '' || !Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** 审核通过：把该 PCL 对应的 product_request_link_skus 记录标记为已审核 */
    public function approve(Request $request): JsonResponse
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return response()->json(['ok' => false, 'message' => '未授权'], 403);
        }

        $validated = $request->validate([
            'pcl_code' => ['required', 'string', 'max:255'],
        ]);

        $pclCode = mb_strtolower(trim((string) $validated['pcl_code']));

        $reviewedAt = now();
        $updated = ProductRequestLinkSku::query()
            ->whereRaw('LOWER(TRIM(pcl_product_no)) = ?', [$pclCode])
            ->update([
                'warehouse_review_status' => ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED,
                'warehouse_reviewed_at' => $reviewedAt,
            ]);

        if ($updated === 0) {
            // 允许首次审核就创建记录（和上传图片的策略一致）
            ProductRequestLinkSku::query()->create([
                'pcl_product_no' => trim((string) $validated['pcl_code']),
                'warehouse_images' => [],
                'source' => 'import',
                'review_status' => 'done',
                'supplier_url_hash' => 'wgh-' . substr(bin2hex(random_bytes(8)), 0, 16),
                'warehouse_review_status' => ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED,
                'warehouse_reviewed_at' => $reviewedAt,
            ]);
        }

        return response()->json([
            'ok' => true,
            'warehouse_review_status' => ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED,
            'message' => '已审核通过',
        ]);
    }
}
