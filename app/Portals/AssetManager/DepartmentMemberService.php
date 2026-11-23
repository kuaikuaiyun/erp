<?php

namespace App\Portals\AssetManager;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\DepartmentChangeLog;
use App\Portal\Services\PortalService;
use App\Services\DepartmentOrganizationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentMemberService
{
    public function __construct(private AccountAuthorizationService $authorization, private AccountTransferService $transfer) {}

    public function department(AdminUser $actor, int $id, bool $lock = false): Department
    {
        abort_unless($this->authorization->canManageOrganization($actor), 403);
        $query = Department::business()->whereIn('id', $this->authorization->managedDepartmentIds($actor));
        if ($lock) $query->lockForUpdate();
        return $query->findOrFail($id);
    }

    private function leaf(Department $department): void
    {
        if ($department->children()->exists()) throw ValidationException::withMessages(['department' => __('asset_accounts.member_tool.leaf_only')]);
    }

    private function accounts(AdminUser $actor)
    {
        // A readable account alone is not sufficient: candidates must be writable,
        // active, non-admin accounts in an authorized company (or unassigned).
        return $this->authorization->accounts($actor, true)->where('status', AdminUser::STATUS_ACTIVE)
            ->whereDoesntHave('roles', fn ($q) => $q->where('slug', Department::SYSTEM_SLUG))
            ->whereIn((new AdminUser)->getTable().'.id', $this->transfer->exportQuery($actor)->select((new AdminUser)->getTable().'.id'));
    }

    public function candidates(AdminUser $actor, int $id, array $input): array
    {
        $department = $this->department($actor, $id); $this->leaf($department);
        $allowed = $this->authorization->managedDepartmentIds($actor);
        $companies = $this->transfer->companies($actor);
        $data = Validator::make($input, [
            'page' => ['nullable','integer','min:1'], 'search' => ['nullable','string','max:100'],
            'company_id' => ['nullable','string',Rule::in(array_merge(['unassigned'], $companies->pluck('id')->map(fn ($id) => (string) $id)->all()))],
            'department_id' => ['nullable','integer',Rule::in($allowed->all())],
        ], __('asset_accounts.validation'), __('asset_accounts.attributes'))->validate();
        $query = $this->accounts($actor)->whereDoesntHave('departments', fn ($q) => $q->whereKey($id))
            ->with(['company','departments' => fn ($q) => $q->whereIn('id', $allowed)]);
        if (filled($data['search'] ?? null)) {
            $term = '%'.str_replace(['!','%','_'], ['!!','!%','!_'], $data['search']).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("username LIKE ? ESCAPE '!'", [$term]));
        }
        if (filled($data['department_id'] ?? null)) $query->whereHas('departments', fn ($q) => $q->whereKey((int) $data['department_id']));
        if (filled($data['company_id'] ?? null)) {
            if ($data['company_id'] === 'unassigned') $query->whereNull('company_id');
            else $query->where('company_id', (int) $data['company_id']);
        }
        $rows = $query->orderBy('name')->orderBy('id')->paginate(20, ['*'], 'page', (int) ($data['page'] ?? 1));
        $rows->setCollection($rows->getCollection()->map(fn ($user) => [
            'id' => $user->id,'name' => $user->name,'username' => $user->username,'company' => $user->company?->name,
            'departments' => $user->departments->map(fn ($d) => ['id'=>$d->id,'name'=>$d->name])->values()->all(),
        ]));
        return ['rows'=>$rows, 'companies'=>$companies, 'departments'=>Department::business()->whereIn('id',$allowed)->where('id','!=',$id)->with('parent:id,name')->orderBy('sort')->orderBy('name')->get()->map(fn ($d) => ['id'=>$d->id,'name'=>($d->parent?$d->parent->name.' / ':'').$d->name])];
    }

    public function add(AdminUser $actor, int $id, array $input): array
    {
        $data = Validator::make($input, ['user_ids'=>['required','array','min:1','max:100'],'user_ids.*'=>['required','integer','min:1','distinct']], __('asset_accounts.validation'), __('asset_accounts.attributes'))->validate();
        $this->department($actor, $id);
        return (new Department)->getConnection()->transaction(function () use ($actor, $id, $data) {
            $department = $this->department($actor, $id, true);
            $this->leaf($department);
            $users = $this->accounts($actor)->whereKey($data['user_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== count($data['user_ids'])) throw ValidationException::withMessages(['user_ids'=>__('asset_accounts.member_tool.selection_invalid')]);
            $organization = new DepartmentOrganizationService($this->authorization, app(PortalService::class));
            $managed = $this->authorization->managedDepartmentIds($actor); $added = [];
            foreach ($users as $user) {
                $memberships = $organization->membershipsForAccount($user);
                // Retries never downgrade an existing manager/deputy to a member.
                if ($memberships->contains(fn ($m) => (int) $m->department_id === $id)) continue;
                $editable = $memberships->filter(fn ($m) => $managed->contains((int) $m->department_id))
                    ->map(fn ($m) => ['department_id'=>(int)$m->department_id,'position'=>$m->position ?: Department::POSITION_MEMBER])->values();
                $editable->push(['department_id'=>$id,'position'=>Department::POSITION_MEMBER]);
                $organization->syncAccountMemberships($user, $editable->all(), $actor);
                $added[] = $user->id;
            }
            if ($added) DepartmentChangeLog::create(['actor_id'=>$actor->id,'department_id'=>$id,'event'=>'hr_department_members_added','payload'=>json_encode(['user_ids'=>$added])]);
            return ['count'=>count($added),'message'=>__('asset_accounts.member_tool.added',['count'=>count($added)])];
        }, 3);
    }
}
