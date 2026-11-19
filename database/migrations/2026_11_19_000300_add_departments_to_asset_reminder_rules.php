<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('admin.database.connection') ?: config('database.default'));
        if (! $schema->hasColumn('asset_reminder_rules', 'department_ids')) {
            $schema->table('asset_reminder_rules', fn (Blueprint $t) => $t->json('department_ids')->nullable());
        }
    }

    public function down(): void
    {
        // Preserve department selections and historical associations.
    }
};
