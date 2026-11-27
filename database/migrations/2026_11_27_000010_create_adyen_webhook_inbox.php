<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adyen_webhook_inbox', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant', 191);
            $table->char('digest', 64);
            $table->longText('payload')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->unique(['merchant', 'digest']);
            $table->index(['merchant', 'acknowledged_at', 'id'], 'adyen_inbox_pending');
        });
    }

    public function down(): void
    {
        // Preserve pending deliveries during application rollback.
    }
};
