<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))->create('applovin_spend_screenshots', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 64)->unique();
            $table->unsignedBigInteger('operator_user_id')->index();
            $table->unsignedBigInteger('operation_department_id')->nullable()->index();
            $table->string('agency', 191); $table->string('media', 100); $table->string('account_name', 191); $table->string('account_number', 191);
            $table->string('screenshot_path', 500); $table->unsignedBigInteger('uploaded_by'); $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(config('admin.database.connection') ?: config('database.default'))->dropIfExists('applovin_spend_screenshots');
    }
};
