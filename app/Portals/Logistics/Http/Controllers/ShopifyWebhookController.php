<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\LogisticsShopifyConnection;
use App\Models\LogisticsWebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $shopDomain = $this->normaliseDomain($request->header('X-Shopify-Shop-Domain'));
        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id'));
        $signature = trim((string) $request->header('X-Shopify-Hmac-Sha256'));
        $topic = Str::lower(trim((string) $request->header('X-Shopify-Topic')));
        $rawBody = $request->getContent();

        if ($shopDomain === null || $webhookId === '' || $signature === '' || $rawBody === '') {
            return response('Invalid Shopify webhook headers.', 400);
        }

        $connections = LogisticsShopifyConnection::query()
            ->where('shop_domain', $shopDomain)
            ->limit(2)
            ->get();
        if ($connections->count() !== 1) {
            return response('Unknown Shopify shop.', 404);
        }

        $connection = $connections->first();
        if (blank($connection->webhook_secret) || !$this->signatureIsValid($rawBody, $signature, (string) $connection->webhook_secret)) {
            return response('Invalid Shopify webhook signature.', 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response('Invalid Shopify webhook payload.', 400);
        }

        $delivery = LogisticsWebhookDelivery::query()->firstOrCreate(
            ['webhook_id' => $webhookId],
            [
                'shop_domain' => $shopDomain,
                'topic' => $topic,
                'status' => 'processing',
                'received_at' => now(),
            ],
        );
        if (!$delivery->wasRecentlyCreated && in_array($delivery->status, ['queued', 'processing', 'success'], true)) {
            return response('', 200);
        }

        $delivery->fill([
            'shop_domain' => $shopDomain,
            'topic' => $topic,
            'status' => 'queued',
            'message' => null,
            'received_at' => $delivery->received_at ?: now(),
        ])->save();

        try {
            ProcessShopifyWebhookJob::dispatch((int) $delivery->getKey(), (int) $connection->getKey(), $payload, $topic)
                ->onConnection('database')
                ->onQueue('logistics-shopify');
        } catch (\Throwable $exception) {
            $delivery->forceFill([
                'status' => 'failed',
                'message' => Str::limit('Webhook 队列投递失败：'.$exception->getMessage(), 1000),
                'processed_at' => now(),
            ])->save();

            return response('Shopify webhook queue unavailable.', 503);
        }

        return response('', 200);
    }

    private function signatureIsValid(string $rawBody, string $signature, string $secret): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    private function normaliseDomain(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?: '';
        $domain = trim(explode('/', $domain)[0]);

        return $domain !== '' ? $domain : null;
    }
}
