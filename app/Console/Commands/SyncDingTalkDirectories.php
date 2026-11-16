<?php
namespace App\Console\Commands;
use App\Models\DingTalkCompany;
use App\Services\DingTalk\DirectoryService;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;
class SyncDingTalkDirectories extends Command
{
    protected $signature='dingtalk:directory-sync {--company=}';
    public function __construct() { parent::__construct(); $this->setDescription(__('dingtalk.company.sync_command')); }
    public function handle(DirectoryService $directories): int
    {
        $query=DingTalkCompany::query()->where('enabled',true)->where(fn ($q)=>$q->whereNotNull('directory_state')->orWhereNull('directory_synced_at')->orWhere('directory_synced_at','<=',now()->subMinutes(15)));
        if ($this->option('company')) $query->whereKey($this->option('company'));
        $companies=$query->orderBy('updated_at')->limit(3)->get();
        $failed=false;
        foreach ($companies as $company) {
            if (!$company->directory_state && $company->directory_synced_at && $company->directory_synced_at->gt(now()->subMinutes(15))) continue;
            try { $directories->syncStep($company); }
            catch (HttpException $e) { $failed=true; $this->error(__('dingtalk.company.sync_failed',['id'=>$company->id])); $company->touch(); }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
