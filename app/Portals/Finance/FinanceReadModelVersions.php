<?php

namespace App\Portals\Finance;

use Illuminate\Database\Connection;

/** Source-transaction revisions. Reading these never imports or resolves notifications. */
class FinanceReadModelVersions
{
    public const TABLE = 'finance_read_model_versions';

    public static function installed(Connection $db): bool
    {
        if (! config('finance.read_models.enabled', true)) return false;
        $key = 'finance.read-model-versions:'.$db->getName().':'.$db->getDatabaseName();
        $attributes = app('request')->attributes;
        // A queue process may start just before the migration finishes. Retry a
        // negative schema probe between its writes instead of pinning legacy mode.
        if ($attributes->get($key) === false && ! FinanceExternalChanges::readOnly()) $attributes->remove($key);
        if (! $attributes->has($key)) $attributes->set($key, $db->getSchemaBuilder()->hasTable(self::TABLE)
            && $db->getSchemaBuilder()->hasTable('finance_read_model_jobs')
            && $db->getSchemaBuilder()->hasTable('finance_summary_changes')
            && $db->getSchemaBuilder()->hasColumn('finance_summary_changes', 'scopes_resolved')
            && FinanceApplicationChanges::available($db));
        return $attributes->get($key);
    }

    public static function touch(Connection $db, string $source, array $values): void
    {
        if (! self::installed($db)) return;
        $channel = $values['scope_channel'] ?? ($source === 'scope_account_day' ? ($values['channel'] ?? null) : null);
        if ($source === 'finance' && $channel === null) $channel = ($values['dataset'] ?? '') === 'paypal-orders' ? 'paypal' : 'credit_card';
        if (in_array($source, ['fashion', 'fashion_item', 'scope_credit_refund', 'scope_rate'], true)) $channel = 'credit_card';
        if ($source === 'scope_type') $channel = 'paypal';
        $channels = $channel === 'paypal' ? ['paypal'] : (in_array($channel, ['credit_card', 'xuzong1', 'xuzong2', 'ye', 'worldpay'], true)
            ? ['credit_card'] : ['credit_card', 'paypal']);
        // Spread independent writers across short revision locks. A MAX(outbox.id)
        // watermark alone misses transactions that commit out of ID order.
        $identity = $values['id'] ?? $values['logistics_order_id'] ?? $values['reference_number'] ?? $values['account'] ?? $source;
        $bucket = hexdec(substr(hash('sha256', $source.':'.$identity), 0, 4)) % 64;
        foreach ($channels as $name) {
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                $db->statement('INSERT INTO '.self::TABLE.' (channel, bucket, revision) VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE revision = revision + 1', [$name, $bucket]);
            } else {
                $key = ['channel' => $name, 'bucket' => $bucket];
                if ($db->table(self::TABLE)->where($key)->exists()) $db->table(self::TABLE)->where($key)->increment('revision');
                else $db->table(self::TABLE)->insert([...$key, 'revision' => 1]);
            }
        }
    }

    public static function version(Connection $db, array $channels): string
    {
        $channels = array_values(array_unique(array_map(fn ($channel) => $channel === 'paypal' ? 'paypal' : 'credit_card', $channels)));
        sort($channels);
        $revisions = $db->table(self::TABLE)->whereIn('channel', $channels)->orderBy('channel')->orderBy('bucket')->get();
        return hash('sha256', json_encode(['transactional-read-model-v1', $db->getName(), $db->getDatabaseName(), $channels, $revisions], JSON_THROW_ON_ERROR));
    }
}
