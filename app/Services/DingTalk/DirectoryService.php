<?php
namespace App\Services\DingTalk;

use App\Models\DingTalkCompany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DirectoryService
{
    public function __construct(private readonly Client $client, private readonly BindingAccessService $access) {}
    private function cached(DingTalkCompany $company,string $part,bool $refresh,callable $fetch): array
    {
        $version=(int)$company->version;
        app(CompanyService::class)->assertVersion($company,$version);
        $key='dingtalk:directory:'.$company->id.':'.$version.':'.$part;
        if ($refresh) Cache::forget($key);
        $result=Cache::remember($key,60,$fetch);
        app(CompanyService::class)->assertVersion($company,$version);
        return $result;
    }
    public function departments(string $corp,int $parent,bool $refresh=false): array
    {
        $company=$this->access->assertCorp($corp);
        return $this->companyDepartments($company,$parent,$refresh);
    }
    public function members(string $corp,int $dept,int $cursor,bool $refresh=false): array
    {
        $company=$this->access->assertCorp($corp);
        return $this->companyMembers($company,$dept,$cursor,$refresh);
    }
    public function companyDepartments(DingTalkCompany $company,int $parent,bool $refresh=false): array
    {
        return $this->cached($company,'departments:'.$parent,$refresh,fn ()=>$this->client->forCompany($company)->departmentChildren($parent));
    }
    public function companyMembers(DingTalkCompany $company,int $dept,int $cursor,bool $refresh=false): array
    {
        $result=$this->cached($company,'members:'.$dept.':'.$cursor,$refresh,fn ()=>$this->client->forCompany($company)->departmentMembers($dept,$cursor));
        if ($result['has_more'] && $result['next_cursor']===$cursor) throw new HttpException(503,__('dingtalk.manage.directory_invalid'));
        return $result;
    }
    public function syncStep(DingTalkCompany $company,bool $restart=false): array
    {
        $lock=Cache::lock('dingtalk:directory-sync:'.$company->id,120);
        if (!$lock->get()) throw new HttpException(409,__('dingtalk.company.sync_busy'));
        try {
            $company=app(CompanyService::class)->enabled((int)$company->id);
            $version=(int)$company->version;
            $state=(!$restart && $company->directory_state) ? $company->directory_state : [
                'generation'=>bin2hex(random_bytes(16)),'queue'=>[['id'=>1,'cursor'=>0]],'seen'=>[1],'processed'=>0];
            $current=$state['queue'][0];
            $children=$current['cursor']===0 ? $this->companyDepartments($company,$current['id'],true) : [];
            $page=$this->companyMembers($company,$current['id'],$current['cursor'],true);
            return DB::transaction(function () use ($company,$version,$state,$current,$children,$page): array {
                app(CompanyService::class)->assertVersion($company,$version);
                foreach ($page['members'] as $member) {
                    $query=DB::table('dingtalk_directory_members')->where('company_id',$company->id)->where('generation',$state['generation'])->where('user_id',$member['user_id']);
                    $prior=$query->first();
                    $departments=$prior ? json_decode($prior->department_ids,true) : [];
                    $departments[]= (int)$current['id']; $departments=array_values(array_unique($departments));
                    if ($prior) $query->update(['name'=>$member['name'],'department_ids'=>json_encode($departments)]);
                    else DB::table('dingtalk_directory_members')->insert(['company_id'=>$company->id,'generation'=>$state['generation'],'user_id'=>$member['user_id'],'name'=>$member['name'],'department_ids'=>json_encode($departments)]);
                }
                foreach ($children as $child) {
                    if (!in_array((int)$child['id'],$state['seen'],true)) { $state['seen'][]=(int)$child['id']; $state['queue'][]=['id'=>(int)$child['id'],'cursor'=>0]; }
                }
                if (count($state['seen'])>10000) throw new HttpException(503,__('dingtalk.manage.directory_invalid'));
                if ($page['has_more']) {
                    $visited=$current['visited'] ?? [0];
                    if (in_array($page['next_cursor'],$visited,true)) throw new HttpException(503,__('dingtalk.manage.directory_invalid'));
                    $visited[]=$page['next_cursor'];
                    $state['queue'][0]['visited']=$visited;
                    $state['queue'][0]['cursor']=$page['next_cursor'];
                }
                else { array_shift($state['queue']); $state['processed']++; }
                $done=$state['queue']===[];
                $updates=['directory_state'=>$done ? null : $state];
                if ($done) {
                    $updates+=['directory_generation'=>$state['generation'],'directory_synced_at'=>now(),
                        'member_count'=>DB::table('dingtalk_directory_members')->where('company_id',$company->id)->where('generation',$state['generation'])->count()];
                    DB::table('dingtalk_directory_members')->where('company_id',$company->id)->where('generation','!=',$state['generation'])->delete();
                }
                $company->forceFill($updates)->save();
                return ['done'=>$done,'processed'=>$state['processed'],'remaining'=>count($state['queue'])];
            },3);
        } finally { $lock->release(); }
    }
}
