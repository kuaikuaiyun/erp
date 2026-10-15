<?php

namespace App\Portals\Assistant;

use App\Models\AdminUser;
use App\Models\AssistantDomainStore;
use App\Models\AssistantEmailPurchaseRecord;
use App\Models\AssistantEmailPurchaseSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;

/**
 * Maintains a locally auditable snapshot of the external purchased-mailbox
 * list. The UI never calls the remote endpoint directly.
 */
class AssistantEmailPurchaseSyncService
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_MISSING = 'missing';

    public const STATUS_NEVER = 'never';

    public const STATUS_UNSYNCED = 'unsynced';

    public function __construct(private readonly AssistantDomainStoreHistoryService $history)
    {
    }

    /**
     * @return array{source_count:int,matched_store_count:int,unmatched_store_count:int}
     */
    public function synchronize(AdminUser $actor): array
    {
        try {
            if (!$this->storageReady()) {
                throw new LogicException('email_purchase_storage_not_ready');
            }

            $emails = $this->fetchEmails();
            $result = DB::connection($this->connection())->transaction(function () use ($emails, $actor): array {
                $this->persistSourceEmails($emails);
                $result = $this->applyStoreStatuses($emails, $actor);

                AssistantEmailPurchaseSync::query()->create([
                    'actor_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                    'status' => AssistantEmailPurchaseSync::STATUS_SUCCESS,
                    'source_count' => count($emails),
                    'matched_store_count' => $result['matched_store_count'],
                    'unmatched_store_count' => $result['unmatched_store_count'],
                    'completed_at' => now(),
                ]);

                return [
                    'source_count' => count($emails),
                    ...$result,
                ];
            });

            return $result;
        } catch (\Throwable $exception) {
            report($exception);

            if ($this->storageReady()) {
                AssistantEmailPurchaseSync::query()->create([
                    'actor_id' => $actor->getKey(),
                    'actor_name' => $actor->name,
                    'status' => AssistantEmailPurchaseSync::STATUS_FAILED,
                    'error_message' => mb_substr($exception->getMessage(), 0, 4000),
                    'completed_at' => now(),
                ]);
            }

            throw $exception;
        }
    }

    public function statusFor(?string $email): string
    {
        $normalized = $this->normalize($email);

        if ($normalized === null) {
            return self::STATUS_NEVER;
        }

        return $this->statusesFor([$normalized])[$normalized] ?? self::STATUS_NEVER;
    }

    /**
     * @param iterable<array-key, mixed> $emails
     * @return array<string, string> normalized email => status
     */
    public function statusesFor(iterable $emails): array
    {
        $normalizedEmails = [];
        foreach ($emails as $email) {
            $normalized = is_scalar($email) ? $this->normalize((string) $email) : null;
            if ($normalized !== null) {
                $normalizedEmails[$normalized] = true;
            }
        }

        if ($normalizedEmails === []) {
            return [];
        }

        if (!$this->storageReady()) {
            return array_fill_keys(array_keys($normalizedEmails), self::STATUS_UNSYNCED);
        }

        if (!AssistantEmailPurchaseSync::query()
            ->where('status', AssistantEmailPurchaseSync::STATUS_SUCCESS)
            ->exists()) {
            return array_fill_keys(array_keys($normalizedEmails), self::STATUS_UNSYNCED);
        }

        $records = AssistantEmailPurchaseRecord::query()
            ->whereIn('normalized_email', array_keys($normalizedEmails))
            ->get(['normalized_email', 'is_current'])
            ->keyBy('normalized_email');
        $statuses = [];

        foreach (array_keys($normalizedEmails) as $email) {
            /** @var AssistantEmailPurchaseRecord|null $record */
            $record = $records->get($email);
            $statuses[$email] = $record === null
                ? self::STATUS_NEVER
                : ($record->is_current ? self::STATUS_CURRENT : self::STATUS_MISSING);
        }

        return $statuses;
    }

    public function normalizedEmail(?string $email): ?string
    {
        return $this->normalize($email);
    }

    public function isPurchased(?string $email): bool
    {
        return $this->statusFor($email) === self::STATUS_CURRENT;
    }

    public function applyPurchaseFilter(Builder $query, bool $purchased): void
    {
        if (!$this->storageReady() || !AssistantEmailPurchaseSync::query()
            ->where('status', AssistantEmailPurchaseSync::STATUS_SUCCESS)
            ->exists()) {
            $query->where('email_purchased', $purchased);

            return;
        }

        $snapshotConstraint = function ($snapshot): void {
            $snapshot->selectRaw('1')
                ->from('assistant_email_purchase_records as email_purchase_snapshot')
                ->where('email_purchase_snapshot.is_current', true)
                ->whereRaw('email_purchase_snapshot.normalized_email = LOWER(TRIM(assistant_domain_stores.email))');
        };
        if ($purchased) {
            $query->whereExists($snapshotConstraint);
        } else {
            $query->whereNotExists($snapshotConstraint);
        }
    }

    /** @return array<string, string> */
    public function statusLabels(): array
    {
        return [
            self::STATUS_CURRENT => __('assistant.domain_stores.email_purchase_status.current'),
            self::STATUS_MISSING => __('assistant.domain_stores.email_purchase_status.missing'),
            self::STATUS_NEVER => __('assistant.domain_stores.email_purchase_status.never'),
            self::STATUS_UNSYNCED => __('assistant.domain_stores.email_purchase_status.unsynced'),
        ];
    }

    /** @return list<string> */
    private function fetchEmails(): array
    {
        $url = trim((string) config('services.assistant.email_purchase.sync_url', ''));
        if ($url === '') {
            throw new LogicException('email_purchase_source_not_configured');
        }

        $method = strtoupper(trim((string) config('services.assistant.email_purchase.sync_method', 'GET')));
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new LogicException('email_purchase_source_method_invalid');
        }

        $timeout = max(5, min(120, (int) config('services.assistant.email_purchase.timeout_seconds', 30)));
        $request = Http::timeout($timeout)->acceptJson();
        $token = trim((string) config('services.assistant.email_purchase.sync_token', ''));
        if ($token === '') {
            throw new LogicException('email_purchase_source_token_missing');
        }

        $headers = [
            'X-Support-Mailbox-Token' => $token,
        ];
        $origin = trim((string) config('services.assistant.email_purchase.sync_origin', ''));
        if ($origin !== '') {
            $headers['Origin'] = $origin;
        }
        $request = $request->withHeaders($headers);

        $response = $method === 'POST' ? $request->post($url) : $request->get($url);
        if (!$response->successful()) {
            throw new RuntimeException('email_purchase_source_request_failed');
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new RuntimeException('email_purchase_source_payload_invalid');
        }

        $missing = new \stdClass();
        $path = trim((string) config('services.assistant.email_purchase.emails_path', 'data'));
        $list = $path === '' ? $payload : data_get($payload, $path, $missing);
        if ($list === $missing && array_is_list($payload)) {
            $list = $payload;
        }
        if (!is_array($list)) {
            throw new RuntimeException('email_purchase_source_emails_missing');
        }

        $emails = [];
        foreach ($list as $item) {
            $value = is_array($item) ? ($item['email'] ?? null) : $item;
            $normalized = is_scalar($value) ? $this->normalize((string) $value) : null;

            if ($normalized !== null) {
                $emails[$normalized] = true;
            }
        }

        return array_keys($emails);
    }

    /** @param list<string> $emails */
    private function persistSourceEmails(array $emails): void
    {
        AssistantEmailPurchaseRecord::query()->where('is_current', true)->update(['is_current' => false]);

        foreach (array_chunk($emails, 500) as $chunk) {
            $existing = AssistantEmailPurchaseRecord::query()
                ->whereIn('normalized_email', $chunk)
                ->get()
                ->keyBy('normalized_email');

            foreach ($chunk as $email) {
                /** @var AssistantEmailPurchaseRecord|null $record */
                $record = $existing->get($email);
                if ($record === null) {
                    AssistantEmailPurchaseRecord::query()->create([
                        'normalized_email' => $email,
                        'is_current' => true,
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ]);

                    continue;
                }

                $record->fill([
                    'is_current' => true,
                    'last_seen_at' => now(),
                ])->save();
            }
        }
    }

    /**
     * @param list<string> $emails
     * @return array{matched_store_count:int,unmatched_store_count:int}
     */
    private function applyStoreStatuses(array $emails, AdminUser $actor): array
    {
        $current = array_fill_keys($emails, true);
        $matched = 0;
        $unmatched = 0;

        AssistantDomainStore::query()
            ->with('authorizedDepartments')
            ->orderBy('id')
            ->chunkById(200, function ($stores) use ($current, $actor, &$matched, &$unmatched): void {
                foreach ($stores as $store) {
                    $normalized = $this->normalize($store->email);
                    $purchased = $normalized !== null && isset($current[$normalized]);
                    $purchased ? $matched++ : $unmatched++;

                    if ((bool) $store->email_purchased === $purchased) {
                        continue;
                    }

                    $before = $store->historySnapshot();
                    $store->fill([
                        'email_purchased' => $purchased,
                        'last_operator_id' => $actor->getKey(),
                        'last_operator_name' => $actor->name,
                    ])->save();
                    $this->history->recordUpdated($store, $before, $store->historySnapshot(), $actor);
                }
            });

        return [
            'matched_store_count' => $matched,
            'unmatched_store_count' => $unmatched,
        ];
    }

    private function normalize(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function connection(): ?string
    {
        return config('admin.database.connection') ?: config('database.default');
    }

    private function storageReady(): bool
    {
        $schema = Schema::connection($this->connection());

        return $schema->hasTable('assistant_email_purchase_records')
            && $schema->hasTable('assistant_email_purchase_syncs');
    }
}
