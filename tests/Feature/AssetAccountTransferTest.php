<?php

namespace Tests\Feature;

require_once __DIR__.'/AssetAccountsTest.php';

use App\Models\AdminUser;
use App\Models\DingTalkCompanyGrant;
use App\Portals\AssetManager\AccountTransferService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AssetAccountTransferTest extends TestCase
{
    use AssetAccountsEnvironment { setUp as private accountsSetUp; }

    protected function setUp(): void
    {
        $this->accountsSetUp();
        if (!Schema::hasColumn('admin_users','company_id')) Schema::table('admin_users', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable());
        DingTalkCompanyGrant::create(['company_id'=>$this->company->id,'user_id'=>$this->hrActor->id,'created_by'=>1]);
    }

    private function row(string $username = 'hr-import'): array
    {
        return ['_line'=>2,'username'=>$username,'name'=>'Imported Person','company_id'=>(string)$this->company->id,'company_name'=>$this->company->name,
            'status'=>'1','employment_status'=>'在职','hired_at'=>'2026-11-01','resigned_at'=>'','department_codes'=>$this->teamA->slug,'department_positions'=>'成员'];
    }

    public function test_manager_import_creates_only_authorized_memberships_and_company_without_binding(): void
    {
        $result = app(AccountTransferService::class)->create([$this->row()],$this->hrActor,'initial-password');
        $user = AdminUser::findOrFail($result['account_ids'][0]);
        $this->assertSame($this->company->id,$user->company_id); $this->assertTrue(Hash::check('initial-password',$user->password));
        $this->assertSame([$this->teamA->id],$user->departments()->pluck('id')->all());
        $this->assertSame(0,$user->dingTalkBindings()->count());
        $this->get($this->hrUrl)->assertOk()->assertSee('归属企业')->assertSee($this->company->name);
    }

    public function test_import_rejects_outside_departments_companies_duplicates_and_revoked_grants(): void
    {
        $service = app(AccountTransferService::class);
        $bad=$this->row(); $bad['department_codes']=$this->outside->slug;
        $this->assertNotEmpty($service->validateRows([$bad],$this->hrActor)['errors']);
        $bad=$this->row(); $bad['company_id']='999999';
        $this->assertNotEmpty($service->validateRows([$bad],$this->hrActor)['errors']);
        $this->assertNotEmpty($service->validateRows([$this->row(),$this->row()],$this->hrActor)['errors']);
        DingTalkCompanyGrant::where('user_id',$this->hrActor->id)->delete();
        $before=AdminUser::count();
        try { $service->create([$this->row()],$this->hrActor,'initial-password'); $this->fail('Expected grant check'); }
        catch(ValidationException $e){$this->assertSame($before,AdminUser::count());}
    }

    public function test_batch_company_is_atomic_and_preserves_cross_department_memberships(): void
    {
        $shared=$this->hrMember($this->teamA); $shared->roles()->attach($this->outside->id,['position'=>'member']);
        $outside=$this->hrMember($this->outside);
        $url=$this->hrUrl.'/transfer/company';
        $this->postJson($url,['account_ids'=>[$shared->id,$outside->id],'company_id'=>$this->company->id])->assertUnprocessable();
        $this->assertNull($shared->fresh()->company_id);
        $before=$shared->roles()->pluck('id')->all();
        $this->postJson($url,['account_ids'=>[$shared->id],'company_id'=>$this->company->id])->assertOk()->assertJsonPath('changed',1);
        $this->assertSame($before,$shared->fresh()->roles()->pluck('id')->all());
        $this->assertSame(1,DB::table('admin_department_change_logs')->where('event','hr_account_company_assigned')->count());
        $this->postJson($url,['account_ids'=>[$shared->id],'company_id'=>999999])->assertForbidden();
    }

    public function test_member_is_readonly_but_can_export_visible_accounts(): void
    {
        $visible=$this->hrMember($this->teamA); $this->hrActor=$this->hrMember($this->hrSource);
        foreach(['options','template'] as $action) $this->getJson($this->hrUrl.'/transfer/'.$action)->assertForbidden();
        $this->postJson($this->hrUrl.'/transfer/company',['account_ids'=>[$visible->id],'company_id'=>$this->company->id])->assertForbidden();
        $this->get($this->hrUrl.'/transfer/export?account_ids[]='.$visible->id)->assertOk();
        $outside=$this->hrMember($this->outside);
        $this->getJson($this->hrUrl.'/transfer/export?account_ids[]='.$outside->id)->assertUnprocessable();
    }

    public function test_scoped_workbook_has_no_outside_department_references_or_memberships(): void
    {
        $shared=$this->hrMember($this->teamA); $shared->roles()->attach($this->outside->id,['position'=>'member']);
        $response=$this->get($this->hrUrl.'/transfer/export?account_ids[]='.$shared->id)->assertOk();
        $path=tempnam(sys_get_temp_dir(),'hr-xlsx-'); file_put_contents($path,$response->streamedContent());
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame($this->teamA->slug,(string)$book->getSheet(0)->getCell('I2')->getValue());
            $references=json_encode($book->getSheet(1)->toArray());
            $this->assertStringNotContainsString($this->outside->slug,$references);
            $this->assertStringNotContainsString($this->outside->name,$references);
            $book->disconnectWorksheets();
        } finally { unlink($path); }
        $this->get($this->hrUrl.'/transfer/template')->assertOk();
    }

    public function test_preview_commit_is_session_bound_revalidated_and_idempotent(): void
    {
        $path=tempnam(sys_get_temp_dir(),'hr-import-');
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray(app(AccountTransferService::class)->sheets->headers(),null,'A1');
        $row=$this->row(); unset($row['_line']); $book->getActiveSheet()->fromArray(array_values($row),null,'A2');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path); $book->disconnectWorksheets();
        $session=app('session')->driver(); $session->start();
        $request=function(string $action,array $data=[],array $files=[]) use($session) {
            $request=Request::create($this->hrUrl.'/transfer/'.$action,'POST',$data,[], $files);
            $request->setLaravelSession($session); $route=app('router')->getRoutes()->match($request); $request->setRouteResolver(fn()=>$route);
            return $request;
        };
        $controller=app(\App\Portals\AssetManager\Http\Controllers\AccountTransferController::class);
        try {
            $preview=$controller->preview($request('preview',[],['file'=>new UploadedFile($path,'accounts.xlsx',null,null,true)]))->getData(true);
            $this->assertSame([], $preview['errors']); $this->assertNotEmpty($preview['token']);
            $before=AdminUser::count();
            $result=$controller->commit($request('import',['token'=>$preview['token'],'password'=>'initial-password']))->getData(true);
            $again=$controller->commit($request('import',['token'=>$preview['token'],'password'=>'different-password']))->getData(true);
            $this->assertSame($result,$again); $this->assertSame($before+1,AdminUser::count());
            $this->assertTrue(Hash::check('initial-password',AdminUser::find($result['account_ids'][0])->password));
            $session->migrate();
            try {$controller->commit($request('import',['token'=>$preview['token'],'password'=>'initial-password']));$this->fail('Must reject another session');}
            catch(ValidationException $e){$this->assertArrayHasKey('file',$e->errors());}
        } finally { unlink($path); }
    }

    public function test_late_position_conflict_rolls_back_all_new_accounts(): void
    {
        $rows=[$this->row('first-new'),$this->row('second-new')];
        $rows[1]['_line']=3; $rows[1]['department_positions']='主管';
        $service=app(AccountTransferService::class);
        $this->assertSame([],$service->validateRows($rows,$this->hrActor)['errors']);
        $this->hrMember($this->teamA,'manager'); $before=AdminUser::count();
        try{$service->create($rows,$this->hrActor,'initial-password');$this->fail('Must recheck occupied positions');}
        catch(ValidationException $e){$this->assertSame($before,AdminUser::count());$this->assertSame(0,AdminUser::whereIn('username',['first-new','second-new'])->count());}
    }
}
