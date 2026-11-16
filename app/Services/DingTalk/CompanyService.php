<?php
namespace App\Services\DingTalk;

use App\Models\DingTalkCompany;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyService
{
    public function enabled(int $id): DingTalkCompany
    {
        $company=DingTalkCompany::query()->where('enabled',true)->when(\Illuminate\Support\Facades\DB::transactionLevel()>0,fn ($q)=>$q->lockForUpdate())->find($id);
        if (!$company) throw new HttpException(403,__('dingtalk.company.unavailable'));
        return $company;
    }
    public function forCorp(string $corp): DingTalkCompany
    {
        $company=DingTalkCompany::query()->where('corp_id',$corp)->where('enabled',true)->first();
        if (!$company) throw new HttpException(401,__('dingtalk.errors.revoked'));
        return $company;
    }
    public function assertVersion(DingTalkCompany $company, int $version): void
    {
        $current=$this->enabled((int)$company->id);
        if ((int)$current->version!==$version) throw new HttpException(401,__('dingtalk.errors.revoked'));
    }
    public function forLoginSlug(string $slug): DingTalkCompany
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 64) {
            throw new HttpException(404, __('dingtalk.entry.unavailable'));
        }
        $company = DingTalkCompany::query()->where('enabled', true)->where('login_slug', $slug)->first();
        if (!$company) throw new HttpException(404, __('dingtalk.entry.unavailable'));
        return $company;
    }

    public function options()
    {
        return DingTalkCompany::query()->where('enabled',true)->orderBy('name')->get(['id','name']);
    }
}
