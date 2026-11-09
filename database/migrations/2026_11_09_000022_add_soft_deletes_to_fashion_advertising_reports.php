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
        if (! $schema->hasTable('fashion_advertising_reports')) return;

        $schema->table('fashion_advertising_reports', function (Blueprint $table): void {
            $table->softDeletes();
            $table->unsignedInteger('deleted_by')->nullable()->index()->after('deleted_at');
            $table->string('deleter_name', 100)->nullable()->after('deleted_by');
            $table->dropUnique('fashion_ad_report_date_user_unique');
            $table->index(['report_date', 'submitter_id'], 'fashion_ad_report_date_user_index');
        });

        $permissions = config('admin.database.permissions_table');
        if ($schema->hasTable($permissions)) {
            DB::connection($connection)->table($permissions)
                ->where('slug', 'portal.zhuli.fashion_advertising_reviews')
                ->update(['http_method' => 'GET,POST,DELETE', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('fashion_advertising_reports')) return;

        $schema->table('fashion_advertising_reports', function (Blueprint $table): void {
            $table->dropIndex('fashion_ad_report_date_user_index');
            $table->dropIndex(['deleted_by']);
            $table->dropColumn(['deleted_by', 'deleter_name']);
            $table->dropSoftDeletes();
            $table->unique(['report_date', 'submitter_id'], 'fashion_ad_report_date_user_unique');
        });
    }
};
