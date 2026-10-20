<?php

namespace App\Portals\Aikaipin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AikaipinProduct;
use App\Models\AikaipinProductCategory;
use App\Models\AdminUser;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Aikaipin\AikaipinPortalAuthorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProductLibraryController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly AikaipinPortalAuthorizationService $authorization,
    ) {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $canManageProductLibrary = $this->authorization->canManageProductLibrary($context['user']);
        $filters = $this->productFilters($request);

        return view('portals.aikaipin.product-library.index', [
            ...$context,
            'products' => $this->productQuery($context['user'], $canManageProductLibrary, $filters)
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
            'categories' => AikaipinProductCategory::query()->orderBy('sort')->orderBy('name')->get(['id', 'name']),
            'canManageProductLibrary' => $canManageProductLibrary,
            'operationVisibilitySource' => $this->authorization->operationVisibilitySource(),
            'quickAuthorizationDepartments' => $this->authorization->quickAuthorizationDepartments(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $validated['name']) ?: '');

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('aikaipin.messages.category_empty_message'),
            ]);
        }

        $category = AikaipinProductCategory::firstOrCreate(
            ['name' => $name],
            ['created_by' => (int) $context['user']->getKey()],
        );

        if (!$category->wasRecentlyCreated) {
            return response()->json([
                'message' => __('aikaipin.messages.category_duplicate_message'),
            ], 422);
        }

        return response()->json([
            'category' => $this->categoryPayload($category),
            'message' => __('aikaipin.messages.category_created_message'),
        ], 201);
    }

    public function destroyCategory(Request $request, AikaipinProductCategory $category): JsonResponse|Response
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($this->authorization->canManageProductLibrary($context['user']), 403);

        if ($category->products()->exists()) {
            return response()->json([
                'message' => __('aikaipin.messages.category_in_use_message'),
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => __('aikaipin.messages.category_deleted_message'),
        ]);
    }

    public function selectionProposal(Request $request): Response|View
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return view('portals.aikaipin.selection-proposal.index', [
            ...$context,
            'proposals' => $this->sampleSelectionProposals(),
        ]);
    }

    public function placementEvaluation(Request $request): Response|View
    {
        $context = $this->pageContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        return view('portals.aikaipin.placement-evaluation.index', [
            ...$context,
            'products' => $this->sampleProducts(),
            'evaluations' => $this->samplePlacementEvaluations(),
        ]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function pageContext(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->assertPortal($context['portal']);
        $context['portal']->load('menus');

        return $context;
    }

    /** @return array{keyword:string,category:?int,source:string,type:string} */
    private function productFilters(Request $request): array
    {
        $category = $request->query('category');

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'category' => is_numeric($category) && (int) $category > 0 ? (int) $category : null,
            'source' => trim((string) $request->query('source', '')),
            'type' => trim((string) $request->query('type', '')),
        ];
    }

    /**
     * @param array{keyword:string,category:?int,source:string,type:string} $filters
     */
    private function productQuery(AdminUser $user, bool $canManageProductLibrary, array $filters): Builder
    {
        $query = AikaipinProduct::query()
            ->with([
                'skus',
                'categories',
                'images',
                'referenceFile',
                'packageFile',
            ])
            ->latest('updated_at')
            ->latest('id');

        if (!$canManageProductLibrary) {
            $departmentIds = $this->authorization->operationDepartmentIdsForUser($user);

            if ($departmentIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('authorizedDepartments', static fn (Builder $departments) => $departments->whereKey($departmentIds));
            }
        }

        if ($filters['keyword'] !== '') {
            $like = '%'.$filters['keyword'].'%';

            $query->where(function (Builder $products) use ($like): void {
                $products->where('product_code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('skus', static fn (Builder $skus) => $skus->where('sku', 'like', $like));
            });
        }

        if ($filters['category'] !== null) {
            $query->whereHas('categories', static fn (Builder $categories) => $categories->whereKey($filters['category']));
        }

        if ($filters['source'] !== '') {
            $query->where('source_platform', $filters['source']);
        }

        if ($filters['type'] !== '') {
            $query->where('product_type', $filters['type']);
        }

        return $query;
    }

    /** @return array{value:string,label:string} */
    private function categoryPayload(AikaipinProductCategory $category): array
    {
        return [
            'value' => (string) $category->getKey(),
            'label' => (string) $category->name,
        ];
    }

    /**
     * Temporary frontend-only sample data.
     *
     * @return list<array{
     *     code:string,
     *     name_key:string,
     *     skus:list<string>,
     *     category:string,
     *     source:string,
     *     type:string,
     *     images:list<array{url:?string}>,
     *     landing_url:string,
     *     reference_url:string,
     *     updated_at:string
     * }>
     */
    private function sampleProducts(): array
    {
        return [
            [
                'code' => 'AKP-202610-000001',
                'name_key' => 'samples.products.foldable_organizer',
                'skus' => ['FDO-GRY-01', 'FDO-BLK-01', 'FDO-WHT-01'],
                'category' => 'home_living',
                'source' => 'tiktok',
                'type' => 'trending_product',
                'images' => [['url' => null], ['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000001',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000001',
                'updated_at' => '2026-10-19 09:36',
            ],
            [
                'code' => 'AKP-202610-000002',
                'name_key' => 'samples.products.portable_blender',
                'skus' => ['PBL-PNK-01'],
                'category' => 'kitchen',
                'source' => 'amazon',
                'type' => 'competitive_product',
                'images' => [['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000002',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000002',
                'updated_at' => '2026-10-19 08:52',
            ],
            [
                'code' => 'AKP-202610-000003',
                'name_key' => 'samples.products.pocket_projector',
                'skus' => ['PPR-SLV-01', 'PPR-BLU-01'],
                'category' => 'electronics',
                'source' => 'independent_store',
                'type' => 'seasonal_product',
                'images' => [['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000003',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000003',
                'updated_at' => '2026-10-18 21:14',
            ],
            [
                'code' => 'AKP-202610-000004',
                'name_key' => 'samples.products.pet_grooming_kit',
                'skus' => ['PGK-ORA-01', 'PGK-GRN-01', 'PGK-BLU-01', 'PGK-PNK-01'],
                'category' => 'pet_supplies',
                'source' => 'tiktok',
                'type' => 'trending_product',
                'images' => [['url' => null], ['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000004',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000004',
                'updated_at' => '2026-10-18 16:30',
            ],
            [
                'code' => 'AKP-202610-000005',
                'name_key' => 'samples.products.travel_jewelry_case',
                'skus' => ['TJC-BEI-01'],
                'category' => 'fashion_accessories',
                'source' => 'facebook',
                'type' => 'test_product',
                'images' => [['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000005',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000005',
                'updated_at' => '2026-10-18 11:05',
            ],
            [
                'code' => 'AKP-202610-000006',
                'name_key' => 'samples.products.led_book_light',
                'skus' => ['LBL-AMB-01', 'LBL-WHT-01'],
                'category' => 'home_living',
                'source' => 'amazon',
                'type' => 'competitive_product',
                'images' => [['url' => null], ['url' => null]],
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000006',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000006',
                'updated_at' => '2026-10-17 18:40',
            ],
        ];
    }

    /**
     * Temporary frontend-only sample data.
     *
     * @return list<array{
     *     code:string,
     *     name_key:string,
     *     specification_keys:list<string>,
     *     attribute_keys:list<string>,
     *     updated_at:string
     * }>
     */
    private function sampleSelectionProposals(): array
    {
        return [
            [
                'code' => 'SPR-202610-000001',
                'name_key' => 'samples.proposals.portable_camping_lamp',
                'specification_keys' => [
                    'samples.proposal_options.specifications.warm_white',
                    'samples.proposal_options.specifications.us_plug',
                ],
                'attribute_keys' => [
                    'samples.proposal_options.attributes.rechargeable',
                    'samples.proposal_options.attributes.waterproof',
                ],
                'updated_at' => '2026-10-19 10:26',
            ],
            [
                'code' => 'SPR-202610-000002',
                'name_key' => 'samples.proposals.travel_pill_organizer',
                'specification_keys' => [
                    'samples.proposal_options.specifications.seven_day',
                ],
                'attribute_keys' => [
                    'samples.proposal_options.attributes.portable',
                    'samples.proposal_options.attributes.large_capacity',
                ],
                'updated_at' => '2026-10-18 15:40',
            ],
        ];
    }

    /**
     * Temporary frontend-only sample data.
     *
     * @return list<array{
     *     code:string,
     *     product_code:string,
     *     product_name_key:string,
     *     start_date:string,
     *     end_date:string,
     *     records:list<array{platform:string,currency:string,spend:string,orders:string}>,
     *     updated_at:string
     * }>
     */
    private function samplePlacementEvaluations(): array
    {
        return [
            [
                'code' => 'ATE-202610-000001',
                'product_code' => 'AKP-202610-000001',
                'product_name_key' => 'samples.products.foldable_organizer',
                'start_date' => '2026-10-10',
                'end_date' => '2026-10-16',
                'records' => [
                    ['platform' => 'facebook', 'currency' => 'usd', 'spend' => '168.20', 'orders' => '12'],
                    ['platform' => 'tiktok', 'currency' => 'usd', 'spend' => '96.50', 'orders' => '8'],
                ],
                'updated_at' => '2026-10-19 09:15',
            ],
            [
                'code' => 'ATE-202610-000002',
                'product_code' => 'AKP-202610-000003',
                'product_name_key' => 'samples.products.pocket_projector',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-12',
                'records' => [
                    ['platform' => 'google', 'currency' => 'eur', 'spend' => '142.00', 'orders' => '6'],
                ],
                'updated_at' => '2026-10-18 18:22',
            ],
        ];
    }
}
