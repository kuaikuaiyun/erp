<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Portals\Assistant\AssistantEmailPurchaseSyncService;
use Mockery;
use Tests\TestCase;

class AssistantEmailPurchaseSyncCommandTest extends TestCase
{
    public function test_command_synchronizes_with_system_actor(): void
    {
        $sync = Mockery::mock(AssistantEmailPurchaseSyncService::class);
        $sync->shouldReceive('synchronize')
            ->once()
            ->with(Mockery::on(fn (AdminUser $actor): bool => $actor->getKey() === null
                && $actor->name === '系统自动同步'))
            ->andReturn([
                'source_count' => 12,
                'matched_store_count' => 8,
                'unmatched_store_count' => 4,
            ]);
        $this->app->instance(AssistantEmailPurchaseSyncService::class, $sync);

        $this->artisan('assistant:email-purchases:sync')
            ->expectsOutput('Source: 12; purchased stores: 8; other stores: 4.')
            ->assertSuccessful();
    }

    public function test_command_returns_failure_when_synchronization_fails(): void
    {
        $sync = Mockery::mock(AssistantEmailPurchaseSyncService::class);
        $sync->shouldReceive('synchronize')
            ->once()
            ->andThrow(new \RuntimeException('source unavailable'));
        $this->app->instance(AssistantEmailPurchaseSyncService::class, $sync);

        $this->artisan('assistant:email-purchases:sync')
            ->expectsOutputToContain('source unavailable')
            ->assertFailed();
    }
}
