<?php

namespace App\Portals\Fashion;

use App\Models\Department;
use Illuminate\Support\Collection;

final class OperationProjectCatalog
{
    /** @return array<string, array{name:string,route:string,path:string,departments:list<string>,store_projects:list<string>}> */
    public static function all(): array
    {
        return [
            'fuzhuang' => [
                'name' => '单包服装运营系统',
                'route' => 'fashion',
                'path' => 'fashion',
                'departments' => ['服装运营部门', '单包服装运营部门', '单包服装运营系统'],
                'store_projects' => [],
            ],
            'baokuan-fashion' => [
                'name' => '爆款服装运营系统',
                'route' => 'baokuan-fashion',
                'path' => 'baokuan-fashion',
                'departments' => ['爆款服装项目', '爆款服装运营部门', '爆款服装运营系统'],
                'store_projects' => ['爆款服装项目', '爆款服装运营系统'],
            ],
            'baokuan-general' => [
                'name' => '爆款百货运营系统',
                'route' => 'baokuan-general',
                'path' => 'baokuan-general',
                'departments' => ['爆款百货项目', '爆款百货运营部门', '爆款百货运营系统'],
                'store_projects' => ['爆款百货项目', '爆款百货运营系统'],
            ],
            'danbao-general' => [
                'name' => '单包百货运营系统',
                'route' => 'danbao-general',
                'path' => 'danbao-general',
                'departments' => ['单包百货项目', '单包百货', '单包百货运营部门', '单包百货运营系统'],
                'store_projects' => ['单包百货项目', '单包百货', '单包百货运营系统'],
            ],
        ];
    }

    /** @return array{code:string,name:string,route:string,path:string,departments:list<string>,store_projects:list<string>} */
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

    public static function supportsMiscFeeRmb(string $code): bool
    {
        return $code !== 'fuzhuang' && array_key_exists($code, self::all());
    }

    /** @return array{gross_margin_rate:float,store_fee_rate:float,usd_to_rmb_rate:float}|null */
    public static function performanceFormula(string $code): ?array
    {
        return match ($code) {
            'danbao-general' => ['gross_margin_rate' => 0.625, 'store_fee_rate' => 0.01, 'usd_to_rmb_rate' => 7.0],
            'baokuan-fashion' => ['gross_margin_rate' => 0.50, 'store_fee_rate' => 0.01, 'usd_to_rmb_rate' => 7.0],
            'baokuan-general' => ['gross_margin_rate' => 0.45, 'store_fee_rate' => 0.015, 'usd_to_rmb_rate' => 7.0],
            default => null,
        };
    }

    /** @return list<string> */
    public static function storeProjectNames(array $project): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($name): string => trim((string) $name),
            $project['store_projects'] ?? [$project['name']]
        ))));
    }
}
