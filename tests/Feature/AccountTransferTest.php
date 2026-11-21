<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\AccountSpreadsheetService;
use App\Services\DepartmentOrganizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

require_once __DIR__.'/DingTalkLoginTest.php';

class AccountTransferTest extends TestCase
{
    use DingTalkTestEnvironment { setUp as protected authenticationSetUp; }

    protected function setUp(): void
    {
        $this->authenticationSetUp();
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('employment_status')->default('employed');
            $table->date('hired_at')->nullable(); $table->date('resigned_at')->nullable();
        });
        Schema::table('admin_roles', fn (Blueprint $table) => $table->integer('sort')->default(0));
        Schema::create('admin_department_change_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('actor_id'); $table->unsignedBigInteger('department_id')->nullable();
            $table->string('event'); $table->text('payload'); $table->timestamps();
        });
        DB::table('admin_roles')->insert(['name'=>'测试部门','slug'=>'dept-qa','sort'=>0]);
    }

    private function row(string $username = 'new-user', string $position = '成员', int $line = 2): array
    {
        return ['_line'=>$line,'username'=>$username,'name'=>'新成员','company_id'=>(string)$this->company->id,
            'company_name'=>$this->company->name,'status'=>'1','employment_status'=>'在职','hired_at'=>'2026-11-21',
            'resigned_at'=>'','department_codes'=>'dept-qa','department_positions'=>$position];
    }

    public function test_preview_rejects_duplicate_usernames_and_cross_row_manager_capacity(): void
    {
        $service=app(AccountSpreadsheetService::class); $actor=$this->account(true);
        $checked=$service->validateRows([$this->row('first','主管'),$this->row('second','主管',3),$this->row('first','成员',4)],$actor);
        $this->assertSame([3,4],array_column($checked['errors'],'line'));
        $this->assertSame(1,AdminUser::count());
    }

    public function test_mismatched_pairs_unknown_company_and_system_role_are_rejected(): void
    {
        $actor=$this->account(true); $service=app(AccountSpreadsheetService::class);
        $row=$this->row(); $row['department_codes']='dept-qa，administrator';
        $this->assertNotEmpty($service->validateRows([$row],$actor)['errors']);
        $row['department_positions']='成员，主管';
        $this->assertNotEmpty($service->validateRows([$row],$actor)['errors']);
        $row=$this->row(); $row['company_id']='999999';
        $this->assertNotEmpty($service->validateRows([$row],$actor)['errors']);
    }

    public function test_commit_creates_hashed_password_and_business_membership_only(): void
    {
        $actor=$this->account(true); $result=app(AccountSpreadsheetService::class)->create([$this->row()],$actor,'initial-password');
        $account=AdminUser::findOrFail($result['account_ids'][0]);
        $this->assertTrue(Hash::check('initial-password',$account->password));
        $this->assertSame(1,$result['count']);
        $this->assertFalse($account->isAdministrator());
        $this->assertSame('member',$account->departments()->first()->pivot->position);
        $this->assertSame(0,$account->dingTalkBindings()->count());
    }

    public function test_late_membership_failure_rolls_back_the_entire_batch(): void
    {
        $actor=$this->account(true);
        $organization=\Mockery::mock(DepartmentOrganizationService::class);
        $organization->shouldReceive('syncAccountMemberships')->once()->ordered()->andReturnNull();
        $organization->shouldReceive('syncAccountMemberships')->once()->ordered()->andThrow(ValidationException::withMessages(['memberships'=>'conflict']));
        $service=new AccountSpreadsheetService($organization);
        try {
            $service->create([$this->row('one'),$this->row('two','成员',3)],$actor,'initial-password');
            $this->fail('Expected rollback');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('3',implode(' ',array_merge(...array_values($e->errors()))));
        }
        $this->assertSame(1,AdminUser::count());
    }

    public function test_chinese_comma_pairs_preserve_position_order_and_leading_zero_usernames(): void
    {
        DB::table('admin_roles')->insert(['name'=>'第二部门','slug'=>'dept-qa-2','sort'=>0]);
        $row=$this->row('00123'); $row['department_codes']=' dept-qa，dept-qa-2 ';
        $row['department_positions']='副主管，成员';
        $checked=app(AccountSpreadsheetService::class)->validateRows([$row],$this->account(true));
        $this->assertSame([],$checked['errors']);
        $this->assertSame('00123',$checked['rows'][0]['username']);
        $this->assertSame(['deputy','member'],array_column($checked['rows'][0]['memberships'],'position'));
    }

    public function test_commit_revalidates_new_conflicts_before_creating_accounts(): void
    {
        $service=app(AccountSpreadsheetService::class); $actor=$this->account(true); $row=$this->row('new-user');
        $this->assertSame([],$service->validateRows([$row],$actor)['errors']);
        $account=new AdminUser(); $account->forceFill(['username'=>'new-user','name'=>'Existing','password'=>Hash::make('existing-password')]); $account->save();
        try {
            $service->create([$row],$actor,'initial-password');
            $this->fail('Expected conflict');
        } catch (ValidationException $e) {
            $this->assertSame(2,AdminUser::count());
            $this->assertTrue(Hash::check('existing-password',$account->fresh()->password));
        }
    }

    public function test_formula_cells_are_rejected_without_evaluation(): void
    {
        $service=app(AccountSpreadsheetService::class);
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray($service->headers(),null,'A1');
        $book->getActiveSheet()->setCellValue('A2','=1+1');
        $path=tempnam(sys_get_temp_dir(),'account-import-test-');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
            $this->expectException(ValidationException::class);
            $service->read(new \Illuminate\Http\UploadedFile($path,'accounts.xlsx',null,null,true));
        } finally { $book->disconnectWorksheets(); @unlink($path); }
    }
}
