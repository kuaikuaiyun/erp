<?php

use App\Models\AssistantDomainStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_domain_stores')
            || !$schema->hasColumn('assistant_domain_stores', 'team')
            || !$schema->hasColumn('assistant_domain_stores', 'erp')) {
            return;
        }

        if ($schema->hasIndex('assistant_domain_stores', 'assistant_store_team_index')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropIndex('assistant_store_team_index');
            });
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->text('team')->nullable()->change();
            $table->text('erp')->nullable()->change();
        });

        if (!$schema->hasIndex('assistant_domain_stores', 'assistant_store_created_index')) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->index('created_at', 'assistant_store_created_index');
            });
        }

        $database = DB::connection($connection);

        $database->table('assistant_domain_stores')
            ->select(['id', 'team', 'erp'])
            ->orderBy('id')
            ->chunkById(100, function ($stores) use ($database): void {
                foreach ($stores as $store) {
                    $updates = [];

                    foreach (['team', 'erp'] as $field) {
                        $values = AssistantDomainStore::normalizeMultiValue($store->{$field});
                        $value = $values === []
                            ? null
                            : json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        if ($value !== $store->{$field}) {
                            $updates[$field] = $value;
                        }
                    }

                    if ($updates !== []) {
                        $database->table('assistant_domain_stores')
                            ->where('id', $store->id)
                            ->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: rolling back must not discard multi-value data.
    }
};
