<?php

namespace App\Portals\Aikaipin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiProductShopifyPublication;
use App\Models\AikaipinProduct;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Aikaipin\AiProductOrderStatisticsService;
use App\Portals\Aikaipin\AikaipinPortalAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductOrderStatisticsController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AikaipinPortalAuthorizationService $authorization,
        private readonly AiProductOrderStatisticsService $statistics,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertPortal($context['portal']);
        $context['portal']->loadMissing('menus');

        $filters = $this->filters($request);
        $query = $this->publicationQuery($filters);
        $publicationIndex = (clone $query)->get([
            'ai_product_shopify_publications.id',
            'ai_product_shopify_publications.aikaipin_product_id',
        ]);
        $publicationIds = $publicationIndex->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $productPublicationIds = $publicationIndex
            ->groupBy('aikaipin_product_id')
            ->map(fn (Collection $rows): array => $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Shanghai')->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['date_to'], 'Asia/Shanghai')->endOfDay()->utc();
        $productStatistics = $this->statistics->forProducts($publicationIds, $from, $to);

        $orderedProductIds = $productPublicationIds->keys()
            ->map(fn ($id): int => (int) $id)
            ->sort(function (int $leftId, int $rightId) use ($productStatistics): int {
                $left = $productStatistics->get($leftId, $this->emptyStatistics());
                $right = $productStatistics->get($rightId, $this->emptyStatistics());

                return ($right['order_count'] <=> $left['order_count'])
                    ?: ($right['revenue'] <=> $left['revenue'])
                    ?: ($rightId <=> $leftId);
            })
            ->values();
        $currentPage = max(1, LengthAwarePaginator::resolveCurrentPage());
        $lastPage = max(1, (int) ceil($orderedProductIds->count() / $filters['per_page']));
        $currentPage = min($currentPage, $lastPage);
        $pageProductIds = $orderedProductIds
            ->slice(($currentPage - 1) * $filters['per_page'], $filters['per_page'])
            ->values();
        $pagePositions = $pageProductIds->flip();
        $pagePublications = $query
            ->with([
                'product:id,product_code,name,images,created_by,created_by_name,updated_at',
            ])
            ->whereIn('ai_product_shopify_publications.aikaipin_product_id', $pageProductIds->all())
            ->get()
            ->groupBy('aikaipin_product_id');

        $pageProducts = $pageProductIds->map(function (int $productId) use ($pagePublications, $pagePositions, $productStatistics) {
            /** @var Collection<int, AiProductShopifyPublication> $group */
            $group = $pagePublications->get($productId, collect());
            /** @var AiProductShopifyPublication|null $productRow */
            $productRow = $group->sortBy([
                ['published_at', 'desc'],
                ['id', 'desc'],
            ])->first();
            if (! $productRow) return null;

            $productRow->setAttribute('order_statistics', $productStatistics->get($productId, $this->emptyStatistics()));
            $productRow->setAttribute('publication_count', $group->count());
            $productRow->setAttribute('product_sort_position', (int) $pagePositions->get($productId));

            return $productRow;
        })->filter()->sortBy('product_sort_position')->values();

        $products = new LengthAwarePaginator(
            $pageProducts,
            $orderedProductIds->count(),
            $filters['per_page'],
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('portals.aikaipin.product-order-statistics.index', [
            ...$context,
            'products' => $products,
            'filters' => $filters,
            'productCreatorOptions' => $this->productCreatorOptions(),
            'publisherOptions' => $this->publisherOptions(),
            'storeOptions' => $this->storeOptions(),
            'summary' => $this->summary($productStatistics, $orderedProductIds->count(), count($publicationIds)),
        ]);
    }

    public function productDetails(Request $request, int $product): Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->authorization->assertPortal($context['portal']);

        $filters = $this->filters($request);
        $publications = $this->publicationQuery($filters)
            ->where('aikaipin_product_id', $product)
            ->with(['store:id,shop_name,domain', 'productPage:id,aikaipin_product_id,name'])
            ->orderBy('published_at')
            ->orderBy('id')
            ->get();
        abort_if($publications->isEmpty(), 404);

        $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Shanghai')->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['date_to'], 'Asia/Shanghai')->endOfDay()->utc();
        $orders = $this->statistics->ordersForPublications(
            $publications->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $from,
            $to,
        );

        return new JsonResponse([
            'publications' => $publications->map(function (AiProductShopifyPublication $publication) use ($orders): array {
                $publicationId = (int) $publication->getKey();
                $publicationOrders = $orders->filter(
                    fn (array $order): bool => in_array($publicationId, $order['publication_ids'] ?? [], true)
                );
                $publicationItems = $publicationOrders->flatMap(
                    fn (array $order): array => collect($order['items'] ?? [])
                        ->where('publication_id', $publicationId)
                        ->values()
                        ->all()
                );

                return [
                    'id' => $publicationId,
                    'publisher' => (string) ($publication->published_by_name ?: '未知用户'),
                    'store_id' => (int) $publication->assistant_domain_store_id,
                    'store' => $this->storeLabel($publication->store?->shop_name, $publication->store?->domain),
                    'sku' => (string) ($publication->sku ?: '—'),
                    'page' => (string) ($publication->productPage?->name ?: '—'),
                    'published_at' => $publication->published_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
                    'order_count' => $publicationOrders->count(),
                    'item_quantity' => (float) $publicationItems->sum('quantity'),
                    'revenue' => (float) $publicationItems->sum('revenue'),
                ];
            })->sortBy([
                ['order_count', 'desc'],
                ['revenue', 'desc'],
                ['published_at', 'desc'],
            ])->values(),
            'orders' => $orders,
            'summary' => [
                'publication_count' => $publications->count(),
                'store_count' => $publications->pluck('assistant_domain_store_id')->unique()->count(),
                'order_count' => $orders->count(),
                'item_quantity' => (float) $orders->sum('item_quantity'),
                'revenue' => (float) $orders->sum('revenue'),
            ],
        ]);
    }

    public function orders(Request $request, AiProductShopifyPublication $publication): Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        $this->authorization->assertPortal($context['portal']);

        abort_unless($publication->status === 'success' && $publication->published_at !== null, 404);

        $filters = $this->filters($request);
        $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Shanghai')->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['date_to'], 'Asia/Shanghai')->endOfDay()->utc();
        $orders = $this->statistics->ordersForPublication((int) $publication->getKey(), $from, $to);

        return new JsonResponse([
            'orders' => $orders,
            'summary' => [
                'order_count' => $orders->count(),
                'item_quantity' => (float) $orders->sum('item_quantity'),
                'revenue' => (float) $orders->sum('revenue'),
            ],
        ]);
    }

    /** @return array{keyword:string,created_by:string,published_by:string,store_id:int,date_from:string,date_to:string,per_page:int} */
    private function filters(Request $request): array
    {
        $today = now('Asia/Shanghai')->toDateString();
        $dateFrom = trim((string) $request->query('date_from', now('Asia/Shanghai')->subDays(29)->toDateString()));
        $dateTo = trim((string) $request->query('date_to', $today));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = now('Asia/Shanghai')->subDays(29)->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $today;
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

        $perPage = (int) $request->query('per_page', 20);
        if (! in_array($perPage, [20, 50, 100], true)) $perPage = 20;

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'created_by' => trim((string) $request->query('created_by', '')),
            'published_by' => trim((string) $request->query('published_by', '')),
            'store_id' => max(0, (int) $request->query('store_id', 0)),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => $perPage,
        ];
    }

    /** @param array{keyword:string,created_by:string,published_by:string,store_id:int,date_from:string,date_to:string,per_page:int} $filters */
    private function publicationQuery(array $filters): Builder
    {
        $query = AiProductShopifyPublication::query()
            ->where('status', 'success')
            ->whereNotNull('published_at');

        if ($filters['keyword'] !== '') {
            $like = '%'.$filters['keyword'].'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('sku', 'like', $like)
                    ->orWhere('shopify_product_id', 'like', $like)
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('product_code', 'like', $like)
                        ->orWhere('name', 'like', $like))
                    ->orWhereHas('store', fn (Builder $store) => $store
                        ->where('domain', 'like', $like)
                        ->orWhere('shop_name', 'like', $like));
            });
        }
        if ($filters['created_by'] !== '') {
            $query->whereHas('product', fn (Builder $product) => $product
                ->where('created_by', (int) $filters['created_by']));
        }
        if ($filters['published_by'] !== '') $query->where('published_by', (int) $filters['published_by']);
        if ($filters['store_id'] > 0) $query->where('assistant_domain_store_id', $filters['store_id']);

        return $query;
    }

    /** @return Collection<int, string> */
    private function productCreatorOptions(): Collection
    {
        $productIds = AiProductShopifyPublication::query()
            ->where('status', 'success')
            ->whereNotNull('published_at')
            ->pluck('aikaipin_product_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        return AikaipinProduct::query()
            ->whereIn('id', $productIds->all())
            ->whereNotNull('created_by')
            ->orderBy('created_by_name')
            ->get(['created_by', 'created_by_name'])
            ->filter(fn (AikaipinProduct $product): bool => (int) $product->created_by > 0)
            ->unique('created_by')
            ->mapWithKeys(fn (AikaipinProduct $product): array => [
                (int) $product->created_by => trim((string) $product->created_by_name) ?: ('用户 #'.(int) $product->created_by),
            ]);
    }

    /** @return Collection<int, string> */
    private function publisherOptions(): Collection
    {
        return AiProductShopifyPublication::query()
            ->where('status', 'success')
            ->whereNotNull('published_at')
            ->orderBy('published_by_name')
            ->get(['published_by', 'published_by_name'])
            ->unique('published_by')
            ->mapWithKeys(fn ($row): array => [(int) $row->published_by => (string) $row->published_by_name]);
    }

    /** @return Collection<int, string> */
    private function storeOptions(): Collection
    {
        return AiProductShopifyPublication::query()
            ->where('status', 'success')
            ->whereNotNull('published_at')
            ->with('store:id,domain,shop_name')
            ->get(['assistant_domain_store_id'])
            ->mapWithKeys(function ($row): array {
                $label = $this->storeLabel($row->store?->shop_name, $row->store?->domain);
                return $label === '' ? [] : [(int) $row->assistant_domain_store_id => $label];
            })
            ->sort();
    }

    private function storeLabel(?string $shopName, ?string $domain): string
    {
        $shopName = trim((string) $shopName);
        $domain = trim((string) $domain);

        if ($shopName !== '' && $domain !== '' && strcasecmp($shopName, $domain) !== 0) {
            return $shopName.'（'.$domain.'）';
        }

        return $shopName !== '' ? $shopName : ($domain !== '' ? $domain : '—');
    }

    /** @param Collection<int, array{order_count:int,item_quantity:float,revenue:float,first_order_at:?string,last_order_at:?string}> $statistics */
    private function summary(Collection $statistics, int $productCount, int $publicationCount): array
    {
        return [
            'product_count' => $productCount,
            'publication_count' => $publicationCount,
            'converted_count' => $statistics->filter(fn (array $row): bool => $row['order_count'] > 0)->count(),
            'order_count' => (int) $statistics->sum('order_count'),
            'item_quantity' => (float) $statistics->sum('item_quantity'),
            'revenue' => (float) $statistics->sum('revenue'),
        ];
    }

    /** @return array{order_count:int,item_quantity:float,revenue:float,first_order_at:?string,last_order_at:?string} */
    private function emptyStatistics(): array
    {
        return ['order_count' => 0, 'item_quantity' => 0.0, 'revenue' => 0.0, 'first_order_at' => null, 'last_order_at' => null];
    }

}
