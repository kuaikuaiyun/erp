<?php
namespace App\Admin\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\Department;
use App\Services\AccountSpreadsheetService;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AccountTransferController extends Controller
{
    private function actor(): AdminUser
    {
        $actor=Admin::user();
        abort_unless($actor && $actor->isAdministrator(),403,__('organization.authorization.super_admin_only'));
        return $actor;
    }

    public function template(AccountSpreadsheetService $sheets)
    {
        $this->actor();
        return $sheets->download();
    }

    public function export(Request $request, AccountSpreadsheetService $sheets)
    {
        $this->actor();
        $filters=$request->validate(['account_ids'=>'nullable|array|max:5000','account_ids.*'=>'required|integer|min:1|distinct',
            'username'=>'nullable|string|max:190','name'=>'nullable|string|max:255',
            'department_ids'=>'nullable|array|max:500','department_ids.*'=>'required|integer|min:1']);
        $model=config('admin.database.users_model'); $query=$model::query();
        if (!empty($filters['account_ids'])) {
            $query->whereIn('id',$filters['account_ids']);
            if ((clone $query)->count()!==count($filters['account_ids'])) throw ValidationException::withMessages(['account_ids'=>__('account_transfer.selection_changed')]);
        } else {
            foreach (['username','name'] as $field) if (filled($filters[$field] ?? null)) $query->where($field,'like','%'.$filters[$field].'%');
            if (!empty($filters['department_ids'])) $query->whereHas('departments',fn ($q)=>$q->whereIn((new Department())->getTable().'.id',$filters['department_ids']));
        }
        if ((clone $query)->count()>5000) throw ValidationException::withMessages(['export'=>__('account_transfer.export_limit')]);
        return $sheets->download($query);
    }

    public function preview(Request $request, AccountSpreadsheetService $sheets)
    {
        $actor=$this->actor();
        $request->validate(['file'=>'required|file|mimes:xlsx|max:5120']);
        $rows=$sheets->read($request->file('file'));
        $checked=$sheets->validateRows($rows,$actor);
        $token=null;
        if (!$checked['errors']) {
            $token=bin2hex(random_bytes(32));
            Cache::put('accounts.import.'.$token,['actor'=>$actor->id,'browser'=>hash('sha256',$request->session()->getId()),'rows'=>$rows],now()->addMinutes(10));
        }
        return response()->json(['token'=>$token,'count'=>count($rows),'errors'=>$checked['errors'],
            'rows'=>array_map(fn ($row)=>['line'=>$row['_line'],'username'=>$row['username'],'name'=>$row['name'],
                'company'=>$row['company_name'] ?: $row['company_id'], 'departments'=>$row['department_codes'],'positions'=>$row['department_positions']],$rows)])
            ->header('Cache-Control','no-store');
    }

    public function commit(Request $request, AccountSpreadsheetService $sheets)
    {
        $actor=$this->actor();
        $data=$request->validate(['token'=>['required','string','regex:/^[a-f0-9]{64}$/D'],'password'=>'required|string|min:8|max:255']);
        $lock=Cache::lock('accounts.import.lock.'.$data['token'],600);
        if (!$lock->get()) throw ValidationException::withMessages(['import'=>__('account_transfer.busy')]);
        try {
            $key='accounts.import.'.$data['token']; $batch=Cache::get($key);
            if (!$batch || (int)$batch['actor']!==(int)$actor->id || !hash_equals($batch['browser'],hash('sha256',$request->session()->getId()))) {
                throw ValidationException::withMessages(['import'=>__('account_transfer.expired')]);
            }
            if (!empty($batch['result'])) return response()->json($batch['result'])->header('Cache-Control','no-store');
            $result=$sheets->create($batch['rows'],$actor,$data['password']);
            $batch['result']=$result; unset($batch['rows']);
            Cache::put($key,$batch,now()->addMinutes(10));
            return response()->json($result)->header('Cache-Control','no-store');
        } finally { $lock->release(); }
    }
}
