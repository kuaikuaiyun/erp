<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_email_purchase_records')) {
            $schema->create('assistant_email_purchase_records', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('normalized_email', 191)->unique();
                $table->boolean('is_current')->default(false)->index();
                $table->dateTime('first_seen_at');
                $table->dateTime('last_seen_at');
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('assistant_email_purchase_syncs')) {
            $schema->create('assistant_email_purchase_syncs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('actor_name', 100)->nullable();
                $table->string('status', 20)->index();
                $table->unsignedInteger('source_count')->default(0);
                $table->unsignedInteger('matched_store_count')->default(0);
                $table->unsignedInteger('unmatched_store_count')->default(0);
                $table->text('error_message')->nullable();
                $table->dateTime('completed_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Sync evidence is intentionally retained on rollback.
    }
};
