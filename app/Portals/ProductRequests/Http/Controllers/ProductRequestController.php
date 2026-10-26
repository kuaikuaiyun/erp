<?php

namespace App\Portals\ProductRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\ProductRequest;
use App\Models\ProductRequestAttachment;
use App\Models\ProductRequestDraft;
use App\Models\ProductRequestLinkSku;
use App\Models\ProductRequestPlacementReview;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\ProductRequests\ProductRequestAccessService;
use App\Portals\ProductRequests\ProductRequestWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        $drafts = ProductRequestDraft::query()
            ->where('stage', ProductRequestDraft::STAGE_OPERATION)
            ->where('user_id', $context['user']->getKey())
            ->latest('id')
            ->get();

        return view('portals.product-requests.operation.index', [
            ...$this->viewContext($context),
            'requests' => $requests,
            'drafts' => $drafts,
            'filters' => $filters,
            'statusLabels' => $this->statusLabels(),
            'reviewStatusLabels' => $this->reviewStatusLabels(),
            'rectificationLabels' => $this->rectificationLabels(),
            'warehouseResultLabels' => $this->warehouseResultLabels(),
        ]);
    }

    /** 查重工具：按产品名或 1688 链接查询 PCL SKU 管理维护的链接 SKU 库 */
    public function operationDedupeIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $keyword = trim((string) $request->input('keyword', ''));
        $supplierUrl = trim((string) $request->input('supplier_url', ''));
        $results = [];
        $matchType = null;

        // 链接与品名不分优先级：两者都填时各自查询，并把结果合并展示
        $linkResults = [];
        if ($supplierUrl !== '') {
            // 1688 链接精确匹配：只查链接 SKU 库
            $normalizedUrl = $this->workflow->normalizeSupplierUrl($supplierUrl);
            $hash = $this->workflow->supplierUrlHash($normalizedUrl);

            $linkSku = ProductRequestLinkSku::query()->where('supplier_url_hash', $hash)->first();
            if ($linkSku) {
                $linkResults[] = $this->linkSkuReusePayload($linkSku);
            }
        }

        $keywordResults = $keyword !== '' ? $this->dedupeKeywordResults($keyword) : [];

        // 合并去重：同一 1688 链接只展示一条，链接命中的排在前面
        $seenUrls = [];
        foreach (array_merge($linkResults, $keywordResults) as $item) {
            $key = (string) ($item['supplier_url'] ?? '');
            if ($key !== '') {
                if (isset($seenUrls[$key])) {
                    continue;
                }
                $seenUrls[$key] = true;
            }
            $results[] = $item;
        }

        if ($linkResults !== [] && $keywordResults !== []) {
            $matchType = 'mixed';
        } elseif ($linkResults !== []) {
            $matchType = 'supplier_url';
        } elseif ($keywordResults !== []) {
            $matchType = 'keyword';
        }

        return view('portals.product-requests.operation.dedupe', [
            ...$this->viewContext($context),
            'keyword' => $keyword,
            'supplierUrl' => $supplierUrl,
            'results' => $results,
            'matchType' => $matchType,
        ]);
    }

    /** 关键词模糊查询：只查 product_request_link_skus.product_name，支持两字以上的关联匹配 */
    private function dedupeKeywordResults(string $keyword): array
    {
        return $this->linkSkuNameMatches($keyword, 30)
            ->map(fn (ProductRequestLinkSku $row): array => $this->linkSkuReusePayload($row))
            ->values()
            ->all();
    }

    /**
     * 产品名关联搜索：把关键词切成两字（英文按整词）片段，
     * 只要产品名包含任意片段就算命中，命中片段越多排越前。
     */
    private function linkSkuNameMatches(string $keyword, int $limit = 20): Collection
    {
        $terms = $this->linkSkuNameSearchTerms($keyword);
        if ($terms === []) {
            return collect();
        }

        $rows = ProductRequestLinkSku::query()
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
                    $query->orWhere('product_name', 'like', '%'.$escaped.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->filter(fn (ProductRequestLinkSku $row): bool => $this->splitSkus((string) $row->skus) !== []);

        $rows = $rows->sortByDesc(function (ProductRequestLinkSku $row) use ($terms): string {
            $name = mb_strtolower((string) $row->product_name);
            $score = 0;
            foreach ($terms as $term) {
                if ($name !== '' && mb_strpos($name, mb_strtolower($term)) !== false) {
                    $score++;
                }
            }

            return sprintf('%03d-%012d', $score, (int) $row->getKey());
        })->values();

        return $rows->unique('supplier_url_hash')->take($limit)->values();
    }

    /** 把关键词拆成用于关联匹配的片段：中文按两字滑动窗口，英文按整词 */
    private function linkSkuNameSearchTerms(string $keyword): array
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword);
        if ($keyword === '') {
            return [];
        }

        $terms = [];
        foreach (preg_split('/[\s,，、;；\/|]+/u', $keyword) ?: [] as $chunk) {
            $chunk = trim($chunk);
            $length = mb_strlen($chunk, 'UTF-8');
            if ($length === 0) {
                continue;
            }
            if ($length <= 2) {
                $terms[] = $chunk;
                continue;
            }
            // 纯英文/数字片段按整词匹配，避免被切碎后命中大量无关产品
            if (preg_match('/^[\x20-\x7E]+$/', $chunk) === 1) {
                $terms[] = $chunk;
                continue;
            }
            for ($i = 0; $i <= $length - 2; $i++) {
                $terms[] = mb_substr($chunk, $i, 2, 'UTF-8');
            }
        }

        $terms = array_values(array_unique($terms));

        return array_slice($terms, 0, 12);
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

        $draft = $this->resolveDraft($request, $context['user']);
        $data = $this->validateOperationData($request, null, $draft);
        $connection = config('admin.database.connection') ?: config('database.default');

        $productRequest = DB::connection($connection)->transaction(function () use ($context, $data, $request, $draft): ProductRequest {
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
                $request->hasFile('product_image') ? [$request->file('product_image')] : [],
                $context['user'],
            );
            if (!$request->hasFile('product_image') && $draft) {
                $draftProductImages = $this->draftItems($draft, 'images');
                if ($draftProductImages !== []) {
                    $this->workflow->adoptStageAttachments(
                        $productRequest,
                        ProductRequestAttachment::STAGE_OPERATION,
                        [$draftProductImages[0]],
                        $context['user'],
                    );
                }
            }

            // 草稿中保留的图片先接管，再追加本次新上传的图片，总数不超过 6 张
            $keptPurchaseImages = $draft ? $this->draftSplitItems($draft, $request, 'purchase_images', 'purchase_images_removed')[0] : [];
            if ($keptPurchaseImages !== []) {
                $this->workflow->adoptStageAttachments(
                    $productRequest,
                    ProductRequestAttachment::STAGE_PURCHASE_ITEM,
                    $keptPurchaseImages,
                    $context['user'],
                );
            }
            $newPurchaseImages = array_slice(
                (array) $request->file('purchase_item_images', []),
                0,
                max(0, 6 - count($keptPurchaseImages)),
            );
            foreach ($newPurchaseImages as $file) {
                $this->workflow->storeStageAttachment(
                    $productRequest,
                    ProductRequestAttachment::STAGE_PURCHASE_ITEM,
                    $file,
                    $context['user'],
                );
            }
            $this->workflow->log($productRequest, 'operation_submitted', $context['user']);

            if ($draft) {
                $this->deleteDraft($draft);
            }

            return $productRequest;
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.operation_submitted', ['report_no' => $productRequest->report_no]),
            'product-requests.operation.index',
        );
    }

    /** 保存需求提报草稿：不进入流程，仅本人可见，可随时回来继续编辑 */
    public function operationDraftStore(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $user = $context['user'];
        $data = $request->validate([
            'skus' => ['nullable', 'string', 'max:2048'],
            'operation_supplier_url' => ['nullable', 'string', 'max:2048'],
            'has_stock' => ['nullable', Rule::in(['yes', 'no'])],
            'operation_remark' => ['nullable', 'string', 'max:2000'],
            'product_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'purchase_item_images' => ['nullable', 'array', 'max:6'],
            'purchase_item_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $existing = $this->resolveDraft($request, $user);
        $draft = $existing ?? new ProductRequestDraft();
        $draft->user_id = $user->getKey();
        $draft->stage = ProductRequestDraft::STAGE_OPERATION;
        $draft->product_request_id = null;
        $draft->skus = $this->normalizeSkus((string) ($data['skus'] ?? ''));
        $draft->operation_supplier_url = trim((string) ($data['operation_supplier_url'] ?? ''));
        $draft->has_stock = $data['has_stock'] ?? null;
        $draft->operation_remark = $data['operation_remark'] ?? null;

        $images = $existing ? $this->draftItems($existing, 'images') : [];
        if ($request->hasFile('product_image')) {
            $this->deleteDraftImageFiles($images);
            $images = [$this->storeDraftImage($request->file('product_image'))];
        }
        $draft->images = $images === [] ? null : $images;

        [$keptPurchaseImages, $droppedPurchaseImages] = $existing
            ? $this->draftSplitItems($existing, $request, 'purchase_images', 'purchase_images_removed')
            : [[], []];
        $this->deleteDraftImageFiles($droppedPurchaseImages);
        foreach (array_slice((array) $request->file('purchase_item_images', []), 0, max(0, 6 - count($keptPurchaseImages))) as $file) {
            $keptPurchaseImages[] = $this->storeDraftImage($file);
        }
        $draft->purchase_images = $keptPurchaseImages === [] ? null : $keptPurchaseImages;

        $draft->save();

        return $this->modalSuccess($request, __('product_requests.operation.draft_saved'), 'product-requests.operation.index');
    }

    /** 打开草稿继续编辑 */
    public function operationDraftEdit(Request $request, ProductRequestDraft $draft): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $this->assertDraftOwner($draft, $context['user'], ProductRequestDraft::STAGE_OPERATION);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.operation.index');
        }

        return view('portals.product-requests.operation.form', [
            ...$this->viewContext($context),
            'actor' => $this->access->actorSnapshot($context['user']),
            'productRequest' => new ProductRequest(),
            'draft' => $draft,
        ]);
    }

    public function operationDraftDestroy(Request $request, ProductRequestDraft $draft): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $this->assertDraftOwner($draft, $context['user'], ProductRequestDraft::STAGE_OPERATION);

        $this->deleteDraft($draft);

        return $this->modalSuccess($request, __('product_requests.operation.draft_deleted'), 'product-requests.operation.index');
    }

    /** 读取草稿图片（仅草稿归属人可见） */
    public function operationDraftImage(Request $request, ProductRequestDraft $draft, string $type, int $index): Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $this->assertDraftOwner($draft, $context['user'], ProductRequestDraft::STAGE_OPERATION);
        abort_unless(in_array($type, ['product', 'purchase'], true), 404);

        $items = $this->draftItems($draft, $type === 'product' ? 'images' : 'purchase_images');
        $item = $items[$index] ?? null;
        $path = is_array($item) ? (string) ($item['path'] ?? '') : '';
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        return $this->cachedImageResponse(
            Storage::disk('local')->path($path),
            ($item['mime_type'] ?? '') ?: null,
        );
    }

    /** 校验草稿归属与类型，避免跨用户、跨模块操作草稿 */
    private function assertDraftOwner(ProductRequestDraft $draft, AdminUser $user, string $stage): void
    {
        abort_unless($draft->stage === $stage && (int) $draft->user_id === (int) $user->getKey(), 403);
    }

    /** 读取草稿图片：按草稿类型匹配对应门户，且仅草稿归属人可见 */
    public function draftImage(Request $request, ProductRequestDraft $draft, string $type, int $index): Response
    {
        $context = $this->portalContext($request, $draft->stage === ProductRequestDraft::STAGE_PROCUREMENT
            ? ProductRequestAccessService::PROCUREMENT_PORTAL_CODE
            : ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $this->assertDraftOwner($draft, $context['user'], $draft->stage);

        $items = $this->draftItems($draft, $type === 'purchase' ? 'purchase_images' : 'images');
        $item = $items[$index] ?? null;
        $path = is_array($item) ? (string) ($item['path'] ?? '') : '';
        abort_unless($path !== '' && Storage::disk('local')->exists($path), 404);

        return $this->cachedImageResponse(
            Storage::disk('local')->path($path),
            ($item['mime_type'] ?? '') ?: null,
        );
    }

    private function resolveDraft(Request $request, AdminUser $user): ?ProductRequestDraft
    {
        $id = (int) $request->input('draft_id', 0);
        if ($id <= 0) {
            return null;
        }

        return ProductRequestDraft::query()
            ->where('stage', ProductRequestDraft::STAGE_OPERATION)
            ->where('user_id', $user->getKey())
            ->find($id);
    }

    /** @return array<int, array<string, mixed>> */
    private function draftItems(ProductRequestDraft $draft, string $field): array
    {
        return array_values(array_filter((array) ($draft->{$field} ?? []), 'is_array'));
    }

    /** 拆出草稿中保留与移除的图片 @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
    private function draftSplitItems(ProductRequestDraft $draft, Request $request, string $field, string $removedField): array
    {
        $removed = collect(preg_split('/[,，;；\s]+/u', (string) $request->input($removedField, '')))
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '' && ctype_digit($value))
            ->map(fn (string $value): int => (int) $value)
            ->all();

        $kept = [];
        $dropped = [];
        foreach ($this->draftItems($draft, $field) as $index => $item) {
            if (in_array($index, $removed, true)) {
                $dropped[] = $item;
            } else {
                $kept[] = $item;
            }
        }

        return [$kept, $dropped];
    }

    /** @return array{path: string, original_name: string, mime_type: string|null, size: int} */
    private function storeDraftImage(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension()) ?: 'img';
        $path = 'product-request-drafts/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return [
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function deleteDraftImageFiles(array $items): void
    {
        foreach ($items as $item) {
            $path = is_array($item) ? (string) ($item['path'] ?? '') : '';
            if ($path !== '') {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function deleteDraft(ProductRequestDraft $draft): void
    {
        $this->deleteDraftImageFiles($this->draftItems($draft, 'images'));
        $this->deleteDraftImageFiles($this->draftItems($draft, 'purchase_images'));
        $draft->delete();
    }

    /** Excel 批量导入链接级 SKU 与 PCL 数据：仅 boss 账号可用，只写链接维度数据供流程使用，不生成需求提报记录 */
    public function linkSkuImport(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->canImportProductRequests($context['user']), 403);

        $data = $request->validate([
            'import_file' => ['required', 'file', 'extensions:xlsx,xls', 'max:10240'],
        ]);

        try {
            [$groups, $rowErrors] = $this->parseProductRequestImport($data['import_file']->getRealPath());
        } catch (\Throwable) {
            return back()->with('error', __('product_requests.import.parse_failed'));
        }

        if ($groups === []) {
            $message = $rowErrors !== [] ? implode('；', array_slice($rowErrors, 0, 5)) : __('product_requests.import.empty');
            return back()->with('error', $message);
        }

        $connection = config('admin.database.connection') ?: config('database.default');
        $linkCount = 0;
        $skuCount = 0;

        try {
            DB::connection($connection)->transaction(function () use ($groups, &$linkCount, &$skuCount): void {
                foreach ($groups as $group) {
                    $linkSku = ProductRequestLinkSku::query()->firstOrNew(['supplier_url_hash' => $group['hash']]);
                    $linkSku->supplier_url = $group['url'];
                    if ($group['pcl'] !== '') {
                        $linkSku->pcl_product_no = $group['pcl'];
                    }
                    if (trim((string) ($group['name'] ?? '')) !== '') {
                        $linkSku->product_name = mb_substr(trim((string) $group['name']), 0, 191);
                    }
                    // 来源与复查状态：新建记录标记为导入添加，导入后统一进入待复查
                    if (!$linkSku->exists) {
                        $linkSku->source = 'import';
                    }
                    $linkSku->review_status = 'pending';
                    // 链接级 SKU 只增不减：导入的 SKU 与历史集合合并去重
                    $linkSku->skus = implode(',', $this->mergeLinkSkus((string) $linkSku->skus, $group['skus']));
                    // 按 SKU 的采购价/重量以导入值覆盖同 SKU 的历史值
                    $values = (array) ($linkSku->sku_values ?? []);
                    foreach ($group['values'] as $sku => $row) {
                        $values[$sku] = $row;
                    }
                    $linkSku->sku_values = $values === [] ? null : $values;
                    $linkSku->save();
                    // 产品图：取导入 Excel 的主图单独保存，与后台上传的实拍图字段区分；重复导入有图时覆盖旧图
                    $importImage = $group['images'][0] ?? null;
                    if (is_array($importImage) && ($importImage['bytes'] ?? '') !== '') {
                        $ext = strtolower((string) ($importImage['ext'] ?: 'jpg'));
                        $path = 'link-skus/'.$linkSku->getKey().'/product-'.Str::uuid().'.'.$ext;
                        Storage::disk('local')->put($path, $importImage['bytes']);
                        if ($linkSku->product_image && $linkSku->product_image !== $path) {
                            Storage::disk('local')->delete($linkSku->product_image);
                        }
                        $linkSku->forceFill(['product_image' => $path])->save();
                    }
                    $linkCount++;
                    $skuCount += count($group['skus']);
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('product_requests.import.failed', ['message' => $e->getMessage()]));
        }

        $message = __('product_requests.import.success', ['count' => $linkCount, 'skus' => $skuCount]);
        if ($rowErrors !== []) {
            $message .= '；'.implode('；', array_slice($rowErrors, 0, 5));
        }

        return redirect()->route('product-requests.link-skus.index')->with('success', $message);
    }

    /** 下载链接 SKU 导入模板（仅 boss 账号，入口在 PCL SKU 管理页） */
    public function operationImportTemplate(Request $request): Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        abort_unless($this->canImportProductRequests($context['user']), 403);

        return response()->download(
            storage_path('app/templates/product-request-import-template.xlsx'),
            '链接SKU导入模板.xlsx'
        );
    }

    /** 导入基础数据：boss 账号与入录审核部门树内主管/副主管可用 */
    private function canImportProductRequests(AdminUser $user): bool
    {
        return $this->access->canImportLinkSkus($user);
    }

    /** 链接 SKU 记录删除：同时清理产品图、实拍图等磁盘文件，仅移除链接级数据不影响流程记录 */
    public function linkSkuDestroy(Request $request, ProductRequestLinkSku $linkSku): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canManageLinkSkus($context['user']), 403);

        $disk = Storage::disk('local');
        foreach (array_values((array) ($linkSku->images ?? [])) as $imagePath) {
            if (is_string($imagePath) && $imagePath !== '') {
                $disk->delete($imagePath);
            }
        }
        if (is_string($linkSku->product_image) && $linkSku->product_image !== '') {
            $disk->delete($linkSku->product_image);
        }
        $linkSku->delete();

        return $this->modalSuccess($request, __('product_requests.link_skus.deleted'), 'product-requests.link-skus.index');
    }

    /** 确认复查：仅待复查状态可变更 */
    public function linkSkuReviewDone(Request $request, ProductRequestLinkSku $linkSku): RedirectResponse|Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canManageLinkSkus($context['user']), 403);
        abort_unless($linkSku->review_status === 'pending', 403);

        $linkSku->forceFill(['review_status' => 'done'])->save();

        return redirect()->route('product-requests.link-skus.index')->with('success', __('product_requests.link_skus.review_done_success'));
    }

    /** 链接实拍图鉴权读取（跨门户共享，已登录即可访问） */
    public function linkSkuImage(Request $request, ProductRequestLinkSku $linkSku, int $index): Response
    {
        $context = $this->anyPortalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $images = array_values((array) ($linkSku->images ?? []));
        $path = $images[$index] ?? null;
        abort_if(!is_string($path) || $path === '' || !Storage::disk('local')->exists($path), 404);

        return $this->cachedImageResponse(Storage::disk('local')->path($path));
    }

    /** 链接产品图（导入 Excel 主图）鉴权读取（跨门户共享，已登录即可访问） */
    public function linkSkuProductImage(Request $request, ProductRequestLinkSku $linkSku): Response
    {
        $context = $this->anyPortalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $path = (string) ($linkSku->product_image ?? '');
        abort_if($path === '' || !Storage::disk('local')->exists($path), 404);

        return $this->cachedImageResponse(Storage::disk('local')->path($path));
    }

    /**
     * 解析导入 Excel：表头固定为 产品主图/PCL（商品编号）/商品名称/当前SKU/采购参考价(CNY)/重量(g)/1688采购链接
     * 主图列支持 WPS DISPIMG 内嵌图片与标准 Excel 嵌入图片，行按 1688 链接聚合为一条提报
     *
     * @return array{0: array<int, array{url: string, hash: string, pcl: ?string, name: string, skus: array<int, string>, values: array<string, array{price: string, weight: string}>, images: array<int, array{bytes: string, ext: string, name: string}>}>, 1: array<int, string>}
     */
    private function parseProductRequestImport(string $path): array
    {
        $sheet = IOFactory::load($path)->getSheet(0);
        $highest = $sheet->getHighestDataRow();
        $cellImages = $this->extractWpsCellImages($path);
        $drawingImages = $this->extractDrawingImages($sheet);
        $groups = [];
        $errors = [];

        for ($row = 2; $row <= $highest; $row++) {
            $imageRef = trim((string) $sheet->getCell('A'.$row)->getValue());
            $pcl = trim((string) $sheet->getCell('B'.$row)->getValue());
            $name = trim((string) $sheet->getCell('C'.$row)->getValue());
            $sku = trim((string) $sheet->getCell('D'.$row)->getValue());
            $price = trim((string) $sheet->getCell('E'.$row)->getValue());
            $weight = trim((string) $sheet->getCell('F'.$row)->getValue());
            $url = trim((string) $sheet->getCell('G'.$row)->getValue());
            if ($pcl === '' && $name === '' && $sku === '' && $url === '') {
                continue;
            }
            $missing = [];
            if ($name === '') { $missing[] = '商品名称'; }
            if ($sku === '') { $missing[] = 'SKU'; }
            if ($url === '') { $missing[] = '1688采购链接'; }
            if ($missing !== []) {
                $errors[] = __('product_requests.import.row_missing', ['row' => $row, 'fields' => implode('、', $missing)]);
                continue;
            }
            if (!preg_match('#^https?://#i', $url)) {
                $errors[] = __('product_requests.import.row_invalid_url', ['row' => $row]);
                continue;
            }

            $normalized = $this->workflow->normalizeSupplierUrl($url);
            $hash = $this->workflow->supplierUrlHash($normalized);
            if (!isset($groups[$hash])) {
                $groups[$hash] = ['url' => $normalized, 'hash' => $hash, 'pcl' => $pcl !== '' ? $pcl : null, 'name' => $name, 'skus' => [], 'values' => [], 'images' => []];
            }
            if (!in_array($sku, $groups[$hash]['skus'], true)) {
                $groups[$hash]['skus'][] = $sku;
            }
            $groups[$hash]['values'][$sku] = ['price' => $price, 'weight' => $weight];

            // 收集产品主图：WPS DISPIMG 按图片 ID 解析，标准嵌入图片按行号匹配，均按图源去重
            if (preg_match('/DISPIMG\("([^"]+)"/i', $imageRef, $matches) && isset($cellImages[$matches[1]])) {
                $image = $cellImages[$matches[1]];
                $groups[$hash]['images'][$image['key']] = $image;
            }
            foreach ($drawingImages[$row] ?? [] as $image) {
                $groups[$hash]['images'][$image['key']] = $image;
            }
        }

        foreach ($groups as &$group) {
            $group['images'] = array_values($group['images']);
        }
        unset($group);

        return [array_values($groups), $errors];
    }

    /**
     * 读取 WPS cellimages.xml 内嵌图片，返回 DISPIMG 图片 ID => 图片数据 的映射
     *
     * @return array<string, array{key: string, bytes: string, ext: string, name: string}>
     */
    private function extractWpsCellImages(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $cellImagesXml = $zip->getFromName('xl/cellimages.xml');
        $relsXml = $zip->getFromName('xl/_rels/cellimages.xml.rels');
        if ($cellImagesXml === false || $relsXml === false) {
            $zip->close();

            return [];
        }

        $relMap = [];
        $rels = simplexml_load_string($relsXml);
        if ($rels !== false) {
            foreach ($rels->Relationship as $relationship) {
                $relMap[(string) $relationship['Id']] = (string) $relationship['Target'];
            }
        }

        $images = [];
        $dom = new \DOMDocument();
        if (@$dom->loadXML($cellImagesXml)) {
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            foreach ($xpath->query('//xdr:pic') ?: [] as $pic) {
                $idNode = $xpath->query('.//xdr:cNvPr', $pic)?->item(0);
                $blipNode = $xpath->query('.//a:blip', $pic)?->item(0);
                if (!$idNode instanceof \DOMElement || !$blipNode instanceof \DOMElement) {
                    continue;
                }
                $imageId = $idNode->getAttribute('name');
                $rId = $blipNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
                $target = $relMap[$rId] ?? '';
                if ($imageId === '' || $target === '') {
                    continue;
                }
                $zipPath = 'xl/'.ltrim($target, '/');
                $bytes = $zip->getFromName($zipPath);
                if ($bytes === false) {
                    continue;
                }
                $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION)) ?: 'png';
                $images[$imageId] = ['key' => $zipPath, 'bytes' => $bytes, 'ext' => $extension, 'name' => basename($target)];
            }
        }
        $zip->close();

        return $images;
    }

    /**
     * 读取标准 Excel 嵌入图片（绘图对象），返回 行号 => 图片数据列表 的映射，仅采集产品主图所在的 A 列
     *
     * @return array<int, array<int, array{key: string, bytes: string, ext: string, name: string}>>
     */
    private function extractDrawingImages($sheet): array
    {
        $images = [];
        foreach ($sheet->getDrawingCollection() as $index => $drawing) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', (string) $drawing->getCoordinates(), $matches) || strtoupper($matches[1]) !== 'A') {
                continue;
            }
            $bytes = @file_get_contents($drawing->getPath());
            if ($bytes === false) {
                continue;
            }
            $extension = strtolower((string) $drawing->getExtension()) ?: 'png';
            $images[(int) $matches[2]][] = [
                'key' => 'drawing-'.$index,
                'bytes' => $bytes,
                'ext' => $extension,
                'name' => trim((string) $drawing->getName()) !== '' ? trim((string) $drawing->getName()) : 'image.'.$extension,
            ];
        }

        return $images;
    }

    /** 运营重新编辑检验不通过的需求提报 */
    public function operationEdit(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.operation.index');
        }

        // 仅允许检验不通过的记录由原提报人编辑
        abort_unless(
            (int) $productRequest->operation_user_id === (int) $context['user']->getKey()
            && trim((string) $productRequest->inspection_reject_reason) !== '',
            403,
        );

        return view('portals.product-requests.operation.form', [
            ...$this->viewContext($context),
            'actor' => $this->access->actorSnapshot($context['user']),
            'productRequest' => $productRequest->loadMissing('attachments'),
        ]);
    }

    public function operationUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless(
            (int) $productRequest->operation_user_id === (int) $context['user']->getKey()
            && trim((string) $productRequest->inspection_reject_reason) !== '',
            403,
        );

        $data = $this->validateOperationData($request, $productRequest);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $data, $request, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            $locked->fill([
                'skus' => $this->normalizeSkus($data['skus']),
                'operation_supplier_url' => $data['operation_supplier_url'],
                'has_stock' => $data['has_stock'],
                'operation_remark' => $data['operation_remark'] ?? null,
                'status' => ProductRequest::STATUS_PENDING,
                'inspection_reject_reason' => null,
            ])->save();

            if ($request->hasFile('product_image')) {
                $this->workflow->replaceStageAttachments(
                    $locked,
                    ProductRequestAttachment::STAGE_OPERATION,
                    [$request->file('product_image')],
                    $context['user'],
                );
            }
            if ($request->hasFile('purchase_item_images')) {
                $this->workflow->replaceStageAttachments(
                    $locked,
                    ProductRequestAttachment::STAGE_PURCHASE_ITEM,
                    $request->file('purchase_item_images', []),
                    $context['user'],
                );
            }
            $this->workflow->log($locked, 'operation_resubmitted', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.operation_resubmitted', ['report_no' => $productRequest->report_no]),
            'product-requests.operation.index',
        );
    }

    /** 运营标记已整改 */
    public function operationRectify(Request $request, ProductRequest $productRequest): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless(
            (int) $productRequest->operation_user_id === (int) $context['user']->getKey()
            && $productRequest->operation_rectification_status === ProductRequest::RECTIFICATION_PENDING,
            403,
        );

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($productRequest, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            $locked->operation_rectification_status = ProductRequest::RECTIFICATION_DONE;
            // 双方都已整改时，仓库审核状态自动变为通过，并进入审核链接人员复查流程
            if ($locked->procurement_rectification_status === ProductRequest::RECTIFICATION_DONE) {
                $locked->warehouse_acceptance_result = ProductRequest::WAREHOUSE_PASSED;
                $locked->warehouse_reject_reason = null;
                $locked->placement_recheck_status = ProductRequest::RECTIFICATION_PENDING;
            }
            $locked->save();
            $this->workflow->log($locked, 'operation_rectified', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.operation_rectified', ['report_no' => $productRequest->report_no]),
            'product-requests.operation.index',
        );
    }

    /** 录入人员（采购）标记已整改 */
    public function procurementRectify(Request $request, ProductRequest $productRequest): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless(
            $productRequest->procurement_rectification_status === ProductRequest::RECTIFICATION_PENDING,
            403,
        );

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($productRequest, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            $locked->procurement_rectification_status = ProductRequest::RECTIFICATION_DONE;
            // 双方都已整改时，仓库审核状态自动变为通过，并进入审核链接人员复查流程
            if ($locked->operation_rectification_status === ProductRequest::RECTIFICATION_DONE) {
                $locked->warehouse_acceptance_result = ProductRequest::WAREHOUSE_PASSED;
                $locked->warehouse_reject_reason = null;
                $locked->placement_recheck_status = ProductRequest::RECTIFICATION_PENDING;
            }
            $locked->save();
            $this->workflow->log($locked, 'procurement_rectified', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.procurement_rectified', ['report_no' => $productRequest->report_no]),
            'product-requests.procurement.index',
        );
    }

    /** 审核链接人员确认复查（双方整改完成后） */
    public function placementReviewRecheck(Request $request, ProductRequest $productRequest): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->access->canReviewPlacement($context['user']), 403);
        abort_unless($productRequest->placement_recheck_status === ProductRequest::RECTIFICATION_PENDING, 403);

        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($productRequest, $context): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless($locked->placement_recheck_status === ProductRequest::RECTIFICATION_PENDING, 403);
            $locked->placement_recheck_status = ProductRequest::RECTIFICATION_DONE;
            $locked->save();
            $this->workflow->log($locked, 'placement_rechecked', $context['user']);
        });

        return $this->modalSuccess(
            $request,
            __('product_requests.messages.placement_rechecked', ['report_no' => $productRequest->report_no]),
            'product-requests.placement-reviews.index',
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
                ->where(function ($q): void {
                    $q->where('status', ProductRequest::STATUS_INSPECTION_PASSED)
                        ->whereIn('placement_review_status', [ProductRequest::REVIEW_UNFILLED, ProductRequest::REVIEW_REJECTED])
                        ->orWhere('operation_rectification_status', ProductRequest::RECTIFICATION_PENDING)
                        ->orWhere('inspection_reject_reason', '!=', '');
                }),
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

        $records = $this->access->applyInspectionVisibility(
            ProductRequest::query()
                ->where(function ($q): void {
                    $q->where('status', ProductRequest::STATUS_PENDING)
                        ->where(function ($sub): void {
                            $sub->whereNull('inspection_reject_reason')->orWhere('inspection_reject_reason', '');
                        })
                        ->orWhere('procurement_rectification_status', ProductRequest::RECTIFICATION_PENDING);
                }),
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
            $this->access->applyInspectionVisibility(ProductRequest::query(), $context['user']),
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
            'canAssign' => $this->access->canAssignProcurement($context['user']),
            'statusLabels' => $this->statusLabels(),
            'warehouseResultLabels' => $this->warehouseResultLabels(),
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
            'rectificationLabels' => $this->rectificationLabels(),
        ]);
    }

    public function procurementEdit(Request $request, ProductRequest $productRequest): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);
        abort_unless($this->access->canViewInspectionRequest($productRequest, $context['user']), 403);
        // 已检验通过的记录允许再次编辑（覆盖保存）
        abort_unless(in_array($productRequest->status, [ProductRequest::STATUS_PENDING, ProductRequest::STATUS_INSPECTION_PASSED], true), 403);
        // 检验通过且仓库审核已通过的记录不允许再修改
        abort_if($productRequest->status === ProductRequest::STATUS_INSPECTION_PASSED && $productRequest->warehouse_acceptance_result === ProductRequest::WAREHOUSE_PASSED, 403);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.procurement.index');
        }

        $productRequest->load('attachments');

        // 存在本人草稿时优先回填草稿内容，方便继续上次未完成的填写
        $draft = $this->resolveProcurementDraft($productRequest, $context['user']);
        $skuValues = is_array($productRequest->procurement_sku_values) ? $productRequest->procurement_sku_values : [];
        if ($draft) {
            foreach ((array) (($draft->payload ?? [])['sku_values'] ?? []) as $sku => $value) {
                if (is_array($value) && is_array($skuValues[$sku] ?? null)) {
                    $skuValues[$sku] = array_merge($skuValues[$sku], $value);
                } elseif (is_array($value)) {
                    $skuValues[$sku] = $value;
                }
            }
        }

        return view('portals.product-requests.procurement.form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'actor' => $this->access->actorSnapshot($context['user']),
            'skuValues' => $skuValues,
            'draft' => $draft,
        ]);
    }

    public function procurementUpdate(Request $request, ProductRequest $productRequest): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);
        abort_unless($this->access->canViewInspectionRequest($productRequest, $context['user']), 403);

        $draft = $this->resolveProcurementDraft($productRequest, $context['user']);
        $data = $this->validateProcurementData($request, $productRequest, $draft);
        $rejected = ($data['result'] ?? '') === 'rejected';
        unset($data['result']);
        $connection = config('admin.database.connection') ?: config('database.default');

        DB::connection($connection)->transaction(function () use ($productRequest, $request, $data, $context, $rejected, $draft): void {
            $locked = ProductRequest::query()->lockForUpdate()->findOrFail($productRequest->getKey());
            abort_unless($this->access->canViewInspectionRequest($locked, $context['user']), 403);
            // 允许已检验通过的记录再次编辑，第二次保存覆盖第一次结果
            abort_unless(in_array($locked->status, [ProductRequest::STATUS_PENDING, ProductRequest::STATUS_INSPECTION_PASSED], true), 403);
            // 检验通过且仓库审核已通过的记录不允许再修改
            abort_if($locked->status === ProductRequest::STATUS_INSPECTION_PASSED && $locked->warehouse_acceptance_result === ProductRequest::WAREHOUSE_PASSED, 403);

            $actor = $this->access->actorSnapshot($context['user']);

            // 审核不通过：仅保存备注理由，状态保持待处理，运营可重新提交
            if ($rejected) {
                $locked->fill([
                    'inspection_reject_reason' => $data['inspection_reject_reason'],
                    'status' => ProductRequest::STATUS_PENDING,
                    'erp_operator_id' => $actor['user_id'],
                    'erp_operator_name' => $actor['user_name'],
                    'erp_operator_department_name' => $actor['department_name'],
                    'erp_operator_display_name' => $actor['display_name'],
                    'inspected_at' => now(),
                ])->save();

                $this->workflow->log($locked, 'procurement_inspection_rejected', $context['user'], [
                    'inspection_reject_reason' => $data['inspection_reject_reason'],
                ]);
                return;
            }

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
            // 面板左列可临时修正运营 SKU，仅用于本次勾选与链接级 SKU 处理；
            // 需求记录保留运营原始提交的 skus，采购确认值写入 procurement_skus，运营端据此提示 SKU 有变动
            $operationSkus = $this->splitSkus($this->normalizeSkus((string) ($data['skus'] ?? '')));
            // 本次面板展示过的 SKU（右栏系统已录入 + 左栏运营填写），用于识别已取消勾选项
            $displayedSkus = array_values(array_unique(array_merge(
                $this->splitSkus((string) ($data['link_skus'] ?? '')),
                $operationSkus,
            )));
            // 面板左列被改写/删除的旧运营 SKU，需要从链接记录中移除，不能作为历史数据继续累计
            $removedOperationSkus = array_values(array_diff($this->splitSkus((string) $locked->skus), $operationSkus));
            $obsoleteSkus = array_values(array_unique(array_merge($displayedSkus, $removedOperationSkus)));
            $skuValueMap = $this->parseProcurementSkuValues((string) ($data['sku_value_rows'] ?? ''), $checkedSkus);
            unset($data['skus'], $data['reuse_source_id'], $data['seller_images'], $data['selected_skus'], $data['link_skus'], $data['sku_value_rows'], $data['removed_seller_images'], $data['inspection_reject_reason']);
            // 链接级 SKU 以本次勾选结果为最终集合：展示过但取消勾选的、以及被改写的旧运营 SKU 不再累计保留；
            // 未查重（右栏未加载）时，未被展示过的历史 SKU 予以保留，避免误删链接已有数据
            $linkSkus = array_values(array_unique(array_merge(
                $checkedSkus,
                array_values(array_diff($this->splitSkus($this->linkSkuSet($linkHash, $locked->getKey())), $obsoleteSkus)),
            )));
            $procurementSkus = implode(',', $checkedSkus);
            if ($linkSkus !== []) {
                $linkSkuPayload = [
                    'supplier_url' => $normalizedUrl,
                    'skus' => implode(',', $linkSkus),
                    'sku_values' => $skuValueMap === [] ? null : $skuValueMap,
                    'pcl_product_no' => trim((string) ($data['pcl_product_no'] ?? $locked->pcl_product_no)) ?: null,
                    'product_name' => trim((string) ($data['standard_chinese_name'] ?? $locked->standard_chinese_name)) ?: null,
                    'source' => 'system',
                ];
                $linkCover = $locked->attachmentsFor(ProductRequestAttachment::STAGE_OPERATION)->first();
                if ($linkCover) {
                    $linkSkuPayload['product_image'] = $linkCover->path;
                }

                // 同一 PCL 商品编号只维护一条链接记录：先按链接找，找不到再按 PCL 找历史记录并更新，
                // 避免同一产品因换了 1688 链接而重复新增记录
                $pclNo = trim((string) ($linkSkuPayload['pcl_product_no'] ?? ''));
                $linkSku = ProductRequestLinkSku::query()->where('supplier_url_hash', $linkHash)->first();
                if (!$linkSku && $pclNo !== '') {
                    $linkSku = ProductRequestLinkSku::query()
                        ->where('pcl_product_no', $pclNo)
                        ->orderByDesc('id')
                        ->first();
                }

                if ($linkSku && (string) $linkSku->supplier_url_hash !== $linkHash) {
                    // 命中同 PCL 的历史记录（不同链接）：直接覆盖为本次结果，旧链接 SKU 不再累计保留
                    $linkSkuPayload['supplier_url_hash'] = $linkHash;
                }

                ProductRequestLinkSku::query()->updateOrCreate(
                    $linkSku ? ['id' => $linkSku->getKey()] : ['supplier_url_hash' => $linkHash],
                    $linkSkuPayload,
                );
            }
            $locked->fill([
                ...$data,
                'procurement_skus' => $procurementSkus,
                'procurement_sku_values' => $skuValueMap === [] ? null : $skuValueMap,
                'supplier_url_normalized' => $normalizedUrl,
                'supplier_url_hash' => $linkHash,
                'status' => ProductRequest::STATUS_INSPECTION_PASSED,
                'inspection_reject_reason' => null,
                'erp_operator_id' => $actor['user_id'],
                'erp_operator_name' => $actor['user_name'],
                'erp_operator_department_name' => $actor['department_name'],
                'erp_operator_display_name' => $actor['display_name'],
                'inspected_at' => now(),
            ])->save();

            $newImages = $request->file('seller_images', []);
            // 草稿中保留的图片（本次未删除的），在未新上传且未复用来源时接管为正式附件
            $draftImages = $draft ? $this->draftSplitItems($draft, $request, 'images', 'removed_seller_images')[0] : [];
            // 复用来源：本次未删除任何已存图片且携带复用来源时使用
            $reuseSource = null;
            if ($removedImageIds === [] && $reuseSourceId > 0) {
                $reuseSource = ProductRequest::query()->with('attachments')->find($reuseSourceId);
                if (!$reuseSource || $reuseSource->getKey() === $locked->getKey()) {
                    $reuseSource = null;
                }
            }
            $adoptDraftImages = ($newImages === [] && $reuseSource === null) ? $draftImages : [];
            // 删除后剩余 0 张视为必填校验失败；携带复用来源且来源已包含采购阶段图片时放行
            $totalAfter = max(0, $locked->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)->count() - count($removedImageIds)) + count($newImages) + count($adoptDraftImages);
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
            } elseif ($adoptDraftImages !== []) {
                $this->workflow->adoptStageAttachments($locked, ProductRequestAttachment::STAGE_PROCUREMENT, $adoptDraftImages, $context['user']);
            }

            // 系统添加的实拍图同步复制到链接 SKU 记录，PCL SKU 管理中可统一查看与编辑
            $this->syncLinkSellerImages($locked);

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

        // 正式提交完成后草稿已无意义，一并清理
        $draft = $this->resolveProcurementDraft($productRequest, $context['user']);
        if ($draft) {
            $this->deleteDraft($draft);
        }

        return $this->modalSuccess(
            $request,
            $rejected
                ? __('product_requests.messages.procurement_rejected')
                : __('product_requests.messages.procurement_completed'),
            'product-requests.procurement.index',
        );
    }

    /** 保存商品信息检验草稿：不改变记录状态，仅本人可见，下次打开该表单自动回填 */
    public function procurementDraftStore(Request $request): Response|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }

        $user = $context['user'];
        abort_unless($this->access->canInspectProcurement($user), 403);

        $data = $request->validate([
            'product_request_id' => ['required', 'integer'],
            'pcl_product_no' => ['nullable', 'string', 'max:100'],
            'standard_chinese_name' => ['nullable', 'string', 'max:191'],
            'inspection_reject_reason' => ['nullable', 'string', 'max:2000'],
            'skus' => ['nullable', 'string', 'max:10000'],
            'selected_skus' => ['nullable', 'array', 'max:100'],
            'selected_skus.*' => ['string', 'max:500'],
            'link_skus' => ['nullable', 'string', 'max:20000'],
            'sku_value_rows' => ['nullable', 'string', 'max:20000'],
            'reuse_source_id' => ['nullable', 'integer'],
            'removed_seller_images' => ['nullable', 'string', 'max:100'],
            'seller_images' => ['nullable', 'array', 'max:3'],
            'seller_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $productRequest = ProductRequest::query()->findOrFail((int) $data['product_request_id']);
        abort_unless($this->access->canViewInspectionRequest($productRequest, $user), 403);

        $draft = $this->resolveProcurementDraft($productRequest, $user);
        $isExisting = $draft !== null;
        $draft ??= new ProductRequestDraft();
        $draft->user_id = $user->getKey();
        $draft->stage = ProductRequestDraft::STAGE_PROCUREMENT;
        $draft->product_request_id = $productRequest->getKey();

        // 草稿图片：按勾选移除旧图，再追加本次新上传的图片，总数不超过 3 张
        [$images] = $isExisting
            ? $this->draftSplitItems($draft, $request, 'images', 'removed_seller_images')
            : [[], []];
        $dropped = $isExisting
            ? $this->draftSplitItems($draft, $request, 'images', 'removed_seller_images')[1]
            : [];
        $this->deleteDraftImageFiles($dropped);
        foreach (array_slice((array) $request->file('seller_images', []), 0, max(0, 3 - count($images))) as $file) {
            $images[] = $this->storeDraftImage($file);
        }
        $draft->images = $images === [] ? null : $images;

        $skuValueRows = json_decode((string) ($data['sku_value_rows'] ?? ''), true);
        $draft->payload = [
            'pcl_product_no' => (string) ($data['pcl_product_no'] ?? ''),
            'standard_chinese_name' => (string) ($data['standard_chinese_name'] ?? ''),
            'inspection_reject_reason' => (string) ($data['inspection_reject_reason'] ?? ''),
            'skus' => $this->normalizeSkus((string) ($data['skus'] ?? '')),
            'selected_skus' => array_values(array_unique(array_filter(array_map(
                fn ($value): string => trim((string) $value),
                (array) ($data['selected_skus'] ?? []),
            ), fn (string $value): bool => $value !== ''))),
            'system_skus' => $this->splitSkus((string) ($data['link_skus'] ?? '')),
            'sku_values' => is_array($skuValueRows) ? $skuValueRows : [],
            'reuse_source_id' => (int) ($data['reuse_source_id'] ?? 0),
        ];
        $draft->save();

        return $this->modalSuccess($request, __('product_requests.procurement.draft_saved'), 'product-requests.procurement.index');
    }

    private function resolveProcurementDraft(ProductRequest $productRequest, AdminUser $user): ?ProductRequestDraft
    {
        return ProductRequestDraft::query()
            ->where('stage', ProductRequestDraft::STAGE_PROCUREMENT)
            ->where('user_id', $user->getKey())
            ->where('product_request_id', $productRequest->getKey())
            ->latest('id')
            ->first();
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
        $hash = $this->workflow->supplierUrlHash($normalizedUrl);
        // 链接与产品名统一只查 product_request_link_skus 表；
        // 两者不分优先级，各自查询后合并展示（同一链接只保留一条，链接命中的排前面）
        $linkMatches = collect();
        $linkSku = ProductRequestLinkSku::query()->where('supplier_url_hash', $hash)->first();

        if ($linkSku && $this->splitSkus((string) $linkSku->skus) !== []) {
            $linkMatches = collect([$linkSku]);
        }

        $keywordMatches = collect();
        $keyword = trim((string) ($data['standard_chinese_name'] ?? ''));

        // 名称模糊查询可能命中多个链接，按链接聚合后全部返回，供采购选择
        if ($keyword !== '') {
            $keywordMatches = $this->linkSkuNameMatches($keyword, 20);
        }

        $linkSkus = $linkMatches->concat($keywordMatches)->unique('supplier_url_hash')->values();

        $matchType = 'supplier_url';
        if ($linkMatches->isNotEmpty() && $keywordMatches->isNotEmpty()) {
            $matchType = 'mixed';
        } elseif ($linkMatches->isEmpty() && $keywordMatches->isNotEmpty()) {
            $matchType = 'standard_chinese_name';
        }

        return response()->json([
            'match_type' => $matchType,
            'records' => $linkSkus
                ->map(fn (ProductRequestLinkSku $row): array => $this->linkSkuReusePayload($row))
                ->values()
                ->all(),
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

        // 同链接历史记录按 SKU 保存的采购价/重量（新记录覆盖旧记录），供右栏只读展示
        $skuValues = $skus === [] ? [] : array_intersect_key($this->linkSkuValues($hash), array_flip($skus));

        return response()->json([
            'items' => $skus === [] ? [] : [[
                'id' => null,
                'report_no' => null,
                'skus' => $skus,
            ]],
            'values' => $skuValues === [] ? new \stdClass() : $skuValues,
        ]);
    }

    /** 分配录入人员弹窗：展示本次分配的记录与候选人今日已分配条数 */
    public function procurementAssignForm(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);
        abort_unless($this->access->canAssignProcurement($context['user']), 403);

        $ids = collect((array) $request->input('ids', []))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();
        // 仅允许分配主状态为待检验的记录，且必须在当前用户可见范围内
        $records = $this->access->applyInspectionVisibility(
            ProductRequest::query()
                ->whereIn('id', $ids->all() ?: [0])
                ->where('status', ProductRequest::STATUS_PENDING),
            $context['user'],
        )
            ->orderByDesc('id')
            ->get(['id', 'report_no', 'standard_chinese_name', 'procurement_assignee_name']);
        abort_if($records->isEmpty(), 404);

        return view('portals.product-requests.procurement.assign', [
            ...$this->viewContext($context),
            'records' => $records,
            'assignees' => $this->assigneeOptions(),
        ]);
    }

    /** 保存分配：写入录入人员与分配时间，并记录操作日志 */
    public function procurementAssign(Request $request): JsonResponse|RedirectResponse
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canInspectProcurement($context['user']), 403);
        abort_unless($this->access->canAssignProcurement($context['user']), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'assignee_id' => ['required', 'integer'],
        ]);

        $assignee = AdminUser::query()->findOrFail((int) $data['assignee_id']);
        abort_unless($this->access->isAssignableUser($assignee), 403);
        $assigneeSnapshot = $this->access->actorSnapshot($assignee);

        $records = $this->access->applyInspectionVisibility(
            ProductRequest::query()
                ->whereIn('id', collect($data['ids'])->map(fn ($value): int => (int) $value)->all())
                ->where('status', ProductRequest::STATUS_PENDING),
            $context['user'],
        )->get();

        foreach ($records as $record) {
            $record->fill([
                'procurement_assignee_id' => $assigneeSnapshot['user_id'],
                'procurement_assignee_name' => $assigneeSnapshot['display_name'],
                'procurement_assigned_at' => now(),
            ])->save();
            $this->workflow->log($record, 'procurement_assigned', $context['user'], [
                'assignee' => $assigneeSnapshot['display_name'],
            ]);
        }

        return $this->modalSuccess(
            $request,
            __('product_requests.procurement.assign_success', ['count' => $records->count()]),
            'product-requests.procurement.index',
        );
    }

    /** 候选录入人员：树内成员 + 今日已被分配条数（按东八区当天统计） */
    private function assigneeOptions(): array
    {
        $dayStart = \Carbon\Carbon::today('Asia/Shanghai');
        $counts = ProductRequest::query()
            ->whereBetween('procurement_assigned_at', [$dayStart->copy()->startOfDay(), $dayStart->copy()->endOfDay()])
            ->groupBy('procurement_assignee_id')
            ->selectRaw('procurement_assignee_id, count(*) as total')
            ->pluck('total', 'procurement_assignee_id');

        return collect($this->access->assignableUsers())
            ->map(fn (array $user): array => [
                'id' => $user['id'],
                'name' => $user['name'],
                'display_name' => $user['display_name'],
                'today_count' => (int) ($counts[(int) $user['id']] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** 已有产品库（运营系统只读版）：数据源与 PCL SKU 管理完全一致，仅查看与搜索 */
    public function existingProductLibraryIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canViewExistingProductLibrary($context['user']), 403);

        $keyword = trim((string) $request->query('keyword', ''));
        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20], true)) {
            $perPage = 10;
        }
        $links = ProductRequestLinkSku::query()
            ->where('is_runnable', true)
            ->when($keyword !== '', function (Builder $query) use ($keyword): Builder {
                $matchedHashes = ProductRequest::query()
                    ->where(function (Builder $inner) use ($keyword): void {
                        $inner->where('pcl_product_no', 'like', '%'.$keyword.'%')
                            ->orWhere('standard_chinese_name', 'like', '%'.$keyword.'%');
                    })
                    ->pluck('supplier_url_hash');

                return $query->where(function (Builder $q) use ($keyword, $matchedHashes): void {
                    $q->where('supplier_url', 'like', '%'.$keyword.'%')
                        ->orWhere('skus', 'like', '%'.$keyword.'%')
                        ->orWhere('pcl_product_no', 'like', '%'.$keyword.'%')
                        ->orWhere('product_name', 'like', '%'.$keyword.'%')
                        ->when($matchedHashes->isNotEmpty(), fn (Builder $inner): Builder => $inner->orWhereIn('supplier_url_hash', $matchedHashes));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();

        $pclMap = $links->pluck('pcl_product_no', 'supplier_url_hash')
            ->filter(fn (?string $pcl): bool => trim((string) $pcl) !== '');
        $nameMap = $links->pluck('product_name', 'supplier_url_hash')
            ->filter(fn (?string $name): bool => trim((string) $name) !== '');
        $coverMap = $links->pluck('product_image', 'supplier_url_hash')
            ->filter(fn (?string $path): bool => trim((string) $path) !== '');

        return view('portals.product-requests.operation.existing-product-library', [
            ...$this->viewContext($context),
            'links' => $links,
            'pclMap' => $pclMap,
            'nameMap' => $nameMap,
            'coverMap' => $coverMap,
            'filters' => ['keyword' => $keyword, 'per_page' => $perPage],
        ]);
    }

    /** 已有产品库详情弹窗（只读） */
    public function existingProductLibraryDetail(Request $request, ProductRequestLinkSku $linkSku): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::OPERATION_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canViewExistingProductLibrary($context['user']), 403);

        // 不可跑的产品不让看详情
        abort_unless((bool) ($linkSku->is_runnable ?? true), 404);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.operation.existing-product-library');
        }

        $pcl = trim((string) $linkSku->pcl_product_no) !== ''
            ? $linkSku->pcl_product_no
            : ProductRequest::query()
                ->where('supplier_url_hash', $linkSku->supplier_url_hash)
                ->whereNotNull('pcl_product_no')
                ->where('pcl_product_no', '!=', '')
                ->orderByDesc('updated_at')
                ->value('pcl_product_no');
        $productName = trim((string) $linkSku->product_name) !== ''
            ? $linkSku->product_name
            : ProductRequest::query()
                ->where('supplier_url_hash', $linkSku->supplier_url_hash)
                ->whereNotNull('standard_chinese_name')
                ->where('standard_chinese_name', '!=', '')
                ->orderByDesc('updated_at')
                ->value('standard_chinese_name');

        return view('portals.product-requests.operation.existing-product-library-detail', [
            'linkSku' => $linkSku,
            'pcl' => $pcl,
            'productName' => $productName,
            'skus' => $this->splitSkus((string) $linkSku->skus),
            'skuValues' => $this->linkSkuValues($linkSku->supplier_url_hash),
        ]);
    }

    /** PCL 与 SKU 管理列表（按 1688 链接维度） */
    public function linkSkuIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canManageLinkSkus($context['user']), 403);

        $keyword = trim((string) $request->query('keyword', ''));
        $sourceFilter = (string) $request->query('source', '');
        $reviewStatusFilter = (string) $request->query('review_status', '');
        $runnableFilter = (string) $request->query('is_runnable', '');
        $reasonFilter = (string) $request->query('not_runnable_reason', '');
        // 每页条数仅支持 20 / 50，非法值回落到默认 20
        $perPage = (int) $request->query('per_page', 20);
        if (!in_array($perPage, [20, 50], true)) {
            $perPage = 20;
        }
        $links = ProductRequestLinkSku::query()
            ->when($keyword !== '', function (Builder $query) use ($keyword): Builder {
                // PCL 与商品名称都可能只存在同链接的提报记录中，按链接哈希一并匹配
                $matchedHashes = ProductRequest::query()
                    ->where(function (Builder $inner) use ($keyword): void {
                        $inner->where('pcl_product_no', 'like', '%'.$keyword.'%')
                            ->orWhere('standard_chinese_name', 'like', '%'.$keyword.'%');
                    })
                    ->pluck('supplier_url_hash');

                return $query->where(function (Builder $q) use ($keyword, $matchedHashes): void {
                    $q->where('supplier_url', 'like', '%'.$keyword.'%')
                        ->orWhere('skus', 'like', '%'.$keyword.'%')
                        ->orWhere('pcl_product_no', 'like', '%'.$keyword.'%')
                        ->orWhere('product_name', 'like', '%'.$keyword.'%')
                        ->when($matchedHashes->isNotEmpty(), fn (Builder $inner): Builder => $inner->orWhereIn('supplier_url_hash', $matchedHashes));
                });
            })
            ->when(in_array($sourceFilter, ['system', 'import'], true), fn (Builder $query): Builder => $query->where('source', $sourceFilter))
            ->when(in_array($reviewStatusFilter, ['pending', 'done'], true), fn (Builder $query): Builder => $query->where('review_status', $reviewStatusFilter))
            ->when($runnableFilter !== '' && in_array($runnableFilter, ['0', '1'], true), function (Builder $query) use ($runnableFilter): Builder {
                return $query->where('is_runnable', (bool) $runnableFilter);
            })
            ->when($reasonFilter !== '' && isset(ProductRequestLinkSku::NOT_RUNNABLE_REASONS[$reasonFilter]), function (Builder $query) use ($reasonFilter): Builder {
                return $query->where('not_runnable_reason', $reasonFilter);
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        // 每个链接展示 PCL/商品名称/产品图，全部直接读 product_request_link_skus 表自身字段，不回退到提报记录
        $pclMap = $links->pluck('pcl_product_no', 'supplier_url_hash')
            ->filter(fn (?string $pcl): bool => trim((string) $pcl) !== '');
        $nameMap = $links->pluck('product_name', 'supplier_url_hash')
            ->filter(fn (?string $name): bool => trim((string) $name) !== '');
        $coverMap = $links->pluck('product_image', 'supplier_url_hash')
            ->filter(fn (?string $path): bool => trim((string) $path) !== '');

        return view('portals.product-requests.link-skus.index', [
            ...$this->viewContext($context),
            'links' => $links,
            'pclMap' => $pclMap,
            'nameMap' => $nameMap,
            'coverMap' => $coverMap,
            'filters' => ['keyword' => $keyword, 'source' => $sourceFilter, 'review_status' => $reviewStatusFilter, 'per_page' => $perPage],
            'canImport' => $this->canImportProductRequests($context['user']),
        ]);
    }

    /** PCL 与 SKU 编辑弹窗 */
    public function linkSkuEdit(Request $request, ProductRequestLinkSku $linkSku): Response|View
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canManageLinkSkus($context['user']), 403);

        if (!$this->isModalRequest($request)) {
            return redirect()->route('product-requests.link-skus.index');
        }

        $pcl = trim((string) $linkSku->pcl_product_no) !== ''
            ? $linkSku->pcl_product_no
            : ProductRequest::query()
                ->where('supplier_url_hash', $linkSku->supplier_url_hash)
                ->whereNotNull('pcl_product_no')
                ->where('pcl_product_no', '!=', '')
                ->orderByDesc('updated_at')
                ->value('pcl_product_no');
        $productName = trim((string) $linkSku->product_name) !== ''
            ? $linkSku->product_name
            : ProductRequest::query()
                ->where('supplier_url_hash', $linkSku->supplier_url_hash)
                ->whereNotNull('standard_chinese_name')
                ->where('standard_chinese_name', '!=', '')
                ->orderByDesc('updated_at')
                ->value('standard_chinese_name');

        return view('portals.product-requests.link-skus.form', [
            'linkSku' => $linkSku,
            'pcl' => $pcl,
            'productName' => $productName,
            'skus' => $this->splitSkus((string) $linkSku->skus),
            'skuValues' => $this->linkSkuValues($linkSku->supplier_url_hash),
        ]);
    }

    /** 保存链接 SKU：只增不减，提交值与历史值合并去重；PCL 商品编号可编辑 */
    public function linkSkuUpdate(Request $request, ProductRequestLinkSku $linkSku): JsonResponse|RedirectResponse|Response
    {
        $context = $this->portalContext($request, ProductRequestAccessService::PROCUREMENT_PORTAL_CODE);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($this->access->canManageLinkSkus($context['user']), 403);

        $data = $request->validate([
            'supplier_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://detail\.1688\.com/#i'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'pcl_product_no' => ['nullable', 'string', 'max:100'],
            'skus' => ['nullable', 'string', 'max:10000'],
            'sku_values' => ['nullable', 'array', 'max:100'],
            'sku_values.*.price' => ['nullable', 'string', 'max:100'],
            'sku_values.*.weight' => ['nullable', 'string', 'max:100'],
            'product_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_product_image' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
            'is_runnable' => ['required', 'in:0,1'],
            'not_runnable_reason' => ['nullable', 'required_if:is_runnable,0', 'in:'.implode(',', array_keys(\App\Models\ProductRequestLinkSku::NOT_RUNNABLE_REASONS))],
        ]);

        // 是否可跑：不可跑必须选原因
        $isRunnable = (bool) ($data['is_runnable'] ?? true);
        $notRunnableReason = $isRunnable ? null : ($data['not_runnable_reason'] ?? null);
        if (!$isRunnable && empty($notRunnableReason)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'not_runnable_reason' => '设为不可跑时必须选择原因。',
            ]);
        }

        // 1688 主链接可编辑：改动后需规范化并重算哈希；若新链接已被其他记录占用则拒绝
        $submittedUrl = trim((string) ($data['supplier_url'] ?? ''));
        if ($submittedUrl !== '') {
            $normalizedUrl = $this->workflow->normalizeSupplierUrl($submittedUrl);
            $newHash = $this->workflow->supplierUrlHash($normalizedUrl);
            if ($newHash !== (string) $linkSku->supplier_url_hash) {
                $occupied = ProductRequestLinkSku::query()
                    ->where('supplier_url_hash', $newHash)
                    ->whereKeyNot($linkSku->getKey())
                    ->exists();
                if ($occupied) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'supplier_url' => '该 1688 链接已存在另一条 SKU 记录，不能重复关联。',
                    ]);
                }
                $linkSku->supplier_url = $normalizedUrl;
                $linkSku->supplier_url_hash = $newHash;
            }
        }

        // PCL SKU 管理为全量维护：提交内容即最终集合，删除的 SKU 会被移除
        $submitted = $this->splitSkus(str_replace(["\r\n", "\r", "\n"], ',', (string) ($data['skus'] ?? '')));
        $linkSku->forceFill([
            'skus' => implode(',', $submitted),
            'pcl_product_no' => trim((string) ($data['pcl_product_no'] ?? '')) !== '' ? trim((string) $data['pcl_product_no']) : null,
            'product_name' => trim((string) ($data['product_name'] ?? '')) !== '' ? trim((string) $data['product_name']) : null,
            'is_runnable' => $isRunnable,
            'not_runnable_reason' => $notRunnableReason,
        ])->save();

        // 按 SKU 的采购价/重量同步到链接表（仅保留本次提交 SKU 的数值，两项都清空表示移除）
        $valueRows = collect($data['sku_values'] ?? [])->map(fn (mixed $row): array => [
            'price' => trim((string) (is_array($row) ? ($row['price'] ?? '') : '')),
            'weight' => trim((string) (is_array($row) ? ($row['weight'] ?? '') : '')),
        ]);
        $linkValues = [];
        foreach ($submitted as $sku) {
            if (!$valueRows->has($sku)) {
                continue;
            }
            $row = $valueRows->get($sku);
            if ($row['price'] !== '' || $row['weight'] !== '') {
                $linkValues[$sku] = $row;
            }
        }
        $linkSku->forceFill(['sku_values' => $linkValues === [] ? null : $linkValues])->save();

        // 产品主图编辑：勾选删除 → 删旧图并清空；上传新图 → 覆盖旧图
        $oldProductImage = (string) ($linkSku->product_image ?? '');
        $newProductImage = $oldProductImage;
        if (!empty($data['remove_product_image'])) {
            if ($oldProductImage !== '') {
                Storage::disk('local')->delete($oldProductImage);
            }
            $newProductImage = '';
        }
        if ($request->hasFile('product_image')) {
            $file = $request->file('product_image');
            $ext = strtolower((string) ($file->getClientOriginalExtension() ?: 'jpg'));
            $path = 'link-skus/'.$linkSku->getKey().'/product-image.'.$ext;
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
            if ($oldProductImage !== '' && $oldProductImage !== $path) {
                Storage::disk('local')->delete($oldProductImage);
            }
            $newProductImage = $path;
        }
        if ($newProductImage !== $oldProductImage) {
            $linkSku->forceFill(['product_image' => $newProductImage === '' ? null : $newProductImage])->save();
        }

        // 实拍图编辑：先移除勾选删除的旧图并清理磁盘文件，再追加新上传图片，合计最多 3 张
        $currentImages = array_values((array) ($linkSku->images ?? []));
        $removeIndexes = collect($data['remove_images'] ?? [])->map(fn (mixed $i): int => (int) $i)->all();
        $keptImages = [];
        foreach ($currentImages as $i => $imagePath) {
            if (in_array($i, $removeIndexes, true)) {
                Storage::disk('local')->delete($imagePath);
                continue;
            }
            $keptImages[] = $imagePath;
        }
        foreach (array_slice($request->file('images', []), 0, max(0, 3 - count($keptImages))) as $file) {
            $ext = strtolower((string) ($file->getClientOriginalExtension() ?: 'jpg'));
            $path = 'link-skus/'.$linkSku->getKey().'/'.Str::uuid().'.'.$ext;
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
            $keptImages[] = $path;
        }
        if ($keptImages !== $currentImages) {
            $linkSku->forceFill(['images' => $keptImages === [] ? null : $keptImages])->save();
        }

        // 按 SKU 的采购价/重量同步到同链接所有提报记录
        if ($valueRows->isNotEmpty()) {
            ProductRequest::query()
                ->where('supplier_url_hash', $linkSku->supplier_url_hash)
                ->whereNotNull('procurement_skus')
                ->get(['id', 'procurement_skus', 'procurement_sku_values'])
                ->each(function (ProductRequest $record) use ($valueRows): void {
                    $values = (array) $record->procurement_sku_values;
                    foreach ($this->splitSkus((string) $record->procurement_skus) as $sku) {
                        if (!$valueRows->has($sku)) {
                            continue;
                        }
                        $row = $valueRows->get($sku);
                        if ($row['price'] === '' && $row['weight'] === '') {
                            unset($values[$sku]);
                        } else {
                            $values[$sku] = $row;
                        }
                    }
                    $record->forceFill(['procurement_sku_values' => $values === [] ? null : $values])->save();
                });
        }

        return $this->modalSuccess($request, __('product_requests.link_skus.updated'), 'product-requests.link-skus.index');
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
        // 统计待审核记录 + 双方整改完成后待复查的记录
        $records = ProductRequest::query()
            ->where(function (Builder $query): void {
                $query->where('placement_review_status', ProductRequest::REVIEW_PENDING)
                    ->orWhere('placement_recheck_status', ProductRequest::RECTIFICATION_PENDING);
            })
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

        // 每条采购 SKU 一行：参考值为采购填写的重量，输入框默认回填已保存的实测值（无则取参考值）
        $procurementSkuValues = is_array($productRequest->procurement_sku_values) ? $productRequest->procurement_sku_values : [];
        $warehouseSkuWeights = is_array($productRequest->warehouse_sku_weights) ? $productRequest->warehouse_sku_weights : [];
        $skuWeightRows = collect($this->splitSkus((string) $productRequest->procurement_skus))
            ->map(function (string $sku) use ($procurementSkuValues, $warehouseSkuWeights): array {
                $ref = trim((string) ($procurementSkuValues[$sku]['weight'] ?? ''));
                return [
                    'sku' => $sku,
                    'ref' => $ref,
                    'value' => (string) ($warehouseSkuWeights[$sku] ?? $ref),
                ];
            })->values()->all();

        return view('portals.product-requests.warehouse.form', [
            ...$this->viewContext($context),
            'productRequest' => $productRequest,
            'actor' => $this->access->actorSnapshot($context['user']),
            'warehouseRejectReasonLabels' => $this->warehouseRejectReasonLabels(),
            'skuWeightRows' => $skuWeightRows,
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
            $result = $data['result'];
            $rejectReason = $rejected ? ($data['warehouse_reject_reason'] ?? null) : null;
            // 按 SKU 组装实测重量，仅保留本次采购 SKU
            $skuWeights = [];
            foreach ((array) ($data['sku_weight_rows'] ?? []) as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                $weight = mb_substr(trim((string) ($row['weight'] ?? '')), 0, 100);
                if ($sku === '' || $weight === '') {
                    continue;
                }
                $skuWeights[$sku] = $weight;
            }
            $skuWeights = array_intersect_key($skuWeights, array_flip($this->splitSkus((string) $locked->procurement_skus)));
            unset($data['result'], $data['warehouse_reject_reason'], $data['sku_weight_rows']);
            $locked->fill([
                ...$data,
                'warehouse_sku_weights' => $skuWeights === [] ? null : $skuWeights,
                'warehouse_acceptance_result' => $result,
                'warehouse_reject_reason' => $rejectReason,
                // 审核不通过时回退到检验通过，商品信息检验可修改后重新提交审核
                'status' => $rejected ? ProductRequest::STATUS_INSPECTION_PASSED : $locked->status,
                'operation_rectification_status' => $rejected ? ProductRequest::RECTIFICATION_PENDING : ProductRequest::RECTIFICATION_NONE,
                'procurement_rectification_status' => $rejected ? ProductRequest::RECTIFICATION_PENDING : ProductRequest::RECTIFICATION_NONE,
                // 仓库重新审核不通过时重置复查状态，待双方整改完成后重新进入复查流程
                'placement_recheck_status' => $rejected ? ProductRequest::RECTIFICATION_NONE : $locked->placement_recheck_status,
                'warehouse_operator_id' => $actor['user_id'],
                'warehouse_operator_name' => $actor['user_name'],
                'warehouse_operator_department_name' => $actor['department_name'],
                'warehouse_operator_display_name' => $actor['display_name'],
                'warehouse_processed_at' => now(),
            ])->save();
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

        $path = Storage::disk($attachment->disk)->path($attachment->path);
        $mimeType = (string) ($attachment->mime_type ?: '');
        // 列表缩略图按 ?w= 生成缓存小图，避免每张原图（截图常见近 1MB）都完整下载
        $width = (int) $request->query('w', 0);
        if ($width > 0 && str_starts_with($mimeType, 'image/') && ($thumb = $this->thumbnailPath($path, $width)) !== null) {
            return $this->cachedImageResponse($thumb, 'image/jpeg');
        }

        return $this->cachedImageResponse($path, $mimeType ?: null);
    }

    /**
     * 按宽度生成缩略图并缓存到本地磁盘，原图更新后自动重建；GD 不可用时返回 null 走原图。
     */
    private function thumbnailPath(string $sourcePath, int $width): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale') || !function_exists('imagejpeg')) {
            return null;
        }

        $width = max(80, min(1600, $width));
        $modifiedAt = (int) (@filemtime($sourcePath) ?: 0);
        $target = storage_path('app/private/thumbnails/'.$width.'/'.md5($sourcePath.'|'.$modifiedAt).'.jpg');

        if (is_file($target)) {
            return $target;
        }

        // 缩略图生成失败（GD 缺失、超大图内存不足、目录不可写等）时统一回退原图，
        // 不能让 ?w= 请求直接报错，否则列表图片会整片无法访问
        try {
            $info = @getimagesize($sourcePath);
            if (!$info || (int) $info[0] <= $width || (int) $info[1] <= 0) {
                return null;
            }
            // 超大图解码会占用大量内存，直接跳过缩略图，避免触发内存耗尽导致 500
            if ((int) $info[0] * (int) $info[1] > 20000000) {
                return null;
            }

            $contents = @file_get_contents($sourcePath);
            if ($contents === false) {
                return null;
            }

            $image = @imagecreatefromstring($contents);
            if (!$image) {
                return null;
            }

            $height = (int) max(1, round($info[1] * $width / $info[0]));
            $resized = @imagescale($image, $width, $height, IMG_BILINEAR_FIXED);
            imagedestroy($image);
            if (!$resized) {
                return null;
            }

            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0775, true);
            }
            @imagejpeg($resized, $target, 82);
            imagedestroy($resized);
        } catch (\Throwable $e) {
            return null;
        }

        return is_file($target) ? $target : null;
    }

    /**
     * 图片响应统一加浏览器缓存头：附件/图片按 uuid 命名且不覆盖，
     * 首次加载后浏览器直接命中本地缓存，避免列表里几十张缩略图每次都回源触发框架启动与鉴权查询。
     */
    private function cachedImageResponse(string $path, ?string $mimeType = null): Response
    {
        $lastModified = @filemtime($path) ?: time();
        $lastModifiedHttp = gmdate('D, d M Y H:i:s', $lastModified).' GMT';
        $etag = '"'.md5($path.'|'.$lastModified.'|'.(@filesize($path) ?: 0)).'"';
        $mimeType = $mimeType ?: (string) (@mime_content_type($path) ?: 'application/octet-stream');
        $cacheHeaders = [
            'Cache-Control' => 'private, max-age=604800, immutable',
            'ETag' => $etag,
            'Last-Modified' => $lastModifiedHttp,
        ];

        $request = request();
        if ($request->headers->get('If-None-Match') === $etag
            || $request->headers->get('If-Modified-Since') === $lastModifiedHttp) {
            return response('', 304, $cacheHeaders);
        }

        return response()->file($path, $cacheHeaders + ['Content-Type' => $mimeType]);
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
            'operation_rectification_status' => in_array($request->input('operation_rectification_status'), array_keys($this->rectificationLabels()), true) ? (string) $request->input('operation_rectification_status') : '',
            'procurement_rectification_status' => in_array($request->input('procurement_rectification_status'), array_keys($this->rectificationLabels()), true) ? (string) $request->input('procurement_rectification_status') : '',
            'placement_recheck_status' => in_array($request->input('placement_recheck_status'), array_keys($this->rectificationLabels()), true) ? (string) $request->input('placement_recheck_status') : '',
            'assign_status' => in_array($request->input('assign_status'), ['unassigned', 'assigned'], true) ? (string) $request->input('assign_status') : '',
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
            if ($filters['status'] === 'inspection_rejected') {
                $query->whereNotNull('inspection_reject_reason')->where('inspection_reject_reason', '!=', '');
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if ($filters['review_status'] !== '') {
            $query->where('placement_review_status', $filters['review_status']);
        }
        if (($filters['warehouse_status'] ?? '') !== '') {
            $query->where('warehouse_acceptance_result', $filters['warehouse_status']);
        }
        if (($filters['operation_rectification_status'] ?? '') !== '') {
            $query->where('operation_rectification_status', $filters['operation_rectification_status']);
        }
        if (($filters['procurement_rectification_status'] ?? '') !== '') {
            $query->where('procurement_rectification_status', $filters['procurement_rectification_status']);
        }
        if (($filters['placement_recheck_status'] ?? '') !== '') {
            $query->where('placement_recheck_status', $filters['placement_recheck_status']);
        }
        if (($filters['assign_status'] ?? '') !== '') {
            if ($filters['assign_status'] === 'unassigned') {
                $query->whereNull('procurement_assignee_id');
            } else {
                $query->whereNotNull('procurement_assignee_id');
            }
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function validateOperationData(Request $request, ?ProductRequest $productRequest = null, ?ProductRequestDraft $draft = null): array
    {
        $hasProductImage = false;
        $hasPurchaseImages = false;
        if ($productRequest) {
            $productRequest->loadMissing('attachments');
            $hasProductImage = $productRequest->attachmentsFor(ProductRequestAttachment::STAGE_OPERATION)->isNotEmpty();
            $hasPurchaseImages = $productRequest->attachmentsFor(ProductRequestAttachment::STAGE_PURCHASE_ITEM)->isNotEmpty();
        } elseif ($draft) {
            // 草稿已保存的图片同样视为已上传，提交时无需重复选择
            $hasProductImage = $this->draftItems($draft, 'images') !== [];
            $hasPurchaseImages = $this->draftSplitItems($draft, $request, 'purchase_images', 'purchase_images_removed')[0] !== [];
        }

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
                'regex:#^https://detail\.1688\.com/#i',
            ],
            'has_stock' => ['required', Rule::in(['yes', 'no'])],
            'product_image' => [$hasProductImage ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'purchase_item_images' => [$hasPurchaseImages ? 'nullable' : 'required', 'array', 'min:1', 'max:6'],
            'purchase_item_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'operation_remark' => ['nullable', 'string', 'max:2000'],
        ], [
            'operation_supplier_url.regex' => __('product_requests.operation.link_prefix_error'),
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
    private function validateProcurementData(Request $request, ?ProductRequest $productRequest = null, ?ProductRequestDraft $draft = null): array
    {
        $rejected = $request->input('result') === 'rejected';
        $hasExistingImages = $productRequest
            ? $productRequest->loadMissing('attachments')->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)->isNotEmpty()
            : false;
        $reusedImages = (int) $request->input('reuse_source_id', 0) > 0;
        // 草稿中保留的图片同样视为已上传，正式保存时会被接管为附件
        $hasDraftImages = $draft
            ? $this->draftSplitItems($draft, $request, 'images', 'removed_seller_images')[0] !== []
            : false;

        if ($rejected) {
            return $request->validate([
                'result' => ['required', Rule::in(['passed', 'rejected'])],
                'inspection_reject_reason' => ['required', 'string', 'max:2000'],
            ]);
        }

        $data = $request->validate([
            'result' => ['required', Rule::in(['passed', 'rejected'])],
            'standard_chinese_name' => ['required', 'string', 'max:191'],
            'supplier_url' => ['required', 'string', 'max:2048', 'regex:#^https?://#i'],
            'company_spu' => ['nullable', 'string', 'max:191'],
            'sku_sequence' => ['nullable', 'string', 'max:191'],
            'standard_sku' => ['nullable', 'string', 'max:191'],
            'stock_status' => ['nullable', Rule::in(['yes', 'no'])],
            'minimum_order_quantity' => ['nullable', 'string', 'max:255'],
            'pcl_product_no' => ['required', 'string', 'max:100'],
            'erp_binding_status' => ['nullable', Rule::in(['unbound', 'bound'])],
            'selected_skus' => ['nullable', 'array', 'max:100'],
            'selected_skus.*' => ['string', 'max:500'],
            'skus' => ['nullable', 'string', 'max:10000'],
            'link_skus' => ['nullable', 'string', 'max:20000'],
            'sku_value_rows' => ['nullable', 'string', 'max:20000'],
            // 卖家图 / 实拍图必填：已保存附件或复用来源已带图时无需重复上传
            'seller_images' => ($reusedImages || $hasExistingImages || $hasDraftImages) ? ['nullable', 'array', 'max:3'] : ['required', 'array', 'max:3'],
            'seller_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'reuse_source_id' => ['nullable', 'integer'],
            'removed_seller_images' => ['nullable', 'string', 'max:100'],
            'inspection_reject_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // 运营填写且勾选的 SKU 必须填写采购价与重量（未勾选的 SKU 不要求）
        $validator = Validator::make($request->all(), []);
        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($request): void {
            $checkedSkus = collect((array) $request->input('selected_skus', []))
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter(fn (string $value): bool => $value !== '')
                ->unique();
            // 未勾选任何系统已录入 SKU 时，运营填写的 SKU 至少要勾选一个
            if ($checkedSkus->isEmpty()) {
                $validator->errors()->add('selected_skus', __('product_requests.procurement.sku_check_required'));
                return;
            }
            $operationSkus = $this->splitSkus(str_replace(["\r\n", "\r", "\n"], ',', (string) $request->input('skus', '')));
            $rows = json_decode((string) $request->input('sku_value_rows', ''), true);
            $rows = is_array($rows) ? $rows : [];
            $checkedSkus->intersect($operationSkus)
                ->each(function (string $sku) use ($rows, $validator): void {
                    $row = $rows[$sku] ?? [];
                    $price = trim((string) (is_array($row) ? ($row['price'] ?? '') : ''));
                    $weight = trim((string) (is_array($row) ? ($row['weight'] ?? '') : ''));
                    if ($price === '' || $weight === '') {
                        $validator->errors()->add('sku_value_rows', __('product_requests.procurement.sku_value_required', ['sku' => $sku]));
                    }
                });
        });
        $validator->validate();

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateWarehouseData(Request $request, ProductRequest $productRequest): array
    {
        $rejected = $request->input('result') === ProductRequest::WAREHOUSE_REJECTED;

        $data = $request->validate([
            'result' => ['required', Rule::in([ProductRequest::WAREHOUSE_PASSED, ProductRequest::WAREHOUSE_REJECTED])],
            'sku_weight_rows' => ['nullable', 'array', 'max:100'],
            'sku_weight_rows.*.sku' => ['nullable', 'string', 'max:500'],
            'sku_weight_rows.*.weight' => [Rule::requiredIf(! $rejected), 'nullable', 'string', 'max:100'],
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
        ]);
        $data['warehouse_remark'] = $this->sanitizeRichRemark($data['warehouse_remark'] ?? null);

        return $data;
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            ProductRequest::STATUS_PENDING => __('product_requests.status.pending'),
            'inspection_rejected' => __('product_requests.status.inspection_rejected'),
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
    private function rectificationLabels(): array
    {
        return [
            ProductRequest::RECTIFICATION_NONE => __('product_requests.rectification.none'),
            ProductRequest::RECTIFICATION_PENDING => __('product_requests.rectification.pending'),
            ProductRequest::RECTIFICATION_DONE => __('product_requests.rectification.done'),
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

    /** @return array<string, array{price: string, weight: string}> 同链接按 SKU 保存的采购价/重量（链接表为基础，历史记录新值覆盖） */
    private function linkSkuValues(string $hash): array
    {
        $skuValues = [];
        $linkRow = ProductRequestLinkSku::query()->where('supplier_url_hash', $hash)->first(['sku_values']);
        foreach ((array) ($linkRow?->sku_values ?? []) as $sku => $row) {
            if (!is_array($row)) {
                continue;
            }
            $skuValues[$sku] = [
                'price' => (string) ($row['price'] ?? ''),
                'weight' => (string) ($row['weight'] ?? ''),
            ];
        }
        ProductRequest::query()
            ->where('supplier_url_hash', $hash)
            ->whereNotNull('procurement_sku_values')
            ->orderBy('id')
            ->get(['procurement_sku_values'])
            ->each(function (ProductRequest $record) use (&$skuValues): void {
                foreach ((array) $record->procurement_sku_values as $sku => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $skuValues[$sku] = [
                        'price' => (string) ($row['price'] ?? ''),
                        'weight' => (string) ($row['weight'] ?? ''),
                    ];
                }
            });

        return $skuValues;
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

    /**
     * 把提报记录采购阶段的实拍图同步复制到链接 SKU 记录（PCL SKU 管理可编辑）：
     * 复制为独立文件，删除链接图片不影响流程附件；按内容去重，合计最多 3 张
     */
    private function syncLinkSellerImages(ProductRequest $productRequest): void
    {
        $hash = (string) $productRequest->supplier_url_hash;
        if ($hash === '') {
            return;
        }
        $linkSku = ProductRequestLinkSku::query()->where('supplier_url_hash', $hash)->first();
        if (!$linkSku) {
            return;
        }

        $disk = Storage::disk('local');
        $images = array_values((array) ($linkSku->images ?? []));
        $known = [];
        foreach ($images as $path) {
            if (is_string($path) && $path !== '' && $disk->exists($path)) {
                $known[md5((string) $disk->get($path))] = true;
            }
        }

        $attachments = $productRequest->attachments()
            ->where('stage', ProductRequestAttachment::STAGE_PROCUREMENT)
            ->orderBy('sort')
            ->get();
        foreach ($attachments as $attachment) {
            if (count($images) >= 3) {
                break;
            }
            $sourceDisk = Storage::disk($attachment->disk ?: 'local');
            $path = (string) $attachment->path;
            if ($path === '' || !$sourceDisk->exists($path)) {
                continue;
            }
            $md5 = md5((string) $sourceDisk->get($path));
            if (isset($known[$md5])) {
                continue;
            }
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $newPath = 'link-skus/'.$linkSku->getKey().'/'.Str::uuid().($extension !== '' ? '.'.$extension : '.jpg');
            $disk->put($newPath, $sourceDisk->get($path));
            $images[] = $newPath;
            $known[$md5] = true;
        }

        if ($images !== array_values((array) ($linkSku->images ?? []))) {
            $linkSku->forceFill(['images' => $images === [] ? null : $images])->save();
        }
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

    /**
     * 解析 SKU 价格/重量 JSON，仅保留本次勾选的 SKU
     *
     * @param  list<string>  $checkedSkus
     * @return array<string, array{price: string, weight: string}>
     */
    private function parseProcurementSkuValues(string $raw, array $checkedSkus): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($checkedSkus as $sku) {
            $row = $decoded[$sku] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $price = mb_substr(trim((string) ($row['price'] ?? '')), 0, 100);
            $weight = mb_substr(trim((string) ($row['weight'] ?? '')), 0, 100);
            if ($price === '' && $weight === '') {
                continue;
            }
            $result[$sku] = ['price' => $price, 'weight' => $weight];
        }
        return $result;
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
            'sku_values' => $productRequest->procurement_sku_values ?: new \stdClass(),
            'seller_images' => $productRequest->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)
                ->map(fn (ProductRequestAttachment $attachment): array => [
                    'url' => route('product-requests.attachments.show', [$productRequest, $attachment]),
                    'name' => $attachment->original_name,
                ])->values()->all(),
        ];
    }

    /**
     * 链接 SKU 表命中时合成复用记录：SKU/价格/重量/PCL/实拍图取链接级数据，
     * 品名等展示字段取同链接最新提报记录（无录入记录时为空）
     *
     * @return array<string, mixed>
     */
    private function linkSkuReusePayload(ProductRequestLinkSku $linkSku): array
    {
        $hash = (string) $linkSku->supplier_url_hash;
        $latest = ProductRequest::query()
            ->where('supplier_url_hash', $hash)
            ->with('attachments')
            ->orderByDesc('updated_at')
            ->first();

        $images = array_values((array) ($linkSku->images ?? []));
        $sellerImages = collect($images)
            ->map(fn (string $path, int $index): array => [
                'url' => route('product-requests.link-skus.image', [$linkSku, $index]),
                'name' => basename($path),
            ])->values()->all();
        // images 数组基本未存储（全表 NULL），兜底用 product_image 封面显示
        if ($sellerImages === [] && $linkSku->product_image) {
            $sellerImages = [[
                'url' => route('product-requests.link-skus.product-image', $linkSku),
                'name' => basename($linkSku->product_image),
            ]];
        }
        if ($sellerImages === [] && $latest) {
            $sellerImages = $latest->attachmentsFor(ProductRequestAttachment::STAGE_PROCUREMENT)
                ->map(fn (ProductRequestAttachment $attachment): array => [
                    'url' => route('product-requests.attachments.show', [$latest, $attachment]),
                    'name' => $attachment->original_name,
                ])->values()->all();
        }

        $cover = $latest?->attachmentsFor(ProductRequestAttachment::STAGE_OPERATION)->first();
        $pcl = trim((string) $linkSku->pcl_product_no) !== '' ? $linkSku->pcl_product_no : $latest?->pcl_product_no;
        $name = trim((string) ($latest?->standard_chinese_name ?? '')) !== ''
            ? $latest->standard_chinese_name
            : (trim((string) $linkSku->product_name) !== '' ? $linkSku->product_name : null);

        return [
            'id' => null,
            'report_no' => __('product_requests.procurement.dedupe_link_sku_source'),
            'cover_url' => $linkSku->product_image
                ? route('product-requests.link-skus.product-image', $linkSku)
                : ($sellerImages[0]['url'] ?? ($latest && $cover ? route('product-requests.attachments.show', [$latest, $cover]) : null)),
            'standard_chinese_name' => $name,
            'pcl_product_no' => $pcl,
            'supplier_url' => $linkSku->supplier_url,
            'minimum_purchase_price_rmb' => null,
            'estimated_shipping_weight_g' => null,
            'outer_package_size' => null,
            'large_volume_weight_g' => null,
            'skus' => $this->splitSkus($this->linkSkuSet($hash)),
            'sku_values' => (object) $this->linkSkuValues($hash),
            'seller_images' => $sellerImages,
        ];
    }
}
