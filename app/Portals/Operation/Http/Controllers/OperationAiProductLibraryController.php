<?php

namespace App\Portals\Operation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AikaipinProduct;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OperationAiProductLibraryController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly ProductRequestAccessService $access,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $filters = $this->filters($request);

        return view('portals.operation.ai-product-library.index', [
            'portal' => $context['portal']->load('menus'),
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
            'created_at' => $product->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'updated_at' => $product->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
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

    /** @return array{portal:\App\Models\Portal,user:\App\Models\AdminUser}|Response */
    private function portalContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->access->assertPortal($context['portal'], ProductRequestAccessService::OPERATION_PORTAL_CODE);

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
        $query = AikaipinProduct::query()->latest('created_at')->latest('id');

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
}
