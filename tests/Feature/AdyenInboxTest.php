<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdyenInboxTest extends TestCase
{
    public function test_report_download_is_read_only_and_scoped_to_the_inbox_merchant(): void
    {
        config(['adyen_inbox.merchant' => 'TestMerchant', 'adyen_inbox.report_api_key' => 'fixture-report-key',
            'adyen_inbox.pull_token' => str_repeat('r', 40)]);
        $filename = 'received_payments_report_2026_11_26.csv';
        $endpoint = '/api/adyen/reports/TestMerchant/'.$filename;
        $official = 'https://ca-live.adyen.com/reports/download/MerchantAccount/TestMerchant/'.$filename;
        $csv = "Merchant Account,Psp Reference\nTestMerchant,PSP-1\n";
        $auth = ['Authorization' => 'Bearer '.str_repeat('r', 40)];
        Http::preventStrayRequests();
        Http::fake([$official => Http::response($csv, 200, ['Content-Type' => 'text/csv'])]);

        $this->getJson($endpoint)->assertUnauthorized();
        $this->getJson('/api/adyen/reports/OtherMerchant/'.$filename, $auth)->assertNotFound();
        $this->getJson('/api/adyen/reports/TestMerchant/unknown.csv', $auth)->assertNotFound();
        Http::assertNothingSent();

        $response = $this->get($endpoint, $auth)->assertOk()
            ->assertHeader('Content-Length', (string) strlen($csv));
        self::assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        self::assertSame($csv, $response->streamedContent());
        Http::assertSent(fn ($request) => $request->url() === $official && $request->method() === 'GET'
            && $request->hasHeader('X-API-Key', 'fixture-report-key') && !$request->hasHeader('Authorization'));
    }

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
