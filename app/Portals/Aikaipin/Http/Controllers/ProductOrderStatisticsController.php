<?php

namespace App\Portals\Aikaipin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiProductShopifyPublication;
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
        $publicationIds = (clone $query)->pluck('ai_product_shopify_publications.id')->map(fn ($id): int => (int) $id)->all();
        $from = CarbonImmutable::parse($filters['date_from'], 'Asia/Shanghai')->startOfDay()->utc();
        $to = CarbonImmutable::parse($filters['date_to'], 'Asia/Shanghai')->endOfDay()->utc();
        $allStatistics = $this->statistics->forPublications($publicationIds, $from, $to);

        $orderedPublicationIds = collect($publicationIds)
            ->sort(function (int $leftId, int $rightId) use ($allStatistics): int {
                $left = $allStatistics->get($leftId, $this->emptyStatistics());
                $right = $allStatistics->get($rightId, $this->emptyStatistics());

                return ($right['order_count'] <=> $left['order_count'])
                    ?: ($right['revenue'] <=> $left['revenue'])
                    ?: ($rightId <=> $leftId);
            })
            ->values();
        $currentPage = max(1, LengthAwarePaginator::resolveCurrentPage());
        $lastPage = max(1, (int) ceil($orderedPublicationIds->count() / $filters['per_page']));
        $currentPage = min($currentPage, $lastPage);
        $pageIds = $orderedPublicationIds
            ->slice(($currentPage - 1) * $filters['per_page'], $filters['per_page'])
            ->values();
        $pagePositions = $pageIds->flip();
        $pagePublications = $query
            ->with([
                'product:id,product_code,name',
                'productPage:id,aikaipin_product_id,name',
                'store:id,shop_name,domain',
            ])
            ->whereIn('ai_product_shopify_publications.id', $pageIds->all())
            ->get()
            ->sortBy(fn (AiProductShopifyPublication $publication): int => (int) $pagePositions->get((int) $publication->getKey()))
            ->values();

        $publications = new LengthAwarePaginator(
            $pagePublications,
            $orderedPublicationIds->count(),
            $filters['per_page'],
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        foreach ($publications as $publication) {
            $publication->setAttribute('order_statistics', $allStatistics->get((int) $publication->getKey(), $this->emptyStatistics()));
        }

        return view('portals.aikaipin.product-order-statistics.index', [
            ...$context,
            'publications' => $publications,
            'filters' => $filters,
            'publisherOptions' => $this->publisherOptions(),
            'storeOptions' => $this->storeOptions(),
            'summary' => $this->summary($allStatistics, count($publicationIds)),
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

    /** @return array{keyword:string,published_by:string,store_id:int,date_from:string,date_to:string,per_page:int} */
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
            'published_by' => trim((string) $request->query('published_by', '')),
            'store_id' => max(0, (int) $request->query('store_id', 0)),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'per_page' => $perPage,
        ];
    }

    /** @param array{keyword:string,published_by:string,store_id:int,date_from:string,date_to:string,per_page:int} $filters */
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
        if ($filters['published_by'] !== '') $query->where('published_by', (int) $filters['published_by']);
        if ($filters['store_id'] > 0) $query->where('assistant_domain_store_id', $filters['store_id']);

        return $query;
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
                $label = trim((string) ($row->store?->domain ?: $row->store?->shop_name));
                return $label === '' ? [] : [(int) $row->assistant_domain_store_id => $label];
            })
            ->sort();
    }

    /** @param Collection<int, array{order_count:int,item_quantity:float,revenue:float,first_order_at:?string,last_order_at:?string}> $statistics */
    private function summary(Collection $statistics, int $publicationCount): array
    {
        return [
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
