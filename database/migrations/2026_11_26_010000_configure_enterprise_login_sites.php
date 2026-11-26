<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dingtalk_companies', function (Blueprint $table): void {
            $table->text('callback_token')->nullable()->change();
            $table->text('callback_aes_key')->nullable()->change();
            $table->string('callback_owner_key', 190)->nullable()->change();
        });

        Schema::create('erp_login_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 253)->unique();
            $table->string('display_name', 190);
            $table->string('logo_path')->nullable();
            $table->boolean('password_enabled')->default(true);
            $table->boolean('dingtalk_enabled')->default(false);
            $table->foreignId('dingtalk_company_id')->nullable()->unique()->constrained('dingtalk_companies');
            $table->unsignedBigInteger('password_version')->default(1);
            $table->unsignedBigInteger('dingtalk_version')->default(1);
            $table->timestamps();
        });

        Schema::table('dingtalk_challenges', function (Blueprint $table): void {
            $table->unsignedBigInteger('login_site_id')->nullable();
            $table->unsignedBigInteger('login_method_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dingtalk_challenges', function (Blueprint $table): void {
            $table->dropColumn(['login_site_id', 'login_method_version']);
        });
        Schema::dropIfExists('erp_login_sites');

        foreach (['callback_token', 'callback_aes_key', 'callback_owner_key'] as $column) {
            $missing = DB::table('dingtalk_companies')->whereNull($column);
            if ($missing->exists()) {
                $missing->update([$column => $column === 'callback_owner_key' ? '' : Crypt::encryptString('')]);
            }
        }
        Schema::table('dingtalk_companies', function (Blueprint $table): void {
            $table->text('callback_token')->nullable(false)->change();
            $table->text('callback_aes_key')->nullable(false)->change();
            $table->string('callback_owner_key', 190)->nullable(false)->change();
        });
    }
};
