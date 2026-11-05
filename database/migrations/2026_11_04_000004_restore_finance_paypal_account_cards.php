<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $defaults = [
            '尚云' => ['尚云', '尚雲', 'SHANGYUN'],
            '立鉑' => ['立鉑', '立铂', 'LIBO'],
            '嘉匯' => ['嘉匯', '嘉汇', 'JIAHUI'],
            '啟盛' => ['啟盛', '启盛', 'QISHENG'],
            '晟達' => ['晟達', '晟达', 'SHENGDA'],
            '維馳' => ['維馳', '维驰', 'WEICHI'],
        ];
        $db->transaction(function () use ($db, $defaults): void {
            // Include deleted accounts so reruns never recreate a card removed by the user.
            $accounts = $db->table('finance_payment_accounts')->where('account_type', 'paypal')->get();
            foreach ($defaults as $name => $aliases) {
                $aliases = array_map('mb_strtolower', $aliases);
                if ($accounts->contains(fn ($account) => in_array(mb_strtolower($account->account_name), $aliases, true)
                    || in_array(mb_strtolower($account->activity_account), $aliases, true))) {
                    continue;
                }
                $db->table('finance_payment_accounts')->insert([
                    'account_code' => 'FA-'.Str::ulid(), 'account_name' => $name, 'activity_account' => $name,
                    'account_type' => 'paypal', 'main_currency' => 'USD', 'status' => 'enabled',
                    'relay_hmac_key_id' => 'erp-main', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep accounts and any financial history created after installation.
    }
};
