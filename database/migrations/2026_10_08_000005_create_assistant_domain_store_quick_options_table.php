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

        if ($schema->hasTable('assistant_domain_store_quick_options')) {
            return;
        }

        $schema->create('assistant_domain_store_quick_options', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('field', 40);
            $table->string('value', 100);
            $table->timestamps();

            $table->unique(['field', 'value'], 'assistant_store_quick_option_unique');
            $table->index('field', 'assistant_store_quick_option_field_index');
        });
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);

        $schema->dropIfExists('assistant_domain_store_quick_options');
    }
};
