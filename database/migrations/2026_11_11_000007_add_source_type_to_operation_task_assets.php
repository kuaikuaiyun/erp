<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_task_assets', function (Blueprint $table): void {
            $table->string('source_type', 32)->nullable()->after('selling_point')->comment('ai_created or peer_copied');
        });
    }

    public function down(): void
    {
        Schema::table('operation_task_assets', function (Blueprint $table): void {
            $table->dropColumn('source_type');
        });
    }
};
