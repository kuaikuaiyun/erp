<?php

namespace App\Portals\ProductRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ProductRequest;
use App\Models\ProductRequestAttachment;
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
        )->with('attachments')->latest('id')->paginate($filters['per_page'])->withQueryString();

        return view('portals.product-requests.operation.index', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'filters' => $filters,
            'statusLabels' => $this->statusLabels(),
            'reviewStatusLabels' => $this->reviewStatusLabels(),
        ]);
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
            $this->workflow->log($productRequest, 'operation_submitted', $context['user']);

            return $productRequest;
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.operation_submitted', ['report_no' => $productRequest->report_no]),
            'product-requests.operation.index',
        );
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
            'warehouseConfirmationLabels' => $this->warehouseConfirmationLabels(),
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
                ->where('placement_review_status', ProductRequest::REVIEW_UNFILLED),
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
        abort_unless($productRequest->status === ProductRequest::STATUS_PENDING, 403);

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

        $data = $this->validateProcurementData($request);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $request, $data, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless($this->access->canViewProcurementRequest($locked, $context['user']), 403);
            abort_unless($locked->status === ProductRequest::STATUS_PENDING, 403);

            $normalizedUrl = $this->workflow->normalizeSupplierUrl($data['supplier_url']);
            $reuseSourceId = (int) ($data['reuse_source_id'] ?? 0);
            $selectedSkus = collect($data['selected_skus'] ?? [])
                ->map(fn (string $sku): string => trim($sku))
                ->filter(fn (string $sku): bool => $sku !== '')
                ->unique()
                ->values();
            unset($data['reuse_source_id'], $data['seller_images'], $data['selected_skus']);
            $actor = $this->access->actorSnapshot($context['user']);
            // 采购勾选的 SKU 单独存入 procurement_skus，不覆盖运营填写的 skus（勾选为空则保留原值）
            $procurementSkus = $selectedSkus->isNotEmpty()
                ? $selectedSkus->implode(',')
                : $locked->procurement_skus;
            $locked->fill([
                ...$data,
                'procurement_skus' => $procurementSkus,
                'supplier_url_normalized' => $normalizedUrl,
                'supplier_url_hash' => $this->workflow->supplierUrlHash($normalizedUrl),
                'status' => ProductRequest::STATUS_INSPECTION_PASSED,
                'erp_operator_id' => $actor['user_id'],
                'erp_operator_name' => $actor['user_name'],
                'erp_operator_department_name' => $actor['department_name'],
                'erp_operator_display_name' => $actor['display_name'],
                'inspected_at' => now(),
            ])->save();

            $newImages = $request->file('seller_images', []);
            if ($newImages !== []) {
                $this->workflow->replaceStageAttachments($locked, ProductRequestAttachment::STAGE_PROCUREMENT, $newImages, $context['user']);
            } elseif ($reuseSourceId > 0) {
                $source = ProductRequest::query()->with('attachments')->find($reuseSourceId);
                if ($source && $source->getKey() !== $locked->getKey()) {
                    $this->workflow->copyStageAttachments($source, $locked, ProductRequestAttachment::STAGE_PROCUREMENT, $context['user']);
                    $locked->forceFill(['reused_from_product_request_id' => $source->getKey()])->save();
                }
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

        return response()->json([
            'match_type' => $matchType,
            'records' => $records->map(fn (ProductRequest $record): array => $this->reusePayload($record))->values(),
        ]);
    }

    /** 输入 1688 链接时返回系统中已录入该链接的 SKU，供采购多选合并 */
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

        $records = ProductRequest::query()
            ->where('supplier_url_hash', $this->workflow->supplierUrlHash($normalizedUrl))
            ->when($data['current_id'] ?? null, fn ($query, int $id) => $query->where('id', '!=', $id))
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereNotNull('skus')->where('skus', '!=', ''))
                ->orWhere(fn ($q) => $q->whereNotNull('procurement_skus')->where('procurement_skus', '!=', '')))
            ->latest('id')
            ->limit(20)
            ->get(['id', 'report_no', 'skus', 'procurement_skus']);

        return response()->json([
            'items' => $records->map(fn (ProductRequest $record): array => [
                'id' => $record->getKey(),
                'report_no' => $record->report_no,
                'skus' => collect([(string) $record->skus, (string) $record->procurement_skus])
                    ->flatMap(fn (string $chunk): array => explode(',', $chunk))
                    ->map(fn (string $sku): string => trim($sku))
                    ->filter(fn (string $sku): bool => $sku !== '')
                    ->unique()
                    ->values()
                    ->all(),
            ])->values()->all(),
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

        $data = $request->validate([
            'result' => ['required', Rule::in([
                ProductRequestPlacementReview::STATUS_APPROVED,
                ProductRequestPlacementReview::STATUS_REJECTED,
            ])],
            'remark' => [Rule::requiredIf($request->input('result') === ProductRequestPlacementReview::STATUS_REJECTED), 'nullable', 'string', 'max:2000'],
        ]);
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
            'warehouseConfirmationLabels' => $this->warehouseConfirmationLabels(),
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

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.warehouse.index');
        }

        $productRequest->load('attachments');

        return view('portals.product-requests.warehouse.form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'actor' => $this->access->actorSnapshot($context['user']),
            'warehouseResultLabels' => $this->warehouseResultLabels(),
            'warehouseConfirmationLabels' => $this->warehouseConfirmationLabels(),
        ]);
    }

    public function warehouseUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::WAREHOUSE_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $this->validateWarehouseData($request, $productRequest);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $data, $request, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless(in_array($locked->status, [ProductRequest::STATUS_INSPECTION_PASSED, ProductRequest::STATUS_APPROVED], true), 403);
            $actor = $this->access->actorSnapshot($context['user']);
            unset($data['warehouse_images']);
            $locked->fill([
                ...$data,
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
            $this->workflow->log($locked, 'warehouse_processed', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.warehouse_completed'),
            'product-requests.warehouse.index',
        );
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
    private function validateProcurementData(Request $request): array
    {
        return $request->validate([
            'standard_chinese_name' => ['required', 'string', 'max:191'],
            'supplier_url' => ['required', 'string', 'max:2048', 'regex:#^https?://#i'],
            'company_spu' => ['nullable', 'string', 'max:191'],
            'sku_sequence' => ['nullable', 'string', 'max:191'],
            'standard_sku' => ['nullable', 'string', 'max:191'],
            'stock_status' => ['nullable', Rule::in(['yes', 'no'])],
            'minimum_order_quantity' => ['nullable', 'string', 'max:255'],
            'minimum_purchase_price_rmb' => ['required', 'string', 'max:100'],
            'estimated_shipping_weight_g' => ['required', 'string', 'max:100'],
            'outer_package_size' => ['nullable', 'string', 'max:191'],
            'large_volume_weight_g' => ['nullable', 'string', 'max:100'],
            'erp_binding_status' => ['nullable', Rule::in(['unbound', 'bound'])],
            'selected_skus' => ['nullable', 'array', 'max:100'],
            'selected_skus.*' => ['string', 'max:500'],
            'seller_images' => ['nullable', 'array', 'max:3'],
            'seller_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'reuse_source_id' => ['nullable', 'integer'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateWarehouseData(Request $request, ProductRequest $productRequest): array
    {
        $warehouseImages = $productRequest->loadMissing('attachments')->attachmentsFor(ProductRequestAttachment::STAGE_WAREHOUSE);

        return $request->validate([
            'actual_shipping_weight_g' => ['required', 'string', 'max:100'],
            'package_length_cm' => ['nullable', 'string', 'max:100', 'required_with:package_width_cm,package_height_cm'],
            'package_width_cm' => ['nullable', 'string', 'max:100', 'required_with:package_length_cm,package_height_cm'],
            'package_height_cm' => ['nullable', 'string', 'max:100', 'required_with:package_length_cm,package_width_cm'],
            'warehouse_acceptance_result' => ['required', Rule::in(array_keys($this->warehouseResultLabels()))],
            'warehouse_confirmation' => ['required', Rule::in(array_keys($this->warehouseConfirmationLabels()))],
            'warehouse_remark' => [Rule::requiredIf($request->input('warehouse_confirmation') === ProductRequest::WAREHOUSE_RETURN_FOR_SUPPLEMENT), 'nullable', 'string', 'max:2000'],
            'warehouse_images' => [$warehouseImages->isEmpty() ? 'required' : 'nullable', 'array', 'max:3'],
            'warehouse_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
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
        ];
    }

    /** @return array<string, string> */
    private function warehouseConfirmationLabels(): array
    {
        return [
            ProductRequest::WAREHOUSE_CONFIRMED => __('product_requests.warehouse_confirmation.confirmed'),
            ProductRequest::WAREHOUSE_RETURN_FOR_SUPPLEMENT => __('product_requests.warehouse_confirmation.return_for_supplement'),
        ];
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
            'seller_images' => $productRequest->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)
                ->map(fn (ProductRequestAttachment $attachment): array => [
                    'url' => route('product-requests.attachments.show', [$productRequest, $attachment]),
                    'name' => $attachment->original_name,
                ])->values()->all(),
        ];
    }
}
