<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        $schema->table('assistant_advertising_media', function (Blueprint $table): void {
            $table->unsignedInteger('assistant_department_id')->nullable()->after('id')->index('assistant_ad_media_department_idx');
            $table->dropUnique('assistant_advertising_media_name_unique');
            $table->unique(['assistant_department_id', 'name'], 'assistant_ad_media_department_name_unique');
        });
        $schema->table('assistant_advertising_agencies', function (Blueprint $table): void {
            $table->unsignedInteger('assistant_department_id')->nullable()->after('id')->index('assistant_ad_agency_department_idx');
        });

        $assistantRootId = $db->table(config('admin.database.roles_table'))->where('slug', 'dept-000004')->value('id');
        $departmentIds = $assistantRootId
            ? $db->table(config('admin.database.roles_table'))->where('parent_id', $assistantRootId)->orderBy('sort')->orderBy('id')->pluck('id')->all()
            : [];
        if ($departmentIds === []) return;

        $sourceMedia = $db->table('assistant_advertising_media')->orderBy('id')->get();
        $sourceAgencies = $db->table('assistant_advertising_agencies')->orderBy('id')->get();
        $firstDepartmentId = (int) array_shift($departmentIds);
        $db->table('assistant_advertising_media')->update(['assistant_department_id' => $firstDepartmentId]);
        $db->table('assistant_advertising_agencies')->update(['assistant_department_id' => $firstDepartmentId]);

        foreach ($departmentIds as $departmentId) {
            $mediaMap = [];
            foreach ($sourceMedia as $medium) {
                $newId = $db->table('assistant_advertising_media')->insertGetId([
                    'assistant_department_id' => $departmentId,
                    'name' => $medium->name, 'is_enabled' => $medium->is_enabled, 'sort' => $medium->sort,
                    'created_at' => $medium->created_at, 'updated_at' => $medium->updated_at,
                ]);
                $mediaMap[(int) $medium->id] = $newId;
            }
            foreach ($sourceAgencies as $agency) {
                $db->table('assistant_advertising_agencies')->insert([
                    'assistant_department_id' => $departmentId,
                    'name' => $agency->name,
                    'advertising_media_id' => $mediaMap[(int) $agency->advertising_media_id] ?? null,
                    'settlement_coefficient' => $agency->settlement_coefficient,
                    'is_enabled' => $agency->is_enabled, 'sort' => $agency->sort,
                    'created_at' => $agency->created_at, 'updated_at' => $agency->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        $firstDepartmentId = $db->table('assistant_advertising_media')->whereNotNull('assistant_department_id')->min('assistant_department_id');
        if ($firstDepartmentId) {
            $mediaIds = $db->table('assistant_advertising_media')->where('assistant_department_id', $firstDepartmentId)->pluck('id');
            $db->table('assistant_advertising_agencies')->where('assistant_department_id', '!=', $firstDepartmentId)->delete();
            $db->table('assistant_advertising_media')->where('assistant_department_id', '!=', $firstDepartmentId)->delete();
            $db->table('assistant_advertising_agencies')->whereIn('advertising_media_id', $mediaIds)->update(['assistant_department_id' => null]);
            $db->table('assistant_advertising_media')->whereIn('id', $mediaIds)->update(['assistant_department_id' => null]);
        }

        $schema->table('assistant_advertising_agencies', function (Blueprint $table): void {
            $table->dropIndex('assistant_ad_agency_department_idx');
            $table->dropColumn('assistant_department_id');
        });
        $schema->table('assistant_advertising_media', function (Blueprint $table): void {
            $table->dropUnique('assistant_ad_media_department_name_unique');
            $table->dropIndex('assistant_ad_media_department_idx');
            $table->dropColumn('assistant_department_id');
            $table->unique('name');
        });
    }
};
