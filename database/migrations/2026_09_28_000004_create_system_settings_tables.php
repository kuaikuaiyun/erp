<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if (!$schema->hasTable('system_settings')) {
            $schema->create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 120)->unique();
                $table->longText('value')->nullable();
                $table->boolean('is_encrypted')->default(false);
                $table->unsignedInteger('updated_by_admin_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('system_setting_change_logs')) {
            $schema->create('system_setting_change_logs', function (Blueprint $table) {
                $table->id();
                $table->string('key', 120)->index();
                $table->string('action', 30);
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->boolean('is_encrypted')->default(false);
                $table->unsignedInteger('admin_user_id')->nullable()->index();
                $table->string('admin_username', 190)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Never remove production configuration or its security audit trail.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }

    public function getConnection()
    {
        return config('admin.database.connection') ?: config('database.default');
    }
};


