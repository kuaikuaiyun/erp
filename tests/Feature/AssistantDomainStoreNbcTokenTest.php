<?php

namespace Tests\Feature;

use App\Models\AssistantDomainStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantDomainStoreNbcTokenTest extends TestCase
{
    public function test_token_round_trips_and_survives_builder_changes(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        app('db')->purge('sqlite');
        Schema::create('assistant_domain_stores', function (Blueprint $table): void {
            $table->id(); $table->string('site_builder'); $table->timestamps();
        });
        $migration = require database_path('migrations/2026_11_16_000300_add_nbc_app_token_to_assistant_domain_stores.php');
        $migration->up();
        $migration->up();
        $store = AssistantDomainStore::create(['site_builder' => 'nbc', 'nbc_app_token' => str_repeat('test-token', 100)]);
        self::assertSame(str_repeat('test-token', 100), $store->fresh()->nbc_app_token);
        $store->update(['site_builder' => 'shopline']);
        self::assertSame(str_repeat('test-token', 100), $store->fresh()->nbc_app_token);
        $store->update(['site_builder' => 'nbc', 'nbc_app_token' => 'replacement']);
        self::assertSame('replacement', $store->fresh()->nbc_app_token);
        $store->update(['nbc_app_token' => null]);
        self::assertNull($store->fresh()->nbc_app_token);
    }
}
