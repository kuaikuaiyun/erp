<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';
use App\Models\DingTalkCompanyGrant;
use App\Services\DingTalk\DirectoryService;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DingTalkDirectoryTest extends TestCase
{
    use DingTalkTestEnvironment;
    private function fakeDirectory(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake([
            'api.dingtalk.com/v1.0/oauth2/accessToken'=>Http::response(['accessToken'=>'token','expireIn'=>7200]),
            'oapi.dingtalk.com/topapi/v2/department/listsub*'=>Http::response(['errcode'=>0,'result'=>[['dept_id'=>2,'name'=>'Department','private'=>'hidden']]]),
            'oapi.dingtalk.com/topapi/v2/user/list*'=>Http::response(['errcode'=>0,'result'=>['list'=>[['userid'=>'u1','name'=>'Member','mobile'=>'private-number']],'has_more'=>false]]),
        ]);
    }
    public function test_scoped_directory_is_cached_and_refreshable_without_exposing_extra_profile_fields(): void
    {
        $owner=$this->account(true); Admin::guard()->setUser($owner); $this->fakeDirectory();
        $directory=app(DirectoryService::class);
        self::assertSame([['id'=>2,'name'=>'Department']],$directory->departments('dingcorp-test',1));
        $directory->departments('dingcorp-test',1); $directory->departments('dingcorp-test',1,true);
        Http::assertSentCount(3);
        self::assertSame(['members'=>[['user_id'=>'u1','name'=>'Member']],'has_more'=>false,'next_cursor'=>null],$directory->members('dingcorp-test',2,0));
    }
    public function test_manager_without_company_assignment_cannot_query_directory(): void
    {
        $this->account(true); $manager=$this->manager(); Admin::guard()->setUser($manager); $this->fakeDirectory();
        $this->denied(fn()=>app(DirectoryService::class)->departments('dingcorp-test',1),403); Http::assertNothingSent();
    }
    public function test_assignment_revocation_also_blocks_cached_directory(): void
    {
        $this->account(true); $manager=$this->manager(); Admin::guard()->setUser($manager); $this->fakeDirectory();
        $grant=DingTalkCompanyGrant::query()->create(['company_id'=>$this->company->id,'user_id'=>$manager->id,'created_by'=>1]);
        $directory=app(DirectoryService::class); $directory->departments('dingcorp-test',1);
        $grant->delete(); $this->denied(fn()=>$directory->departments('dingcorp-test',1),403);
    }
    public function test_wrong_company_never_reaches_dingtalk(): void
    {
        $owner=$this->account(true); Admin::guard()->setUser($owner); $this->fakeDirectory();
        $this->denied(fn()=>app(DirectoryService::class)->departments('foreign',1),403); Http::assertNothingSent();
    }
    public function test_directory_members_require_a_non_null_pagination_flag(): void
    {
        $this->fakeDirectory(); Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(['api.dingtalk.com/v1.0/oauth2/accessToken'=>Http::response(['accessToken'=>'token','expireIn'=>7200]),
            'oapi.dingtalk.com/topapi/v2/user/list*'=>Http::response(['errcode'=>0,'result'=>['list'=>[],'has_more'=>null]])]);
        $this->denied(fn()=>app(\App\Services\DingTalk\Client::class)->forCompany($this->company)->departmentMembers(1,0),503);
    }
}
