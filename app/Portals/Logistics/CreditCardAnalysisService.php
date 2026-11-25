<?php

namespace App\Portals\Logistics;

use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsCreditCardRefundOperation;
use App\Models\LogisticsOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditCardAnalysisService
{
    public const RELATIONS = [
        'order:id,assistant_domain_store_id,store_name,collection_account,buyer_email,shopify_order_number,paid_amount_usd,order_created_at,subscription_type,subscription_origin_order_id,is_add_on,logistics_status,tracking_number,tongtu_order_number',
        'order.items:id,logistics_order_id,tongtu_sku,tongtu_product_name,product_title', 'operations',
    ];

    public function filters(Request $request): array
    {
        $rules = [];
        foreach (['collection_account','keyword','customer_email','reason','logistics_status','tracking_number','tongtu_order_number','item_keyword','note'] as $key) {
            $rules[$key] = ['nullable','string','max:1000'];
        }
        $rules['store_id'] = ['nullable','integer','min:1'];
        $rules['category'] = ['nullable','in:alert,customer_service,dispute,uncategorized'];
        foreach (['matched','handled','post_upsell','repurchase','subscription_cancelled','add_on_cancelled','shipped'] as $key) $rules[$key] = ['nullable','in:yes,no'];
        foreach (['date_from','date_to','order_from','order_to','handled_from','handled_to'] as $key) $rules[$key] = ['nullable','date_format:Y-m-d'];
        foreach (['refund_amount_min','refund_amount_max','order_amount_min','order_amount_max'] as $key) $rules[$key] = ['nullable','numeric','min:0','max:99999999999999'];
        $rules['per_page'] = ['nullable','integer','in:20,50,100'];
        $attributes = [];
        foreach ($rules as $key => $_) {
            $label = preg_replace('/_(min|max|from|to)$/', '', $key);
            $label = match ($label) { 'handled' => str_starts_with($key,'handled_') ? 'handled_date' : 'handled', 'order'=>'order_date', default=>$label };
            $attributes[$key] = __('credit_card_analysis.fields.'.$label);
        }
        $request->merge(['subscription_type' => (array) $request->input('subscription_type', [])]);
        $data = $request->validate($rules + [
            'subscription_type' => ['array'],
            'subscription_type.*' => ['nullable', 'string', 'max:1000'],
        ], [], $attributes + [
            'subscription_type' => __('credit_card_analysis.fields.subscription_type'),
            'subscription_type.*' => __('credit_card_analysis.fields.subscription_type'),
        ]);
        $filters = [];
        foreach ($rules as $key => $_) $filters[$key] = trim((string) ($data[$key] ?? ''));
        $filters['subscription_type'] = array_values(array_unique(array_filter(
            array_map(static fn (?string $type): string => trim($type ?? ''), $data['subscription_type'] ?? []),
            static fn (string $type): bool => $type !== '',
        )));
        $filters['per_page'] = (int) ($data['per_page'] ?? 20);
        foreach ([['date_from','date_to'],['order_from','order_to'],['handled_from','handled_to']] as [$from,$to]) {
            if ($filters[$from] !== '' && $filters[$to] !== '' && $filters[$from] > $filters[$to]) {
                throw ValidationException::withMessages([$to=>__('credit_card_analysis.validation.date_order')]);
            }
        }
        foreach (['refund_amount','order_amount'] as $key) {
            if ($filters[$key.'_min'] !== '' && $filters[$key.'_max'] !== '' && (float) $filters[$key.'_min'] > (float) $filters[$key.'_max']) {
                throw ValidationException::withMessages([$key.'_max'=>__('credit_card_analysis.validation.amount_order')]);
            }
        }
        return $filters;
    }

    public function query(array $f): Builder
    {
        $q = LogisticsCreditCardRefund::query();
        if ($f['category'] !== '') {
            $reasons = match ($f['category']) {
                'alert'=>LogisticsCreditCardRefundReason::ALERT,
                'customer_service'=>LogisticsCreditCardRefundReason::CUSTOMER_SERVICE,
                'dispute'=>LogisticsCreditCardRefundReason::DISPUTE,
                default=>LogisticsCreditCardRefundReason::allowed(),
            };
            $values = array_map([LogisticsCreditCardRefundReason::class,'normalize'], $reasons);
            $expression = $this->normalizedReason($q);
            if ($f['category'] === 'uncategorized') $q->whereNotIn(DB::raw($expression), $values);
            else $q->whereIn(DB::raw($expression), $values);
        }
        if ($f['reason'] !== '') $q->whereRaw($this->normalizedReason($q).' = ?', [LogisticsCreditCardRefundReason::normalize($f['reason'])]);
        foreach (['subscription_cancelled','add_on_cancelled'] as $key) if ($f[$key] !== '') $q->where($key, $f[$key] === 'yes');
        if ($f['handled'] === 'yes') $q->whereNotNull('first_handled_at');
        if ($f['handled'] === 'no') $q->whereNull('first_handled_at');
        if ($f['matched'] === 'yes') $q->whereHas('order');
        if ($f['matched'] === 'no') $q->whereDoesntHave('order');
        $this->dates($q, 'occurred_at', $f['date_from'], $f['date_to']);
        $this->dates($q, 'first_handled_at', $f['handled_from'], $f['handled_to']);
        $this->amounts($q, 'refund_amount_usd', $f['refund_amount_min'], $f['refund_amount_max']);
        if ($f['keyword'] !== '') {
            $like = $this->like($f['keyword']);
            $q->where(fn ($query) => $query->whereRaw("bill_number LIKE ? ESCAPE '!'",[$like])->orWhereRaw("refund_reference LIKE ? ESCAPE '!'",[$like]));
        }
        if ($f['note'] !== '') {
            $q->whereHas('operations', function (Builder $operation) use ($f): void {
                $table = (new LogisticsCreditCardRefundOperation())->getTable();
                $grammar = $operation->getQuery()->getGrammar();
                $note = $grammar->wrap('latest_note.payload->note');
                $latest = LogisticsCreditCardRefundOperation::query()->from($table.' as latest_note')
                    ->select('latest_note.id')->whereColumn('latest_note.logistics_credit_card_refund_id', $table.'.logistics_credit_card_refund_id')
                    ->whereRaw("TRIM(COALESCE($note, '')) <> ''")->orderByDesc('latest_note.id')->limit(1);
                $operation->where($table.'.id', '=', $latest)
                    ->whereRaw($grammar->wrap($table.'.payload->note')." LIKE ? ESCAPE '!'", [$this->like($f['note'])]);
            });
        }
        $orderKeys = ['store_id','collection_account','customer_email','subscription_type','post_upsell','repurchase','shipped','logistics_status','tracking_number','tongtu_order_number','item_keyword','order_from','order_to','order_amount_min','order_amount_max'];
        if (array_filter(array_intersect_key($f, array_flip($orderKeys)), fn ($value) => $value !== '' && $value !== []) !== []) {
            $q->whereHas('order', function (Builder $order) use ($f): void {
                if ($f['store_id'] !== '') $order->where('assistant_domain_store_id',$f['store_id']);
                foreach (['collection_account'=>'collection_account','customer_email'=>'buyer_email','tracking_number'=>'tracking_number','tongtu_order_number'=>'tongtu_order_number'] as $key=>$column) {
                    if ($f[$key] !== '') $order->whereRaw($column." LIKE ? ESCAPE '!'",[$this->like($f[$key])]);
                }
                if ($f['subscription_type'] !== []) $order->whereIn('subscription_type', $f['subscription_type']);
                if ($f['logistics_status'] !== '') $order->where('logistics_status', $f['logistics_status']);
                if ($f['post_upsell'] === 'yes') $order->where('is_add_on','已加购');
                if ($f['post_upsell'] === 'no') $order->where(fn ($x) => $x->whereNull('is_add_on')->orWhere('is_add_on','!=','已加购'));
                if ($f['repurchase'] === 'yes') $order->whereNotNull('subscription_origin_order_id')->where('subscription_origin_order_id','!=','');
                if ($f['repurchase'] === 'no') $order->where(fn ($x) => $x->whereNull('subscription_origin_order_id')->orWhere('subscription_origin_order_id',''));
                $shipped = [LogisticsOrder::LOGISTICS_SHIPPED,LogisticsOrder::LOGISTICS_IN_TRANSIT,LogisticsOrder::LOGISTICS_DELIVERED];
                if ($f['shipped'] === 'yes') $order->whereIn('logistics_status',$shipped);
                if ($f['shipped'] === 'no') $order->where(fn ($x) => $x->whereNull('logistics_status')->orWhereNotIn('logistics_status',$shipped));
                $this->dates($order,'order_created_at',$f['order_from'],$f['order_to']);
                $this->amounts($order,'paid_amount_usd',$f['order_amount_min'],$f['order_amount_max']);
                if ($f['item_keyword'] !== '') $order->whereHas('items', function (Builder $items) use ($f): void {
                    $like = $this->like($f['item_keyword']);
                    $items->where(fn ($x) => $x->whereRaw("tongtu_sku LIKE ? ESCAPE '!'",[$like])->orWhereRaw("tongtu_product_name LIKE ? ESCAPE '!'",[$like])->orWhereRaw("product_title LIKE ? ESCAPE '!'",[$like]));
                });
            });
        }
        return $q;
    }

    public function category(mixed $reason): string
    {
        $reason = LogisticsCreditCardRefundReason::canonical($reason);
        return match (true) {
            in_array($reason,LogisticsCreditCardRefundReason::ALERT,true)=>'alert',
            in_array($reason,LogisticsCreditCardRefundReason::CUSTOMER_SERVICE,true)=>'customer_service',
            in_array($reason,LogisticsCreditCardRefundReason::DISPUTE,true)=>'dispute', default=>'uncategorized',
        };
    }

    public function latestNote(LogisticsCreditCardRefund $refund): ?string
    {
        $operation = $refund->operations->first(fn ($row) => filled($row->payload['note'] ?? null));
        return $operation?->payload['note'] ?? null;
    }

    private function dates(Builder $query, string $column, string $from, string $to): void
    {
        if ($from !== '') $query->where($column,'>=',CarbonImmutable::parse($from,'Asia/Shanghai')->startOfDay()->utc());
        if ($to !== '') $query->where($column,'<',CarbonImmutable::parse($to,'Asia/Shanghai')->startOfDay()->addDay()->utc());
    }

    private function amounts(Builder $query, string $column, string $min, string $max): void
    {
        if ($min !== '') $query->where($column,'>=',$min);
        if ($max !== '') $query->where($column,'<=',$max);
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['!','%','_'],['!!','!%','!_'],$value).'%';
    }

    private function normalizedReason(Builder $query): string
    {
        $expression = "LOWER(COALESCE(refund_reason, ''))";
        foreach (['（','）','(',')',' ',"\t","\r","\n","\u{00A0}","\u{3000}"] as $char) {
            $expression = 'REPLACE('.$expression.','.$query->getConnection()->getPdo()->quote($char).",'')";
        }
        return $expression;
    }
}
