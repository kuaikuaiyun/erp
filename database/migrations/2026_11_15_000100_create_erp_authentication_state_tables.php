<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_authentication_states', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary(); $table->string('mode', 16);
            $table->unsignedBigInteger('version'); $table->timestamp('updated_at');
        });
        Schema::create('erp_authentication_account_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->primary(); $table->unsignedBigInteger('version');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('erp_authentication_account_versions');
        Schema::dropIfExists('erp_authentication_states');
    }
};
