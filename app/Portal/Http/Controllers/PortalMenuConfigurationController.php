<?php

namespace App\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PortalMenu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortalMenuConfigurationController extends Controller
{
    private const SYSTEM_MENU_CODES = [
        'home',
        'backend_management',
        'menu_configuration',
    ];

    public function __construct(private readonly PortalEntryController $entry)
    {
    }

    public function index(Request $request): Response
    {
        $context = $this->entry->portalContext($request, true);

        if ($context instanceof Response) {
            return $context;
        }

        $portal = $context['portal']->load('menus');
        $menus = $portal->menus;

        return response()->view('portal.menu-configuration.index', [
            'portal' => $portal,
            'user' => $context['user'],
            'menus' => $menus,
            'treeMenus' => $menus->where('parent_id', 0),
            'systemParents' => $menus
                ->filter(fn (PortalMenu $menu): bool => $this->isSystemMenu($menu))
                ->mapWithKeys(fn (PortalMenu $menu): array => [$menu->getKey() => (int) $menu->parent_id]),
        ]);
    }

    public function saveOrder(Request $request): JsonResponse|Response
    {
        $context = $this->entry->portalContext($request, true);

        if ($context instanceof Response) {
            return $context;
        }

        $tree = json_decode((string) $request->input('_order', ''), true);

        if (!is_array($tree)) {
            return $this->invalidOrderResponse();
        }

        $portal = $context['portal'];
        $menus = $portal->menus()->get()->keyBy('id');

        try {
            $changes = $this->normaliseTreeOrder($tree, $menus);
        } catch (\InvalidArgumentException) {
            return $this->invalidOrderResponse();
        }

        DB::connection($portal->getConnectionName())->transaction(function () use ($changes, $portal): void {
            foreach ($changes as $change) {
                PortalMenu::query()
                    ->where('portal_id', $portal->getKey())
                    ->whereKey($change['id'])
                    ->update([
                        'parent_id' => $change['parent_id'],
                        'sort' => $change['sort'],
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json([
            'status' => true,
            'message' => __('portal.menu_configuration.order_saved'),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $tree
     * @param Collection<int, PortalMenu> $menus
     * @return array<int, array{id:int, parent_id:int, sort:int}>
     */
    private function normaliseTreeOrder(array $tree, Collection $menus): array
    {
        $changes = [];
        $seen = [];
        $systemParents = [
            'home' => 0,
            'backend_management' => 0,
            'menu_configuration' => (int) optional(
                $menus->first(fn (PortalMenu $menu): bool => $menu->code === 'backend_management')
            )->getKey(),
        ];

        $walk = function (array $nodes, int $parentId, int $depth) use (&$walk, &$changes, &$seen, $menus, $systemParents): void {
            foreach (array_values($nodes) as $sort => $node) {
                $menuId = isset($node['id']) ? (int) $node['id'] : 0;

                if ($menuId === 0 || isset($seen[$menuId]) || !$menus->has($menuId)) {
                    throw new \InvalidArgumentException();
                }

                $menu = $menus->get($menuId);
                $seen[$menuId] = true;

                if ($this->isSystemMenu($menu)) {
                    if ($parentId !== ($systemParents[$menu->code] ?? -1)) {
                        throw new \InvalidArgumentException();
                    }
                } elseif ($depth > 2 || ($parentId !== 0 && $this->isSystemMenu($menus->get($parentId)))) {
                    throw new \InvalidArgumentException();
                }

                $changes[] = [
                    'id' => $menuId,
                    'parent_id' => $parentId,
                    'sort' => $sort + 1,
                ];

                $children = $node['children'] ?? [];

                if (!is_array($children)) {
                    throw new \InvalidArgumentException();
                }

                $walk($children, $menuId, $depth + 1);
            }
        };

        $walk($tree, 0, 1);

        if (count($seen) !== $menus->count()) {
            throw new \InvalidArgumentException();
        }

        return $changes;
    }

    private function isSystemMenu(PortalMenu $menu): bool
    {
        return in_array($menu->code, self::SYSTEM_MENU_CODES, true);
    }

    private function invalidOrderResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => __('portal.menu_configuration.order_invalid'),
        ], 422);
    }
}


