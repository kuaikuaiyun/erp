<?php

namespace App\Portals\AliExpress\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AliExpressSelectionProduct;
use App\Models\AliExpressSelectionSku;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\ProductRequests\ProductRequestAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SelectionPoolController extends Controller
{
    public function __construct(private readonly PortalEntryController $entries)
    {
    }

    public function browse(Request $request): Response|View
    {
        return $this->listing($request, false);
    }

    public function index(Request $request): Response|View
    {
        return $this->listing($request, true);
    }

    public function lookup(Request $request): Response|JsonResponse
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        $data = $request->validate(['supplier_url' => ['required', 'string', 'max:2048']]);
        $supplier = $this->supplier($data['supplier_url']);
        $product = AliExpressSelectionProduct::query()
            ->where('supplier_product_id', $supplier['supplier_product_id'])
            ->first(['id', 'name']);

        return response()->json([
            'product_id' => $supplier['supplier_product_id'],
            'existing' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'edit_url' => route('aliexpress.selection.edit', $product),
            ] : null,
        ]);
    }

    public function create(Request $request): Response|View
    {
        return $this->form($request, new AliExpressSelectionProduct());
    }

    public function edit(Request $request, AliExpressSelectionProduct $product): Response|View
    {
        return $this->form($request, $product);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        return $this->save($request, new AliExpressSelectionProduct());
    }

    public function update(Request $request, AliExpressSelectionProduct $product): Response|RedirectResponse
    {
        return $this->save($request, $product);
    }

    private function context(Request $request, bool $manage): array|Response
    {
        $context = $this->entries->portalContext($request);
        if ($context instanceof Response) {
            return $context;
        }

        abort_unless($context['portal']->code === 'caigou', 404);
        $code = $manage ? 'aliexpress_selection_manage' : 'aliexpress_selection_browse';
        abort_unless($context['portal']->menus->contains(fn ($menu) => $menu->code === $code && $menu->is_enabled), 403);
        app()->setLocale('zh-CN');

        return $context;
    }

    private function listing(Request $request, bool $manage): Response|View
    {
        $context = $this->context($request, $manage);
        if ($context instanceof Response) {
            return $context;
        }

        $keyword = $request->query('keyword', '');
        $keyword = is_string($keyword) ? mb_substr(trim($keyword), 0, 191) : '';
        $status = $manage ? $request->query('status', '') : '';
        $status = in_array($status, ['enabled', 'disabled'], true) ? $status : '';
        $perPage = $request->integer('per_page', 50);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 50;
        $activeOnly = !$manage || $status === 'enabled';

        $products = AliExpressSelectionProduct::query()
            ->with(['skus' => fn ($query) => $query->when(!$manage, fn ($query) => $query->where('is_enabled', true))])
            ->when($activeOnly, fn (Builder $query) => $query->whereHas('skus', fn (Builder $skus) => $skus->where('is_enabled', true)))
            ->when($status === 'disabled', fn (Builder $query) => $query->whereDoesntHave('skus', fn (Builder $skus) => $skus->where('is_enabled', true)))
            ->when($keyword !== '', function (Builder $query) use ($keyword, $manage): void {
                $like = '%'.$keyword.'%';
                $query->where(function (Builder $query) use ($like, $manage): void {
                    $query->where('name', 'like', $like)
                        ->orWhere('supplier_product_id', 'like', $like)
                        ->orWhere('supplier_url', 'like', $like)
                        ->orWhereHas('skus', fn (Builder $skus) => $skus
                            ->when(!$manage, fn (Builder $skus) => $skus->where('is_enabled', true))
                            ->where('sku', 'like', $like));
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends(array_filter(['keyword' => $keyword, 'status' => $status, 'per_page' => $perPage], fn ($value) => $value !== ''));

        return view('portals.aliexpress.selection.index', $context + compact('products', 'manage', 'keyword', 'status', 'perPage'));
    }

    private function form(Request $request, AliExpressSelectionProduct $product): Response|View
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        $product->loadMissing('skus');
        $prefix = $product->sku_prefix ?? $this->prefix($context['user']);

        return view('portals.aliexpress.selection.form', $context + compact('product', 'prefix'));
    }

    private function save(Request $request, AliExpressSelectionProduct $product): Response|RedirectResponse
    {
        $context = $this->context($request, true);
        if ($context instanceof Response) {
            return $context;
        }

        $segment = 'regex:/\A[\pL\pN][\pL\pN._*\-]*\z/u';
        $labels = __('aliexpress.fields');
        $data = $request->validate([
            'supplier_url' => ['required', 'string', 'max:2048'],
            'name' => ['required', 'string', 'max:191'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*' => ['required', 'array:id,pinyin,attribute,package_label,supplier_sku,is_enabled'],
            'rows.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'rows.*.pinyin' => ['nullable', 'string', 'max:48', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_\-]*\z/'],
            'rows.*.attribute' => ['nullable', 'string', 'max:48', $segment],
            'rows.*.package_label' => ['nullable', 'string', 'max:32', $segment],
            'rows.*.supplier_sku' => ['required', 'string', 'max:191'],
            'rows.*.is_enabled' => ['required', 'boolean'],
        ], [
            'rows.required' => __('aliexpress.messages.sku_required'),
            'rows.max' => __('aliexpress.messages.max_rows'),
            'rows.*.pinyin.regex' => __('aliexpress.messages.pinyin_invalid'),
            'rows.*.attribute.regex' => __('aliexpress.messages.segment_invalid'),
            'rows.*.package_label.regex' => __('aliexpress.messages.segment_invalid'),
        ], [
            'supplier_url' => $labels['supplier_url'], 'name' => $labels['name'], 'remark' => $labels['remark'],
            'rows' => $labels['sku'], 'rows.*.pinyin' => $labels['pinyin'], 'rows.*.attribute' => $labels['attribute'],
            'rows.*.package_label' => $labels['package_label'], 'rows.*.supplier_sku' => $labels['supplier_sku'],
            'rows.*.is_enabled' => $labels['status'],
        ]);

        $supplier = $this->supplier($data['supplier_url']);
        $user = $context['user'];

        try {
            $product->getConnection()->transaction(function () use ($product, $data, $supplier, $user): void {
                $record = $product->exists
                    ? AliExpressSelectionProduct::query()->lockForUpdate()->findOrFail($product->id)
                    : $product;
                $existing = $record->exists ? $record->skus()->lockForUpdate()->get()->keyBy('id') : collect();
                $prefix = $record->sku_prefix ?? $this->prefix($user);
                $newCount = collect($data['rows'])->filter(fn (array $row) => empty($row['id']))->count();
                if ($existing->count() + $newCount > 50) {
                    throw ValidationException::withMessages(['rows' => __('aliexpress.messages.max_rows')]);
                }

                if (AliExpressSelectionProduct::query()->where('supplier_product_id', $supplier['supplier_product_id'])
                    ->when($record->exists, fn (Builder $query) => $query->whereKeyNot($record->id))->exists()) {
                    throw ValidationException::withMessages(['supplier_url' => __('aliexpress.messages.product_exists')]);
                }

                if ($record->exists && $record->supplier_product_id !== $supplier['supplier_product_id']
                    && $existing->keys()->diff(collect($data['rows'])->pluck('id')->filter())->isNotEmpty()) {
                    throw ValidationException::withMessages(['rows' => __('aliexpress.messages.refresh_before_rebind')]);
                }

                $record->fill($supplier + [
                    'name' => $data['name'],
                    'remark' => $data['remark'] ?? null,
                    'sku_prefix' => $prefix,
                    'created_by' => $record->created_by ?? $user->id,
                    'updated_by' => $user->id,
                ])->save();

                $keys = [];
                foreach ($data['rows'] as $index => $row) {
                    $values = ['supplier_sku' => $row['supplier_sku'], 'is_enabled' => (bool) $row['is_enabled']];
                    if (!empty($row['id'])) {
                        $sku = $existing->get((int) $row['id']);
                        if (!$sku) {
                            throw ValidationException::withMessages(["rows.$index.id" => __('aliexpress.messages.invalid_row')]);
                        }
                        $sku->update($values);
                        continue;
                    }

                    $segments = [];
                    foreach (['pinyin', 'attribute', 'package_label'] as $field) {
                        if (empty($row[$field]) && ($row[$field] ?? null) !== '0') {
                            throw ValidationException::withMessages(["rows.$index.$field" => __('aliexpress.messages.segment_required')]);
                        }
                        $segments[$field] = $row[$field];
                    }

                    $sku = $prefix.implode('-', $segments);
                    $key = AliExpressSelectionSku::keyFor($sku);
                    if (mb_strlen($sku) > 191) {
                        throw ValidationException::withMessages(["rows.$index.pinyin" => __('aliexpress.messages.sku_too_long')]);
                    }
                    if (isset($keys[$key]) || AliExpressSelectionSku::query()->where('sku_key', $key)->exists()) {
                        throw ValidationException::withMessages(["rows.$index.pinyin" => __('aliexpress.messages.sku_exists', ['sku' => $sku])]);
                    }

                    $keys[$key] = true;
                    $record->skus()->create($values + $segments + ['sku' => $sku, 'sku_key' => $key]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['rows' => __('aliexpress.messages.concurrent_duplicate')]);
        }

        return redirect()->route('aliexpress.selection.index')->with('success', __('aliexpress.messages.saved'));
    }

    private function prefix(AdminUser $user): string
    {
        $department = app(ProductRequestAccessService::class)->primaryDepartment($user);
        $team = trim((string) $department?->short_name) ?: trim((string) $department?->name);
        $team = mb_substr((string) preg_replace('/[\s,，;；]+/u', '', $team), 0, 48) ?: 'SKU';

        return AliExpressSelectionSku::PREFIX.$team.'-'.now('Asia/Shanghai')->format('Ymd').'-';
    }

    private function supplier(string $value): array
    {
        $parts = parse_url(trim($value));
        $host = strtolower($parts['host'] ?? '');
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !preg_match('/\A(?:[a-z0-9-]+\.)*aliexpress\.(?:com|us|ru)\z/', $host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !preg_match('#\A/item/([0-9]{1,32})\.html/?\z#', $parts['path'] ?? '', $match)) {
            throw ValidationException::withMessages(['supplier_url' => __('aliexpress.messages.link_invalid')]);
        }

        return [
            'supplier_product_id' => $match[1],
            'supplier_url' => 'https://'.$host.'/item/'.$match[1].'.html',
        ];
    }
}
