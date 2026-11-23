<?php

namespace Tests\Feature;

use App\Portals\Assistant\AssistantStoreOwnerAttributionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantStoreOwnerAttributionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'admin.database.connection' => null]);
        DB::purge('sqlite');
        Schema::create('assistant_domain_store_owner_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assistant_domain_store_id');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_name_snapshot')->nullable();
            $table->unsignedBigInteger('operation_department_id')->nullable();
            $table->string('operation_department_name_snapshot')->nullable();
            $table->string('event')->default('assigned');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_resolves_old_and_new_owners_at_the_exact_transfer_boundary(): void
    {
        DB::table('assistant_domain_store_owner_histories')->insert([
            [
                'assistant_domain_store_id' => 88,
                'owner_user_id' => 101,
                'owner_name_snapshot' => '玄策',
                'operation_department_id' => 11,
                'operation_department_name_snapshot' => '旧团队',
                'event' => 'assigned',
                'effective_from' => '2026-10-01 00:00:00',
                'effective_to' => '2026-11-01 12:30:00',
                'created_at' => '2026-10-01 00:00:00',
                'updated_at' => '2026-10-01 00:00:00',
            ],
            [
                'assistant_domain_store_id' => 88,
                'owner_user_id' => 202,
                'owner_name_snapshot' => '通天代',
                'operation_department_id' => 22,
                'operation_department_name_snapshot' => '新团队',
                'event' => 'transferred',
                'effective_from' => '2026-11-01 12:30:00',
                'effective_to' => null,
                'created_at' => '2026-11-01 12:30:00',
                'updated_at' => '2026-11-01 12:30:00',
            ],
        ]);

        $service = app(AssistantStoreOwnerAttributionService::class);
        $timelines = $service->timelines([88]);
        $before = $service->resolve($timelines, 88, Carbon::parse('2026-11-01 12:29:59', 'UTC'));
        $atBoundary = $service->resolve($timelines, 88, Carbon::parse('2026-11-01 12:30:00', 'UTC'));

        self::assertTrue($before['has_history']);
        self::assertSame(101, $before['owner_user_id']);
        self::assertSame('玄策', $before['owner_name']);
        self::assertSame(202, $atBoundary['owner_user_id']);
        self::assertSame('通天代', $atBoundary['owner_name']);
    }

    public function test_missing_history_is_distinguishable_from_an_explicit_unassigned_range(): void
    {
        DB::table('assistant_domain_store_owner_histories')->insert([
            'assistant_domain_store_id' => 99,
            'owner_user_id' => null,
            'event' => 'cleared',
            'effective_from' => '2026-11-01 00:00:00',
            'created_at' => '2026-11-01 00:00:00',
            'updated_at' => '2026-11-01 00:00:00',
        ]);

        $service = app(AssistantStoreOwnerAttributionService::class);
        $timelines = $service->timelines([99, 100]);

        self::assertTrue($service->resolve($timelines, 99, '2026-11-02 00:00:00')['has_history']);
        self::assertNull($service->resolve($timelines, 99, '2026-11-02 00:00:00')['owner_user_id']);
        self::assertFalse($service->resolve($timelines, 100, '2026-11-02 00:00:00')['has_history']);
    }
}
