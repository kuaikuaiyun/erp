<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function schema()
    {
        return DB::connection(config('admin.database.connection') ?: config('database.default'))->getSchemaBuilder();
    }

    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('finance_read_model_versions')) $schema->create('finance_read_model_versions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('channel', 24);
            $table->unsignedTinyInteger('bucket');
            $table->unsignedBigInteger('revision');
            $table->primary(['channel', 'bucket']);
        });
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        // Seed before exposing the jobs table, which completes the activation gate.
        // Reinstallations must not adopt an earlier installation's cached rows.
        foreach (['paypal', 'credit_card'] as $channel) $db->table('finance_read_model_versions')->insertOrIgnore([
            'channel' => $channel, 'bucket' => 0, 'revision' => random_int(1, 1 << 50),
        ]);
        if (! $schema->hasTable('finance_read_model_jobs')) $schema->create('finance_read_model_jobs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->char('job_key', 64)->unique();
            $table->string('kind', 32);
            $table->json('payload');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_class')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at', 'id'], 'finance_read_model_queue_idx');
        });
        foreach (['finance.read-model-versions:', 'finance.read-model-jobs:'] as $prefix) {
            $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
            request()->attributes->remove($prefix.$db->getName().':'.$db->getDatabaseName());
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('finance_read_model_jobs');
        $this->schema()->dropIfExists('finance_read_model_versions');
    }
};
