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
        if ($schema->hasTable('internal_message_recipients')) {
            $schema->table('internal_message_recipients', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('internal_message_recipients', 'processed_at')) {
                    $table->timestamp('processed_at')->nullable()->after('read_at');
                }
                if (! $schema->hasColumn('internal_message_recipients', 'processed_by')) {
                    $table->unsignedInteger('processed_by')->nullable()->after('processed_at');
                }
                if (! $schema->hasIndex('internal_message_recipients', 'internal_message_recipient_pending_idx')) {
                    $table->index(['recipient_user_id', 'processed_at'], 'internal_message_recipient_pending_idx');
                }
            });
        }

        if ($schema->hasTable('portals') && $schema->hasTable('portal_menus')) {
            $database = DB::connection($connection);
            $portalId = $database->table('portals')->where('code', 'yunying')->value('id');
            if ($portalId) {
                $database->table('portal_menus')->updateOrInsert(
                    ['portal_id' => $portalId, 'code' => 'operation_internal_messages'],
                    [
                        'parent_id' => 0,
                        'title' => '站内消息',
                        'title_key' => 'internal_messages.operation_menu',
                        'path' => '/operation/internal-messages',
                        'icon' => 'fa-envelope',
                        'sort' => 95,
                        'is_enabled' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if ($schema->hasTable('portal_menus')) {
            DB::connection($connection)->table('portal_menus')->where('code', 'operation_internal_messages')->delete();
        }
        if ($schema->hasTable('internal_message_recipients')) {
            $schema->table('internal_message_recipients', function (Blueprint $table) use ($schema): void {
                if ($schema->hasIndex('internal_message_recipients', 'internal_message_recipient_pending_idx')) {
                    $table->dropIndex('internal_message_recipient_pending_idx');
                }
                $columns = array_values(array_filter(['processed_at', 'processed_by'], fn ($column) => $schema->hasColumn('internal_message_recipients', $column)));
                if ($columns !== []) $table->dropColumn($columns);
            });
        }
    }
};
