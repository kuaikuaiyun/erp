<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdyenInboxTest extends TestCase
{
    public function test_verified_delivery_survives_pulls_until_matching_acknowledgement(): void
    {
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
            'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'adyen_inbox' => ['merchant' => 'TestMerchant', 'username' => 'test-inbox', 'password' => 'fixture-secret',
                'hmac_keys' => ['44782DEF547AAA06C910C43932B1EB0C71FC68D9D0C057550C48EC2ACF6BA056'], 'pull_token' => str_repeat('r', 40)],
        ]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_11_27_000010_create_adyen_webhook_inbox.php'))->up();

        // Adyen's published AUTHORISATION signature example.
        $item = ['pspReference'=>'7914073381342284', 'merchantAccountCode'=>'TestMerchant', 'merchantReference'=>'TestPayment-1407325143704',
            'amount'=>['value'=>1130,'currency'=>'EUR'], 'eventCode'=>'AUTHORISATION', 'success'=>'true',
            'additionalData'=>['hmacSignature'=>'coqCmt/IZ4E3CzPvMY8zTjQVL5hYJUiBRg8UU+iCWo0=']];
        $body = ['live'=>'true','notificationItems'=>[['NotificationRequestItem'=>$item]]];
        $auth = ['Authorization'=>'Basic '.base64_encode('test-inbox:fixture-secret')];
        $pull = ['Authorization'=>'Bearer '.str_repeat('r', 40)];
        $receive = '/api/adyen/webhook';
        $inbox = '/api/adyen/webhook-inbox';
        $this->postJson($receive, $body)->assertUnauthorized();
        $this->postJson($receive, array_replace($body, ['live'=>'false']), $auth)->assertBadRequest();
        $tampered = $body;
        $tampered['notificationItems'][0]['NotificationRequestItem']['amount']['value'] = 1131;
        $this->postJson($receive, $tampered, $auth)->assertUnauthorized();
        self::assertSame(0, DB::table('adyen_webhook_inbox')->count());
        $this->postJson($receive, $body, $auth)->assertAccepted();
        $this->postJson($receive, $body, $auth)->assertAccepted();
        $row = DB::table('adyen_webhook_inbox')->sole();
        self::assertStringNotContainsString('TestPayment', $row->payload);

        $this->getJson($inbox, $auth)->assertUnauthorized();
        $this->getJson($inbox, $pull)->assertOk()->assertExactJson($body)->assertHeader('X-Adyen-Delivery-Id', (string) $row->id);
        $this->getJson($inbox, $pull)->assertOk()->assertHeader('X-Adyen-Delivery-Digest', $row->digest);
        $ack = $inbox.'/'.$row->id.'/ack';
        $this->postJson($ack, [], $pull + ['X-Adyen-Delivery-Digest'=>str_repeat('0', 64)])->assertNotFound();
        $this->postJson($ack, [], $pull + ['X-Adyen-Delivery-Digest'=>$row->digest])->assertNoContent();
        $this->postJson($ack, [], $pull + ['X-Adyen-Delivery-Digest'=>$row->digest])->assertNoContent();
        $this->postJson($receive, $body, $auth)->assertAccepted();
        $this->getJson($inbox, $pull)->assertNoContent();
        self::assertNull(DB::table('adyen_webhook_inbox')->sole()->payload);
    }
}
