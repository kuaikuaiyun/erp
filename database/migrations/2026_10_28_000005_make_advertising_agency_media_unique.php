<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->table('assistant_advertising_agencies', function (Blueprint $table): void {
            $table->dropUnique('assistant_advertising_agencies_name_unique');
            $table->unique(['name', 'advertising_media_id'], 'assistant_ad_agency_name_media_unique');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        Schema::connection($connection)->table('assistant_advertising_agencies', function (Blueprint $table): void {
            $table->dropUnique('assistant_ad_agency_name_media_unique');
            $table->unique('name');
        });
    }
};
