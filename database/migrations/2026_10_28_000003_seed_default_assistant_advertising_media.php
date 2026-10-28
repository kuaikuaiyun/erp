<?php

use App\Models\LogisticsOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('assistant_advertising_media')) return;

        $database = DB::connection($connection);
        $media = array_values([...LogisticsOrder::MEDIA_SOURCES, LogisticsOrder::MEDIA_SOURCE_OTHER]);
        foreach ($media as $index => $name) {
            $exists = $database->table('assistant_advertising_media')->where('name', $name)->exists();
            if (! $exists) {
                $database->table('assistant_advertising_media')->insert([
                    'name' => $name,
                    'is_enabled' => true,
                    'sort' => ($index + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        if (! Schema::connection($connection)->hasTable('assistant_advertising_media')) return;

        DB::connection($connection)->table('assistant_advertising_media')
            ->whereIn('name', array_values([...LogisticsOrder::MEDIA_SOURCES, LogisticsOrder::MEDIA_SOURCE_OTHER]))
            ->delete();
    }
};
