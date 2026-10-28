<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProductRequestLinkSku;
use App\Models\LogisticsTongtuSkuMapping;
use App\Models\WarehouseSkuWeightException;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\TongtuSkuMappingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 入录审核系统（caigou portal）下的「仓库异常SKU数据」列表。
 *
 * 列出仓库系统在出单产品统计里提报的规格 SKU 重量异常，
 * 审核人员可以标记为已处理。
 */
class WarehouseWeightExceptionController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
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
        $query = $this->buildQuery($filters);

        $total = $query->count();
        $rows = $query
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'resolved' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->limit($filters['per_page'])
            ->offset(($filters['page'] - 1) * $filters['per_page'])
            ->get();

        $this->attachWarehouseImages($rows);

        $pendingCount = WarehouseSkuWeightException::query()->where('status', 'pending')->count();
        $resolvedCount = WarehouseSkuWeightException::query()->where('status', 'resolved')->count();

        return view('portals.caigou.warehouse-weight-exceptions.index', [
            ...$this->viewContext($context),
            'rows' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'from' => $total === 0 ? 0 : (($filters['page'] - 1) * $filters['per_page'] + 1),
                'to' => min($total, $filters['page'] * $filters['per_page']),
                'last_page' => max(1, (int) ceil($total / $filters['per_page'])),
            ],
            'pendingCount' => $pendingCount,
            'resolvedCount' => $resolvedCount,
            'filters' => $filters,
        ]);
    }

    /** 侧边栏菜单待处理计数 */
    public function pendingCount(Request $request): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return response()->json([
            'count' => WarehouseSkuWeightException::query()->where('status', 'pending')->count(),
        ]);
    }

    /** 将异常标记为已处理（入录审核手动关闭队列） */
    public function resolve(Request $request, int $id): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $validated = $request->validate([
            'resolution_remark' => ['nullable', 'string', 'max:500'],
        ]);

        $exception = WarehouseSkuWeightException::query()->findOrFail($id);
        if ($exception->status === 'resolved') {
            return response()->json([
                'ok' => false,
                'message' => '该异常已整改，请勿重复操作',
            ], 409);
        }

        DB::transaction(function () use ($exception, $context, $validated): void {
            $exception->fill([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by_user_id' => (int) $context['user']->getKey(),
                'resolution_remark' => trim((string) ($validated['resolution_remark'] ?? '')) ?: $exception->resolution_remark,
            ])->save();
        });

        return response()->json([
            'ok' => true,
            'message' => '已标记为整改完成',
        ]);
    }

    public function refresh(Request $request, int $id): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $exception = WarehouseSkuWeightException::query()->findOrFail($id);
        abort_unless($exception->status === 'resolved', 422, '请先将异常标记为已整改');

        try {
            $result = $this->tongtuMappings->refreshWeightsByTongtuSkus([(string) $exception->tongtu_sku]);
        } catch (\Throwable $throwable) {
            return response()->json(['ok' => false, 'message' => '抓取失败：'.$throwable->getMessage()], 422);
        }

        $refreshed = (int) ($result['refreshed'] ?? 0);
        if ($refreshed > 0) {
            $normalizedSku = LogisticsTongtuSkuMapping::normalizedSku((string) $exception->tongtu_sku);
            $mapping = LogisticsTongtuSkuMapping::query()
                ->whereRaw('LOWER(TRIM(tongtu_sku)) = ?', [$normalizedSku])
                ->orWhere('shopify_sku', $normalizedSku)
                ->first();
            if ($mapping && (float) $mapping->goods_weight_gram > 0) {
                $exception->forceFill(['original_weight_gram' => (float) $mapping->goods_weight_gram])->save();
            }
        }
        return response()->json([
            'ok' => $refreshed > 0,
            'message' => $refreshed > 0 ? '已抓取最新数据' : '未抓取到可用的最新数据，请检查 SKU',
            'result' => $result,
        ], $refreshed > 0 ? 200 : 422);
    }

    public function approve(Request $request, int $id): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $exception = WarehouseSkuWeightException::query()->findOrFail($id);
        abort_unless($exception->status === 'resolved', 422, '待整改异常不能审核通过');
        $code = trim((string) $exception->tongtu_product_code);
        abort_if($code === '', 422, '该记录缺少 PCL 编号，无法审核');

        $updated = ProductRequestLinkSku::query()
            ->whereRaw('LOWER(TRIM(pcl_product_no)) = ?', [mb_strtolower($code)])
            ->update([
                'warehouse_review_status' => ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED,
                'warehouse_reviewed_at' => now(),
            ]);

        return response()->json([
            'ok' => $updated > 0,
            'message' => $updated > 0 ? '审核通过成功' : '未找到对应的 PCL SKU 数据',
        ], $updated > 0 ? 200 : 422);
    }

    /** 显示异常反馈中上传的仓库实拍图。 */
    public function warehouseImage(Request $request, ProductRequestLinkSku $linkSku, int $index): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $images = array_values(array_filter((array) ($linkSku->warehouse_images ?? [])));
        $path = (string) ($images[$index] ?? '');
        abort_if($path === '' || !Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function attachWarehouseImages(\Illuminate\Support\Collection $rows): void
    {
        $codes = $rows->pluck('tongtu_product_code')
            ->map(static fn ($code): string => mb_strtolower(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        $imageMap = [];
        if ($codes->isNotEmpty()) {
            $linkSkus = ProductRequestLinkSku::query()
                ->whereRaw('LOWER(TRIM(pcl_product_no)) IN ('.implode(',', array_fill(0, $codes->count(), '?')).')', $codes->all())
                ->get(['id', 'pcl_product_no', 'warehouse_images', 'warehouse_review_status']);

            foreach ($linkSkus as $linkSku) {
                $key = mb_strtolower(trim((string) $linkSku->pcl_product_no));
                if ($linkSku->warehouse_review_status === ProductRequestLinkSku::WAREHOUSE_REVIEW_STATUS_APPROVED) {
                    $imageMap[$key]['approved'] = true;
                }
                foreach (array_values(array_filter((array) ($linkSku->warehouse_images ?? []))) as $index => $path) {
                    $imageMap[$key]['images'][] = [
                        'url' => route('caigou.warehouse-weight-exceptions.warehouse-image', [
                            'linkSku' => $linkSku->getKey(),
                            'index' => $index,
                        ]),
                    ];
                }
            }
        }

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->tongtu_product_code));
            $row->setAttribute('warehouse_image_refs', $imageMap[$key]['images'] ?? []);
            $row->setAttribute('warehouse_review_approved', (bool) ($imageMap[$key]['approved'] ?? false));
        }
    }

    /** @return array{portal: \App\Models\Portal, user: \App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === 'caigou', 404);
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
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['', 'pending', 'resolved'])],
            'keyword' => ['nullable', 'string', 'max:191'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])],
        ]);

        return [
            'status' => trim((string) ($validated['status'] ?? '')),
            'keyword' => trim((string) ($validated['keyword'] ?? '')),
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => in_array((int) ($validated['per_page'] ?? 20), [10, 20, 50], true)
                ? (int) ($validated['per_page'] ?? 20)
                : 20,
        ];
    }

    /** @param array<string,mixed> $filters */
    private function buildQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = WarehouseSkuWeightException::query();

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        $keyword = $filters['keyword'];
        if ($keyword !== '') {
            $like = '%'.$keyword.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('tongtu_sku', 'like', $like)
                  ->orWhere('shopify_sku', 'like', $like)
                  ->orWhere('tongtu_product_code', 'like', $like)
                  ->orWhere('tongtu_product_name', 'like', $like)
                  ->orWhere('reject_reason', 'like', $like)
                  ->orWhere('remark', 'like', $like)
                  ->orWhere('resolution_remark', 'like', $like)
                  ->orWhere('reporter_name', 'like', $like);
            });
        }

        return $query;
    }
}
