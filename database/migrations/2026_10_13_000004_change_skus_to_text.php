<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (): void {
            DB::statement('ALTER TABLE product_requests MODIFY skus TEXT NULL');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (): void {
            DB::statement('ALTER TABLE product_requests MODIFY skus VARCHAR(1000) NULL');
        });
    }
};
