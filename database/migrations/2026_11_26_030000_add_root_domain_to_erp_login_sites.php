<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_login_sites', function (Blueprint $table): void {
            $table->string('root_domain', 253)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('erp_login_sites', function (Blueprint $table): void {
            $table->dropUnique(['root_domain']);
            $table->dropColumn('root_domain');
        });
    }
};
