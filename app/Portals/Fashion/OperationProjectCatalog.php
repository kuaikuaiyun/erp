<?php

namespace App\Portals\Fashion;

use App\Models\Department;
use Illuminate\Support\Collection;

final class OperationProjectCatalog
{
    /** @return array<string, array{name:string,route:string,path:string,departments:list<string>}> */
    public static function all(): array
    {
        return [
            'fuzhuang' => ['name' => '服装运营系统', 'route' => 'fashion', 'path' => 'fashion', 'departments' => ['服装运营部门']],
            'baokuan-fashion' => ['name' => '爆款服装项目', 'route' => 'baokuan-fashion', 'path' => 'baokuan-fashion', 'departments' => ['爆款服装项目', '爆款服装运营部门']],
            'baokuan-general' => ['name' => '爆款百货项目', 'route' => 'baokuan-general', 'path' => 'baokuan-general', 'departments' => ['爆款百货项目', '爆款百货运营部门']],
            'kunpeng-shopify' => ['name' => '鲲鹏shopify项目', 'route' => 'kunpeng-shopify', 'path' => 'kunpeng-shopify', 'departments' => ['鲲鹏shopify项目', '鲲鹏Shopify项目', '鲲鹏Shopify运营部门']],
            'kunpeng-general' => ['name' => '鲲鹏百货项目', 'route' => 'kunpeng-general', 'path' => 'kunpeng-general', 'departments' => ['鲲鹏百货项目', '鲲鹏百货运营部门']],
        ];
    }

    /** @return array{code:string,name:string,route:string,path:string,departments:list<string>} */
    public static function get(string $code): array
    {
        $project = self::all()[$code] ?? null;
        abort_unless($project, 404);

        return ['code' => $code, ...$project];
    }

    public static function departmentIds(string $code): Collection
    {
        $project = self::get($code);
        $roots = Department::business()->whereIn('name', $project['departments'])->pluck('id')->map(fn ($id): int => (int) $id);
        if ($roots->isEmpty()) {
            return collect();
        }

        $departments = Department::business()->get(['id', 'parent_id']);
        $ids = $roots->unique()->values();
        do {
            $children = $departments->whereIn('parent_id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->diff($ids)->values();
            $before = $ids->count();
            $ids = $ids->merge($children)->unique()->values();
        } while ($ids->count() > $before);

        return $ids;
    }

    public static function route(array $project, string $page): string
    {
        return $project['route'].'.'.$page;
    }
}
