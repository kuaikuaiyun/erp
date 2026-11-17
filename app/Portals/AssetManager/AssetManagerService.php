<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssetManagerService
{
    private ?array $userOptions = null;

    private ?array $departmentOptions = null;

    public function users()
    {
        return $this->userOptions ??= AdminUser::query()->orderBy('name')->get(['id', 'name', 'username'])->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->username.')'])->all();
    }

    public function departments()
    {
        return $this->departmentOptions ??= Department::business()->with('parent')->where(fn ($q) => $q->whereNotNull('parent_id')->orWhereDoesntHave('children'))->orderBy('name')->get()->mapWithKeys(fn ($d) => [$d->id => ($d->parent ? $d->parent->name.' / ' : '').$d->name])->all();
    }

    public function query(string $kind, Request $request)
    {
        $class = AssetManagerCatalog::model($kind);
        $q = $class::query();
        foreach (AssetManagerCatalog::fields($kind) as $key => $type) {
            $value = $request->input('filter.'.$key);
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            if (in_array($type, ['user', 'department', 'money', 'date'])) {
                $q->where($key, $value);
            } else {
                $q->where($key, 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value).'%');
            }
        }
        foreach (['from' => ['>=', false], 'to' => ['<', true]] as $key => [$op,$next]) {
            if ($request->filled($key)) {
                $date = $this->date($request->input($key), true);
                $q->where('created_at', $op, ($next ? $date->addDay() : $date)->utc());
            }
        }
        if ($kind === 'codex-renewals') {
            foreach (['renewal_from' => '>=', 'renewal_to' => '<='] as $key => $op) {
                if ($request->filled($key)) {
                    $q->where('renewal_date', $op, $this->date($request->input($key), true)->toDateString());
                }
            }
            $today = CarbonImmutable::now('Asia/Shanghai')->toDateString();
            switch ($request->input('reminder')) {
                case 'expired':$q->where('renewal_date', '<', $today);
                    break;
                case 'today':$q->where('renewal_date', $today);
                    break;
                case 'upcoming':$q->whereBetween('renewal_date', [CarbonImmutable::now('Asia/Shanghai')->addDay()->toDateString(), CarbonImmutable::now('Asia/Shanghai')->addDays(7)->toDateString()]);
                    break;
                case 'unset':$q->whereNull('renewal_date');
            }
        }

        return $q;
    }

    public function date(mixed $value, bool $dateOnly = false): CarbonImmutable
    {
        $value = trim((string) $value);
        $format = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? 'Y-m-d' : (! $dateOnly && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) ? 'Y-m-d H:i:s' : null);
        if ($format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, $value, 'Asia/Shanghai');
                if ($d && $d->format($format) === $value) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }
        throw ValidationException::withMessages(['date' => __('asset_manager.invalid_date')]);
    }

    public function validate(string $kind, array $input): array
    {
        $rules = [];
        foreach (AssetManagerCatalog::fields($kind) as $key => $type) {
            $rules[$key] = match ($type) {
                'money' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],'user' => ['nullable', 'integer', Rule::in(array_keys($this->users()))],'department' => ['nullable', 'integer', Rule::in(array_keys($this->departments()))],'date' => ['nullable', 'date_format:Y-m-d'],default => ['nullable', 'string', 'max:5000']
            };
        }
        $data = validator($input, $rules, [], __('asset_manager.fields'))->validate();
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        if (isset($data['user_id'])) {
            $data['user_snapshot'] = $this->users()[$data['user_id']];
        } elseif (array_key_exists('user_id', $data)) {
            $data['user_snapshot'] = null;
        }
        if (isset($data['department_id'])) {
            $data['department_snapshot'] = $this->departments()[$data['department_id']];
        } elseif (array_key_exists('department_id', $data)) {
            $data['department_snapshot'] = null;
        }

        return $data;
    }

    public function cards(string $kind, $query): array
    {
        if ($kind === 'computers') {
            return ['total_assets' => (clone $query)->count(), 'rent_total' => bcadd((string) (clone $query)->sum('monthly_rent'), '0', 2), 'unknown_rent' => (clone $query)->whereNull('monthly_rent')->count()];
        }
        if ($kind !== 'codex-renewals') {
            return [];
        }$today = CarbonImmutable::now('Asia/Shanghai');

        return ['total_assets' => (clone $query)->count(), 'expired' => (clone $query)->where('renewal_date', '<', $today->toDateString())->count(), 'today' => (clone $query)->where('renewal_date', $today->toDateString())->count(), 'upcoming' => (clone $query)->whereBetween('renewal_date', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()])->count(), 'unset' => (clone $query)->whereNull('renewal_date')->count()];
    }

    public function display($record, string $key, string $type): string
    {
        if ($type === 'user') {
            return $record->user_snapshot ?? '';
        }if ($type === 'department') {
            return $record->department_snapshot ?? '';
        }if ($type === 'date') {
            return $record->$key?->format('Y-m-d') ?? '';
        }

return (string) ($record->$key ?? '');
    }
}
