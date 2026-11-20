<?php

use App\Models\AdminUser;
use App\Portals\AssetManager\ReminderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('admin.database.connection') ?: config('database.default');
        $schema = Schema::connection($connection);
        if (! $schema->hasColumn('asset_reminder_groups', 'owner_source_ids')) {
            $schema->table('asset_reminder_groups', fn (Blueprint $t) => $t->json('owner_source_ids')->nullable());
        }
        $db = DB::connection($connection);
        foreach ($db->table('asset_reminder_groups')->whereNull('owner_source_ids')->orderBy('id')->get() as $group) {
            $actor = AdminUser::find($group->updated_by);
            $sources = $actor ? app(ReminderService::class)->sourceIds($actor, true) : [];
            // Unresolved ownership remains administrator-only; never assign a random business department.
            $db->table('asset_reminder_groups')->where('id', $group->id)->whereNull('owner_source_ids')
                ->update(['owner_source_ids' => json_encode($sources)]);
        }
    }
    public function down(): void { /* Preserve group ownership and audit history. */ }
};
