<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'assistant_store_shop_domain_unique';

    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if (!$schema->hasTable('assistant_domain_stores')
            || !$schema->hasColumn('assistant_domain_stores', 'shop_name')
            || !$schema->hasColumn('assistant_domain_stores', 'domain')
            || $schema->hasIndex('assistant_domain_stores', self::INDEX_NAME)) {
            return;
        }

        $hasDuplicates = DB::connection($connection)
            ->table('assistant_domain_stores')
            ->select(['shop_name', 'domain'])
            ->groupBy('shop_name', 'domain')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new \RuntimeException(
                'Cannot add the assistant store/domain unique index because duplicate store and domain combinations already exist.'
            );
        }

        $schema->table('assistant_domain_stores', function (Blueprint $table): void {
            $table->unique(['shop_name', 'domain'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        if ($schema->hasTable('assistant_domain_stores')
            && $schema->hasIndex('assistant_domain_stores', self::INDEX_NAME)) {
            $schema->table('assistant_domain_stores', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX_NAME);
            });
        }
    }
};
