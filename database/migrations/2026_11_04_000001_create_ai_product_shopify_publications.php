<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->create('ai_product_shopify_publications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('aikaipin_product_id')->index();
                $table->unsignedBigInteger('assistant_domain_store_id')->index();
                $table->unsignedInteger('published_by')->index();
                $table->string('published_by_name', 100);
                $table->string('status', 20)->default('processing')->index();
                $table->string('shopify_product_id', 100)->nullable()->index();
                $table->string('shopify_variant_id', 100)->nullable();
                $table->string('shopify_admin_url', 500)->nullable();
                $table->string('sku', 191)->nullable();
                $table->decimal('price', 18, 2)->default(0);
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))
            ->dropIfExists('ai_product_shopify_publications');
    }
};
