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

        if ($schema->hasTable('aikaipin_placement_evaluation_records')
            && ! $schema->hasColumn('aikaipin_placement_evaluation_records', 'roi')) {
            $schema->table('aikaipin_placement_evaluation_records', function (Blueprint $table): void {
                $table->decimal('roi', 12, 4)->nullable()->after('orders');
            });
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('aikaipin_placement_evaluation_records')
            && $schema->hasColumn('aikaipin_placement_evaluation_records', 'roi')) {
            $schema->table('aikaipin_placement_evaluation_records', function (Blueprint $table): void {
                $table->dropColumn('roi');
            });
        }
    }
};
