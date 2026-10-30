<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('internal_messages')) {
            $schema->create('internal_messages', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('title', 150);
                $table->text('content')->nullable();
                $table->unsignedInteger('sender_user_id');
                $table->string('sender_name', 100);
                $table->string('sender_portal_code', 30);
                $table->string('sender_portal_name', 100);
                $table->timestamp('published_at');
                $table->timestamps();
                $table->index(['sender_portal_code', 'published_at'], 'internal_messages_portal_published_idx');
            });
        }
        if (! $schema->hasTable('internal_message_images')) {
            $schema->create('internal_message_images', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('internal_message_id');
                $table->string('path', 500);
                $table->string('original_name', 255);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
                $table->index('internal_message_id', 'internal_message_images_message_idx');
            });
        }
        if (! $schema->hasTable('internal_message_recipients')) {
            $schema->create('internal_message_recipients', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('internal_message_id');
                $table->unsignedInteger('recipient_user_id');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->unique(['internal_message_id', 'recipient_user_id'], 'internal_message_recipient_unique');
                $table->index(['recipient_user_id', 'read_at'], 'internal_message_recipient_unread_idx');
            });
        }

        if ($schema->hasTable('portals') && $schema->hasTable('portal_menus')) {
            $database = DB::connection($connection);
            foreach (['zhuli', 'caigou'] as $portalCode) {
                $portalId = $database->table('portals')->where('code', $portalCode)->value('id');
                if (! $portalId) continue;
                $database->table('portal_menus')->updateOrInsert(
                    ['portal_id' => $portalId, 'code' => $portalCode.'_internal_messages'],
                    ['parent_id' => 0, 'title' => '站内消息', 'title_key' => 'internal_messages.menu', 'path' => '/internal-messages', 'icon' => 'fa-bullhorn', 'sort' => 80, 'is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $database = DB::connection($connection);
        if (Schema::connection($connection)->hasTable('portal_menus')) {
            $database->table('portal_menus')->whereIn('code', ['zhuli_internal_messages', 'caigou_internal_messages'])->delete();
        }
        $schema = Schema::connection($connection);
        $schema->dropIfExists('internal_message_recipients');
        $schema->dropIfExists('internal_message_images');
        $schema->dropIfExists('internal_messages');
    }
};
