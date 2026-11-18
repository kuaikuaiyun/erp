<?php

namespace App\Portals\AssetManager;

use App\Models\AssetIpfoxyAccount;
use App\Models\AssetIpfoxyProxy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IpfoxyService
{
    public function begin(AssetIpfoxyAccount $account, int $actor): array
    {
        $id = (string) Str::uuid();
        abort_unless(Cache::add('asset-ipfoxy-busy:'.$account->id, $id, 900), 409, __('asset_manager.busy'));
        $state = ['id' => $id, 'account_id' => $account->id, 'actor' => $actor, 'page' => 1, 'rows' => [], 'total' => null];
        Cache::put('asset-ipfoxy-sync:'.$id, $state, 900);

        return ['id' => $id, 'done' => false, 'count' => 0];
    }

    public function step(string $id, int $actor): array
    {
        $state = Cache::get('asset-ipfoxy-sync:'.$id);
        abort_unless($state && $state['actor'] === $actor, 404);
        abort_unless(Cache::get('asset-ipfoxy-busy:'.$state['account_id']) === $id, 409, __('asset_manager.busy'));
        $lock = Cache::lock('asset-ipfoxy-step:'.$id, 45);
        abort_unless($lock->get(), 409, __('asset_manager.busy'));
        try {
            $account = AssetIpfoxyAccount::query()->findOrFail($state['account_id']);
            usleep(1100000);
            $response = Http::acceptJson()->withHeaders(['api-id' => $account->api_id, 'api-token' => $account->api_token])->connectTimeout(10)->timeout(25)->get('https://apis.ipfoxy.com/ip/open-api/proxy-list', ['page' => $state['page'], 'page_size' => 50, 'include_remark' => 0]);
            $body = $response->json();
            if (! $response->successful() || ! is_array($body) || ($body['code'] ?? null) !== 0) {
                throw new \RuntimeException;
            }$data = $body['data'] ?? [];
            $rows = $data['list'] ?? null;
            $total = filter_var($data['total'] ?? null, FILTER_VALIDATE_INT);
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 50 || $total === false || $total < 0 || $total > 100000 || ($state['total'] !== null && $state['total'] !== $total)) {
                throw new \RuntimeException;
            }$state['total'] = $total;
            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['id']) || isset($state['rows'][(string) $row['id']])) {
                    throw new \RuntimeException;
                }$expires = $row['expire_time'] ?? null;
                if (! is_numeric($expires) || $expires <= 0) {
                    throw new \RuntimeException;
                }$state['rows'][(string) $row['id']] = ['proxy_id' => (string) $row['id'], 'host' => (string) ($row['host'] ?? ''), 'public_ip' => (string) ($row['public_ip'] ?? ''), 'port' => (string) ($row['port'] ?? ''), 'protocol' => (string) ($row['type'] ?? ''), 'country' => (string) ($row['country_code'] ?? ''), 'expires_at' => CarbonImmutable::createFromTimestampUTC((int) $expires)->format('Y-m-d H:i:s')];
            }
            $count = count($state['rows']);
            if ($count > $total || ($rows === [] && $count < $total)) {
                throw new \RuntimeException;
            }$done = $count === $total;
            if ($done) {
                $account->getConnection()->transaction(function () use ($account, $state) {
                    $locked = AssetIpfoxyAccount::query()->lockForUpdate()->findOrFail($account->id);
                    AssetIpfoxyProxy::query()->where('account_id', $account->id)->update(['missing' => true]);
                    foreach (array_chunk(array_values($state['rows']), 500) as $chunk) {
                        AssetIpfoxyProxy::query()->upsert(array_map(fn ($row) => $row + ['account_id' => $account->id, 'missing' => false], $chunk), ['account_id', 'proxy_id'], ['host', 'public_ip', 'port', 'protocol', 'country', 'expires_at', 'missing', 'updated_at']);
                    }$locked->update(['last_status' => 'success', 'last_synced_at' => now()]);
                });
                Cache::forget('asset-ipfoxy-sync:'.$id);
                Cache::forget('asset-ipfoxy-busy:'.$account->id);
            } else {
                $state['page']++;
                Cache::put('asset-ipfoxy-sync:'.$id, $state, 900);
                Cache::put('asset-ipfoxy-busy:'.$account->id, $id, 900);
            }

return ['id' => $id, 'done' => $done, 'count' => $count, 'total' => $total];
        } catch (\Throwable $e) {
            AssetIpfoxyAccount::query()->whereKey($state['account_id'])->update(['last_status' => 'failed']);
            Cache::forget('asset-ipfoxy-sync:'.$id);
            Cache::forget('asset-ipfoxy-busy:'.$state['account_id']);
            throw ValidationException::withMessages(['sync' => __('asset_manager.sync_failed')]);
        } finally {
            $lock->release();
        }
    }

    public function query(Request $request)
    {
        $q = AssetIpfoxyProxy::query()->join('asset_ipfoxy_accounts as a', 'a.id', '=', 'asset_ipfoxy_proxies.account_id')->select('asset_ipfoxy_proxies.*', 'a.label as account_label', 'a.owner_department_id');
        foreach (AssetManagerCatalog::fields('ipfoxy') as $key => $type) {
            $value = $request->input('filter.'.$key);
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }if ($key === 'expires_at') {
                $d = app(AssetManagerService::class)->date($value, true);
                $q->where('expires_at', '>=', $d->utc())->where('expires_at', '<', $d->addDay()->utc());
            } else {
                $q->where($key === 'account_label' ? 'a.label' : 'asset_ipfoxy_proxies.'.$key, 'like', '%'.str_replace(['\\', '%', '_'],['\\\\', '\\%', '\\_'],(string) $value).'%');
            }
        }

return $q;
    }
}
