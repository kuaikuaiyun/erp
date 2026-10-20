<?php

namespace App\Portals\Aikaipin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Aikaipin\AikaipinPortalAuthorizationService;
use Illuminate\Http\Request;
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
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        $this->authorization->assertPortal($context['portal']);
        $context['portal']->load('menus');

        return view('portals.aikaipin.product-library.index', [
            ...$context,
            'products' => $this->sampleProducts(),
            'canManageProductLibrary' => $this->authorization->canManageProductLibrary($context['user']),
            'operationVisibilitySource' => $this->authorization->operationVisibilitySource(),
        ]);
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
                'landing_url' => 'https://example.invalid/aikaipin/landing/AKP-202610-000006',
                'reference_url' => 'https://example.invalid/aikaipin/reference/AKP-202610-000006',
                'updated_at' => '2026-10-17 18:40',
            ],
        ];
    }
}
