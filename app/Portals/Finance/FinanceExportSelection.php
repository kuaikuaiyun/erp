<?php

namespace App\Portals\Finance;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FinanceExportSelection
{
    public static function key(array $row): string
    {
        if (isset($row['account_group'])) {
            return hash('sha256', json_encode([$row['channel'], $row['account_group']], JSON_THROW_ON_ERROR));
        }
        $fields = isset($row['id']) ? ['id'] : (isset($row['raw_type']) ? ['raw_type'] : (isset($row['name']) ? ['name', 'channel'] : ['channel', 'currency', 'team', 'subscription', 'month']));
        return hash('sha256', json_encode(array_map(fn ($field) => $row[$field] ?? null, $fields), JSON_THROW_ON_ERROR));
    }

    public static function keys(Request $request): ?array
    {
        $data = $request->validate(['selected_rows' => 'sometimes|required|array|min:1|max:100', 'selected_rows.*' => 'required|string|size:64|distinct|regex:/^[a-f0-9]+$/']);
        return $data['selected_rows'] ?? null;
    }

    public static function filter(Request $request, iterable $rows): Collection
    {
        $keys = self::keys($request);
        $rows = collect($rows);
        if ($keys === null) return $rows;
        $selected = $rows->filter(fn ($row) => in_array(self::key((array) $row), $keys, true))->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['selected_rows' => '所选数据已不在当前筛选结果中，请重新查询后选择。']);
        }
        return $selected;
    }
}
