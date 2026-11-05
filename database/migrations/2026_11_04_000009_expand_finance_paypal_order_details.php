<?php

use App\Portals\Finance\FinancePayPalDetails;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(config('admin.database.connection') ?: config('database.default'));
        $db->table('finance_payment_transactions')->orderBy('id')->chunkById(200, function ($rows) use ($db): void {
            foreach ($rows as $row) {
                $record = $db->table('finance_records')->where('id', $row->finance_record_id)->where('dataset', 'paypal-orders')->first();
                if (! $record) {
                    continue;
                }
                $payload = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
                $details = FinancePayPalDetails::payload(json_decode($row->raw_payload, true, 512, JSON_THROW_ON_ERROR));
                foreach ($details as $key => $value) {
                    if (! isset($payload[$key]) || $payload[$key] === '') {
                        $payload[$key] = $value;
                    }
                }
                $db->table('finance_records')->where('id', $record->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void {}
};
