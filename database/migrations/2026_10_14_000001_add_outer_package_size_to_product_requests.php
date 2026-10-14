<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->string('outer_package_size', 191)->nullable()->after('estimated_shipping_weight_g');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table): void {
            $table->dropColumn('outer_package_size');
        });
    }
};
