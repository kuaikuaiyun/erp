<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_login_sites', function (Blueprint $table): void {
            $table->string('company_name', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('erp_login_sites', function (Blueprint $table): void {
            $table->dropColumn('company_name');
        });
    }
};
