<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AikaipinProduct;
use App\Models\AiProductShopifyPublication;
use App\Models\AssistantDomainStore;
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
use Illuminate\Support\Str;
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

    public function referencePreview(Request $request, AikaipinProduct $product): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->referencePreviewFile($product);

        return view('portals.aikaipin.product-library.reference-preview', [
            'product' => $product,
            'contentUrl' => route('operation.ai-product-library.reference-preview.content', ['product' => $product]),
            'libraryUrl' => route('operation.ai-product-library.index'),
        ]);
    }

    public function referencePreviewContent(Request $request, AikaipinProduct $product): Response
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $file = $this->referencePreviewFile($product);
        $content = Storage::disk($file['disk'])->get($file['path']);

        return response($content, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="preview.html"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Content-Security-Policy' => "sandbox allow-scripts allow-modals; default-src 'none'; base-uri 'none'; object-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' https:; style-src 'unsafe-inline' https:; img-src data: blob: https:; font-src data: https:; media-src data: blob: https:; connect-src https:; frame-src https:; worker-src blob:; form-action 'none'; frame-ancestors 'self'",
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
