<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DepartmentChangeLog;
use App\Models\DingTalkCompany;
use App\Models\DingTalkCompanyGrant;
use App\Services\AccountSpreadsheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountTransferService
{
    public function __construct(public AccountAuthorizationService $authorization, public AccountService $accounts, public AccountSpreadsheetService $sheets) {}

    public function companies(AdminUser $actor)
    {
        return DingTalkCompany::where('enabled', true)->when(! $actor->isAdministrator(), fn ($q) => $q->whereIn('id', DingTalkCompanyGrant::where('user_id', $actor->id)->select('company_id')))->orderBy('name')->get(['id','name']);
    }

    public function validateRows(array $rows, AdminUser $actor): array
    {
        abort_unless($this->authorization->canManageOrganization($actor), 403);
        $codes = Department::business()->whereIn('id', $this->authorization->managedDepartmentIds($actor))->pluck('slug')->all();
        $companies = $this->companies($actor)->pluck('id')->all(); $errors = [];
        foreach ($rows as $row) {
            $selected = array_map('trim', explode(',', str_replace('，', ',', $row['department_codes'])));
            if (array_diff($selected, $codes) || ($row['company_id'] !== '' && ! in_array((int) $row['company_id'], $companies, true))) {
                $errors[] = ['line' => $row['_line'], 'username' => $row['username'], 'messages' => [__('asset_accounts.transfer.scope_error')]];
            }
        }
        if ($errors) return ['rows' => [], 'errors' => $errors];
        return $this->sheets->validateRows($rows, $actor);
    }

    public function create(array $rows, AdminUser $actor, string $password): array
    {
        return (new AdminUser)->getConnection()->transaction(function () use ($rows, $actor, $password) {
            // Revalidate after the preview, including revoked grants and occupied positions.
            $checked = $this->validateRows($rows, $actor);
            if ($checked['errors']) throw ValidationException::withMessages(['file' => array_map(fn ($e) => __('account_transfer.row_error', ['line' => $e['line'], 'error' => implode(' / ', $e['messages'])]), $checked['errors'])]);
            $ids = [];
            foreach ($checked['rows'] as $row) {
                $request = new Request($row + ['password' => $password, 'password_confirmation' => $password]);
                $account = $this->accounts->save($request, $actor);
                if ($row['company_id']) $this->assign($actor, [$account->id], (int) $row['company_id']);
                $ids[] = $account->id;
            }
            return ['count' => count($ids), 'account_ids' => $ids, 'message' => __('account_transfer.created', ['count' => count($ids)])];
        });
    }

    public function assign(AdminUser $actor, array $ids, int $companyId): array
    {
        abort_unless($this->authorization->canManageOrganization($actor), 403);
        return (new AdminUser)->getConnection()->transaction(function () use ($actor, $ids, $companyId) {
            $company = $this->companies($actor)->where('id', $companyId)->first();
            abort_unless($company, 403);
            $accounts = $this->authorization->accounts($actor, true)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($accounts->count() !== count($ids)) throw ValidationException::withMessages(['account_ids' => __('asset_accounts.transfer.selection_error')]);
            $changed = 0;
            foreach ($accounts as $account) {
                if ((int) $account->company_id === $companyId) continue;
                $before = $account->company_id; $account->company_id = $companyId; $account->save(); $changed++;
                DepartmentChangeLog::create(['actor_id' => $actor->id, 'department_id' => null, 'event' => 'hr_account_company_assigned',
                    'payload' => json_encode(['user_id' => $account->id, 'before_company_id' => $before, 'company_id' => $companyId])]);
            }
            return ['count' => count($ids), 'changed' => $changed, 'message' => __('asset_accounts.saved')];
        });
    }

    public function exportQuery(AdminUser $actor)
    {
        $query = $this->authorization->accounts($actor);
        if (! $actor->isAdministrator()) {
            $query->where(fn ($q) => $q->whereNull('company_id')->orWhereIn('company_id', $this->companies($actor)->pluck('id')));
        }
        return $query;
    }

    public function download(AdminUser $actor, $query = null)
    {
        $write = $query === null;
        if ($query) $query->whereIn((new AdminUser)->getTable().'.id', $this->exportQuery($actor)->select((new AdminUser)->getTable().'.id'));
        $departments = Department::business()->whereIn('id', app(AssetComputerDepartmentBindingService::class)->departmentIds($actor, $write))->orderBy('id')->get();
        $allowed = $departments->pluck('id')->all(); $companies = $this->companies($actor);
        $cells = fn ($values) => ['cells' => array_map(fn ($v) => ['value' => (string) $v, 'type' => 'string'], $values)];
        $rows = function () use ($query, $allowed, $cells) {
            if (! $query) return;
            foreach ($query->with(['company', 'departments'])->orderBy('id')->lazy(200) as $account) {
                $members = $account->departments->whereIn('id', $allowed)->sortBy('id');
                yield $cells([$account->username, $account->name, $account->company_id ?? '', $account->company?->name ?? '', $account->status,
                    __('organization.accounts.employment_'.($account->employment_status ?: 'employed')), $account->hired_at?->format('Y-m-d') ?? '', $account->resigned_at?->format('Y-m-d') ?? '',
                    $members->pluck('slug')->implode(','), $members->map(fn ($d) => __('organization.positions.'.$d->pivot->position))->implode(',')]);
            }
        };
        $references = function () use ($departments, $companies, $cells) {
            yield $cells([__('account_transfer.company_reference'), __('organization.common.id'), __('organization.accounts.name')]);
            foreach ($companies as $company) yield $cells(['', $company->id, $company->name]);
            yield $cells([__('account_transfer.department_reference'), __('account_transfer.fields.department_codes'), __('organization.accounts.name')]);
            foreach ($departments as $department) yield $cells(['', $department->slug, $department->name]);
        };
        $response = app(\App\Portals\Logistics\StreamedXlsxWriter::class)->response($query ? 'hr-accounts.xlsx' : 'hr-account-template.xlsx', __('account_transfer.sheet'),
            array_map(fn ($label) => ['label' => $label, 'width' => 26], $this->sheets->headers()), $rows,
            additionalSheets: [[__('account_transfer.references'), [['label' => __('account_transfer.references'), 'width' => 26], ['label' => __('account_transfer.code'), 'width' => 28], ['label' => __('organization.accounts.name'), 'width' => 40]], $references],
                [__('account_transfer.instructions'), [['label' => __('account_transfer.instructions'), 'width' => 100]], function () use ($cells) { foreach (__('account_transfer.notes') as $note) yield $cells([$note]); yield $cells([__('asset_accounts.transfer.scope_error')]); }]]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
