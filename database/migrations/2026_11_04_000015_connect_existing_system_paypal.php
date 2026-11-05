<?php

use App\Models\FinancePaymentAccount;
use App\Portals\Finance\FinanceCatalog;
use App\Portals\Finance\FinanceSystemPayPalService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $schema = $db->getSchemaBuilder();
        $schema->table('finance_payment_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('system_paypal_account_id')->nullable()->unique();
            $table->dateTime('system_paypal_synced_at')->nullable();
        });
        if (! $schema->hasTable('logistics_paypal_accounts')) {
            return;
        }
        $sources = $db->table('logistics_paypal_accounts')->get();
        foreach (FinancePaymentAccount::query()->where('account_type', 'paypal')->get() as $account) {
            $name = FinanceCatalog::simplified($account->activity_account);
            $matches = $sources->filter(fn ($source) => mb_strlen($name) >= 2
                && str_starts_with(FinanceCatalog::simplified($source->account_name), $name));
            if ($matches->count() === 1) {
                $account->forceFill(['system_paypal_account_id' => $matches->first()->id])->save();
            }
        }
        app(FinanceSystemPayPalService::class)->sync();
    }

    public function down(): void {}
};
