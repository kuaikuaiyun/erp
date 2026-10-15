<?php

namespace App\Portals\ProductRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ProductRequest;
use App\Models\ProductRequestAttachment;
use App\Models\ProductRequestLinkSku;
use App\Models\ProductRequestPlacementReview;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\ProductRequests\ProductRequestAccessService;
use App\Portals\ProductRequests\ProductRequestWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductRequestController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
        private readonly ProductRequestWorkflowService $workflow,
    ) {
    }

    public function operationIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $requests = $this->applyFilters(
            $this->access->applyOperationVisibility(ProductRequest::query(), $context['user']),
            $filters,
        )->with(['attachments', 'placementReviews'])->latest('id')->paginate($filters['per_page'])->withQueryString();

        return view('portals.product-requests.operation.index', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => $filters,
            'statusLabels' => $this->statusLabels(),
            'reviewStatusLabels' => $this->reviewStatusLabels(),
        ]);
    }

    /** 审核链接问题：投放链接审核失败的需求提报，按提报编号唯一展示 */
    public function linkIssuesIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        [$keyword, $perPage] = $this->issueFilters($request);
        $requests = $this->access->applyOperationVisibility(ProductRequest::query(), $context['user'])
            ->where('placement_review_status', ProductRequest::REVIEW_REJECTED)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('report_no', 'like', '%'.$keyword.'%')
                        ->orWhere('skus', 'like', '%'.$keyword.'%')
                        ->orWhere('procurement_skus', 'like', '%'.$keyword.'%')
                        ->orWhere('supplier_url', 'like', '%'.$keyword.'%')
                        ->orWhere('operation_supplier_url', 'like', '%'.$keyword.'%');
                });
            })
            ->with(['attachments', 'placementReviews'])
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('portals.product-requests.operation.link-issues', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => ['keyword' => $keyword, 'per_page' => $perPage],
        ]);
    }

    /** 仓库问题：仓库验收审核不通过的需求提报，按提报编号唯一展示 */
    public function warehouseIssuesIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        [$keyword, $perPage] = $this->issueFilters($request);
        $requests = $this->access->applyOperationVisibility(ProductRequest::query(), $context['user'])
            ->where('warehouse_acceptance_result', ProductRequest::WAREHOUSE_REJECTED)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('report_no', 'like', '%'.$keyword.'%')
                        ->orWhere('skus', 'like', '%'.$keyword.'%')
                        ->orWhere('procurement_skus', 'like', '%'.$keyword.'%')
                        ->orWhere('standard_chinese_name', 'like', '%'.$keyword.'%');
                });
            })
            ->with('attachments')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('portals.product-requests.operation.warehouse-issues', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => ['keyword' => $keyword, 'per_page' => $perPage],
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
        ]);
    }

    /** @return array{0: string, 1: int} */
    private function issueFilters(Request $request): array
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $perPage = (int) $request->query('per_page', 20);

        return [$keyword, in_array($perPage, [20, 50, 100], true) ? $perPage : 20];
    }

    public function operationCreate(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.operation.index');
        }

        return view('portals.product-requests.operation.form', [
            ...$this->viewContext($context),
            'actor' => $this->access->actorSnapshot($context['user']),
            'productRequest' => new ProductRequest(),
        ]);
    }

    public function operationStore(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $this->validateOperationData($request);
        $connection = config('admin.database.connection') ?: config('database.default');

        $productRequest = DB::connection($connection)->transaction(function () use ($context, $data, $request): ProductRequest {
            $actor = $this->access->actorSnapshot($context['user']);
            $productRequest = ProductRequest::query()->create([
                'report_no' => 'REQ-'.Str::upper((string) Str::uuid()),
                'reported_at' => now('Asia/Shanghai')->toDateString(),
                'status' => ProductRequest::STATUS_PENDING,
                'placement_review_status' => ProductRequest::REVIEW_UNFILLED,
                'operation_user_id' => $actor['user_id'],
                'operation_user_name' => $actor['user_name'],
                'operation_department_id' => $actor['department_id'],
                'operation_root_department_id' => $actor['root_department_id'],
                'operation_department_name' => $actor['department_name'],
                'operation_display_name' => $actor['display_name'],
                'skus' => $this->normalizeSkus($data['skus']),
                'operation_supplier_url' => $data['operation_supplier_url'],
                'has_stock' => $data['has_stock'],
                'operation_remark' => $data['operation_remark'] ?? null,
            ]);
            $productRequest->forceFill([
                'report_no' => 'REQ-'.now('Asia/Shanghai')->format('Ymd').'-'.str_pad((string) $productRequest->getKey(), 6, '0', STR_PAD_LEFT),
            ])->save();

            $this->workflow->replaceStageAttachments(
                $productRequest,
                ProductRequestAttachment::STAGE_OPERATION,
                [$request->file('product_image')],
                $context['user'],
            );
            $this->workflow->replaceStageAttachments(
                $productRequest,
                ProductRequestAttachment::STAGE_PURCHASE_ITEM,
                $request->file('purchase_item_images', []),
                $context['user'],
            );
            $this->workflow->log($productRequest, 'operation_submitted', $context['user']);

            return $productRequest;
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.operation_submitted', ['report_no' => $productRequest->report_no]),
            'product-requests.operation.index',
        );
    }

    public function operationDestroy(Request $request, ProductRequest $productRequest): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $visible = $this->access->applyOperationVisibility(ProductRequest::query(), $context['user'])
            ->whereKey($productRequest->getKey())
            ->exists();
        abort_unless($visible, 404);
        abort_unless($productRequest->status === ProductRequest::STATUS_PENDING, 403);

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($productRequest): void {
            foreach ($productRequest->attachments as $attachment) {
                if ($attachment->disk && $attachment->path) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                }

                $attachment->delete();
            }

            $productRequest->placementReviews()->delete();
            $productRequest->logs()->delete();
            $productRequest->delete();
        });

        return $this->modalSuccess($request, __('product_requests.operation.deleted'), 'product-requests.operation.index');
    }

    public function show(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->anyPortalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canViewRequestFromPortal($productRequest, $context['user'], $context['portal']), 403);

        $productRequest->load(['attachments', 'placementReviews', 'logs']);

        $viewData = [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'statusLabels' => $this->statusLabels(),
            'reviewStatusLabels' => $this->reviewStatusLabels(),
            'warehouseResultLabels' => $this->warehouseResultLabels(),
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
        ];

        if ($this->isModalRequest($request)) {
            return view('portals.product-requests.partials.detail-modal', $viewData);
        }

        return view('portals.product-requests.show', $viewData);
    }

    public function placementEdit(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canViewOperationRequest($productRequest, $context['user']), 403);
        abort_unless($this->access->canEditPlacement($productRequest, $context['user']), 403);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.operation.index');
        }

        return view('portals.product-requests.operation.placement-form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'reviewStatusLabels' => $this->reviewStatusLabels(),
        ]);
    }

    public function placementUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canViewOperationRequest($productRequest, $context['user']), 403);
        abort_unless($this->access->canEditPlacement($productRequest, $context['user']), 403);

        $data = $request->validate(['placement_link' => ['required', 'url', 'max:2048']]);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $data, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless($this->access->canEditPlacement($locked, $context['user']), 403);
            $locked->forceFill([
                'placement_link' => $data['placement_link'],
                'placement_review_status' => ProductRequest::REVIEW_PENDING,
            ])->save();

            ProductRequestPlacementReview::query()->create([
                'product_request_id' => $locked->getKey(),
                'submission_user_id' => $context['user']->getKey(),
                'submission_user_name' => $context['user']->name,
                'placement_link' => $data['placement_link'],
                'status' => ProductRequestPlacementReview::STATUS_PENDING,
                'submitted_at' => now(),
            ]);
            $this->workflow->log($locked, 'placement_submitted', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.placement_submitted'),
            'product-requests.operation.index',
        );
    }

    public function operationPendingCount(Request $request): JsonResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        $records = $this->access->applyOperationVisibility(
            ProductRequest::query()
                ->where('status', ProductRequest::STATUS_INSPECTION_PASSED)
                ->whereIn('placement_review_status', [ProductRequest::REVIEW_UNFILLED, ProductRequest::REVIEW_REJECTED]),
            $context['user'],
        )->get(['id', 'report_no']);

        return response()->json([
            'count' => $records->count(),
            'items' => $records->map(fn ($r) => ['id' => $r->id, 'report_no' => $r->report_no]),
        ]);
    }

    public function procurementPendingCount(Request $request): JsonResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        if (!$this->access->canInspectProcurement($context['user'])) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        $records = $this->access->applyProcurementVisibility(
            ProductRequest::query()->where('status', ProductRequest::STATUS_PENDING),
            $context['user'],
        )->get(['id', 'report_no']);

        return response()->json([
            'count' => $records->count(),
            'items' => $records->map(fn ($r) => ['id' => $r->id, 'report_no' => $r->report_no]),
        ]);
    }

    public function procurementIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);

        $filters = $this->filters($request);
        $requests = $this->applyFilters(
            $this->access->applyProcurementVisibility(ProductRequest::query(), $context['user']),
            $filters,
        )
            ->with('attachments')
            ->latest('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('portals.product-requests.procurement.index', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => $filters,
            'canInspect' => $this->access->canInspectProcurement($context['user']),
            'statusLabels' => $this->statusLabels(),
            'warehouseResultLabels' => $this->warehouseResultLabels(),
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
        ]);
    }

    public function procurementEdit(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);
        abort_unless($this->access->canViewProcurementRequest($productRequest, $context['user']), 403);
        // 已检验通过的记录允许再次编辑（覆盖保存）
        abort_unless(in_array($productRequest->status, [ProductRequest::STATUS_PENDING, ProductRequest::STATUS_INSPECTION_PASSED], true), 403);
        // 检验通过且仓库审核已通过的记录不允许再修改
        abort_if($productRequest->status === ProductRequest::STATUS_INSPECTION_PASSED && $productRequest->warehouse_acceptance_result === ProductRequest::WAREHOUSE_PASSED, 403);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.procurement.index');
        }

        $productRequest->load('attachments');

        return view('portals.product-requests.procurement.form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'actor' => $this->access->actorSnapshot($context['user']),
        ]);
    }

    public function procurementUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);
        abort_unless($this->access->canViewProcurementRequest($productRequest, $context['user']), 403);

        $data = $this->validateProcurementData($request, $productRequest);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $request, $data, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless($this->access->canViewProcurementRequest($locked, $context['user']), 403);
            // 允许已检验通过的记录再次编辑，第二次保存覆盖第一次结果
            abort_unless(in_array($locked->status, [ProductRequest::STATUS_PENDING, ProductRequest::STATUS_INSPECTION_PASSED], true), 403);
            // 检验通过且仓库审核已通过的记录不允许再修改
            abort_if($locked->status === ProductRequest::STATUS_INSPECTION_PASSED && $locked->warehouse_acceptance_result === ProductRequest::WAREHOUSE_PASSED, 403);

            $normalizedUrl = $this->workflow->normalizeSupplierUrl($data['supplier_url']);
            $linkHash = $this->workflow->supplierUrlHash($normalizedUrl);
            $reuseSourceId = (int) ($data['reuse_source_id'] ?? 0);
            // 前端删除的已保存附件 ID，仅限当前记录的采购阶段附件
            $removedImageIds = collect(explode(',', (string) ($data['removed_seller_images'] ?? '')))
                ->map(fn (string $value): int => (int) trim($value))
                ->filter(fn (int $value): bool => $value > 0)
                ->unique()
                ->values()
                ->all();
            $removedImageIds = $locked->attachments()
                ->where('stage', ProductRequestAttachment::STAGE_PROCUREMENT)
                ->whereIn('id', $removedImageIds)
                ->pluck('id')
                ->all();
            $checkedSkus = $this->splitSkus(implode(',', $data['selected_skus'] ?? []));
            unset($data['reuse_source_id'], $data['seller_images'], $data['selected_skus'], $data['link_skus'], $data['removed_seller_images']);
            $actor = $this->access->actorSnapshot($context['user']);
            // 链接级 SKU 集合只增不减：系统已录入的 SKU 始终保留在链接记录中，运营 SKU 勾选后并入；表单仅保存本次勾选的 SKU
            $linkSkus = $this->mergeLinkSkus($this->linkSkuSet($linkHash), $checkedSkus);
            $procurementSkus = implode(',', $checkedSkus);
            if ($linkSkus !== []) {
                $this->persistLinkSkuSet($linkHash, $normalizedUrl, $linkSkus);
            }
            $locked->fill([
                ...$data,
                'procurement_skus' => $procurementSkus,
                'supplier_url_normalized' => $normalizedUrl,
                'supplier_url_hash' => $linkHash,
                'status' => ProductRequest::STATUS_INSPECTION_PASSED,
                'erp_operator_id' => $actor['user_id'],
                'erp_operator_name' => $actor['user_name'],
                'erp_operator_department_name' => $actor['department_name'],
                'erp_operator_display_name' => $actor['display_name'],
                'inspected_at' => now(),
            ])->save();

            $newImages = $request->file('seller_images', []);
            // 删除后剩余 0 张视为必填校验失败；携带复用来源且来源已包含采购阶段图片时放行
            $totalAfter = max(0, $locked->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)->count() - count($removedImageIds)) + count($newImages);
            $reuseSource = null;
            if ($removedImageIds === [] && $reuseSourceId > 0) {
                $reuseSource = ProductRequest::query()->with('attachments')->find($reuseSourceId);
                if (!$reuseSource || $reuseSource->getKey() === $locked->getKey()) {
                    $reuseSource = null;
                }
            }
            if ($totalAfter === 0 && !($reuseSource && $reuseSource->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)->isNotEmpty())) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'seller_images' => [__('product_requests.uploads.required')],
                ]);
            }
            if ($removedImageIds !== []) {
                $this->workflow->deleteStageAttachments($locked, ProductRequestAttachment::STAGE_PROCUREMENT, $removedImageIds);
            }
            if ($newImages !== []) {
                $this->workflow->appendStageAttachments($locked, ProductRequestAttachment::STAGE_PROCUREMENT, $newImages, $context['user']);
            } elseif ($reuseSource) {
                $this->workflow->copyStageAttachments($reuseSource, $locked, ProductRequestAttachment::STAGE_PROCUREMENT, $context['user']);
                $locked->forceFill(['reused_from_product_request_id' => $reuseSource->getKey()])->save();
            }

            // 仓库验收不通过后，采购重新检验通过即重新进入仓库验收流程
            if ($locked->warehouse_acceptance_result === ProductRequest::WAREHOUSE_REJECTED) {
                $locked->forceFill([
                    'warehouse_acceptance_result' => ProductRequest::WAREHOUSE_PENDING_REVIEW,
                    'warehouse_reject_reason' => null,
                ])->save();
            }

            $this->workflow->log($locked, 'procurement_inspection_passed', $context['user'], [
                'reused_from_product_request_id' => $locked->reused_from_product_request_id,
            ]);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.procurement_completed'),
            'product-requests.procurement.index',
        );
    }

    public function procurementReuseCheck(Request $request): JsonResponse|Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);

        $data = $request->validate([
            'supplier_url' => ['required', 'string', 'max:2048'],
            'standard_chinese_name' => ['nullable', 'string', 'max:191'],
            'current_id' => ['nullable', 'integer'],
        ]);
        $normalizedUrl = $this->workflow->normalizeSupplierUrl($data['supplier_url']);
        $query = ProductRequest::query()
            ->whereNotNull('standard_chinese_name')
            ->with('attachments');
        if (!empty($data['current_id'])) {
            $query->where('id', '!=', $data['current_id']);
        }

        // 优先按 1688 链接精确匹配；链接查不到数据时回退按标准中文名模糊匹配
        $matchType = 'supplier_url';
        $records = (clone $query)
            ->where('supplier_url_hash', $this->workflow->supplierUrlHash($normalizedUrl))
            ->latest('id')
            ->limit(20)
            ->get();

        $keyword = trim((string) ($data['standard_chinese_name'] ?? ''));
        if ($records->isEmpty() && $keyword !== '') {
            $matchType = 'standard_chinese_name';
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $records = (clone $query)
                ->where('standard_chinese_name', 'like', '%'.$escaped.'%')
                ->latest('id')
                ->limit(20)
                ->get();
        }

        // 同一 1688 链接只展示一条记录：按链接聚合，取最新一条作为展示与复用来源
        $records = $records
            ->groupBy(fn (ProductRequest $record): string => $record->supplier_url_hash ?: ('record-'.$record->getKey()))
            ->map(fn ($group): ProductRequest => $group->sortByDesc('id')->first())
            ->values();

        return response()->json([
            'match_type' => $matchType,
            'records' => $records->map(fn (ProductRequest $record): array => $this->reusePayload($record))->values(),
        ]);
    }

    /** 输入 1688 链接时返回该链接对应的一套 SKU，供采购勾选 */
    public function procurementSkuSuggestions(Request $request): JsonResponse|Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return response()->json(['items' => []]);
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);

        $data = $request->validate([
            'supplier_url' => ['required', 'string', 'max:2048'],
            'current_id' => ['nullable', 'integer'],
        ]);
        $normalizedUrl = $this->workflow->normalizeSupplierUrl($data['supplier_url']);
        $hash = $this->workflow->supplierUrlHash($normalizedUrl);
        $skus = $this->splitSkus($this->linkSkuSet($hash, $data['current_id'] ?? null));
        // 当前记录运营已填写的 SKU 已展示在左侧，右侧只展示链接上其余已录入的 SKU
        if (!empty($data['current_id'])) {
            $ownSkus = $this->splitSkus((string) ProductRequest::query()->whereKey($data['current_id'])->value('skus'));
            $skus = array_values(array_diff($skus, $ownSkus));
        }

        return response()->json([
            'items' => $skus === [] ? [] : [[
                'id' => null,
                'report_no' => null,
                'skus' => $skus,
            ]],
        ]);
    }

    public function placementReviewPendingCount(Request $request): JsonResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        if (!$this->access->canReviewPlacement($context['user'])) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        // 与投放链接审核列表保持一致：同样不带可见性过滤
        $records = ProductRequest::query()
            ->where('placement_review_status', ProductRequest::REVIEW_PENDING)
            ->get(['id', 'report_no']);

        return response()->json([
            'count' => $records->count(),
            'items' => $records->map(fn ($r) => ['id' => $r->id, 'report_no' => $r->report_no]),
        ]);
    }

    public function placementReviewIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReviewPlacement($context['user']), 403);

        $filters = $this->filters($request);
        $requests = $this->applyFilters(ProductRequest::query(), $filters)
            ->with(['attachments', 'placementReviews'])
            ->latest('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('portals.product-requests.review.index', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => $filters,
            'reviewStatusLabels' => $this->reviewStatusLabels(),
        ]);
    }

    public function placementReviewEdit(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReviewPlacement($context['user']), 403);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.placement-reviews.index');
        }

        $productRequest->load('placementReviews');
        $pendingReview = $productRequest->placementReviews
            ->firstWhere('status', ProductRequestPlacementReview::STATUS_PENDING);
        abort_unless(
            $productRequest->placement_review_status === ProductRequest::REVIEW_PENDING && $pendingReview,
            403,
        );

        return view('portals.product-requests.review.form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'pendingReview' => $pendingReview,
        ]);
    }

    public function placementReviewUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReviewPlacement($context['user']), 403);

        $rejected = $request->input('result') === ProductRequestPlacementReview::STATUS_REJECTED;
        $data = $request->validate([
            'result' => ['required', Rule::in([
                ProductRequestPlacementReview::STATUS_APPROVED,
                ProductRequestPlacementReview::STATUS_REJECTED,
            ])],
            'remark' => [
                Rule::requiredIf($rejected),
                'nullable',
                'string',
                'max:20000',
                function (string $attribute, mixed $value, \Closure $fail) use ($rejected): void {
                    if ($rejected && $this->remarkIsEmpty($value)) {
                        $fail(__('product_requests.review.remark_required_hint'));
                    }
                },
            ],
        ]);
        $data['remark'] = $this->sanitizeRichRemark($data['remark'] ?? null);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $data, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless($locked->placement_review_status === ProductRequest::REVIEW_PENDING, 403);
            $review = ProductRequestPlacementReview::query()
                ->where('product_request_id', $locked->getKey())
                ->where('status', ProductRequestPlacementReview::STATUS_PENDING)
                ->latest('id')
                ->lockForUpdate()
                ->firstOrFail();
            $actor = $this->access->actorSnapshot($context['user']);
            $review->forceFill([
                'status' => $data['result'],
                'remark' => $data['remark'] ?? null,
                'reviewer_id' => $actor['user_id'],
                'reviewer_name' => $actor['user_name'],
                'reviewer_department_name' => $actor['department_name'],
                'reviewed_at' => now(),
            ])->save();
            $approved = $data['result'] === ProductRequestPlacementReview::STATUS_APPROVED;
            $locked->forceFill([
                'placement_review_status' => $approved ? ProductRequest::REVIEW_APPROVED : ProductRequest::REVIEW_REJECTED,
                'status' => $approved ? ProductRequest::STATUS_APPROVED : ProductRequest::STATUS_INSPECTION_PASSED,
            ])->save();
            $this->workflow->log($locked, $approved ? 'placement_approved' : 'placement_rejected', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            $data['result'] === ProductRequestPlacementReview::STATUS_APPROVED
                ? __('product_requests.messages.placement_approved')
                : __('product_requests.messages.placement_rejected'),
            'product-requests.placement-reviews.index',
        );
    }

    public function placementReviewImageStore(Request $request, ProductRequest $productRequest): JsonResponse|Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canReviewPlacement($context['user']), 403);

        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $attachment = $this->workflow->storeStageAttachment(
            $productRequest,
            ProductRequestAttachment::STAGE_REVIEW,
            $data['image'],
            $context['user'],
        );

        return response()->json([
            'url' => route('product-requests.attachments.show', [$productRequest, $attachment]),
        ]);
    }

    /** 富文本备注允许（含图片），此处仅保留白名单标签并清理事件属性 */
    private function sanitizeRichRemark(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $allowed = '<p><br><b><strong><i><em><u><s><ul><ol><li><span><div><img><a><blockquote>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $html) ?? $html;
        $html = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/i', '', $html) ?? $html;
        $html = preg_replace('/(<a\b[^>]*?)\shref\s*=\s*(["\']?)\s*javascript:[^"\'>]*\2/i', '$1', $html) ?? $html;

        return trim($html) !== '' ? trim($html) : null;
    }

    /** 判断富文本备注是否为空内容（忽略空标签、换行与空白，含图片视为非空） */
    private function remarkIsEmpty(mixed $value): bool
    {
        $html = (string) $value;
        if (stripos($html, '<img') !== false) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $text);

        return trim($text) === '';
    }

    public function warehouseIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::WAREHOUSE_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $filters = $this->filters($request);
        $requests = $this->applyFilters(
            ProductRequest::query()->whereIn('status', [ProductRequest::STATUS_INSPECTION_PASSED, ProductRequest::STATUS_APPROVED]),
            $filters,
        )->with('attachments')->latest('id')->paginate($filters['per_page'])->withQueryString();

        return view('portals.product-requests.warehouse.index', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => $filters,
            'warehouseResultLabels' => $this->warehouseResultLabels(),
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
        ]);
    }

    public function warehousePendingCount(Request $request): JsonResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::WAREHOUSE_PORTAL_CODE);
        if ($context instanceof Response) {
            return response()->json(['count' => 0, 'items' => []]);
        }

        // 与仓库验收列表保持一致：主状态为检验通过/审核通过，且验收结果为待审核
        $records = ProductRequest::query()
            ->whereIn('status', [ProductRequest::STATUS_INSPECTION_PASSED, ProductRequest::STATUS_APPROVED])
            ->where('warehouse_acceptance_result', ProductRequest::WAREHOUSE_PENDING_REVIEW)
            ->get(['id', 'report_no']);

        return response()->json([
            'count' => $records->count(),
            'items' => $records->map(fn ($r) => ['id' => $r->id, 'report_no' => $r->report_no]),
        ]);
    }

    public function warehouseEdit(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::WAREHOUSE_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless(in_array($productRequest->status, [ProductRequest::STATUS_INSPECTION_PASSED, ProductRequest::STATUS_APPROVED], true), 403);
        abort_if($productRequest->warehouse_acceptance_result === ProductRequest::WAREHOUSE_PASSED, 403);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.warehouse.index');
        }

        $productRequest->load('attachments');

        return view('portals.product-requests.warehouse.form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'actor' => $this->access->actorSnapshot($context['user']),
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
        ]);
    }

    public function warehouseUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::WAREHOUSE_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $this->validateWarehouseData($request, $productRequest);
        $rejected = $data['result'] === ProductRequest::WAREHOUSE_REJECTED;
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $data, $request, $context, $rejected): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless(in_array($locked->status, [ProductRequest::STATUS_INSPECTION_PASSED, ProductRequest::STATUS_APPROVED], true), 403);
            abort_if($locked->warehouse_acceptance_result === ProductRequest::WAREHOUSE_PASSED, 403);
            $actor = $this->access->actorSnapshot($context['user']);
            unset($data['warehouse_images']);
            $result = $data['result'];
            $rejectReason = $rejected ? ($data['warehouse_reject_reason'] ?? null) : null;
            unset($data['result'], $data['warehouse_reject_reason']);
            $locked->fill([
                ...$data,
                'warehouse_acceptance_result' => $result,
                'warehouse_reject_reason' => $rejectReason,
                // 审核不通过时回退到检验通过，商品信息检验可修改后重新提交审核
                'status' => $rejected ? ProductRequest::STATUS_INSPECTION_PASSED : $locked->status,
                'warehouse_operator_id' => $actor['user_id'],
                'warehouse_operator_name' => $actor['user_name'],
                'warehouse_operator_department_name' => $actor['department_name'],
                'warehouse_operator_display_name' => $actor['display_name'],
                'warehouse_processed_at' => now(),
            ])->save();
            $warehouseImages = $request->file('warehouse_images', []);
            if ($warehouseImages !== []) {
                $this->workflow->replaceStageAttachments($locked, ProductRequestAttachment::STAGE_WAREHOUSE, $warehouseImages, $context['user']);
            }
            $this->workflow->log($locked, $rejected ? 'warehouse_rejected' : 'warehouse_passed', $context['user'], [
                'warehouse_reject_reason' => $rejectReason,
            ]);
        });

        return $this->modalSuccess(
            $request,
            $rejected
                ? __('product_requests.messages.warehouse_rejected')
                : __('product_requests.messages.warehouse_passed'),
            'product-requests.warehouse.index',
        );
    }

    public function warehouseImageStore(Request $request, ProductRequest $productRequest): JsonResponse|Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::WAREHOUSE_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $attachment = $this->workflow->storeStageAttachment(
            $productRequest,
            ProductRequestAttachment::STAGE_WAREHOUSE_REMARK,
            $data['image'],
            $context['user'],
        );

        return response()->json([
            'url' => route('product-requests.attachments.show', [$productRequest, $attachment]),
        ]);
    }

    public function attachment(Request $request, ProductRequest $productRequest, ProductRequestAttachment $attachment): Response
    {
        $context = $this->anyPortalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless((int) $attachment->product_request_id === (int) $productRequest->getKey(), 404);
        abort_unless($this->access->canViewRequestFromPortal($productRequest, $context['user'], $context['portal']), 403);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return response()->file(Storage::disk($attachment->disk)->path($attachment->path), [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }

    /** @return array{portal:\App\Models\Portal,user:AdminUser}|Response */
    private function portalContext(Request $request, string $portalCode): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->access->assertPortal($context['portal'], $portalCode);

        return $context;
    }

    /** @return array{portal:\App\Models\Portal,user:AdminUser}|Response */
    private function anyPortalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless(in_array($context['portal']->code, [
            ProductRequestAccessService::OPERATION_PORTAL_CODE,
            ProductRequestAccessService::PROCUREMENT_PORTAL_CODE,
            ProductRequestAccessService::WAREHOUSE_PORTAL_CODE,
        ], true), 404);
        app()->setLocale('zh-CN');

        return $context;
    }

    private function isModalRequest(Request $request): bool
    {
        return $request->boolean('modal') || $request->header('X-Product-Request-Modal') === '1';
    }

    private function modalSuccess(Request $request, string $message, string $routeName): JsonResponse|RedirectResponse
    {
        if ($this->isModalRequest($request)) {
            return response()->json([
                'message' => $message,
                'redirect' => route($routeName),
            ]);
        }

        return redirect()->route($routeName)->with('success', $message);
    }

    /** @param array{portal:\App\Models\Portal,user:AdminUser} $context
     * @return array{portal:\App\Models\Portal,user:AdminUser}
     */
    private function viewContext(array $context): array
    {
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array{keyword:string,status:string,review_status:string,per_page:int} */
    private function filters(Request $request): array
    {
        $perPage = (int) $request->input('per_page', 20);
        if (!in_array($perPage, [20, 50, 100], true)) {
            $perPage = 20;
        }

        return [
            'keyword' => trim((string) $request->input('keyword', '')),
            'status' => in_array($request->input('status'), array_keys($this->statusLabels()), true) ? (string) $request->input('status') : '',
            'review_status' => in_array($request->input('review_status'), array_keys($this->reviewStatusLabels()), true) ? (string) $request->input('review_status') : '',
            'warehouse_status' => in_array($request->input('warehouse_status'), array_keys($this->warehouseResultLabels()), true) ? (string) $request->input('warehouse_status') : '',
            'per_page' => $perPage,
        ];
    }

    /** @param array{keyword:string,status:string,review_status:string,per_page:int} $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['keyword'] !== '') {
            $keyword = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['keyword']).'%';
            $query->where(function (Builder $search) use ($keyword): void {
                $search->where('report_no', 'like', $keyword)
                    ->orWhere('skus', 'like', $keyword)
                    ->orWhere('procurement_skus', 'like', $keyword)
                    ->orWhere('operation_display_name', 'like', $keyword)
                    ->orWhere('operation_department_name', 'like', $keyword)
                    ->orWhere('standard_chinese_name', 'like', $keyword)
                    ->orWhere('company_spu', 'like', $keyword)
                    ->orWhere('supplier_url', 'like', $keyword)
                    ->orWhere('operation_supplier_url', 'like', $keyword);
            });
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['review_status'] !== '') {
            $query->where('placement_review_status', $filters['review_status']);
        }
        if (($filters['warehouse_status'] ?? '') !== '') {
            $query->where('warehouse_acceptance_result', $filters['warehouse_status']);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function validateOperationData(Request $request): array
    {
        return $request->validate([
            'skus' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $skus = collect(preg_split('/[\n,，;；\t]+/u', (string) $value))
                        ->map(fn (string $sku): string => trim($sku))
                        ->filter(fn (string $sku): bool => $sku !== '');

                    if ($skus->isEmpty()) {
                        $fail('请至少填写一个 SKU。');

                        return;
                    }
                    if ($skus->count() > 50) {
                        $fail('SKU 最多 50 个。');

                        return;
                    }
                    if ($skus->unique()->count() !== $skus->count()) {
                        $fail('SKU 不能重复。');
                    }
                },
            ],
            'operation_supplier_url' => [
                'required',
                'string',
                'max:2048',
                'regex:#^https?://#i',
            ],
            'has_stock' => ['required', Rule::in(['yes', 'no'])],
            'product_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'purchase_item_images' => ['required', 'array', 'min:1', 'max:6'],
            'purchase_item_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'operation_remark' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** 将换行/逗号/分号分隔的 SKU 规范化为英文逗号分隔 */
    private function normalizeSkus(string $value): string
    {
        return collect(preg_split('/[\n,，;；\t]+/u', $value))
            ->map(fn (string $sku): string => trim($sku))
            ->filter(fn (string $sku): bool => $sku !== '')
            ->unique()
            ->values()
            ->implode(',');
    }

    /** @return array<string, mixed> */
    private function validateProcurementData(Request $request, ?ProductRequest $productRequest = null): array
    {
        $hasExistingImages = $productRequest
            ? $productRequest->loadMissing('attachments')->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)->isNotEmpty()
            : false;
        $reusedImages = (int) $request->input('reuse_source_id', 0) > 0;

        return $request->validate([
            'standard_chinese_name' => ['required', 'string', 'max:191'],
            'supplier_url' => ['required', 'string', 'max:2048', 'regex:#^https?://#i'],
            'company_spu' => ['nullable', 'string', 'max:191'],
            'sku_sequence' => ['nullable', 'string', 'max:191'],
            'standard_sku' => ['nullable', 'string', 'max:191'],
            'stock_status' => ['nullable', Rule::in(['yes', 'no'])],
            'minimum_order_quantity' => ['nullable', 'string', 'max:255'],
            'minimum_purchase_price_rmb' => ['required', 'string', 'max:100'],
            'pcl_product_no' => ['required', 'string', 'max:100'],
            'estimated_shipping_weight_g' => ['required', 'string', 'max:100'],
            'outer_package_size' => ['nullable', 'string', 'max:191'],
            'large_volume_weight_g' => ['nullable', 'string', 'max:100'],
            'erp_binding_status' => ['nullable', Rule::in(['unbound', 'bound'])],
            'selected_skus' => ['nullable', 'array', 'max:100'],
            'selected_skus.*' => ['string', 'max:500'],
            'link_skus' => ['nullable', 'string', 'max:20000'],
            // 卖家图 / 实拍图必填：已保存附件或复用来源已带图时无需重复上传
            'seller_images' => ($reusedImages || $hasExistingImages) ? ['nullable', 'array', 'max:3'] : ['required', 'array', 'max:3'],
            'seller_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'reuse_source_id' => ['nullable', 'integer'],
            'removed_seller_images' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateWarehouseData(Request $request, ProductRequest $productRequest): array
    {
        $warehouseImages = $productRequest->loadMissing('attachments')->attachmentsFor(ProductRequestAttachment::STAGE_WAREHOUSE);
        $rejected = $request->input('result') === ProductRequest::WAREHOUSE_REJECTED;

        $data = $request->validate([
            'result' => ['required', Rule::in([ProductRequest::WAREHOUSE_PASSED, ProductRequest::WAREHOUSE_REJECTED])],
            'actual_shipping_weight_g' => ['required', 'string', 'max:100'],
            'package_length_cm' => ['nullable', 'string', 'max:100', 'required_with:package_width_cm,package_height_cm'],
            'package_width_cm' => ['nullable', 'string', 'max:100', 'required_with:package_length_cm,package_height_cm'],
            'package_height_cm' => ['nullable', 'string', 'max:100', 'required_with:package_length_cm,package_width_cm'],
            'warehouse_reject_reason' => [Rule::requiredIf($rejected), 'nullable', Rule::in(array_keys($this->warehouseRejectReasonLabels()))],
            'warehouse_remark' => [
                Rule::requiredIf($rejected),
                'nullable',
                'string',
                'max:20000',
                function (string $attribute, mixed $value, \Closure $fail) use ($rejected): void {
                    if ($rejected && $this->remarkIsEmpty($value)) {
                        $fail(__('product_requests.warehouse.remark_required_hint'));
                    }
                },
            ],
            'warehouse_images' => [$warehouseImages->isEmpty() ? 'required' : 'nullable', 'array', 'max:3'],
            'warehouse_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $data['warehouse_remark'] = $this->sanitizeRichRemark($data['warehouse_remark'] ?? null);

        return $data;
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            ProductRequest::STATUS_PENDING => __('product_requests.status.pending'),
            ProductRequest::STATUS_INSPECTION_PASSED => __('product_requests.status.inspection_passed'),
            ProductRequest::STATUS_APPROVED => __('product_requests.status.approved'),
        ];
    }

    /** @return array<string, string> */
    private function reviewStatusLabels(): array
    {
        return [
            ProductRequest::REVIEW_UNFILLED => __('product_requests.review_status.unfilled'),
            ProductRequest::REVIEW_PENDING => __('product_requests.review_status.pending'),
            ProductRequest::REVIEW_REJECTED => __('product_requests.review_status.rejected'),
            ProductRequest::REVIEW_APPROVED => __('product_requests.review_status.approved'),
        ];
    }

    /** @return array<string, string> */
    private function warehouseResultLabels(): array
    {
        return [
            ProductRequest::WAREHOUSE_PENDING_REVIEW => __('product_requests.warehouse_result.pending_review'),
            ProductRequest::WAREHOUSE_PASSED => __('product_requests.warehouse_result.passed'),
            ProductRequest::WAREHOUSE_REJECTED => __('product_requests.warehouse_result.rejected'),
        ];
    }

    /** @return array<string, string> */
    private function warehouseRejectReasonLabels(): array
    {
        return [
            ProductRequest::WAREHOUSE_REJECT_REASON_QUALITY => __('product_requests.warehouse_reason.quality'),
            ProductRequest::WAREHOUSE_REJECT_REASON_WEIGHT => __('product_requests.warehouse_reason.weight'),
            ProductRequest::WAREHOUSE_REJECT_REASON_BOTH => __('product_requests.warehouse_reason.both'),
        ];
    }

    /** 读取 1688 链接对应的唯一一套 SKU（以链接表为准，无记录时回退同链接历史数据） */
    private function linkSkuSet(string $hash, ?int $excludeId = null): string
    {
        $stored = ProductRequestLinkSku::query()->where('supplier_url_hash', $hash)->value('skus');
        if (is_string($stored) && trim($stored) !== '') {
            return implode(',', $this->splitSkus($stored));
        }

        return implode(',', $this->legacyLinkSkus($hash, $excludeId));
    }

    /** @return list<string> */
    private function legacyLinkSkus(string $hash, ?int $excludeId = null): array
    {
        return ProductRequest::query()
            ->where('supplier_url_hash', $hash)
            ->when($excludeId, fn (Builder $query, int $id): Builder => $query->where('id', '!=', $id))
            ->get(['skus', 'procurement_skus'])
            ->flatMap(fn (ProductRequest $record): array => $this->splitSkus((string) $record->skus.','.(string) $record->procurement_skus))
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $skus */
    private function persistLinkSkuSet(string $hash, ?string $url, array $skus): void
    {
        ProductRequestLinkSku::query()->updateOrCreate(
            ['supplier_url_hash' => $hash],
            ['supplier_url' => $url, 'skus' => implode(',', $skus)],
        );
    }

    /** @return list<string> */
    private function splitSkus(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $sku): string => trim($sku))
            ->filter(fn (string $sku): bool => $sku !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 计算链接上的 SKU 集合：只增不减，历史 SKU 与本次勾选项全部保留。
     *
     * @param  list<string>  $checked   当前勾选项
     * @return list<string>
     */
    private function mergeLinkSkus(string $current, array $checked): array
    {
        return array_values(array_unique(array_merge($this->splitSkus($current), $checked)));
    }

    /** @return array<string, mixed> */
    private function reusePayload(ProductRequest $productRequest): array
    {
        $cover = $productRequest->attachmentsFor(ProductRequestAttachment::STAGE_OPERATION)->first();

        return [
            'id' => $productRequest->getKey(),
            'report_no' => $productRequest->report_no,
            'cover_url' => $cover ? route('product-requests.attachments.show', [$productRequest, $cover]) : null,
            'standard_chinese_name' => $productRequest->standard_chinese_name,
            'pcl_product_no' => $productRequest->pcl_product_no,
            'supplier_url' => $productRequest->supplier_url,
            'company_spu' => $productRequest->company_spu,
            'sku_sequence' => $productRequest->sku_sequence,
            'standard_sku' => $productRequest->standard_sku,
            'stock_status' => $productRequest->stock_status,
            'minimum_order_quantity' => $productRequest->minimum_order_quantity,
            'minimum_purchase_price_rmb' => $productRequest->minimum_purchase_price_rmb,
            'estimated_shipping_weight_g' => $productRequest->estimated_shipping_weight_g,
            'outer_package_size' => $productRequest->outer_package_size,
            'large_volume_weight_g' => $productRequest->large_volume_weight_g,
            'erp_binding_status' => $productRequest->erp_binding_status,
            'skus' => $this->splitSkus($this->linkSkuSet((string) $productRequest->supplier_url_hash)),
            'seller_images' => $productRequest->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)
                ->map(fn (ProductRequestAttachment $attachment): array => [
                    'url' => route('product-requests.attachments.show', [$productRequest, $attachment]),
                    'name' => $attachment->original_name,
                ])->values()->all(),
        ];
    }
}
