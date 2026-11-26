<?php

namespace App\Portals\AliExpress\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AliExpressProcurementItem;
use App\Portal\Http\Controllers\PortalEntryController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProcurementQueueController extends Controller
{
    public function __construct(private readonly PortalEntryController $entries)
    {
    }

    public function index(Request $request): Response|View
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }
        abort_unless($context['portal']->code === 'caigou', 404);
        abort_unless($context['portal']->menus->contains(fn ($menu) => $menu->code === 'aliexpress_procurement' && $menu->is_enabled), 403);
        app()->setLocale('zh-CN');

        $keyword = $request->query('keyword', '');
        $keyword = is_string($keyword) ? mb_substr(trim($keyword), 0, 191) : '';
        $status = $request->query('status', '');
        $status = in_array($status, ['ready', 'exception'], true) ? $status : '';
        $perPage = $request->integer('per_page', 50);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 50;

        $query = AliExpressProcurementItem::query()
            ->leftJoin('aliexpress_selection_skus as selections', 'selections.sku_key', '=', 'aliexpress_procurement_items.sku_key')
            ->leftJoin('aliexpress_selection_products as products', 'products.id', '=', 'selections.product_id')
            ->select([
                'aliexpress_procurement_items.*',
                'products.supplier_url', 'products.name as selection_name', 'selections.supplier_sku', 'selections.is_enabled',
            ])
            ->when($keyword !== '', function (Builder $query) use ($keyword): void {
                $like = '%'.$keyword.'%';
                $query->where(fn (Builder $query) => $query
                    ->where('aliexpress_procurement_items.sku', 'like', $like)
                    ->orWhere('aliexpress_procurement_items.shop_domain', 'like', $like)
                    ->orWhere('aliexpress_procurement_items.store_name', 'like', $like)
                    ->orWhere('aliexpress_procurement_items.shopify_order_id', 'like', $like)
                    ->orWhere('aliexpress_procurement_items.shopify_order_number', 'like', '%'.ltrim($keyword, '#').'%')
                    ->orWhere('aliexpress_procurement_items.product_title', 'like', $like));
            });

        $ready = fn (Builder $query) => $query->whereNull('aliexpress_procurement_items.source_issue')->where('selections.is_enabled', true);
        $exception = fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereNotNull('aliexpress_procurement_items.source_issue')
            ->orWhereNull('selections.id')->orWhere('selections.is_enabled', false));

        $summary = ['ready' => $ready(clone $query)->count(), 'exception' => $exception(clone $query)->count()];
        $items = $query->when($status === 'ready', $ready)->when($status === 'exception', $exception)
            ->orderByDesc('aliexpress_procurement_items.order_created_at')->orderByDesc('aliexpress_procurement_items.id')
            ->paginate($perPage)->appends(array_filter(compact('keyword', 'status') + ['per_page' => $perPage], fn ($value) => $value !== ''));

        return view('portals.aliexpress.procurement.index', $context + compact('items', 'summary', 'keyword', 'status', 'perPage'));
    }
}
