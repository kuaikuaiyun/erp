<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WarehouseSkuWeightException;
use App\Portal\Http\Controllers\PortalEntryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            ->orderByDesc('status')
            ->orderByDesc('updated_at')
            ->limit($filters['per_page'])
            ->offset(($filters['page'] - 1) * $filters['per_page'])
            ->get();

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
                'message' => '该异常已处理，请勿重复操作',
            ], 409);
        }

        DB::transaction(function () use ($exception, $context, $validated): void {
            $exception->fill([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by_user_id' => (int) $context['user']->getKey(),
                'remark' => trim((string) ($validated['resolution_remark'] ?? '')) ?: $exception->remark,
            ])->save();
        });

        return response()->json([
            'ok' => true,
            'message' => '已标记为处理完成',
        ]);
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
                  ->orWhere('remark', 'like', $like)
                  ->orWhere('reporter_name', 'like', $like);
            });
        }

        return $query;
    }
}
