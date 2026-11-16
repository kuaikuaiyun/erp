<?php
namespace Tests\Feature;
require_once __DIR__.'/DingTalkLoginTest.php';
use App\Models\DingTalkCompany;
use App\Models\DingTalkIdentity;
use App\Services\DingTalk\LoginService;
use App\Services\DingTalk\BindingService;
use App\Services\DingTalk\MembershipService;
use App\Services\DingTalk\CallbackCrypto;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DingTalkMultiCompanyTest extends TestCase
{
    use DingTalkTestEnvironment;
    private function second(): DingTalkCompany
    {
        $attributes=$this->company->getAttributes();
        unset($attributes['id'],$attributes['created_at'],$attributes['updated_at']);
        $company=new DingTalkCompany();
        $company->setRawAttributes($attributes);
        $company->forceFill(['name'=>'Second company','corp_id'=>'dingcorp-second','app_key'=>'second-key','app_secret'=>'second-secret',
            'callback_token'=>'second-token','callback_aes_key'=>rtrim(base64_encode(str_repeat('b',32)),'='),'callback_owner_key'=>'second-key']);
        $company->save(); return $company->fresh();
    }
    public function test_identical_member_ids_in_different_companies_are_separate_and_ordinary_accounts_still_allow_one_identity(): void
    {
        $second=$this->second(); $one=$this->account(); $two=$this->account(); $service=app(BindingService::class);
        $a=$service->bind($one->id,'user-a',false,$this->company->id);
        $this->denied(fn()=>$service->bind($one->id,'user-a',false,$second->id),422);
        $b=$service->bind($two->id,'user-a',false,$second->id);
        self::assertNotSame($a->identity_id,$b->identity_id); self::assertSame(2,DingTalkIdentity::query()->count());
    }
    public function test_departure_in_one_company_does_not_revoke_another_company(): void
    {
        $second=$this->second(); $one=$this->account(); $two=$this->account(); $service=app(BindingService::class);
        $a=$service->bind($one->id,'user-a',false,$this->company->id); $b=$service->bind($two->id,'user-a',false,$second->id);
        app(MembershipService::class)->receiveEvent(['CorpId'=>'dingcorp-test','EventType'=>'user_leave_org','UserId'=>['user-a'],'eventId'=>'leave-first'],$this->company);
        self::assertFalse($a->fresh()->active); self::assertTrue($b->fresh()->active);
    }
    public function test_state_cannot_switch_companies_and_is_consumed_even_after_a_wrong_company_claim(): void
    {
        $second=$this->second(); $request=$this->browser(); $login=app(LoginService::class);
        $flow=$login->start($request,$this->company->id); $request->query->set('company',$second->id); $this->returnedCorp=$second->corp_id;
        $this->denied(fn()=>$login->acceptCallback($request,$flow['state'],'code'),403);
        $this->returnedCorp=$this->company->corp_id; $this->denied(fn()=>$login->acceptCallback($request,$flow['state'],'code'),401);
    }
    public function test_company_revision_invalidates_pending_flow_before_any_token_exchange(): void
    {
        $request=$this->browser(); $login=app(LoginService::class); $flow=$login->start($request,$this->company->id);
        $this->company->increment('version');
        $this->denied(fn()=>$login->acceptCallback($request,$flow['state'],'code'),401); Http::assertNothingSent();
    }
    public function test_disabled_company_does_not_invalidate_shared_administrator_sessions_from_another_company(): void
    {
        $second=$this->second(); $account=$this->account(true); $service=app(BindingService::class);
        $a=$service->bind($account->id,'user-a',true,$this->company->id); $b=$service->bind($account->id,'user-a',true,$second->id);
        $first=$this->browser(true); $this->proof($first,$a);
        $other=$this->browser(true); $this->proof($other,$b); $proof=$other->session()->get(LoginService::PROOF_KEY);
        $proof['company_id']=$second->id; $proof['company_version']=$second->version; $other->session()->put(LoginService::PROOF_KEY,$proof);
        $this->company->update(['enabled'=>false,'version'=>2]);
        $this->denied(fn()=>app(LoginService::class)->validSession($first,$account),403);
        self::assertSame($b->identity_id,app(LoginService::class)->validSession($other,$account)->id);
    }
    public function test_callbacks_are_authenticated_with_the_selected_company_credentials(): void
    {
        $second=$this->second(); $firstCrypto=app(CallbackCrypto::class)->forCompany($this->company); $signed=$firstCrypto->encrypt('message');
        $this->denied(fn()=>app(CallbackCrypto::class)->forCompany($second)->decrypt($signed['msg_signature'],$signed['timeStamp'],$signed['nonce'],$signed['encrypt']),403);
    }
}
