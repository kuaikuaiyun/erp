<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';
use App\Admin\Controllers\ConfigurationCenter\DingTalkCompanyController;
use App\Services\DingTalk\DirectoryService;
use App\Services\DingTalk\CompanyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class DingTalkCompanyTest extends TestCase
{
    use DingTalkTestEnvironment;
    public function test_secrets_are_encrypted_hidden_and_blank_edits_keep_existing_values(): void
    {
        self::assertNotSame('secret-test',DB::table('dingtalk_companies')->value('app_secret'));
        self::assertArrayNotHasKey('app_secret',$this->company->toArray());
        $request=$this->browser(); $request->merge(['id'=>$this->company->id,'login_slug'=>'test-company','name'=>'Renamed company','corp_id'=>$this->company->corp_id,'app_key'=>'app-test',
            'redirect_uri'=>$this->company->redirect_uri,'callback_owner_key'=>'app-test','enabled'=>true,'app_secret'=>'','callback_token'=>'','callback_aes_key'=>'']);
        app(DingTalkCompanyController::class)->save($request);
        self::assertSame('secret-test',$this->company->fresh()->app_secret); self::assertSame(1,$this->company->fresh()->version);
        $request->merge(['app_secret'=>'new-secret']); app(DingTalkCompanyController::class)->save($request);
        self::assertSame(2,$this->company->fresh()->version); self::assertSame('new-secret',$this->company->fresh()->app_secret);
    }
    public function test_archiving_disables_company_without_deleting_its_configuration(): void
    {
        $request=$this->browser(); app(DingTalkCompanyController::class)->destroy($this->company->id);
        self::assertSoftDeleted('dingtalk_companies',['id'=>$this->company->id]);
        $this->denied(fn()=>app(CompanyService::class)->enabled($this->company->id),403);
    }
    public function test_directory_sync_deduplicates_members_across_pages_and_departments_and_publishes_only_when_complete(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path=parse_url($request->url(),PHP_URL_PATH);
            if ($path==='/v1.0/oauth2/accessToken') return Http::response(['accessToken'=>'token','expireIn'=>7200]);
            if ($path==='/topapi/v2/department/listsub') return Http::response(['errcode'=>0,'result'=>(int)$request['dept_id']===1 ? [['dept_id'=>2,'name'=>'Child']] : []]);
            if ($path==='/topapi/v2/user/list') {
                $ids=(int)$request['dept_id']===2 ? ['u3','u4'] : ((int)$request['cursor']===0 ? ['u1','u2'] : ['u1','u3']);
                return Http::response(['errcode'=>0,'result'=>['list'=>array_map(fn ($id)=>['userid'=>$id,'name'=>$id],$ids),'has_more'=>(int)$request['dept_id']===1 && (int)$request['cursor']===0,'next_cursor'=>100]]);
            }
            throw new \LogicException('Unexpected API');
        });
        $directory=app(DirectoryService::class);
        self::assertFalse($directory->syncStep($this->company)['done']); self::assertNull($this->company->fresh()->member_count);
        self::assertFalse($directory->syncStep($this->company)['done']); self::assertNull($this->company->fresh()->member_count);
        self::assertTrue($directory->syncStep($this->company)['done']); self::assertSame(4,(int)$this->company->fresh()->member_count);
        self::assertSame(4,DB::table('dingtalk_directory_members')->where('company_id',$this->company->id)->count());
        self::assertNull($this->company->fresh()->directory_state);
    }
}
