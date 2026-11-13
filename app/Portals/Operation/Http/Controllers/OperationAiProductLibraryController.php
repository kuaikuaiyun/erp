<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AikaipinProduct;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OperationAiProductLibraryController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
        private readonly AiProductShopifyPublisher $shopifyPublisher,
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
            'created_at' => $product->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'updated_at' => $product->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
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

    public function storePlacementEvaluation(Request $request, AikaipinProduct $product): RedirectResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((bool) $product->is_published, 404);
        $data = $request->validate([
            'test_started_on' => ['required', 'date'],
            'data_ended_on' => ['required', 'date', 'after_or_equal:test_started_on'],
            'platform' => ['required', Rule::in(['facebook', 'google', 'tiktok', 'mediago', 'newsbreak', 'applovin'])],
            'currency' => ['required', Rule::in(['usd', 'eur', 'cny'])],
            'actual_spend' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'orders' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'material_name' => ['required', 'string', 'max:191'],
            'video' => ['required', 'file', 'mimes:mp4,mov,avi,webm,mkv,m4v', 'max:307200'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ], [
            'material_name.required' => '请填写素材名称。',
            'video.required' => '请选择需要上传的素材视频。',
            'video.mimes' => '仅支持 MP4、MOV、AVI、WEBM、MKV、M4V 视频。',
            'video.max' => '单个视频不能超过 300MB。',
        ]);

        $file = $request->file('video');
        $date = now('Asia/Shanghai');
        $productCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'mp4');
        $fileName = $productCode.'-'.$this->departmentCode($context['user']).'-'.$date->format('ymd').'-placement-'.Str::lower(Str::random(6)).'.'.$extension;
        $path = $file->storeAs('aikaipin/placement-evaluations/'.$context['user']->getKey().'/'.$product->getKey(), $fileName, 'local');
        $connection = config('admin.database.connection') ?: config('database.default');

        try {
            DB::connection($connection)->transaction(function () use ($context, $product, $data, $file, $fileName, $path): void {
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
                $evaluation->records()->create([
                    'platform' => $data['platform'], 'currency' => $data['currency'],
                    'actual_spend' => $data['actual_spend'], 'orders' => $data['orders'], 'sort' => 0,
                    'material_name' => $data['material_name'], 'video_disk' => 'local', 'video_path' => $path,
                    'video_name' => $fileName, 'video_mime_type' => $file->getMimeType(), 'video_size' => $file->getSize(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('operation.ai-product-library.index')->with('success', '投放测评已保存。');
    }

    public function placementEvaluationVideo(Request $request, AikaipinPlacementEvaluation $evaluation, AikaipinPlacementEvaluationRecord $record): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
        abort_unless((int) $evaluation->created_by === (int) $context['user']->getKey(), 403);
        abort_unless((int) $record->evaluation_id === (int) $evaluation->getKey(), 404);
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
            'referenceVideos' => $this->referenceVideos($product),
            'referenceVideoUploadUrl' => route('operation.ai-product-library.reference-videos.upload', $product),
            'referenceVideoNamingHint' => '上传后自动命名：产品编号-部门编号-日期-递增编号',
        ]);
    }

    public function uploadReferenceVideo(Request $request, AikaipinProduct $product): RedirectResponse|Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) return $context;
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
        $date = now('Asia/Shanghai');
        $historicalCount = $this->historicalReferenceVideoCount($product);
        $this->normalizeUploadedReferenceVideos($product, $historicalCount);
        $sequence = $historicalCount + OperationAiProductReferenceVideo::query()
            ->where('aikaipin_product_id', $product->getKey())->count() + 1;
        $productCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
        $departmentCode = $this->departmentCode($context['user']);
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'mp4');
        $fileName = $productCode.'-'.$departmentCode.'-'.$date->format('ymd').'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).'.'.$extension;
        $path = $file->storeAs('aikaipin/reference-videos/'.$product->getKey(), $fileName, 'local');

        try {
            OperationAiProductReferenceVideo::query()->create([
                'aikaipin_product_id' => $product->getKey(), 'type' => $data['type'],
                'sequence' => $sequence, 'file_date' => $date->toDateString(), 'disk' => 'local',
                'path' => $path, 'file_name' => $fileName, 'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                'department_code' => $departmentCode,
                'uploaded_by' => $context['user']->getKey(),
                'uploaded_by_name' => $context['user']->name ?: $context['user']->username,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()
            ->route('operation.ai-product-library.reference-preview', ['product' => $product])
            ->with('success', '视频上传成功，文件名：'.$fileName);
    }

    public function previewReferenceVideo(Request $request, AikaipinProduct $product, OperationAiProductReferenceVideo $video): Response
    {
        if (! $request->hasValidSignature()) {
            $context = $this->portalContext($request);
            if ($context instanceof Response) return $context;
        }
        $this->assertReferenceVideo($product, $video);

        return response()->file(Storage::disk($video->disk)->path($video->path), [
            'Content-Type' => $video->mime_type ?: 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$video->file_name.'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadReferenceVideo(Request $request, AikaipinProduct $product, OperationAiProductReferenceVideo $video): Response
    {
        if (! $request->hasValidSignature()) {
            $context = $this->portalContext($request);
            if ($context instanceof Response) return $context;
        }
        $this->assertReferenceVideo($product, $video);

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
        $content = Storage::disk($file['disk'])->get($file['path']);
        $historicalCount = substr_count(strtolower($content), '<video');
        $videos = $this->normalizeUploadedReferenceVideos($product, $historicalCount)
            ->map(fn (OperationAiProductReferenceVideo $video): array => [
                'type' => $video->type,
                'name' => $video->file_name,
                'size' => number_format($video->size / 1048576, 2).' MB',
                'preview_url' => URL::temporarySignedRoute(
                    'operation.ai-product-library.reference-videos.preview', now()->addHours(12), [$product, $video]
                ),
                'download_url' => URL::temporarySignedRoute(
                    'operation.ai-product-library.reference-videos.download', now()->addHours(12), [$product, $video]
                ),
            ])->values();
        $injection = view('portals.operation.ai-product-library.reference-video-injection', [
            'videos' => $videos,
            'historicalProductCode' => trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey(),
            'historicalFallbackDate' => $product->created_at?->copy()->timezone('Asia/Shanghai')->format('ymd') ?: now('Asia/Shanghai')->format('ymd'),
        ])->render();
        $bodyPosition = strripos($content, '</body>');
        $content = $bodyPosition === false
            ? $content.$injection
            : substr($content, 0, $bodyPosition).$injection.substr($content, $bodyPosition);

        return response($content, 200, [
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
        return view('portals.operation.ai-product-library.publish-form', [
            'product' => $product,
            'stores' => $this->publishableStores($context['user']),
            'skus' => $product->skuValues(),
            'shopifyData' => $product->shopifyData(),
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
            'title' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:191'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', 'in:DRAFT,ACTIVE'],
        ]);
        $store = $this->publishableStores($user)->firstWhere('id', (int) $data['store_id']);
        abort_unless($store instanceof AssistantDomainStore, 422, '该店铺不属于当前可上架范围。');
        abort_unless(in_array($data['sku'], $product->skuValues(), true), 422, '请选择该产品已有的 SKU。');

        $publication = AiProductShopifyPublication::query()->create([
            'aikaipin_product_id' => $product->getKey(),
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

    private function assertReferenceVideo(AikaipinProduct $product, OperationAiProductReferenceVideo $video): void
    {
        abort_unless((int) $video->aikaipin_product_id === (int) $product->getKey(), 404);
        abort_unless(Storage::disk($video->disk)->exists($video->path), 404);
    }

    private function referenceVideos(AikaipinProduct $product): Collection
    {
        return OperationAiProductReferenceVideo::query()
            ->where('aikaipin_product_id', $product->getKey())
            ->orderByRaw("CASE type WHEN 'flow' THEN 1 WHEN 'traffic' THEN 2 WHEN 'remix' THEN 3 ELSE 4 END")
            ->orderBy('sequence')->orderBy('id')->get();
    }

    private function historicalReferenceVideoCount(AikaipinProduct $product): int
    {
        $file = $this->referencePreviewFile($product);
        $stream = Storage::disk($file['disk'])->readStream($file['path']);
        if (! is_resource($stream)) return 0;

        $count = 0;
        $tail = '';
        while (! feof($stream)) {
            $chunk = $tail.(fread($stream, 1048576) ?: '');
            $count += substr_count(strtolower($chunk), '<video') - substr_count(strtolower($tail), '<video');
            $tail = substr($chunk, -10);
        }
        fclose($stream);

        return $count;
    }

    private function normalizeUploadedReferenceVideos(AikaipinProduct $product, int $historicalCount): Collection
    {
        $productCode = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->product_code), '-_') ?: 'product-'.$product->getKey();
        $videos = $this->referenceVideos($product);
        foreach ($videos->values() as $index => $video) {
            $sequence = $historicalCount + $index + 1;
            $extension = strtolower(pathinfo($video->file_name, PATHINFO_EXTENSION) ?: pathinfo($video->path, PATHINFO_EXTENSION) ?: 'mp4');
            $date = $video->file_date?->format('ymd') ?: $video->created_at?->copy()->timezone('Asia/Shanghai')->format('ymd') ?: now('Asia/Shanghai')->format('ymd');
            $departmentCode = $this->sanitizeDepartmentCode((string) ($video->department_code ?: 'AI'));
            $fileName = $productCode.'-'.$departmentCode.'-'.$date.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).'.'.$extension;
            $directory = trim(str_replace('\\', '/', dirname($video->path)), '.');
            $path = ($directory !== '' ? $directory.'/' : '').$fileName;
            if ($path !== $video->path && Storage::disk($video->disk)->exists($video->path)) {
                Storage::disk($video->disk)->move($video->path, $path);
            }
            if ($sequence !== (int) $video->sequence || $fileName !== $video->file_name || $path !== $video->path) {
                $video->update(['sequence' => $sequence, 'file_name' => $fileName, 'path' => $path]);
            }
        }

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
        $query = AikaipinProduct::query()->where('is_published', true)->latest('created_at')->latest('id');

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
