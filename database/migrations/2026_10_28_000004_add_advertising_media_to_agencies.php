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

        if ($schema->hasTable('assistant_advertising_agencies')
            && ! $schema->hasColumn('assistant_advertising_agencies', 'advertising_media_id')) {
            $schema->table('assistant_advertising_agencies', function (Blueprint $table): void {
                $table->unsignedBigInteger('advertising_media_id')->nullable()->after('name')->index();
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('assistant_advertising_agencies')
            && $schema->hasColumn('assistant_advertising_agencies', 'advertising_media_id')) {
            $schema->table('assistant_advertising_agencies', function (Blueprint $table): void {
                $table->dropColumn('advertising_media_id');
            });
        }
    }
};
