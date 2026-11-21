<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasTable('asset_advertising_reminder_days')) {
            $schema->create('asset_advertising_reminder_days', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('rule_id');
                $t->date('data_date');
                $t->json('snapshot');
                $t->dateTime('synced_at');
                $t->unique(['rule_id', 'data_date'], 'aar_day_rule_date');
            });
        }
    }

    public function down(): void
    {
        // Preserve observed rosters so rollback does not erase the audit baseline.
    }
};
