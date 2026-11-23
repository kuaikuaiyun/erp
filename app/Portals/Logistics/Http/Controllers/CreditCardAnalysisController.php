<?php

namespace App\Portals\Logistics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AssistantDomainStore;
use App\Models\LogisticsCreditCardRefund;
use App\Models\LogisticsCreditCardRefundOperation;
use App\Models\LogisticsOrder;
use App\Portal\Http\Controllers\PortalEntryController;
use App\Portals\Logistics\CreditCardAnalysisService;
use App\Portals\Logistics\CreditCardAnalysisExportService;
use App\Portals\Logistics\LogisticsCreditCardRefundReason;
use App\Portals\Logistics\LogisticsPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CreditCardAnalysisController extends Controller
{
    public function __construct(
        private readonly PortalEntryController $portalEntries,
        private readonly LogisticsPortalService $logistics,
        private readonly CreditCardAnalysisService $analysis,
        private readonly CreditCardAnalysisExportService $exports,
    ) {}

    public function index(Request $request): Response|View
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $filters = $this->analysis->filters($request);
        $records = $this->analysis->query($filters)->with(CreditCardAnalysisService::RELATIONS)
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate($filters['per_page'])->withQueryString();
        $context['portal']->loadMissing('menus');
        $reasons = array_values(array_unique([...LogisticsCreditCardRefundReason::allowed(), ...LogisticsCreditCardRefund::query()
            ->whereNotNull('refund_reason')->where('refund_reason','!=','')->distinct()->pluck('refund_reason')->all()]));
        return view('portals.logistics.credit-card-analysis.index', [
            ...$context, 'records'=>$records, 'filters'=>$filters, 'analysis'=>$this->analysis,
            'stores'=>AssistantDomainStore::query()->whereIn('id',LogisticsOrder::query()
                ->whereIn('id',LogisticsCreditCardRefund::query()->select('logistics_order_id'))->select('assistant_domain_store_id'))
                ->orderBy('shop_name')->get(['id','shop_name','store_prefix']),
            'reasons'=>$reasons, 'categories'=>__('credit_card_analysis.categories'),
            'subscriptionTypes'=>LogisticsOrder::query()->whereNotNull('subscription_type')->where('subscription_type','!=','')->distinct()->orderBy('subscription_type')->pluck('subscription_type'),
        ]);
    }

    public function handle(Request $request, LogisticsCreditCardRefund $refund): Response|RedirectResponse
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $data = $request->validate([
            'subscription_cancelled'=>['nullable','in:yes,no'], 'add_on_cancelled'=>['nullable','in:yes,no'],
            'note'=>['nullable','string','max:1000'], 'return_to'=>['nullable','string','max:4000'],
        ], [], [
            'subscription_cancelled'=>__('credit_card_analysis.fields.subscription_cancelled'),
            'add_on_cancelled'=>__('credit_card_analysis.fields.add_on_cancelled'),
            'note'=>__('credit_card_analysis.fields.note'), 'return_to'=>__('credit_card_analysis.fields.return_to'),
        ]);
        DB::connection($refund->getConnectionName())->transaction(function () use ($refund,$data,$context): void {
            $record = LogisticsCreditCardRefund::query()->lockForUpdate()->findOrFail($refund->getKey());
            $changes = [];
            foreach (['subscription_cancelled','add_on_cancelled'] as $key) if (($data[$key] ?? '') !== '' && ($data[$key] ?? null) !== null) $changes[$key] = $data[$key] === 'yes';
            if ($record->first_handled_at === null) $changes += ['first_handled_at'=>now(),'first_handled_by'=>$context['user']->getKey(),'first_handled_by_name'=>$context['user']->name];
            if ($changes !== []) $record->forceFill($changes)->save();
            LogisticsCreditCardRefundOperation::query()->create([
                'logistics_credit_card_refund_id'=>$record->getKey(), 'operator_id'=>$context['user']->getKey(), 'operator_name'=>$context['user']->name,
                'action'=>'manual_handle', 'payload'=>[
                    'subscription_cancelled'=>$data['subscription_cancelled'] ?? null, 'add_on_cancelled'=>$data['add_on_cancelled'] ?? null,
                    'note'=>trim((string) ($data['note'] ?? '')) ?: null,
                ],
            ]);
        });
        $default = route('logistics.credit-card-analysis.index');
        $returnTo = trim((string) ($data['return_to'] ?? ''));
        $candidate = parse_url($returnTo);
        $expected = parse_url($default);
        $safe = is_array($candidate) && !isset($candidate['user']) && !isset($candidate['pass'])
            && ($candidate['scheme'] ?? null) === ($expected['scheme'] ?? null)
            && ($candidate['host'] ?? null) === ($expected['host'] ?? null)
            && ($candidate['port'] ?? null) === ($expected['port'] ?? null)
            && ($candidate['path'] ?? null) === ($expected['path'] ?? null);
        return redirect()->to($safe ? $returnTo : $default)->with('success',__('credit_card_analysis.saved'));
    }

    public function export(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) return $context;
        $data = $request->validate([
            'scope'=>['required','in:selected,filtered'], 'selected_ids'=>['required_if:scope,selected','array','min:1','max:100'],
            'selected_ids.*'=>['integer','distinct','min:1'],
        ], ['selected_ids.required_if'=>__('credit_card_analysis.validation.select_required')], [
            'scope'=>__('credit_card_analysis.export_scope'), 'selected_ids'=>__('credit_card_analysis.fields.selected_ids'),
            'selected_ids.*'=>__('credit_card_analysis.fields.selected_ids'),
        ]);
        $query = $this->analysis->query($this->analysis->filters($request));
        if ($data['scope'] === 'selected') $query->whereKey($data['selected_ids']);
        if (!(clone $query)->exists()) throw ValidationException::withMessages(['scope'=>__('credit_card_analysis.validation.scope_empty')]);
        return $this->exports->download($query);
    }

    private function context(Request $request): array|Response
    {
        $context = $this->portalEntries->portalContext($request);
        if ($context instanceof Response) return $context;
        $this->logistics->assertLogisticsPortal($context['portal']);
        return $context;
    }
}
