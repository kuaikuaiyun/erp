@extends('portal.layout')

@php
    $translationPrefix = 'payment.product_alerts';
    $portalOrigin = rtrim(request()->getSchemeAndHttpHost(), '/');
    $kpiPresentation = [
        'to_assign' => ['icon' => 'fa-user-plus', 'tone' => 'red'],
        'in_progress' => ['icon' => 'fa-cogs', 'tone' => 'orange'],
        'pending_review' => ['icon' => 'fa-eye', 'tone' => 'indigo'],
        'overdue' => ['icon' => 'fa-clock-o', 'tone' => 'red'],
    ];
@endphp

@section('page_title', __($translationPrefix.'.page_title'))

@push('styles')
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-portal.css?v=20261026-1">
    <link rel="stylesheet" href="{{ $portalOrigin }}/assets/erp/payment-shared.css?v=20261026-1">
@endpush

@section('content')
<section class="content payment-portal-page payment-product-alerts-page">
    @if (session('success'))<div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> {{ __('payment.product_alerts.rule_modal.validation_failed') }}</div>@endif

    <div class="payment-alert-work-view payment-alert-work-view-{{ $assignmentContext['view'] }}"><i class="fa {{ $assignmentContext['view'] === 'management' ? 'fa-users' : 'fa-user' }}"></i><div><strong>{{ __($translationPrefix.'.assignment.views.'.$assignmentContext['view'].'.title') }}</strong><span>{{ __($translationPrefix.'.assignment.views.'.$assignmentContext['view'].'.description') }}</span></div></div>

    <div class="box box-solid payment-filter-box">
        <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-filter"></i> {{ __('payment.common.filter_title') }}</h3></div>
        <div class="box-body"><form class="payment-operation-filter-form" data-payment-filter-form>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.status') }}</span><select class="form-control" data-payment-filter="status"><option value="">{{ __($translationPrefix.'.filters.all_statuses') }}</option>@foreach (['to_assign', 'in_progress', 'pending_review'] as $status)<option value="{{ $status }}">{{ __($translationPrefix.'.statuses.'.$status) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.owner') }}</span><select class="form-control" data-payment-filter="owner"><option value="">{{ __($translationPrefix.'.filters.all_owners') }}</option><option value="to_assign">{{ __($translationPrefix.'.owners.to_assign') }}</option>@foreach(collect($assignmentContext['departments'])->pluck('members')->flatten(1)->unique('id') as $member)<option value="{{ $member['id'] }}">{{ $member['name'] }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.channel') }}</span><select class="form-control" data-payment-filter="channel"><option value="">{{ __($translationPrefix.'.filters.all_channels') }}</option>@foreach (['paypal_renewal', 'card_one_time', 'all_subscription'] as $channel)<option value="{{ $channel }}">{{ __($translationPrefix.'.channels.'.$channel) }}</option>@endforeach</select></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.product') }}</span><input class="form-control" type="search" data-payment-filter="product" placeholder="{{ __($translationPrefix.'.filters.product_placeholder') }}"></label>
            <label class="payment-operation-filter-field"><span>{{ __($translationPrefix.'.filters.only_overdue') }}</span><select class="form-control" data-payment-filter="overdue"><option value="">{{ __($translationPrefix.'.filters.only_overdue') }}</option><option value="yes">{{ __($translationPrefix.'.filters.overdue') }}</option></select></label>
            <div class="payment-operation-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> {{ __('payment.common.query') }}</button><button class="btn btn-default" type="reset">{{ __('payment.common.reset') }}</button></div>
        </form></div>
    </div>

    <aside class="payment-alert-guidance"><div><i class="fa fa-lightbulb-o" aria-hidden="true"></i><p><strong>{{ __($translationPrefix.'.guidance.title') }}</strong><span>{{ __($translationPrefix.'.guidance.description') }}</span></p></div><button type="button" class="btn btn-primary" data-payment-alert-rules><i class="fa fa-cog" aria-hidden="true"></i> {{ __($translationPrefix.'.rules.open') }}</button></aside>

    <div class="payment-kpi-grid payment-kpi-grid-four">
        @foreach ($dashboard['kpis'] as $kpi)
            @php($presentation = $kpiPresentation[$kpi['key']])
            <article class="payment-kpi-card payment-tone-{{ $kpi['tone'] ?? 'primary' }}"><span class="payment-kpi-icon payment-kpi-icon-{{ $presentation['tone'] }}"><i class="fa {{ $presentation['icon'] }}"></i></span><div><p>{{ __($translationPrefix.'.kpis.'.$kpi['key']) }}</p><strong>{{ $kpi['value'] }}{{ __($translationPrefix.'.order_unit') }}</strong><small>{{ __($translationPrefix.'.kpis.'.$kpi['note_key']) }}</small></div></article>
        @endforeach
    </div>

    <article class="payment-panel payment-alert-list-panel">
        <h2>{{ __($translationPrefix.'.list_title') }}</h2>
        <div class="table-responsive"><table class="table payment-data-table payment-alert-table"><thead><tr>@foreach (['alert_product', 'channel', 'sample', 'threshold', 'owner', 'status_deadline', 'progress', 'action'] as $column)<th>{{ __($translationPrefix.'.table.'.$column) }}</th>@endforeach</tr></thead><tbody>
        @foreach ($dashboard['alerts'] as $alert)
            <tr class="payment-alert-severity-{{ $alert['severity_key'] }}" tabindex="0" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}" data-payment-filterable data-status="{{ $alert['status_key'] }}" data-owner="{{ $alert['owner_filter'] ?? $alert['owner_key'] }}" data-channel="{{ $alert['channel_key'] }}" data-product="{{ __($translationPrefix.'.products.'.$alert['product_key']) }}" data-overdue="{{ !empty($alert['deadline']) && strtotime($alert['deadline']) < time() ? 'yes' : 'no' }}">
                <td><span class="payment-alert-severity-badge payment-alert-severity-badge-{{ $alert['severity_key'] }}"><i class="fa fa-circle"></i> {{ __($translationPrefix.'.severity.'.$alert['severity_key']) }}</span><strong>{{ $alert['code'] }}</strong><br><small>{{ __($translationPrefix.'.products.'.$alert['product_key']) }}</small></td>
                <td>{{ __($translationPrefix.'.channels.'.$alert['channel_key']) }}</td>
                <td>{{ $alert['sample'] }}<br><small>{{ __($translationPrefix.'.sample_note') }}</small></td>
                <td><span class="payment-alert-threshold payment-alert-threshold-{{ $alert['severity_key'] }}">{!! nl2br(e(__($translationPrefix.'.thresholds.'.$alert['threshold_key']))) !!}</span></td>
                <td>{{ $alert['owner_name'] ?? __($translationPrefix.'.owners.'.$alert['owner_key']) }}<br><small>{{ $alert['owner_note'] ?? __($translationPrefix.'.owner_note.'.$alert['owner_key']) }}</small></td>
                <td><span class="payment-table-status payment-table-status-{{ $alert['status_key'] }}">{{ __($translationPrefix.'.statuses.'.$alert['status_key']) }}</span><br><small>{{ $alert['deadline'] ?? __($translationPrefix.'.deadlines.'.$alert['deadline_key']) }}</small></td>
                <td>{!! nl2br(e(__($translationPrefix.'.progress.'.$alert['action_key']))) !!}</td>
                <td>
                    @if ($assignmentContext['can_assign'])
                        <button type="button" class="btn btn-primary btn-sm payment-alert-action-button" data-payment-alert-assignment data-alert-code="{{ $alert['code'] }}" data-alert-product="{{ __($translationPrefix.'.products.'.$alert['product_key']) }}" data-assignment="{{ json_encode($alert['assignment'] ?? [], JSON_UNESCAPED_UNICODE) }}">
                            {{ isset($alert['assignment']) ? __($translationPrefix.'.assignment.reassign') : __($translationPrefix.'.actions.assign_owner') }} <i class="fa fa-angle-right"></i>
                        </button>
                    @else
                        <button type="button" class="btn btn-default btn-sm payment-alert-action-button" data-payment-alert-detail="{{ route('payment.product-alerts.show', ['alert' => $alert['code']]) }}">
                            {{ __($translationPrefix.'.actions.'.$alert['action_key']) }} <i class="fa fa-angle-right"></i>
                        </button>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody></table></div>
        <p class="payment-empty-filter" data-payment-filter-empty hidden>{{ __('payment.common.empty_filter') }}</p>
    </article>
    @include('portals.payment.partials.pagination', ['pagination' => $dashboard['pagination'], 'routeName' => 'payment.product-alerts.index'])

    <div class="modal fade payment-alert-detail-modal" id="payment-alert-detail-modal" tabindex="-1" role="dialog" aria-labelledby="payment-alert-detail-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.modals.close') }}"><span>&times;</span></button><h4 id="payment-alert-detail-modal-title">{{ __($translationPrefix.'.detail_modal.title') }}</h4><p>{{ __($translationPrefix.'.detail_modal.subtitle') }}</p></div><div class="modal-body"><div class="payment-detail-state" data-payment-alert-detail-loading><i class="fa fa-spinner fa-spin"></i> {{ __($translationPrefix.'.detail_modal.loading') }}</div><div class="payment-detail-state payment-detail-error" data-payment-alert-detail-error hidden></div><div data-payment-alert-detail-body></div></div><div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.modals.close') }}</button></div></div></div></div>

    <?php if ($assignmentContext['can_assign']) { ?>
    <div class="modal fade payment-alert-assignment-modal" id="payment-alert-assignment-modal" tabindex="-1" role="dialog" aria-labelledby="payment-alert-assignment-title" aria-hidden="true"><div class="modal-dialog" role="document"><form class="modal-content" method="post" data-payment-assignment-form>
        @csrf @method('PUT')
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.modals.close') }}"><span>&times;</span></button><h4 id="payment-alert-assignment-title">{{ __($translationPrefix.'.assignment.title') }}</h4><p data-payment-assignment-summary></p></div>
        <div class="modal-body"><div class="payment-assignment-grid">
            <label><span>{{ __($translationPrefix.'.assignment.department') }}</span><select class="form-control" name="department_id" required data-payment-assignment-department><option value="">{{ __($translationPrefix.'.assignment.department_placeholder') }}</option>
                @foreach ($assignmentContext['departments'] as $department)
                    <option value="{{ $department['id'] }}">{{ $department['name'] }}</option>
                @endforeach
            </select></label>
            <label><span>{{ __($translationPrefix.'.assignment.assignee') }}</span><select class="form-control" name="assignee_id" required data-payment-assignment-user><option value="">{{ __($translationPrefix.'.assignment.assignee_placeholder') }}</option>
                @foreach ($assignmentContext['departments'] as $department)
                    @foreach ($department['members'] as $member)
                        <option value="{{ $member['id'] }}" data-department-id="{{ $department['id'] }}">{{ $member['name'] }} · {{ __($translationPrefix.'.assignment.positions.'.$member['position']) }}</option>
                    @endforeach
                @endforeach
            </select></label>
            <label><span>{{ __($translationPrefix.'.assignment.deadline') }}</span><input class="form-control" type="datetime-local" name="deadline_at" required></label>
            <label class="payment-assignment-note"><span>{{ __($translationPrefix.'.assignment.note') }}</span><textarea class="form-control" name="assignment_note" maxlength="1000" rows="3" placeholder="{{ __($translationPrefix.'.assignment.note_placeholder') }}"></textarea></label>
        </div><p class="payment-assignment-scope-note"><i class="fa fa-info-circle"></i> {{ __($translationPrefix.'.assignment.scope_note') }}</p></div>
        <div class="modal-footer"><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.modals.close') }}</button><button class="btn btn-primary" type="submit"><i class="fa fa-check"></i> {{ __($translationPrefix.'.assignment.save') }}</button></div>
    </form></div></div>
    <?php } ?>

    <div class="modal fade payment-alert-modal payment-alert-rules-modal" id="payment-alert-rules-modal" tabindex="-1" role="dialog" aria-labelledby="payment-alert-rules-title" aria-hidden="true"><div class="modal-dialog modal-lg" role="document"><form class="modal-content" method="post" action="{{ route('payment.product-alerts.rules.update') }}">
        @csrf @method('PUT')
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="{{ __($translationPrefix.'.modals.close') }}"><span>&times;</span></button><h4 id="payment-alert-rules-title">{{ __($translationPrefix.'.rule_modal.title') }}</h4><p>{{ __($translationPrefix.'.rule_modal.subtitle') }}</p></div>
        <div class="modal-body"><div class="payment-alert-rule-layout"><article><h5>{{ __($translationPrefix.'.rule_modal.table_title') }}</h5><div class="table-responsive"><table class="table payment-detail-table payment-rule-edit-table"><thead><tr>@foreach(['rule','yellow','red','minimum_sample','sample_action','enabled'] as $column)<th>{{ __($translationPrefix.'.rule_modal.columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @foreach($alertRules as $rule)<tr><td><strong>{{ $rule->name }}</strong><small>{{ $rule->code }}</small></td><td><input class="form-control" name="rules[{{ $rule->id }}][yellow_condition]" maxlength="255" required value="{{ old('rules.'.$rule->id.'.yellow_condition', $rule->yellow_condition) }}"></td><td><input class="form-control" name="rules[{{ $rule->id }}][red_condition]" maxlength="255" required value="{{ old('rules.'.$rule->id.'.red_condition', $rule->red_condition) }}"></td><td><input class="form-control payment-rule-sample-input" type="number" min="0" max="1000000" name="rules[{{ $rule->id }}][minimum_sample]" required value="{{ old('rules.'.$rule->id.'.minimum_sample', $rule->minimum_sample) }}"></td><td><input class="form-control" name="rules[{{ $rule->id }}][action_text]" maxlength="500" value="{{ old('rules.'.$rule->id.'.action_text', $rule->action_text) }}"></td><td><input type="hidden" name="rules[{{ $rule->id }}][is_enabled]" value="0"><label class="payment-rule-switch"><input type="checkbox" name="rules[{{ $rule->id }}][is_enabled]" value="1" @checked((bool) old('rules.'.$rule->id.'.is_enabled', $rule->is_enabled))><span></span></label></td></tr>@endforeach
        </tbody></table></div></article><aside><h5>{{ __($translationPrefix.'.rule_modal.account_title') }}</h5>@foreach(['restriction','reserve','overdue','funding','recent'] as $item)<div class="payment-account-rule payment-account-rule-{{ $item }}"><strong>{{ __($translationPrefix.'.rule_modal.account_rules.'.$item.'.title') }}</strong><p>{{ __($translationPrefix.'.rule_modal.account_rules.'.$item.'.description') }}</p></div>@endforeach</aside></div>
        <article class="payment-alert-system-behavior"><h5>{{ __($translationPrefix.'.rule_modal.behavior_title') }}</h5><div>@foreach(['explain','assign','save','review'] as $index => $step)<section><b>{{ $index + 1 }}</b><strong>{{ __($translationPrefix.'.rule_modal.behavior.'.$step.'.title') }}</strong><p>{{ __($translationPrefix.'.rule_modal.behavior.'.$step.'.description') }}</p></section>@endforeach</div></article></div>
        <div class="modal-footer"><span>{{ __($translationPrefix.'.rule_modal.save_note') }}</span><button class="btn btn-default" type="button" data-dismiss="modal">{{ __($translationPrefix.'.modals.close') }}</button><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> {{ __($translationPrefix.'.rule_modal.save') }}</button></div>
    </form></div></div>
</section>
@endsection

@push('scripts')
<script>window.PaymentPortal = { actionTitle: @json(__('payment.common.mock_action_title')), actionMessage: @json(__('payment.common.mock_action_message')), alertModal: @json(__($translationPrefix.'.modals')), alertDetail: @json(__($translationPrefix.'.detail_modal')), assignmentUrl: @json(route('payment.product-alerts.assignment.update', ['alert' => '__ALERT__'])), openAlertRules: @json(request()->boolean('open_rules')) };</script>
<script src="{{ $portalOrigin }}/assets/erp/payment-portal.js?v=20261026-1"></script>
@if ($errors->any())<script>jQuery(function () { jQuery('#payment-alert-rules-modal').modal('show'); });</script>@endif
@endpush
