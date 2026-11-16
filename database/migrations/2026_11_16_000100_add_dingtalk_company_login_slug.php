<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dingtalk_companies', function (Blueprint $table): void {
            $table->string('login_slug', 64)->nullable()->unique();
            $table->string('login_display_name', 190)->nullable();
            $table->string('login_page_title', 190)->nullable();
            $table->string('login_welcome', 500)->nullable();
            $table->string('login_logo_path')->nullable();
            $table->string('login_logo_disk', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dingtalk_companies', function (Blueprint $table): void {
            $table->dropUnique(['login_slug']);
            $table->dropColumn(['login_slug', 'login_display_name', 'login_page_title', 'login_welcome', 'login_logo_path', 'login_logo_disk']);
        });
    }
};
