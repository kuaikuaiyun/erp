<?php

use App\Portals\Finance\FinanceWorkbookService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $workbooks = app(FinanceWorkbookService::class);
        $db = $workbooks->database();
        $db->transaction(function () use ($db, $workbooks): void {
            foreach ($workbooks->typeSettings() as $type) {
                if (filled($type->category)) {
                    $db->table('finance_transaction_types')->whereIn('raw_type', $type->aliases)
                        ->where(fn ($query) => $query->whereNull('category')->orWhere('category', ''))
                        ->update(['category' => $type->category, 'updated_at' => now()]);
                }
            }
        });
    }

    public function down(): void {}
};
