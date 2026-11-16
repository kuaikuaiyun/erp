<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dingtalk_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('corp_id', 128);
            $table->string('user_id', 128);
            $table->string('union_id', 128);
            $table->string('name', 190);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->unsignedBigInteger('last_event_at')->default(0);
            $table->timestamps();
            $table->unique(['corp_id', 'user_id']);
        });
        Schema::create('dingtalk_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_id')->constrained('dingtalk_identities');
            // The existing admin user table is configurable; retain historical records on deletion.
            $table->unsignedBigInteger('account_id')->index();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['identity_id', 'account_id']);
        });
        Schema::create('dingtalk_challenges', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->char('browser_hash', 64);
            $table->string('purpose', 16);
            $table->unsignedBigInteger('identity_id')->nullable();
            $table->unsignedBigInteger('identity_version')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('dingtalk_events', function (Blueprint $table): void {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->string('event_type', 64);
            $table->string('corp_id', 128);
            $table->json('user_ids');
            $table->unsignedBigInteger('event_at')->default(0);
            $table->timestamp('created_at');
        });
        Schema::create('dingtalk_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 64)->index();
            $table->unsignedBigInteger('identity_id')->nullable()->index();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('corp_id', 128)->nullable();
            $table->string('user_id', 128)->nullable();
            $table->string('actor_name', 190)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dingtalk_audits');
        Schema::dropIfExists('dingtalk_events');
        Schema::dropIfExists('dingtalk_challenges');
        Schema::dropIfExists('dingtalk_bindings');
        Schema::dropIfExists('dingtalk_identities');
    }
};
