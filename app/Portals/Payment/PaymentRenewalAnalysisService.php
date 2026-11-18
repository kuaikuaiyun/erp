<?php

namespace App\Portals\Payment;

use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsOrder;
use App\Models\LogisticsOrderPaymentReference;
use App\Models\LogisticsPayPalDispute;
use App\Models\LogisticsPayPalRefund;
use App\Models\LogisticsPayPalTransaction;
use Carbon\Carbon;
use Illuminate\Support\LazyCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class PaymentRenewalAnalysisService
{
    // Avoid loading large Shopify/Tongtu payloads when reconstructing historical stages.
    private const ORDER_FIELDS = [
        'id', 'paid_at', 'paid_amount_usd', 'subscription_origin_order_id', 'subscription_type',
        'is_add_on', 'operator_name', 'store_name', 'transaction_id', 'shopify_order_id',
        'shopify_order_number', 'payment_gateway_order_id', 'bill_number',
        'payment_gateway', 'payment_method', 'paypal_account_id', 'paypal_association_source', 'buyer_email', 'collection_account', 'collection_account_email',
    ];

    /** Ordered groups shared by the page and Excel export. */
    public static function breakdownGroups(): array
    {
        return [
            'total' => ['channel'=>'total', 'method'=>'all', 'level'=>'total'],
            'paypal' => ['channel'=>'paypal', 'method'=>'all', 'level'=>'channel'],
            'method_ap' => ['channel'=>'paypal', 'method'=>'ap', 'level'=>'method'],
            'method_gp' => ['channel'=>'paypal', 'method'=>'gp', 'level'=>'method'],
            'method_pp' => ['channel'=>'paypal', 'method'=>'pp', 'level'=>'method'],
            'credit_card' => ['channel'=>'credit_card', 'method'=>'all', 'level'=>'channel'],
            'method_cc' => ['channel'=>'credit_card', 'method'=>'cc', 'level'=>'method'],
            'unknown' => ['channel'=>'unknown', 'method'=>'unknown', 'level'=>'channel'],
        ];
    }

    private function matchesGroup(array $row, string $key): bool
    {
        return $key === 'total' || (str_starts_with($key, 'method_')
            ? $row['payment_method'] === substr($key, 7) : $row['actual_channel'] === $key);
    }

    private function methodFor(LogisticsOrder $order): string
    {
        $method = strtolower(trim((string) $order->payment_method));
        return in_array($method, ['ap', 'gp', 'pp', 'cc'], true) ? $method : 'unknown';
    }

    /** @return array<string,string> */
    public static function timezones(): array
    {
        $options = [];
        for ($hour = -12; $hour <= 14; $hour++) {
            $offset = sprintf('%+03d:00', $hour);
            $options[$offset] = 'UTC'.$offset;
        }
        return $options;
    }

    public function dashboard(array $filters): array
    {
        unset($filters['channel']);
        if (($filters['stage'] ?? '') === 'initial') $filters['stage']='';
        $data=$this->combinedData($filters);
        $total=count($data['stages']);
        $last=max(1,(int)ceil($total/10));
        $page=min($last,max(1,(int)($filters['stage_page'] ?? 1)));
        return [...$data,'filters'=>$filters,'stages'=>array_slice($data['stages'],($page-1)*10,10),
            'stage_pagination'=>['current_page'=>$page,'last_page'=>$last,'total'=>$total,
                'from'=>$total ? ($page-1)*10+1 : 0,'to'=>min($page*10,$total)]];
    }

    public function exportData(array $filters): array
    {
        $data=$this->combinedData($filters);
        $data['initial_rows']=LazyCollection::make(fn ()=>$this->firstRows($this->moduleFilters($filters,'initial'),true,true));
        $data['rows']=LazyCollection::make(fn ()=>$this->renewalRows($this->moduleFilters($filters,'renewal')));
        return $data;
    }

    public function initialOrderDetails(array $filters, array $detail, bool $paginate=true): array
    {
        $module=$this->moduleFilters($filters,'initial');
        $cacheFilters=$detail;
        unset($cacheFilters['detail_page'],$cacheFilters['detail_per_page']);
        $key='payment:renewal-detail-summary:v21:'.sha1(json_encode([$module,$cacheFilters]));
        $stats=Cache::remember($key,now()->addMinutes(5),function () use ($module,$detail): array {
            $scope=$summary=$this->emptyRiskTotal();
            $stores=[];
            foreach ($this->firstRows($module,false,trim((string)($detail['detail_keyword'] ?? ''))!=='') as $row) {
                if (!$this->detailMatches($row,$detail,false)) continue;
                $this->addTotal($scope,$row,true);
                $stores[$row['store_name']]=true;
                if ($this->detailMatches($row,$detail,true)) $this->addTotal($summary,$row,true);
            }
            $names=array_map('strval',array_keys($stores));
            sort($names,SORT_NATURAL|SORT_FLAG_CASE);
            return ['scope'=>$this->finishTotal($scope),'summary'=>$this->finishTotal($summary),
                'stores'=>$names,'calculation'=>$this->window($module)['calculation']];
        });
        $perPage=max(1,min(100,(int)($detail['detail_per_page'] ?? 20)));
        $total=$stats['summary']['orders'];
        $last=max(1,(int)ceil($total/$perPage));
        $page=min($last,max(1,(int)($detail['detail_page'] ?? 1)));
        $rows=LazyCollection::make(fn ()=>$this->filteredDetailRows($module,$detail,$paginate ? ($page-1)*$perPage : 0,$paginate ? $perPage : null));
        return [...$stats,'rows'=>$paginate ? $rows->all() : $rows,'parent_store'=>$module['store'],'filters'=>$detail,
            'pagination'=>['current_page'=>$page,'last_page'=>$last,'total'=>$total,'per_page'=>$perPage,
                'from'=>$total ? ($page-1)*$perPage+1 : 0,'to'=>min($page*$perPage,$total)]];
    }

    private function detailMatches(array $row,array $detail,bool $filtered): bool
    {
        if (!$this->matchesGroup($row,$detail['detail_group'])
            || ($detail['detail_purchase']!=='all' && $row['purchase_type']!==$detail['detail_purchase'])
            || ($detail['detail_kind']==='refunds' && $row['refund_count']===0)) return false;
        if (!$filtered) return true;
        $keyword=trim((string)($detail['detail_keyword'] ?? ''));
        if ($keyword!=='' && !str_contains(mb_strtolower(implode(' ',[
            $row['erp_order_id'],$row['initial_order'],$row['transaction_id'],implode(' ',$row['transaction_ids']),
            $row['store_name'],implode(' ',$row['bill_numbers']),$row['buyer_email'],$row['collection_account'],$row['collection_account_email'],
        ])),mb_strtolower($keyword))) return false;
        foreach (['detail_store'=>'store_name','detail_method'=>'payment_method','detail_type'=>'purchase_type'] as $filter=>$field) {
            if (($detail[$filter] ?? '')!=='' && $row[$field]!==$detail[$filter]) return false;
        }
        if (($detail['detail_refund'] ?? '')==='yes' && $row['refund_count']===0) return false;
        if (($detail['detail_refund'] ?? '')==='no' && $row['refund_count']>0) return false;
        $date=substr($row['paid_at'],0,10);
        return !(($detail['detail_from'] ?? '')!=='' && $date<$detail['detail_from'])
            && !(($detail['detail_to'] ?? '')!=='' && $date>$detail['detail_to']);
    }

    private function filteredDetailRows(array $module,array $detail,int $offset,?int $limit): \Generator
    {
        $ids=[];
        $seen=$emitted=0;
        $window=$this->window($module);
        $identifiers=trim((string)($detail['detail_keyword'] ?? ''))!=='';
        foreach ($this->firstRows($module,false,$identifiers) as $row) {
            if (!$this->detailMatches($row,$detail,true)) continue;
            if ($seen++<$offset) continue;
            $ids[]=$row['erp_order_id'];
            if (count($ids)>=($limit ?? 200)) {
                yield from $this->hydrateDetails($ids,$module,$window);
                $emitted+=count($ids);
                $ids=[];
                if ($limit!==null && $emitted>=$limit) return;
            }
        }
        if ($ids) yield from $this->hydrateDetails($ids,$module,$window);
    }

    private function hydrateDetails(array $ids,array $module,array $window): \Generator
    {
        $orders=LogisticsOrder::query()->select(self::ORDER_FIELDS)->whereIn('id',$ids)->get()->keyBy('id');
        $risk=$this->riskMaps($ids,['timezone'=>$module['timezone']],$window['from'],$window['to']);
        $identifiers=$this->paymentIdentifiers($ids);
        foreach ($ids as $id) {
            if (!$orders->has($id)) continue;
            $order=$orders->get($id);
            yield $this->paymentRow($order,$order,0,$module['timezone'],$risk,
                $order->subscription_type==='无订阅' ? 'non_subscription' : 'subscription',$identifiers[$id] ?? []);
        }
    }

    private function moduleFilters(array $filters,string $module): array
    {
        $timezone=$filters[$module.'_timezone'] ?? $filters['timezone'] ?? '+08:00';
        $today=now($timezone);
        return ['date_from'=>$filters[$module.'_date_from'] ?? $filters['date_from'] ?? $today->copy()->subDays(6)->toDateString(),
            'date_to'=>$filters[$module.'_date_to'] ?? $filters['date_to'] ?? $today->toDateString(),
            'timezone'=>$timezone,'store'=>$filters[$module.'_store'] ?? $filters['store'] ?? '',
            'channel'=>'','risk_date_mode'=>'payment','stage'=>$module==='renewal' && ($filters['stage'] ?? '')!=='initial' ? ($filters['stage'] ?? '') : '',
            'first_only'=>$module==='initial'];
    }

    private function combinedData(array $filters): array
    {
        $initial=$this->dataset($this->moduleFilters($filters,'initial'));
        $renewal=$this->dataset($this->moduleFilters($filters,'renewal'));
        $stageFilter=($filters['stage'] ?? '')==='initial' ? '' : ($filters['stage'] ?? '');
        $stages=array_values(array_filter($renewal['stages'],static fn ($stage)=>$stage['number']>0 && ($stageFilter==='' || $stage['key']===$stageFilter)));
        $summary=$this->emptyRiskTotal();
        $channels=array_fill_keys(['paypal','credit_card','unknown'],$this->emptyRiskTotal());
        foreach ($stages as &$stage) {
            $this->mergeTotal($summary,$stage['_rounded']);
            foreach ($channels as $key=>&$value) $this->mergeTotal($value,$stage['_channels'][$key]);
            unset($value,$stage['_rounded'],$stage['_channels']);
        }
        unset($stage);
        foreach ($channels as &$value) $value=$this->finishTotal($value);
        unset($value);
        return ['summary'=>[...$this->finishTotal($summary),'subscription_rate'=>$initial['subscription_rate']],
            'stages'=>$stages,'stage_options'=>array_column(array_filter($renewal['stages'],static fn ($s)=>$s['number']>0),'key'),
            'initial_refunds'=>$initial['initial_refunds'],'initial_refunds_by_type'=>$initial['initial_refunds_by_type'],
            'calculation'=>$renewal['calculation'],'initial_calculation'=>$initial['calculation'],
            'initial_stores'=>$initial['stores'],'renewal_stores'=>$renewal['stores'],'stores'=>$renewal['stores'],
            'risk_by_channel'=>$channels,'upsell'=>$renewal['upsell']];
    }

    private function dataset(array $filters): array
    {
        $cache=$filters;
        unset($cache['stage'],$cache['stage_page']);
        return Cache::remember('payment:renewal-summary:v21:'.sha1(json_encode($cache)),now()->addMinutes(5),
            fn ()=>$this->buildSummary($cache));
    }

    private function window(array $filters): array
    {
        $zone=$filters['timezone'];
        $localFrom=Carbon::parse($filters['date_from'],$zone)->startOfDay();
        $localTo=Carbon::parse($filters['date_to'],$zone)->endOfDay();
        $comparisonFrom=$localFrom->copy()->subDays(30);
        $comparisonTo=$localTo->copy()->subDays(30);
        return ['from'=>$localFrom->copy()->utc(),'to'=>$localTo->copy()->utc(),
            'previous_from'=>$comparisonFrom->copy()->utc(),'previous_to'=>$comparisonTo->copy()->utc(),
            'calculation'=>['period_from'=>$localFrom->format('Y-m-d H:i:s'),'period_to'=>$localTo->format('Y-m-d H:i:s'),
                'comparison_from'=>$comparisonFrom->format('Y-m-d H:i:s'),'comparison_to'=>$comparisonTo->format('Y-m-d H:i:s'),
                'utc_period_from'=>$localFrom->copy()->utc()->format('Y-m-d H:i:s'),'utc_period_to'=>$localTo->copy()->utc()->format('Y-m-d H:i:s'),
                'utc_comparison_from'=>$comparisonFrom->copy()->utc()->format('Y-m-d H:i:s'),'utc_comparison_to'=>$comparisonTo->copy()->utc()->format('Y-m-d H:i:s'),
                'timezone'=>'UTC'.$zone,'generated_at'=>now($zone)->format('Y-m-d H:i:s'),
                'expires_at'=>now($zone)->addMinutes(5)->format('Y-m-d H:i:s')]];
    }

    private function firstQuery(array $filters,array $window): Builder
    {
        $query=LogisticsOrder::query()->whereBetween('paid_at',[$window['from'],$window['to']])
            ->where(function ($q): void {
                $q->where(function ($q): void {
                    $q->where('subscription_type','订阅首次购买')->where(function ($q): void {
                        $q->whereNull('subscription_origin_order_id')->orWhere('subscription_origin_order_id','');
                    });
                })->orWhere(function ($q): void {
                    $q->where('subscription_type','无订阅')->whereRaw("TRIM(COALESCE(subscription_origin_order_id, '')) = ''");
                });
            })->where(function ($q): void {$q->whereNull('operator_name')->orWhere('operator_name','not like','%刷单%');});
        if ($filters['store']!=='') $query->where('store_name',$filters['store']);
        return $query;
    }

    /** Keyset pagination keeps (paid_at,id) ordering stable even across batch boundaries. */
    private function orderedBatches(Builder $query,bool $descending=false,int $size=500): \Generator
    {
        $lastDate=$lastId=null;
        $direction=$descending ? 'desc' : 'asc';
        $operator=$descending ? '<' : '>';
        do {
            $batch=(clone $query)->select(self::ORDER_FIELDS);
            if ($lastId!==null) $batch->where(function ($q) use ($lastDate,$lastId,$operator): void {
                $q->where('paid_at',$operator,$lastDate)->orWhere(function ($q) use ($lastDate,$lastId,$operator): void {
                    $q->where('paid_at',$lastDate)->where('id',$operator,$lastId);
                });
            });
            $orders=$batch->orderBy('paid_at',$direction)->orderBy('id',$direction)->limit($size)->get();
            if ($orders->isEmpty()) return;
            $last=$orders->last();
            $lastDate=$last->getRawOriginal('paid_at');
            $lastId=(int)$last->id;
            yield $orders;
        } while ($orders->count()===$size);
    }

    private function firstRows(array $filters,bool $details,bool $identifiers): \Generator
    {
        $w=$this->window($filters);
        foreach ($this->orderedBatches($this->firstQuery($filters,$w),true) as $orders) {
            $ids=$orders->modelKeys();
            $risk=$this->riskMaps($ids,['timezone'=>$filters['timezone']],$w['from'],$w['to'],$details);
            $refs=$identifiers ? $this->paymentIdentifiers($ids) : [];
            foreach ($orders as $order) yield $this->paymentRow($order,$order,0,$filters['timezone'],$risk,
                $order->subscription_type==='无订阅' ? 'non_subscription' : 'subscription',$refs[(int)$order->id] ?? []);
        }
    }

    /** Only scalar reference => root IDs survive the discovery pass, never all Eloquent models. */
    private function rootIndex(array $filters,array $w): array
    {
        $refs=$stores=[];
        $candidates=LogisticsOrder::query()->where(function ($q) use ($w): void {
            $q->whereBetween('paid_at',[$w['from'],$w['to']])->orWhereBetween('paid_at',[$w['previous_from'],$w['previous_to']]);
        })->select(['id','subscription_origin_order_id','subscription_type','store_name'])->lazyById(500);
        foreach ($candidates as $order) {
            $origin=trim((string)$order->subscription_origin_order_id);
            if ($origin!=='') $refs[$origin]=true;
            if ($origin==='' && $order->subscription_type==='订阅首次购买') $refs[(string)$order->id]=true;
            if ($order->store_name) $stores[$order->store_name]=true;
        }
        $keys=array_keys($refs);
        unset($refs);
        $ids=$map=[];
        foreach (array_chunk($keys,300) as $chunk) {
            $numeric=array_values(array_filter($chunk,static fn ($v)=>ctype_digit((string)$v)));
            $query=LogisticsOrder::query()->select(self::ORDER_FIELDS)->where('subscription_type','订阅首次购买')
                ->whereNotNull('paid_at')->where('paid_at','<=',$w['to'])
                ->where(function ($q): void {$q->whereNull('subscription_origin_order_id')->orWhere('subscription_origin_order_id','');})
                ->where(function ($q): void {$q->whereNull('operator_name')->orWhere('operator_name','not like','%刷单%');})
                ->where(function ($q) use ($chunk,$numeric): void {
                    foreach (['transaction_id','shopify_order_id','shopify_order_number','payment_gateway_order_id','bill_number'] as $field) $q->orWhereIn($field,$chunk);
                    if ($numeric) $q->orWhereIn('id',$numeric);
                });
            if ($filters['store']!=='') $query->where('store_name',$filters['store']);
            foreach ($query->lazyById(500) as $root) {
                if ($root->store_name) $stores[$root->store_name]=true;
                if ($filters['first_only']) continue;
                $id=(int)$root->id;
                $ids[$id]=true;
                foreach ($this->chainReferences($root) as $ref) if (!isset($map[$ref]) || $map[$ref]<$id) $map[$ref]=$id;
            }
        }
        if ($filters['store']!=='') $stores[$filters['store']]=true;
        $rootIds=array_keys($ids);
        sort($rootIds,SORT_NUMERIC);
        return ['ids'=>$rootIds,'map'=>$map,'stores'=>collect(array_keys($stores))->map(static fn ($name)=>(string)$name)->sort()->values()->all()];
    }

    private function lifecycleBatches(array $filters,array $index,array $w): \Generator
    {
        foreach (array_chunk($index['ids'],100) as $rootIds) {
            $roots=LogisticsOrder::query()->select(self::ORDER_FIELDS)->whereIn('id',$rootIds)->orderBy('id')->get()->keyBy('id');
            $entries=$refs=$seen=[];
            foreach ($roots as $root) {
                $entries[]=['order'=>$root,'initial'=>$root,'number'=>0];
                foreach ($this->chainReferences($root) as $ref) if (($index['map'][$ref] ?? null)===(int)$root->id) $refs[$ref]=true;
            }
            if ($entries) yield $entries;
            if (!$refs) continue;
            $query=LogisticsOrder::query()->whereIn('subscription_origin_order_id',array_keys($refs))
                ->whereNotNull('paid_at')->where('paid_at','<=',$w['to']);
            foreach ($this->orderedBatches($query) as $orders) {
                $entries=[];
                foreach ($orders as $order) {
                    $rootId=$index['map'][trim((string)$order->subscription_origin_order_id)] ?? null;
                    if ($rootId===null || !$roots->has($rootId)) continue;
                    $seen[$rootId]=($seen[$rootId] ?? 0)+1;
                    $entries[]=['order'=>$order,'initial'=>$roots->get($rootId),'number'=>$seen[$rootId]];
                }
                if ($entries) yield $entries;
            }
        }
    }

    private function renewalRows(array $filters): \Generator
    {
        $w=$this->window($filters);
        $index=$this->rootIndex($filters,$w);
        foreach ($this->lifecycleBatches($filters,$index,$w) as $entries) {
            $current=array_values(array_filter($entries,static fn ($entry)=>$entry['number']>0
                && $entry['order']->paid_at->betweenIncluded($w['from'],$w['to'])
                && ($filters['stage']==='' || 'renewal_'.$entry['number']===$filters['stage'])));
            if (!$current) continue;
            $ids=array_map(static fn ($e)=>(int)$e['order']->id,$current);
            $risk=$this->riskMaps($ids,['timezone'=>$filters['timezone']],$w['from'],$w['to']);
            $refs=$this->paymentIdentifiers($ids);
            foreach ($current as $entry) yield $this->paymentRow($entry['order'],$entry['initial'],$entry['number'],$filters['timezone'],$risk,
                'subscription',$refs[(int)$entry['order']->id] ?? []);
        }
    }

    private function addTotal(array &$total,array $row,bool $raw): void
    {
        $total['orders']++;
        $total['revenue']+=$row[$raw ? 'raw_amount' : 'amount'];
        $total['refund_count']+=$row['refund_count'];
        $total['refund_order_count']+=$row['refund_count']>0 ? 1 : 0;
        $total['refund_amount']+=$row[$raw ? 'raw_refund_amount' : 'refund_amount'];
        $total['dispute_count']+=$row['dispute_count'];
        $total['dispute_amount']+=$row[$raw ? 'raw_dispute_amount' : 'dispute_amount'];
    }

    private function mergeTotal(array &$total,array $part): void
    {
        foreach (['orders','revenue','refund_order_count','refund_count','refund_amount','dispute_count','dispute_amount'] as $key) $total[$key]+=$part[$key];
    }

    private function finishTotal(array $total): array
    {
        $total['refund_count_rate']=$this->rate($total['refund_order_count'],$total['orders']);
        $total['refund_amount_rate']=$this->rate($total['refund_amount'],$total['revenue']);
        $total['dispute_rate']=$this->rate($total['dispute_count'],$total['orders']);
        return $total;
    }

    private function buildSummary(array $filters): array
    {
        $w=$this->window($filters);
        $index=$this->rootIndex($filters,$w);
        $groups=array_keys(self::breakdownGroups());
        $first=[];
        foreach ($groups as $key) $first[$key]=array_fill_keys(['all','subscription','non_subscription'],$this->emptyRiskTotal());
        if ($filters['first_only']) {
            foreach ($this->firstRows($filters,false,false) as $row) {
                foreach ($groups as $key) if ($this->matchesGroup($row,$key)) {
                    $this->addTotal($first[$key]['all'],$row,true);
                    $this->addTotal($first[$key][$row['purchase_type']],$row,true);
                }
            }
        }
        $current=$previous=[];
        $upsell=['reference_orders'=>0,'scope_orders'=>0,'linked_orders'=>0,'references'=>0,'unmeasured'=>0,
            'measured_orders'=>0,'revenue'=>0.0,'refund_count'=>0,'refund_amount'=>0.0,'dispute_count'=>0,'dispute_amount'=>0.0];
        if (!$filters['first_only']) foreach ($this->lifecycleBatches($filters,$index,$w) as $entries) {
            $now=[];
            foreach ($entries as $entry) {
                $order=$entry['order'];
                $number=$entry['number'];
                if ($order->paid_at->betweenIncluded($w['previous_from'],$w['previous_to'])) {
                    $stub=['actual_channel'=>$this->channelFor($order),'payment_method'=>$this->methodFor($order)];
                    foreach ($groups as $key) if ($this->matchesGroup($stub,$key)) $previous[$number][$key]=($previous[$number][$key] ?? 0)+1;
                }
                if ($order->paid_at->betweenIncluded($w['from'],$w['to'])) $now[]=$entry;
            }
            if (!$now) continue;
            $ids=array_map(static fn ($e)=>(int)$e['order']->id,$now);
            $risk=$this->riskMaps($ids,['timezone'=>$filters['timezone']],$w['from'],$w['to'],false);
            $renewalIds=[];
            foreach ($now as $entry) {
                $order=$entry['order'];
                $number=$entry['number'];
                $row=$this->paymentRow($order,$entry['initial'],$number,$filters['timezone'],$risk);
                if (!isset($current[$number])) $current[$number]=[
                    'totals'=>array_fill_keys($groups,$this->emptyRiskTotal()),
                    'rounded'=>$this->emptyRiskTotal(),'channels'=>array_fill_keys(['paypal','credit_card','unknown'],$this->emptyRiskTotal()),
                    'from'=>$row['paid_at'],'to'=>$row['paid_at']];
                $s=&$current[$number];
                foreach ($groups as $key) if ($this->matchesGroup($row,$key)) $this->addTotal($s['totals'][$key],$row,true);
                $this->addTotal($s['rounded'],$row,false);
                $this->addTotal($s['channels'][$row['actual_channel']],$row,false);
                $s['from']=min($s['from'],$row['paid_at']);$s['to']=max($s['to'],$row['paid_at']);
                unset($s);
                if ($number>0) {
                    $renewalIds[]=(int)$order->id;
                    $upsell['scope_orders']++;
                    if ($order->is_add_on==='已加购') {
                        $upsell['measured_orders']++;
                        $upsell['revenue']+=(float)$order->paid_amount_usd;
                        foreach (['refund_count','refund_amount','dispute_count','dispute_amount'] as $field) {
                            $upsell[$field]+=$row[match ($field) {'refund_amount'=>'raw_refund_amount','dispute_amount'=>'raw_dispute_amount',default=>$field}];
                        }
                    }
                }
            }
            if ($renewalIds) {
                $stats=LogisticsOrderPaymentReference::query()->whereIn('logistics_order_id',$renewalIds)->where('reference_type',LogisticsOrderPaymentReference::TYPE_UPSELL)
                    ->selectRaw('COUNT(*) as records, COUNT(DISTINCT logistics_order_id) as orders, SUM(CASE WHEN gateway_order_id IS NULL THEN 1 ELSE 0 END) as missing')->first();
                $upsell['references']+=(int)$stats->records;$upsell['reference_orders']+=(int)$stats->orders;$upsell['unmeasured']+=(int)$stats->missing;
            }
        }
        $upsell['linked_orders']=max($upsell['measured_orders'],$upsell['reference_orders']);
        foreach (['revenue','refund_amount','dispute_amount'] as $field) $upsell[$field]=round($upsell[$field],2);
        $numbers=[0=>true];
        foreach (array_keys($current) as $number) $numbers[$number]=true;
        foreach (array_keys($previous) as $number) $numbers[$number+1]=true;
        ksort($numbers,SORT_NUMERIC);
        $stages=[];
        foreach (array_keys($numbers) as $number) {
            $base=$number>0 ? ($previous[$number-1]['total'] ?? 0) : null;
            $totals=$current[$number]['totals'] ?? array_fill_keys($groups,$this->emptyRiskTotal());
            $by=[];
            foreach ($groups as $key) {
                $partBase=$number>0 ? ($previous[$number-1][$key] ?? 0) : null;
                $by[$key]=[...$this->finishTotal($totals[$key]),'previous_orders'=>$partBase,
                    'rate'=>$partBase>0 ? $this->rate($totals[$key]['orders'],$partBase) : null];
            }
            $stages[]=[...$this->finishTotal($totals['total']),'key'=>$number===0 ? 'initial' : 'renewal_'.$number,'number'=>$number,
                'previous_orders'=>$base,'rate'=>$base>0 ? $this->rate($totals['total']['orders'],$base) : null,
                'paid_from'=>$current[$number]['from'] ?? null,'paid_to'=>$current[$number]['to'] ?? null,'by_channel'=>$by,
                '_rounded'=>$current[$number]['rounded'] ?? $this->emptyRiskTotal(),
                '_channels'=>$current[$number]['channels'] ?? array_fill_keys(['paypal','credit_card','unknown'],$this->emptyRiskTotal())];
        }
        $initial=[];
        foreach ($first as $key=>&$types) {
            foreach ($types as &$total) $total=$this->finishTotal($total);
            unset($total);
            $initial[$key]=$types['all'];
        }
        unset($types);
        return ['calculation'=>$w['calculation'],'subscription_rate'=>$filters['first_only'] ? $this->subscriptionRate($w['from'],$w['to'],$filters) : [],
            'initial_refunds'=>$initial,'initial_refunds_by_type'=>$first,'stages'=>$stages,'stores'=>$index['stores'],'upsell'=>$upsell];
    }

    /** @return array<string,mixed> */
    private function paymentRow(LogisticsOrder $order, LogisticsOrder $initial, int $number, string $timezone, array $risk, string $purchaseType = 'subscription', array $identifiers = []): array
    {
        $refund = $risk['refunds'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0, 'details' => []];
        $dispute = $risk['disputes'][(int) $order->id] ?? ['count' => 0, 'amount' => 0.0, 'details' => []];
        $paid = $order->paid_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s');
        $primary = $this->uniqueIdentifiers($identifiers['primary_bills'] ?? []);
        $upsell = $this->uniqueIdentifiers($identifiers['upsell_bills'] ?? []);
        $legacyBill = trim((string)$order->bill_number);
        // Explicit upsell classification takes precedence over the legacy single bill field.
        if ($legacyBill !== '' && !in_array($legacyBill, $upsell, true)) {
            $primary = $this->uniqueIdentifiers([$legacyBill, ...$primary]);
        }
        // If a reference was recorded as both types, the explicit primary wins.
        $upsell = array_values(array_diff($upsell, $primary));
        return [
            'erp_order_id' => (int) $order->id,
            'stage_key' => $number === 0 ? 'initial' : 'renewal_'.$number, 'stage_number' => $number, 'purchase_type' => $purchaseType,
            'initial_channel' => $this->channelFor($initial), 'actual_channel' => $this->channelFor($order), 'payment_method' => $this->methodFor($order),
            'initial_order' => (string) ($initial->shopify_order_number ?: $initial->id),
            'renewal_order' => (string) ($order->shopify_order_number ?: $order->id),
            'store_name' => (string) ($order->store_name ?: $initial->store_name),
            'transaction_id' => (string) $order->transaction_id, 'paid_at' => $paid,
            'bill_number' => (string) $order->bill_number, 'buyer_email' => (string) $order->buyer_email,
            'transaction_ids' => $this->uniqueIdentifiers($identifiers['transactions'] ?? []),
            'primary_bill_numbers' => $primary, 'upsell_bill_numbers' => $upsell,
            'bill_numbers' => $this->uniqueIdentifiers([...$primary, ...$upsell]),
            'collection_account' => trim((string)$order->collection_account),
            'collection_account_email' => trim((string)$order->collection_account_email),
            'collection_accounts' => $this->uniqueIdentifiers([(string)$order->collection_account,(string)$order->collection_account_email]),
            'amount' => round((float) $order->paid_amount_usd, 2), 'raw_amount' => (float) $order->paid_amount_usd,
            'refund_count' => $refund['count'], 'refund_amount' => round($refund['amount'], 2), 'raw_refund_amount' => $refund['amount'], 'refund_details' => $refund['details'],
            'dispute_count' => $dispute['count'], 'dispute_amount' => round($dispute['amount'], 2), 'raw_dispute_amount' => $dispute['amount'], 'dispute_details' => $dispute['details'],
        ];
    }

    /** Load only explicit associations, in batches, without changing the selected order population. */
    private function paymentIdentifiers(array $orderIds): array
    {
        $result = [];
        foreach (array_chunk($orderIds, 500) as $chunk) {
            $references = LogisticsOrderPaymentReference::query()->whereIn('logistics_order_id', $chunk)
                ->orderByRaw('CASE WHEN reference_type = ? THEN 0 ELSE 1 END', [LogisticsOrderPaymentReference::TYPE_PRIMARY])
                ->orderBy('id')->get(['logistics_order_id','reference_number','reference_type']);
            foreach ($references as $reference) {
                $type = match ($reference->reference_type) {
                    LogisticsOrderPaymentReference::TYPE_PRIMARY => 'primary_bills',
                    LogisticsOrderPaymentReference::TYPE_UPSELL => 'upsell_bills',
                    default => null,
                };
                if ($type !== null) $result[(int)$reference->logistics_order_id][$type][] = (string)$reference->reference_number;
            }
            $payments = LogisticsPayPalTransaction::query()->whereIn('logistics_order_id', $chunk)
                ->where('event_code', 'like', 'T00%')->whereIn('status', ['S','V'])
                ->orderBy('id')->get(['logistics_order_id','payment_transaction_id']);
            foreach ($payments as $payment) {
                $result[(int)$payment->logistics_order_id]['transactions'][] = (string)$payment->payment_transaction_id;
            }
        }
        return $result;
    }

    private function uniqueIdentifiers(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string)$value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /** @return array<string,array<int,array{count:int,amount:float,details:list<array<string,mixed>>}>> */
    private function riskMaps(array $orderIds, array $filters, Carbon $from, Carbon $to, bool $includeDetails = true): array
    {
        $refunds = [];
        $disputes = [];
        if ($orderIds === []) return compact('refunds', 'disputes');

        $paypalRefundQuery = LogisticsPayPalRefund::query()->whereIn('logistics_order_id', $orderIds);
        $paypalDisputeQuery = LogisticsPayPalDispute::query()->whereIn('logistics_order_id', $orderIds);
        $cardQuery = LogisticsCreditCardRefund::query()->whereIn('logistics_order_id', $orderIds);
        if (($filters['risk_date_mode'] ?? 'payment') === 'event') {
            $paypalRefundQuery->whereBetween('refunded_at', [$from, $to]);
            $paypalDisputeQuery->whereBetween('dispute_created_at', [$from, $to]);
            $cardQuery->whereBetween('occurred_at', [$from, $to]);
        }

        foreach ($paypalRefundQuery->select($includeDetails ? ['id', 'logistics_order_id', 'refund_amount_usd', 'manual_reason', 'refund_reason', 'event_code', 'status', 'refunded_at'] : ['id','logistics_order_id','refund_amount_usd'])->lazyById(500) as $record) {
            $orderId = (int) $record->logistics_order_id;
            $this->accumulate($refunds, $orderId, (float) $record->refund_amount_usd);
            if ($includeDetails) $this->appendRiskDetail($refunds, $orderId, [
                'amount' => (float) $record->refund_amount_usd,
                'reason' => (string) ($record->manual_reason ?: $record->refund_reason ?: $record->eventLabel()),
                'status' => $record->statusLabel(),
                'time' => $record->refunded_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
            ]);
        }
        foreach ($paypalDisputeQuery->select($includeDetails ? ['id','logistics_order_id','dispute_amount_usd','original_amount','reason','status','dispute_created_at'] : ['id','logistics_order_id','dispute_amount_usd','original_amount'])->lazyById(500) as $record) {
            $orderId=(int)$record->logistics_order_id;
            $amount=(float)($record->dispute_amount_usd ?? $record->original_amount ?? 0);
            if (!isset($disputes[$orderId])) $disputes[$orderId]=['count'=>1,'amount'=>$amount,'details'=>[]];
            else $disputes[$orderId]['amount']=max($disputes[$orderId]['amount'],$amount);
            if ($includeDetails) $this->appendRiskDetail($disputes,$orderId,[
                'amount'=>$amount,'reason'=>$record->reasonLabel(),'status'=>$record->statusLabel(),
                'time'=>$record->dispute_created_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
            ]);
        }
        foreach ($cardQuery->where('refund_amount_usd', '>', 0)
            ->select($includeDetails ? ['id', 'logistics_order_id', 'refund_amount_usd', 'refund_reason', 'occurred_at'] : ['id','logistics_order_id','refund_amount_usd'])->lazyById(500) as $record) {
            $orderId = (int) $record->logistics_order_id;
            $amount = (float) $record->refund_amount_usd;
            // Each positive card record is refunded money, regardless of its reason.
            $this->accumulate($refunds, $orderId, $amount);
            if ($includeDetails) $this->appendRiskDetail($refunds, $orderId, [
                'amount' => $amount,
                'reason' => (string) $record->refund_reason,
                'status' => '-',
                'time' => $record->occurred_at?->copy()->setTimezone($filters['timezone'] ?? '+08:00')->format('Y-m-d H:i:s') ?: '-',
            ]);
        }
        return compact('refunds', 'disputes');
    }

    private function accumulate(array &$map, int $orderId, float $amount): void
    {
        $map[$orderId] ??= ['count' => 0, 'amount' => 0.0, 'details' => []];
        $map[$orderId]['count']++;
        $map[$orderId]['amount'] += $amount;
    }

    /** @param array<int,array{count:int,amount:float,details:list<array<string,mixed>>}> $map @param array<string,mixed> $detail */
    private function appendRiskDetail(array &$map, int $orderId, array $detail): void
    {
        $map[$orderId] ??= ['count' => 0, 'amount' => 0.0, 'details' => []];
        $map[$orderId]['details'][] = $detail;
    }

    /** @param array<string,mixed> $filters @return array<string,array{subscription_orders:int,total_orders:int,rate:float}> */
    private function subscriptionRate(Carbon $from, Carbon $to, array $filters): array
    {
        $query = LogisticsOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->whereIn('subscription_type', ['订阅首次购买', '无订阅'])
            ->where(function ($query): void {
                $query->whereNull('operator_name')->orWhere('operator_name', 'not like', '%刷单%');
            });

        if (($filters['store'] ?? '') !== '') {
            $query->where('store_name', (string) $filters['store']);
        }
        if (($filters['keyword'] ?? '') !== '') {
            $keyword = '%'.trim((string) $filters['keyword']).'%';
            $query->where(function ($query) use ($keyword): void {
                $query->where('shopify_order_number', 'like', $keyword)
                    ->orWhere('transaction_id', 'like', $keyword)
                    ->orWhere('buyer_email', 'like', $keyword);
            });
        }

        $orders = $query->select(['id', 'subscription_type', 'payment_gateway', 'payment_method', 'paypal_account_id', 'paypal_association_source'])->lazyById(500);
        $counts = array_fill_keys(array_keys(self::breakdownGroups()), ['subscription_orders'=>0, 'total_orders'=>0]);
        foreach ($orders as $order) {
            $channel = $this->channelFor($order);
            if (($filters['channel'] ?? '') !== '' && $channel !== $filters['channel']) {
                continue;
            }
            $row = ['actual_channel'=>$channel, 'payment_method'=>$this->methodFor($order)];
            foreach ($counts as $key => &$count) {
                if (!$this->matchesGroup($row, $key)) continue;
                $count['total_orders']++;
                if ($order->subscription_type === '订阅首次购买') $count['subscription_orders']++;
            }
            unset($count);
        }

        return array_map(fn (array $count): array => [
            ...$count, 'rate'=>$this->rate($count['subscription_orders'], $count['total_orders']),
        ], $counts);
    }

    /** @return list<string> */
    private function chainReferences(LogisticsOrder $order): array
    {
        return PaymentSubscriptionLifecycle::chainReferences($order->transaction_id, $order->shopify_order_id, $order->shopify_order_number, $order->payment_gateway_order_id, $order->bill_number, $order->id);
    }

    private function channelFor(LogisticsOrder $order): string
    {
        return match ($this->methodFor($order)) {
            'cc' => 'credit_card',
            'ap', 'gp', 'pp' => 'paypal',
            default => 'unknown',
        };
    }

    private function rate(float|int $numerator, float|int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 2) : 0.0;
    }

    /** @return array<string,int|float> */
    private function emptyRiskTotal(): array
    {
        return ['orders' => 0, 'revenue' => 0.0, 'refund_order_count' => 0, 'refund_count' => 0, 'refund_amount' => 0.0, 'dispute_count' => 0, 'dispute_amount' => 0.0];
    }
}
