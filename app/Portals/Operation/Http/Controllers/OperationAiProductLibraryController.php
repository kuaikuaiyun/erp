<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Models\AikaipinProductPage;
use App\Models\AikaipinPlacementEvaluation;
use App\Models\AikaipinPlacementEvaluationRecord;
use App\Models\AiProductShopifyPublication;
use App\Models\AssistantDomainStore;
use App\Models\OperationAiProductReferenceVideo;
use App\Models\Portal;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Aikaipin\AikaipinProductLibraryStorageService;
use App\Portals\Operation\AiProductShopifyPublisher;
use App\Portals\ProductRequests\ProductRequestAccessService;
use App\Services\AiProductListingPushService;
use App\Services\OperatorMaterialVideoNotificationService;
use App\Support\PrivateVideoResponse;
use Encore\Admin\Facades\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OperationAiProductLibraryController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
        private readonly AiProductShopifyPublisher $shopifyPublisher,
        private readonly AiProductListingPushService $listingPush,
        private readonly OperatorMaterialVideoNotificationService $materialVideoNotifications,
        private readonly PrivateVideoResponse $privateVideoResponse,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $filters = $this->filters($request);

        return view('portals.operation.ai-product-library.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'products' => $this->productQuery($filters)->paginate(15)->withQueryString(),
            'filters' => $filters,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function placementEvaluationsIndex(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;

        $filters = ['keyword' => trim((string) $request->query('keyword', ''))];
        $products = $this->productQuery([
            'keyword' => $filters['keyword'], 'category' => '', 'source' => '', 'type' => '',
        ])->with(['placementEvaluations' => fn ($query) => $query->with('records')->latest('updated_at')])
            ->paginate(15)->withQueryString();

        return view('portals.aikaipin.placement-evaluation.index', [
            'portal' => $context['portal']->loadMissing('menus'),
            'user' => $context['user'],
            'products' => $products,
            'filters' => $filters,
            'canReviewAllPlacementEvaluations' => false,
            'placementRouteNames' => $this->operationPlacementRouteNames(),
        ]);
    }

    public function placementEvaluationProductOptions(Request $request): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;

        $products = $this->productQuery([
            'keyword' => trim((string) $request->query('keyword', '')),
            'category' => '', 'source' => '', 'type' => '',
        ])->limit(12)->get(['id', 'product_code', 'name']);

        return response()->json(['products' => $products->map(static fn (AikaipinProduct $product): array => [
            'id' => (int) $product->getKey(), 'code' => (string) $product->product_code, 'name' => (string) $product->name,
        ])->values()->all()]);
    }

    public function placementEvaluationProductDetails(Request $request, AikaipinProduct $product): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((bool) $product->is_published, 404);

        $evaluations = AikaipinPlacementEvaluation::query()->with('records')
            ->where('product_id', $product->getKey())
            ->latest('updated_at')->get();

        return response()->json([
            'product' => ['code' => $product->product_code, 'name' => $product->name, 'skus' => $product->skuValues()],
            'evaluations' => $evaluations->map(fn (AikaipinPlacementEvaluation $evaluation): array => [
                'code' => $evaluation->evaluation_code,
                'submitter' => $evaluation->created_by_name,
                'period' => $evaluation->test_started_on?->format('Y-m-d').' 至 '.$evaluation->data_ended_on?->format('Y-m-d'),
                'records' => $evaluation->records->map(fn (AikaipinPlacementEvaluationRecord $record): array => [
                    'platform' => $record->platform, 'currency' => $record->currency,
                    'spend' => (string) $record->actual_spend, 'orders' => (int) $record->orders,
                    'roi' => $record->roi !== null ? (string) $record->roi : null,
                    'material_name' => $record->material_name,
                    'video_code' => $record->video_name ? pathinfo((string) $record->video_name, PATHINFO_FILENAME) : null,
                    'video_url' => $record->video_path
                        && (! OperationAiProductReferenceVideo::isOperatorMaterialFile($record->video_name)
                            || (int) $evaluation->created_by === (int) $context['user']->getKey())
                        ? route('operation.ai-product-library.placement-evaluations.video', [$evaluation, $record]) : null,
                ])->values(),
            ])->values(),
        ]);
    }

    public function storeStandalonePlacementEvaluation(Request $request): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $data = $this->validatedStandalonePlacementEvaluation($request);
        $product = $this->standalonePlacementProduct((int) $data['product_id']);
        $records = $this->standalonePlacementRecords($data['records']);
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            $evaluation = DB::connection($connection)->transaction(function () use ($context, $data, $product, $records): AikaipinPlacementEvaluation {
                $evaluation = AikaipinPlacementEvaluation::query()->create([
                    'evaluation_code' => $this->nextPlacementEvaluationCode(),
                    'product_id' => $product->getKey(), 'product_code' => $product->product_code, 'product_name' => $product->name,
                    'test_started_on' => $data['test_started_on'], 'data_ended_on' => $data['data_ended_on'],
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'created_by' => $context['user']->getKey(), 'created_by_name' => $context['user']->name ?: $context['user']->username,
                    'updated_by' => $context['user']->getKey(), 'updated_by_name' => $context['user']->name ?: $context['user']->username,
                ]);
                foreach ($records as $sort => $record) $evaluation->records()->create([...$record, 'sort' => $sort]);
                return $evaluation;
            });
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => __('aikaipin.placement_evaluation.messages.save_failed_message')], 500);
        }

        return response()->json([
            'message' => __('aikaipin.placement_evaluation.messages.created_message'),
            'redirect_url' => route('operation.ai-product-library.placement-evaluations.index'),
            'evaluation_id' => $evaluation->getKey(),
        ], 201);
    }

    public function showStandalonePlacementEvaluation(Request $request, AikaipinPlacementEvaluation $evaluation): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->assertOwnPlacementEvaluation($context['user'], $evaluation);
        $evaluation->load('records');
        return response()->json($this->standalonePlacementPayload($evaluation));
    }

    public function updateStandalonePlacementEvaluation(Request $request, AikaipinPlacementEvaluation $evaluation): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->assertOwnPlacementEvaluation($context['user'], $evaluation);
        $data = $this->validatedStandalonePlacementEvaluation($request);
        $product = $this->standalonePlacementProduct((int) $data['product_id']);
        $records = $this->standalonePlacementRecords($data['records']);
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            DB::connection($connection)->transaction(function () use ($context, $evaluation, $data, $product, $records): void {
                $evaluation->forceFill([
                    'product_id' => $product->getKey(), 'product_code' => $product->product_code, 'product_name' => $product->name,
                    'test_started_on' => $data['test_started_on'], 'data_ended_on' => $data['data_ended_on'],
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'updated_by' => $context['user']->getKey(), 'updated_by_name' => $context['user']->name ?: $context['user']->username,
                ])->save();
                $evaluation->records()->delete();
                foreach ($records as $sort => $record) $evaluation->records()->create([...$record, 'sort' => $sort]);
            });
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => __('aikaipin.placement_evaluation.messages.save_failed_message')], 500);
        }

        return response()->json([
            'message' => __('aikaipin.placement_evaluation.messages.updated_message'),
            'redirect_url' => route('operation.ai-product-library.placement-evaluations.index'),
            'evaluation_id' => $evaluation->getKey(),
        ]);
    }

    public function destroyStandalonePlacementEvaluation(Request $request, AikaipinPlacementEvaluation $evaluation): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->assertOwnPlacementEvaluation($context['user'], $evaluation);
        $connection = config('admin.database.connection') ?: config('database.default');
        DB::connection($connection)->transaction(function () use ($evaluation): void {
            $evaluation->records()->delete();
            $evaluation->delete();
        });

        return response()->json([
            'message' => __('aikaipin.placement_evaluation.messages.deleted_message'),
            'redirect_url' => route('operation.ai-product-library.placement-evaluations.index'),
        ]);
    }

    public function image(Request $request, AikaipinProduct $product, int $imageIndex): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $file = $product->imageFiles()[$imageIndex] ?? null;
        abort_unless(is_array($file), 404);

        $disk = (string) ($file['disk'] ?? 'local');
        $path = (string) ($file['path'] ?? '');
        abort_unless($path !== '' && Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path, (string) ($file['original_name'] ?? basename($path)), [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function detail(Request $request, AikaipinProduct $product): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $product->loadMissing('productPages');

        return response()->json([
            'id' => (int) $product->getKey(),
            'product_code' => (string) $product->product_code,
            'name' => (string) $product->name,
            'skus' => $product->skuValues(),
            'categories' => $product->categoryValues(),
            'source_platform' => (string) $product->source_platform,
            'source_label' => (string) __('aikaipin.upload.source_options.'.$product->source_platform),
            'product_type' => (string) $product->product_type,
            'type_label' => (string) __('aikaipin.upload.type_options.'.$product->product_type),
            'landing_page_url' => $product->landing_page_url,
            'images' => array_map(
                fn (int $index): string => route('operation.ai-product-library.images.show', ['product' => $product, 'imageIndex' => $index]),
                array_keys($product->imageFiles()),
            ),
            'reference_file_name' => $product->referenceFilePayload()['original_name'] ?? null,
            'package_file_name' => $product->packageFilePayload()['original_name'] ?? null,
            'package_download_url' => $product->packageFilePayload() !== null
                ? route('operation.ai-product-library.package.download', ['product' => $product])
                : null,
            'created_by_name' => $product->created_by_name,
            'updated_by_name' => $product->updated_by_name,
            'shopify_data' => $product->hasShopifyData() ? [
                'title' => (string) data_get($product->shopifyData(), 'title'),
                'vendor' => (string) data_get($product->shopifyData(), 'vendor'),
                'product_type' => (string) data_get($product->shopifyData(), 'product_type'),
                'variant_count' => count((array) data_get($product->shopifyData(), 'variants', [])),
                'image_count' => count((array) data_get($product->shopifyData(), 'images', [])),
            ] : null,
            'product_pages' => $product->productPages->map(static function (AikaipinProductPage $page): array {
                $shopify = $page->shopifyData();

                return [
                    'id' => (int) $page->getKey(),
                    'name' => (string) $page->name,
                    'source_file' => (string) ($page->source_file ?: data_get($shopify, 'source_file', '')),
                    'landing_page_url' => $page->landing_page_url,
                    'is_default' => (bool) $page->is_default,
                    'title' => (string) data_get($shopify, 'title', ''),
                    'vendor' => (string) data_get($shopify, 'vendor', ''),
                    'product_type' => (string) data_get($shopify, 'product_type', ''),
                    'variant_count' => count((array) data_get($shopify, 'variants', [])),
                    'image_count' => count((array) data_get($shopify, 'images', [])),
                    'imported_at' => (string) data_get($shopify, 'imported_at', ''),
                ];
            })->values()->all(),
            'created_at' => $product->created_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'updated_at' => $product->updated_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'material_videos' => $this->referenceVideos($product, $context['user'])->map(fn (OperationAiProductReferenceVideo $video): array => [
                'id' => (int) $video->getKey(),
                'type' => (string) $video->type,
                'type_label' => match ($video->type) {
                    OperationAiProductReferenceVideo::TYPE_FLOW => 'Flow素材',
                    OperationAiProductReferenceVideo::TYPE_TRAFFIC => '扒的素材',
                    OperationAiProductReferenceVideo::TYPE_REMIX => '混剪素材',
                    default => '素材视频',
                },
                'video_code' => pathinfo((string) $video->file_name, PATHINFO_FILENAME),
                'file_name' => (string) $video->file_name,
                'size' => number_format(((int) $video->size) / 1048576, 2).' MB',
                'uploaded_by_name' => (string) ($video->uploaded_by_name ?: '—'),
                'preview_url' => route('operation.ai-product-library.reference-videos.preview', [$product, $video], false),
                'download_url' => route('operation.ai-product-library.reference-videos.download', [$product, $video], false),
            ])->values()->all(),
            'material_video_upload_url' => route('operation.ai-product-library.reference-videos.upload', absolute: false),
        ]);
    }

    public function placementEvaluationForm(Request $request, AikaipinProduct $product): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((bool) $product->is_published, 404);

        return view('portals.operation.ai-product-library.placement-evaluation-form', [
            'product' => $product,
            'evaluations' => AikaipinPlacementEvaluation::query()
                ->with('records')
                ->where('product_id', $product->getKey())
                ->where('created_by', $context['user']->getKey())
                ->latest('updated_at')->limit(20)->get(),
        ]);
    }

    public function searchPlacementMaterialVideos(Request $request, AikaipinProduct $product): JsonResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((bool) $product->is_published, 404);

        $keyword = trim((string) $request->query('keyword', ''));
        if (mb_strlen($keyword) > 191) {
            $keyword = mb_substr($keyword, 0, 191);
        }
        if ($keyword === '') return response()->json(['items' => []]);

        $like = '%'.addcslashes($keyword, '\\%_').'%';
        $videos = $this->visibleReferenceVideoQuery($product, $context['user'])
            ->where(function (Builder $query) use ($like): void {
                $query->where('file_name', 'like', $like)
                    ->orWhere('original_name', 'like', $like);
            })
            ->orderByDesc('file_date')
            ->orderByDesc('sequence')
            ->limit(20)
            ->get();

        return response()->json(['items' => $videos->map(fn (OperationAiProductReferenceVideo $video): array => [
            'id' => (int) $video->getKey(),
            'name' => (string) $video->file_name,
            'original_name' => (string) ($video->original_name ?: ''),
            'type' => match ($video->type) {
                OperationAiProductReferenceVideo::TYPE_FLOW => 'Flow素材',
                OperationAiProductReferenceVideo::TYPE_TRAFFIC => '扒的素材',
                OperationAiProductReferenceVideo::TYPE_REMIX => '混剪素材',
                default => '素材视频',
            },
            'size' => number_format(((int) $video->size) / 1048576, 2).' MB',
            'preview_url' => route('operation.ai-product-library.reference-videos.preview', [$product, $video]),
        ])->values()->all()]);
    }

    public function storePlacementEvaluation(Request $request, AikaipinProduct $product): RedirectResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((bool) $product->is_published, 404);
        $data = $request->validate([
            'test_started_on' => ['required', 'date'],
            'data_ended_on' => ['required', 'date', 'after_or_equal:test_started_on'],
            'records' => ['required', 'array', 'min:1', 'max:6'],
            'records.*.platform' => ['required', Rule::in(['facebook', 'google', 'tiktok', 'mediago', 'newsbreak', 'applovin'])],
            'records.*.currency' => ['required', Rule::in(['usd', 'eur', 'cny'])],
            'records.*.actual_spend' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'records.*.orders' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'records.*.roi' => ['required', 'numeric', 'min:0', 'max:99999999.9999'],
            'material_name' => ['required', 'string', 'max:191'],
            'material_video_id' => ['required', 'integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ], [
            'material_name.required' => '请填写素材名称。',
            'material_video_id.required' => '请从素材名称搜索结果中选择一个已有视频。',
        ]);

        $date = now('Asia/Shanghai');
        $productCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
        $path = null;
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            $libraryVideo = $this->visibleReferenceVideoQuery($product, $context['user'])
                ->whereKey((int) $data['material_video_id'])
                ->first();
            if (! $libraryVideo || ! Storage::disk($libraryVideo->disk ?: 'local')->exists($libraryVideo->path)) {
                throw ValidationException::withMessages([
                    'material_name' => '选择的素材视频不存在，请重新搜索选择。',
                ]);
            }
            $extension = strtolower(pathinfo((string) $libraryVideo->file_name, PATHINFO_EXTENSION) ?: 'mp4');
            $videoCode = pathinfo((string) $libraryVideo->file_name, PATHINFO_FILENAME);
            $storedFileName = $productCode.'-selected-'.$date->format('ymd').'-'.Str::upper((string) Str::ulid()).'.'.$extension;
            $recordVideoName = $videoCode.'.'.$extension;
            $path = 'aikaipin/placement-evaluations/'.$context['user']->getKey().'/'.$product->getKey().'/'.$storedFileName;
            $sourceDisk = Storage::disk($libraryVideo->disk ?: 'local');
            $stream = $sourceDisk->readStream($libraryVideo->path);
            if (! is_resource($stream)) {
                throw ValidationException::withMessages(['material_name' => '素材视频读取失败，请重新选择。']);
            }
            try {
                Storage::disk('local')->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }
            $videoMimeType = $libraryVideo->mime_type ?: 'video/mp4';
            $videoSize = (int) ($libraryVideo->size ?: Storage::disk('local')->size($path));
            DB::connection($connection)->transaction(function () use ($context, $product, $data, $recordVideoName, $path, $videoMimeType, $videoSize): void {
                $evaluation = AikaipinPlacementEvaluation::query()->create([
                    'evaluation_code' => $this->nextPlacementEvaluationCode(),
                    'product_id' => $product->getKey(),
                    'product_code' => $product->product_code,
                    'product_name' => $product->name,
                    'test_started_on' => $data['test_started_on'],
                    'data_ended_on' => $data['data_ended_on'],
                    'remark' => ($data['remark'] ?? null) ?: null,
                    'created_by' => $context['user']->getKey(),
                    'created_by_name' => $context['user']->name ?: $context['user']->username,
                    'updated_by' => $context['user']->getKey(),
                    'updated_by_name' => $context['user']->name ?: $context['user']->username,
                ]);
                foreach ($data['records'] as $index => $recordData) {
                    $evaluation->records()->create([
                        'platform' => $recordData['platform'], 'currency' => $recordData['currency'],
                        'actual_spend' => $recordData['actual_spend'], 'orders' => $recordData['orders'],
                        'roi' => $recordData['roi'], 'sort' => $index,
                        'material_name' => $data['material_name'], 'video_disk' => 'local', 'video_path' => $path,
                        'video_name' => $recordVideoName, 'video_mime_type' => $videoMimeType, 'video_size' => $videoSize,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if ($path) Storage::disk('local')->delete($path);
            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => '投放测评已保存。', 'redirect_url' => route('operation.ai-product-library.index')]);
        }

        return redirect()->route('operation.ai-product-library.index')->with('success', '投放测评已保存。');
    }

    public function placementEvaluationVideo(Request $request, AikaipinPlacementEvaluation $evaluation, AikaipinPlacementEvaluationRecord $record): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless(AikaipinProduct::query()
            ->whereKey($evaluation->product_id)
            ->where('is_published', true)
            ->exists(), 404);
        abort_unless((int) $record->evaluation_id === (int) $evaluation->getKey(), 404);
        abort_if(
            OperationAiProductReferenceVideo::isOperatorMaterialFile($record->video_name)
                && (int) $evaluation->created_by !== (int) $context['user']->getKey(),
            404,
        );
        abort_unless($record->video_path && Storage::disk($record->video_disk ?: 'local')->exists($record->video_path), 404);

        return response()->file(Storage::disk($record->video_disk ?: 'local')->path($record->video_path), [
            'Content-Type' => $record->video_mime_type ?: 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$record->video_name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function referencePreview(Request $request, AikaipinProduct $product): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->referencePreviewFile($product);
        $this->normalizeUploadedReferenceVideos($product, $this->historicalReferenceVideoCount($product));

        return view('portals.aikaipin.product-library.reference-preview', [
            'product' => $product,
            'contentUrl' => route('operation.ai-product-library.reference-preview.content', ['product' => $product, 'embedded' => 1]),
            'libraryUrl' => route('operation.ai-product-library.index'),
        ]);
    }

    public function uploadReferenceVideoFromLibrary(Request $request): JsonResponse|RedirectResponse|Response
    {
        $user = Admin::guard()->user();
        if (! $user instanceof AdminUser) {
            return response()->json(['message' => '登录状态已失效，请重新登录后上传。'], 401);
        }
        if (! $this->access->canViewOperationAiProductLibrary($user)) {
            return response()->json(['message' => '当前账号没有上传运营素材的权限。'], 403);
        }

        $productId = filter_var($request->input('product_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($productId === false) {
            throw ValidationException::withMessages(['product_id' => '请选择需要上传素材的产品。']);
        }

        $product = AikaipinProduct::query()->find($productId);
        if (! $product) {
            throw ValidationException::withMessages(['product_id' => '产品不存在或已被删除，请刷新页面后重试。']);
        }

        $request->attributes->set('operation_material_upload_user', $user);

        return $this->uploadReferenceVideo($request, $product);
    }

    public function uploadReferenceVideo(Request $request, AikaipinProduct $product): JsonResponse|RedirectResponse|Response
    {
        $uploadUser = $request->attributes->get('operation_material_upload_user');
        if ($uploadUser instanceof AdminUser) {
            $context = ['user' => $uploadUser];
        } else {
            $context = $this->portalContext($request);
            if ($context instanceof Response) return $context;
        }
        $data = $request->validate([
            'type' => ['required', Rule::in([
                OperationAiProductReferenceVideo::TYPE_FLOW,
                OperationAiProductReferenceVideo::TYPE_TRAFFIC,
                OperationAiProductReferenceVideo::TYPE_REMIX,
            ])],
            'video' => ['required', 'file', 'mimes:mp4,mov,avi,webm,mkv,m4v', 'max:307200'],
        ], [
            'video.required' => '请选择需要上传的视频。',
            'video.mimes' => '仅支持 MP4、MOV、AVI、WEBM、MKV、M4V 视频。',
            'video.max' => '单个视频不能超过 300MB。',
        ]);

        $file = $request->file('video');
        $recentUpload = OperationAiProductReferenceVideo::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where('type', $data['type'])
            ->where('uploaded_by', $context['user']->getKey())
            ->where('original_name', $file->getClientOriginalName())
            ->where('size', $file->getSize())
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest('id')
            ->first();
        if ($recentUpload && Storage::disk($recentUpload->disk ?: 'local')->exists($recentUpload->path)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => '视频已上传成功，无需重复上传。',
                    'video' => [
                        'id' => (int) $recentUpload->getKey(),
                        'video_code' => pathinfo((string) $recentUpload->file_name, PATHINFO_FILENAME),
                        'file_name' => (string) $recentUpload->file_name,
                    ],
                ]);
            }

            return redirect()
                ->route('operation.ai-product-library.reference-preview', ['product' => $product])
                ->with('success', '视频已上传成功，无需重复上传。');
        }

        $date = now('Asia/Shanghai');
        $historicalCount = $this->historicalReferenceVideoCount($product);
        $this->normalizeUploadedReferenceVideos($product, $historicalCount);
        $productCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
        $departmentCode = 'OP:'.$this->departmentCode($context['user']);
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'mp4');
        $fileName = null;
        $path = null;
        $video = null;
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            $video = DB::connection($connection)->transaction(function () use ($context, $product, $data, $file, $date, $historicalCount, $productCode, $departmentCode, $extension, &$fileName, &$path): OperationAiProductReferenceVideo {
                AikaipinProduct::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                $existing = OperationAiProductReferenceVideo::query()
                    ->where('aikaipin_product_id', $product->getKey())
                    ->lockForUpdate()
                    ->get(['file_name', 'sequence']);
                $sequence = max($historicalCount, (int) $existing->max('sequence')) + 1;
                $operatorVideos = OperationAiProductReferenceVideo::query()
                    ->where('uploaded_by', $context['user']->getKey())
                    ->where('source_kind', OperationAiProductReferenceVideo::SOURCE_OPERATOR_UPLOAD)
                    ->lockForUpdate()
                    ->get(['file_name', 'sequence']);
                $fileNumber = $operatorVideos->reduce(fn (int $carry, OperationAiProductReferenceVideo $item): int => max(
                    $carry,
                    OperationAiProductReferenceVideo::fileNumber($item->file_name, 0),
                ), 0) + 1;
                $fileName = OperationAiProductReferenceVideo::operatorFileName(
                    $productCode,
                    (int) $context['user']->getKey(),
                    $date->format('ymd'),
                    $fileNumber,
                    $extension,
                );
                $path = $file->storeAs('aikaipin/reference-videos/'.$product->getKey(), $fileName, 'local');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('视频文件写入失败，请检查服务器存储目录权限及剩余空间。');
                }

                return OperationAiProductReferenceVideo::query()->create([
                    'aikaipin_product_id' => $product->getKey(), 'type' => $data['type'],
                    'sequence' => $sequence, 'file_date' => $date->toDateString(), 'disk' => 'local',
                    'path' => $path, 'file_name' => $fileName, 'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                    'department_code' => $departmentCode,
                    'source_kind' => OperationAiProductReferenceVideo::SOURCE_OPERATOR_UPLOAD,
                    'uploaded_by' => $context['user']->getKey(),
                    'uploaded_by_name' => $context['user']->name ?: $context['user']->username,
                ]);
            }, 3);
        } catch (Throwable $exception) {
            if ($path) Storage::disk('local')->delete($path);
            throw $exception;
        }

        try {
            $this->materialVideoNotifications->notifyManagers($product, $video, $context['user']);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => '视频上传成功。',
                'video' => [
                    'id' => (int) $video->getKey(),
                    'video_code' => pathinfo((string) $video->file_name, PATHINFO_FILENAME),
                    'file_name' => (string) $video->file_name,
                ],
            ], 201);
        }

        return redirect()
            ->route('operation.ai-product-library.reference-preview', ['product' => $product])
            ->with('success', '视频上传成功，文件名：'.$fileName);
    }

    public function previewReferenceVideo(Request $request, AikaipinProduct $product, OperationAiProductReferenceVideo $video): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->assertReferenceVideo($product, $video, $context['user']);

        return $this->privateVideoResponse->inline(
            (string) $video->disk,
            (string) $video->path,
            (string) $video->file_name,
            $video->mime_type,
        );
    }

    public function downloadReferenceVideo(Request $request, AikaipinProduct $product, OperationAiProductReferenceVideo $video): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->assertReferenceVideo($product, $video, $context['user']);

        return Storage::disk($video->disk)->download($video->path, $video->file_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function referencePreviewContent(Request $request, AikaipinProduct $product): Response
    {
        if (! $request->boolean('embedded')) {
            return redirect()->route('operation.ai-product-library.reference-preview', ['product' => $product]);
        }
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $file = $this->referencePreviewFile($product);
        $editsPath = $file['path'].'.erp-edits.json';
        $referenceEdits = Storage::disk($file['disk'])->exists($editsPath)
            ? (json_decode(Storage::disk($file['disk'])->get($editsPath), true) ?: []) : [];
        $hiddenVideosPath = $file['path'].'.erp-hidden-videos.json';
        $referenceHiddenVideos = Storage::disk($file['disk'])->exists($hiddenVideosPath)
            ? (json_decode(Storage::disk($file['disk'])->get($hiddenVideosPath), true) ?: []) : [];
        $layoutPath = $file['path'].'.erp-layout.json';
        $referenceLayouts = Storage::disk($file['disk'])->exists($layoutPath)
            ? (json_decode(Storage::disk($file['disk'])->get($layoutPath), true) ?: []) : [];
        $historicalCount = $this->historicalReferenceVideoCount($product);
        $this->normalizeUploadedReferenceVideos($product, $historicalCount);
        $videos = $this->referenceVideos($product, $context['user'])
            ->map(fn (OperationAiProductReferenceVideo $video): array => [
                'id' => (int) $video->getKey(),
                'type' => $video->type,
                'name' => $video->file_name,
                'size' => number_format($video->size / 1048576, 2).' MB',
                'preview_url' => route('operation.ai-product-library.reference-videos.preview', [$product, $video]),
                'download_url' => route('operation.ai-product-library.reference-videos.download', [$product, $video]),
            ])->values();
        $injection = view('portals.operation.ai-product-library.reference-video-injection', [
            'videos' => $videos,
            'historicalProductCode' => trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey(),
            'historicalFallbackDate' => $product->created_at?->copy()->timezone('Asia/Shanghai')->format('ymd') ?: now('Asia/Shanghai')->format('ymd'),
            'referenceEdits' => $referenceEdits,
            'referenceHiddenVideos' => $referenceHiddenVideos,
            'referenceLayouts' => $referenceLayouts,
        ])->render();
        return response()->stream(function () use ($file, $injection): void {
            $stream = Storage::disk($file['disk'])->readStream($file['path']);
            abort_unless(is_resource($stream), 404);
            try {
                while (! feof($stream)) {
                    echo fread($stream, 1024 * 1024);
                    if (function_exists('ob_flush')) @ob_flush();
                    flush();
                }
                echo $injection;
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="preview.html"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "sandbox allow-scripts allow-modals allow-downloads; default-src 'none'; base-uri 'none'; object-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' https:; style-src 'unsafe-inline' https:; img-src data: blob: https:; font-src data: https:; media-src data: blob: https: http:; connect-src https: http:; frame-src https:; worker-src blob:; form-action 'none'; frame-ancestors 'self'",
        ]);
    }

    public function publishForm(Request $request, AikaipinProduct $product): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $product->load('productPages');
        $defaultPage = $product->productPages->first();

        return view('portals.operation.ai-product-library.publish-form', [
            'product' => $product,
            'stores' => $this->publishableStores($context['user']),
            'skus' => $product->skuValues(),
            'productPages' => $product->productPages,
            'shopifyData' => $defaultPage?->shopifyData() ?? $product->shopifyData(),
        ]);
    }

    public function publish(Request $request, AikaipinProduct $product): RedirectResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $user = $context['user'];

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'product_page_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:191'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', 'in:DRAFT,ACTIVE'],
        ]);
        $store = $this->publishableStores($user)->firstWhere('id', (int) $data['store_id']);
        abort_unless($store instanceof AssistantDomainStore, 422, '该店铺不属于当前可上架范围。');
        $productPage = filled($data['product_page_id'] ?? null)
            ? $product->productPages()->whereKey((int) $data['product_page_id'])->first()
            : $product->defaultProductPage();
        abort_if(filled($data['product_page_id'] ?? null) && ! $productPage instanceof AikaipinProductPage, 422, '选择的产品页信息不存在或不属于当前商品。');
        $pageSkus = $productPage instanceof AikaipinProductPage
            ? collect($productPage->shopifyData()['variants'] ?? [])->pluck('sku')->map(fn ($sku): string => trim((string) $sku))->filter()->all()
            : $product->skuValues();
        abort_unless(in_array($data['sku'], $pageSkus, true), 422, '请选择当前产品页中已有的 SKU。');

        $publication = AiProductShopifyPublication::query()->create([
            'aikaipin_product_id' => $product->getKey(),
            'aikaipin_product_page_id' => $productPage?->getKey(),
            'assistant_domain_store_id' => $store->getKey(),
            'published_by' => $user->getKey(),
            'published_by_name' => $user->name ?: $user->username,
            'status' => 'processing',
            'sku' => $data['sku'],
            'price' => $data['price'],
            'request_payload' => $data,
        ]);

        try {
            $result = $this->shopifyPublisher->publish(
                $product,
                $store,
                $user,
                $data['title'],
                $data['sku'],
                (float) $data['price'],
                $data['status'],
                $productPage,
            );
            $productGid = (string) data_get($result, 'product.id');
            $variantGid = (string) data_get($result, 'variant.id');
            $numericProductId = Str::afterLast($productGid, '/');
            $shopDomain = (string) ($store->shopifyConnection()->value('shop_domain') ?: $store->domain);
            $publication->update([
                'status' => 'success',
                'shopify_product_id' => $productGid,
                'shopify_variant_id' => $variantGid,
                'shopify_admin_url' => $shopDomain !== '' && $numericProductId !== ''
                    ? 'https://'.$shopDomain.'/admin/products/'.$numericProductId
                    : null,
                'response_payload' => $result['responses'],
                'published_at' => now(),
            ]);
            $this->listingPush->completeForPublication($product, $user, $publication);

            return back()->with('success', '商品已成功上架到 '.$store->shop_name.'。');
        } catch (Throwable $exception) {
            report($exception);
            $publication->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 4000),
            ]);

            return back()->withErrors(['publish' => '上架失败：'.$exception->getMessage()]);
        }
    }

    public function shopifyImage(Request $request, AikaipinProduct $product, int $imageIndex): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        $file = $product->imageFiles()[$imageIndex] ?? null;
        abort_unless(is_array($file), 404);
        $disk = (string) ($file['disk'] ?? 'local');
        $path = (string) ($file['path'] ?? '');
        abort_unless($path !== '' && Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path, (string) ($file['original_name'] ?? basename($path)), [
            'Cache-Control' => 'private, max-age=7200',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadPackage(Request $request, AikaipinProduct $product): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $file = $product->packageFilePayload();
        abort_unless($file !== null, 404);

        $disk = (string) $file['disk'];
        $path = (string) $file['path'];
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download($path, (string) $file['original_name'], [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{disk:string,path:string,original_name:string,mime_type:?string,size:int,sha256:?string} */
    private function referencePreviewFile(AikaipinProduct $product): array
    {
        $file = $product->referenceFilePayload();
        abort_unless($file !== null, 404);

        $disk = (string) $file['disk'];
        $path = ltrim(str_replace('\\', '/', trim((string) $file['path'])), '/');
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        abort_unless(
            $disk === AikaipinProductLibraryStorageService::DISK
                && $path !== ''
                && ! in_array('..', explode('/', $path), true)
                && in_array($extension, ['html', 'htm'], true),
            404,
        );
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $file['path'] = $path;

        return $file;
    }

    private function assertReferenceVideo(AikaipinProduct $product, OperationAiProductReferenceVideo $video, AdminUser $viewer): void
    {
        abort_unless((int) $video->aikaipin_product_id === (int) $product->getKey(), 404);
        abort_unless(
            strtoupper(trim((string) $video->department_code)) === 'AI'
                || (int) $video->uploaded_by === (int) $viewer->getKey(),
            404,
        );
        abort_unless(Storage::disk($video->disk)->exists($video->path), 404);
    }

    private function visibleReferenceVideoQuery(AikaipinProduct $product, AdminUser $viewer): Builder
    {
        return OperationAiProductReferenceVideo::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where(function (Builder $query) use ($viewer): void {
                $query->where('department_code', 'AI')
                    ->orWhere('uploaded_by', $viewer->getKey());
            });
    }

    private function referenceVideos(AikaipinProduct $product, ?AdminUser $viewer = null): Collection
    {
        $query = $viewer
            ? $this->visibleReferenceVideoQuery($product, $viewer)
            : OperationAiProductReferenceVideo::query()->where('aikaipin_product_id', $product->getKey());

        return $query
            ->orderByRaw("CASE type WHEN 'flow' THEN 1 WHEN 'traffic' THEN 2 WHEN 'remix' THEN 3 ELSE 4 END")
            ->orderBy('sequence')->orderBy('id')->get();
    }

    private function historicalReferenceVideoCount(AikaipinProduct $product): int
    {
        return OperationAiProductReferenceVideo::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->where('source_kind', OperationAiProductReferenceVideo::SOURCE_HISTORICAL_HTML)
            ->count();
    }

    private function normalizeUploadedReferenceVideos(AikaipinProduct $product, int $historicalCount): Collection
    {
        DB::transaction(function () use ($product, $historicalCount): void {
            $productCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
            $videos = OperationAiProductReferenceVideo::query()
                ->where('aikaipin_product_id', $product->getKey())
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $systemQuery): void {
                        $systemQuery->where('source_kind', OperationAiProductReferenceVideo::SOURCE_UPLOAD)
                            ->where(function (Builder $departmentQuery): void {
                                $departmentQuery->whereNull('department_code')
                                    ->orWhere('department_code', 'AI');
                            });
                    })->orWhere(function (Builder $legacyQuery): void {
                        $legacyQuery->whereNull('source_kind')
                            ->where(function (Builder $departmentQuery): void {
                                $departmentQuery->whereNull('department_code')
                                    ->orWhere('department_code', 'AI');
                            });
                    });
                })
                ->orderByRaw("CASE type WHEN 'flow' THEN 1 WHEN 'traffic' THEN 2 WHEN 'remix' THEN 3 ELSE 4 END")
                ->orderBy('sequence')->orderBy('id')->lockForUpdate()->get()->values();
            if ($videos->isEmpty()) return;

            $temporaryBase = (int) OperationAiProductReferenceVideo::query()
                ->where('aikaipin_product_id', $product->getKey())
                ->max('sequence') + $videos->count() + $historicalCount + 1000;
            $renames = [];
            foreach ($videos as $index => $video) {
                $sequence = $historicalCount + $index + 1;
                $fileNumber = OperationAiProductReferenceVideo::fileNumber($video->file_name, (int) $video->sequence);
                $extension = strtolower(pathinfo($video->file_name, PATHINFO_EXTENSION) ?: pathinfo($video->path, PATHINFO_EXTENSION) ?: 'mp4');
                $date = $video->file_date?->format('ymd') ?: $video->created_at?->copy()->timezone('Asia/Shanghai')->format('ymd') ?: now('Asia/Shanghai')->format('ymd');
                $typeCode = OperationAiProductReferenceVideo::fileTypeCode((string) $video->type);
                $fileName = $productCode.'-'.$typeCode.'-'.$date.'-'.str_pad((string) $fileNumber, 3, '0', STR_PAD_LEFT).'.'.$extension;
                $directory = trim(str_replace('\\', '/', dirname($video->path)), '.');
                $path = ($directory !== '' ? $directory.'/' : '').$fileName;
                $temporaryPath = ($directory !== '' ? $directory.'/' : '').'.renumber-'.$video->getKey().'-'.Str::uuid().'.'.$extension;
                $renames[] = compact('video', 'sequence', 'fileName', 'path', 'temporaryPath');
                $video->update(['sequence' => $temporaryBase + $index]);
            }

            foreach ($renames as &$rename) {
                $video = $rename['video'];
                if ($rename['path'] !== $video->path && Storage::disk($video->disk)->exists($video->path)) {
                    Storage::disk($video->disk)->move($video->path, $rename['temporaryPath']);
                    $rename['moved'] = true;
                }
            }
            unset($rename);
            foreach ($renames as $rename) {
                $video = $rename['video'];
                if (! empty($rename['moved'])) Storage::disk($video->disk)->move($rename['temporaryPath'], $rename['path']);
                $video->update(['sequence' => $rename['sequence'], 'file_name' => $rename['fileName'], 'path' => $rename['path']]);
            }
        }, 3);

        return $this->referenceVideos($product);
    }

    private function departmentCode(AdminUser $user): string
    {
        $rolesTable = config('admin.database.roles_table', 'admin_roles');
        $roleUsersTable = config('admin.database.role_users_table', 'admin_role_users');
        $database = DB::connection($user->getConnectionName());
        $subscriptionRootId = $database->table($rolesTable)->where('slug', 'dept-000022')->value('id');
        $query = $database
            ->table($roleUsersTable)
            ->join($rolesTable, $roleUsersTable.'.role_id', '=', $rolesTable.'.id')
            ->where($roleUsersTable.'.user_id', $user->getKey())
            ->where($rolesTable.'.slug', '!=', 'administrator')
            ->whereNotNull($rolesTable.'.short_name')
            ->where($rolesTable.'.short_name', '!=', '');
        $code = (clone $query)
            ->when($subscriptionRootId, fn ($builder) => $builder->where($rolesTable.'.parent_id', $subscriptionRootId))
            ->orderByRaw('CASE WHEN '.$rolesTable.'.parent_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy($rolesTable.'.id')
            ->value($rolesTable.'.short_name');
        $code ??= $query->orderBy($rolesTable.'.id')->value($rolesTable.'.short_name');

        return $this->sanitizeDepartmentCode((string) ($code ?: 'AI'));
    }

    private function nextPlacementEvaluationCode(): string
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $period = now('Asia/Shanghai')->format('Ym');
        $sequences = DB::connection($connection)->table('aikaipin_placement_evaluation_code_sequences');
        $sequences->insertOrIgnore(['period' => $period, 'last_number' => 0, 'updated_at' => now()]);
        $sequence = $sequences->where('period', $period)->lockForUpdate()->first();
        $next = (int) ($sequence->last_number ?? 0) + 1;
        $sequences->where('period', $period)->update(['last_number' => $next, 'updated_at' => now()]);

        return 'ATE-'.$period.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function sanitizeDepartmentCode(string $code): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', strtoupper($code)), '-_') ?: 'AI';
    }

    /** @return array<string, string> */
    private function operationPlacementRouteNames(): array
    {
        return [
            'index' => 'operation.ai-product-library.placement-evaluations.index',
            'store' => 'operation.ai-product-library.placement-evaluations.standalone.store',
            'product_options' => 'operation.ai-product-library.placement-evaluations.product-options',
            'product_show' => 'operation.ai-product-library.placement-evaluations.products.show',
            'show' => 'operation.ai-product-library.placement-evaluations.show',
            'update' => 'operation.ai-product-library.placement-evaluations.update',
            'destroy' => 'operation.ai-product-library.placement-evaluations.destroy',
            'image' => 'operation.ai-product-library.images.show',
        ];
    }

    /** @return array{product_id:int,test_started_on:string,data_ended_on:string,remark:?string,records:array<int,array<string,mixed>>} */
    private function validatedStandalonePlacementEvaluation(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'test_started_on' => ['required', 'date'],
            'data_ended_on' => ['required', 'date', 'after_or_equal:test_started_on'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'records' => ['required', 'array', 'min:1', 'max:60'],
            'records.*.platform' => ['required', Rule::in(['facebook', 'google', 'tiktok', 'mediago', 'newsbreak', 'applovin'])],
            'records.*.currency' => ['required', Rule::in(['usd', 'eur', 'cny'])],
            'records.*.actual_spend' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'records.*.orders' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'records.*.roi' => ['required', 'numeric', 'min:0', 'max:99999999.9999'],
        ]);
    }

    /** @param array<int,array<string,mixed>> $records
     * @return array<int,array{platform:string,currency:string,actual_spend:string,orders:int}>
     */
    private function standalonePlacementRecords(array $records): array
    {
        return collect($records)->map(static fn (array $record): array => [
            'platform' => (string) $record['platform'],
            'currency' => (string) $record['currency'],
            'actual_spend' => (string) $record['actual_spend'],
            'orders' => (int) $record['orders'],
            'roi' => (string) $record['roi'],
        ])->values()->all();
    }

    private function standalonePlacementProduct(int $productId): AikaipinProduct
    {
        $product = AikaipinProduct::query()->where('is_published', true)->find($productId);
        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => __('aikaipin.placement_evaluation.messages.related_product_invalid_message'),
            ]);
        }

        return $product;
    }

    private function assertOwnPlacementEvaluation(AdminUser $user, AikaipinPlacementEvaluation $evaluation): void
    {
        abort_unless((int) $evaluation->created_by === (int) $user->getKey(), 403);
    }

    /** @return array<string,mixed> */
    private function standalonePlacementPayload(AikaipinPlacementEvaluation $evaluation): array
    {
        return [
            'id' => (int) $evaluation->getKey(),
            'evaluation_code' => (string) $evaluation->evaluation_code,
            'product' => [
                'id' => (int) $evaluation->product_id,
                'code' => (string) $evaluation->product_code,
                'name' => (string) $evaluation->product_name,
            ],
            'test_started_on' => $evaluation->test_started_on?->toDateString(),
            'data_ended_on' => $evaluation->data_ended_on?->toDateString(),
            'remark' => $evaluation->remark,
            'created_by_name' => $evaluation->created_by_name,
            'created_at' => $evaluation->created_at?->toAtomString(),
            'updated_at' => $evaluation->updated_at?->toAtomString(),
            'can_edit' => true,
            'records' => $evaluation->records->map(static fn (AikaipinPlacementEvaluationRecord $record): array => [
                'id' => (int) $record->getKey(),
                'platform' => (string) $record->platform,
                'currency' => (string) $record->currency,
                'actual_spend' => (string) $record->actual_spend,
                'orders' => (int) $record->orders,
                'roi' => $record->roi !== null ? (string) $record->roi : null,
            ])->values()->all(),
        ];
    }

    /** @return array{portal:Portal,user:AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);
        abort_unless($this->access->canViewOperationAiProductLibrary($context['user']), 404);

        return $context;
    }

    /** @return array{keyword:string,category:string,source:string,type:string} */
    private function filters(Request $request): array
    {
        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'category' => trim((string) $request->query('category', '')),
            'source' => in_array($request->query('source'), ['shopify', 'shopline'], true) ? (string) $request->query('source') : '',
            'type' => in_array($request->query('type'), ['general', 'xiaohei'], true) ? (string) $request->query('type') : '',
        ];
    }

    /** @param array{keyword:string,category:string,source:string,type:string} $filters */
    private function productQuery(array $filters): Builder
    {
        $query = AikaipinProduct::query()
            ->with(['productPages:id,aikaipin_product_id,name,landing_page_url,is_default'])
            ->withCount('productPages')
            ->where('is_published', true)
            ->latest('created_at')
            ->latest('id');

        if ($filters['keyword'] !== '') {
            $like = '%'.$filters['keyword'].'%';
            $query->where(static function (Builder $products) use ($like): void {
                $products->where('product_code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('sku', 'like', $like);
            });
        }
        if ($filters['category'] !== '') {
            $query->whereJsonContains('categories', $filters['category']);
        }
        if ($filters['source'] !== '') {
            $query->where('source_platform', $filters['source']);
        }
        if ($filters['type'] !== '') {
            $query->where('product_type', $filters['type']);
        }

        return $query;
    }

    /** @return list<string> */
    private function categoryOptions(): array
    {
        return AikaipinProduct::query()
            ->where('is_published', true)
            ->whereNotNull('categories')
            ->get(['categories'])
            ->flatMap(static fn (AikaipinProduct $product): array => $product->categoryValues())
            ->map(static fn (string $category): string => trim($category))
            ->filter()
            ->unique(static fn (string $category): string => mb_strtolower($category))
            ->sort(static fn (string $left, string $right): int => strnatcasecmp($left, $right))
            ->values()
            ->all();
    }

    private function publishableStores(AdminUser $user): Collection
    {
        $query = AssistantDomainStore::query()
            ->with(['ownerUser:id,name,username', 'shopifyConnection'])
            ->where('site_builder', 'shopify')
            ->where('shop_status', AssistantDomainStore::SHOP_STATUS_IN_USE);

        if ($user->isAdministrator()) {
            return $query->orderBy('shop_name')->get();
        }

        $userNames = collect([$user->name, $user->username])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $query
            ->where(static function (Builder $stores) use ($user, $userNames): void {
                $stores->where('owner_user_id', $user->getKey());
                if ($userNames !== []) {
                    $stores->orWhereIn('user_name', $userNames);
                }
            })
            ->orderBy('shop_name')
            ->get();
    }
}
