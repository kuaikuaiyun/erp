@php
    $reportHeaders = [...$definition['headers'], __($definition['channel'] === 'paypal' ? 'finance.fields.paypal_account' : 'finance.fields.account'), __('finance.fields.category'), __('finance.fields.display_time'), __('finance.fields.timezone')];
@endphp
@push('styles')
<style>
.finance-activity{color:#111;margin-bottom:22px;background:#f0f2f9}.finance-activity-heading{display:flex;align-items:center;gap:12px;margin:0;padding:8px 0 22px;font-size:36px;font-weight:400;line-height:1.4}.finance-activity-star{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.5}.finance-activity-body{background:#fff;padding:28px 28px 12px;font-size:13px}.finance-activity-description{margin:0;line-height:1.8}.finance-activity-controls{background:#f5f7fa;padding:10px 12px 12px}.finance-activity-row{display:flex;align-items:center;gap:15px;flex-wrap:wrap}.finance-activity-field{position:relative;display:block;margin:0;width:210px;min-height:64px;border:1px solid #ccc;border-radius:13px;background:#fff;font-weight:400}.finance-activity-field span{position:absolute;left:11px;top:8px;pointer-events:none;font-size:12px;color:#333}.finance-activity-field select{appearance:none;-webkit-appearance:none;width:100%;height:62px;padding:27px 36px 9px 11px;border:0;border-radius:13px;background:transparent;color:#111;cursor:pointer;font-size:14px;line-height:20px}.finance-activity-field:after{content:'';position:absolute;right:24px;top:24px;width:11px;height:11px;border-right:1.5px solid #666;border-bottom:1.5px solid #666;transform:rotate(45deg);pointer-events:none}.finance-activity-field--dates{width:232px}.finance-activity-field--format{width:200px}.finance-activity-create{margin-left:5px;border:0;border-radius:22px;padding:8px 16px;background:#000;color:#fff;font-size:12px;line-height:18px;white-space:nowrap}.finance-activity-create:disabled{cursor:not-allowed;opacity:.45}.finance-activity-field select:focus-visible,.finance-activity-create:focus-visible,.finance-activity-fields summary:focus-visible{outline:2px solid #0070e0;outline-offset:3px}.finance-activity-fields{margin:0 0 0 11px}.finance-activity-fields summary{display:inline-block;color:#0070e0;font-size:16px;font-weight:600;cursor:pointer;list-style:none;line-height:1.6}.finance-activity-fields summary::-webkit-details-marker{display:none}.finance-activity-column-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px 18px;padding:14px 0 4px}.finance-activity-column-list label{display:flex;align-items:flex-start;gap:7px;margin:0;font-size:13px;font-weight:400}.finance-activity-custom-dates{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0}.finance-activity-custom-dates[hidden]{display:none}.finance-activity-custom-dates label{font-weight:400}.finance-activity-custom-dates input{display:block;border:1px solid #ccc;border-radius:6px;padding:7px;margin-top:4px;background:#fff}.finance-activity-updated{margin:0;padding-top:1px;line-height:1.8}.finance-activity-selection{margin:3px 0 0;color:#697586;font-size:12px}@media(max-width:767px){.finance-activity-heading{font-size:28px;padding-bottom:16px}.finance-activity-body{padding:20px 16px 12px}.finance-activity-row{gap:10px}.finance-activity-field,.finance-activity-field--dates,.finance-activity-field--format{width:100%}.finance-activity-create{margin:2px 0 4px}.finance-activity-column-list{grid-template-columns:1fr}}
</style>
@endpush
<section class="finance-activity" aria-labelledby="finance-activity-title">
    <h2 class="finance-activity-heading" id="finance-activity-title">{{ __('finance.records.activity_title') }} <svg class="finance-activity-star" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2.5 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3.1-5.8 3.1 1.1-6.5-4.7-4.6 6.5-.9Z"/></svg></h2>
    <div class="finance-activity-body">
        <p class="finance-activity-description">{{ __('finance.records.activity_description') }}</p>
        <form id="finance-activity-report" method="get" action="{{ route('finance.records.export', $dataset) }}" data-loading="{{ ($accountActivityMode ?? false) ? 'off' : 'download' }}">
            <input type="hidden" name="timezone" value="{{ $filters['timezone'] }}">
            <input type="hidden" name="export_scope" value="accounts" data-report-scope>
            <input type="hidden" name="report_fields_customized" value="1">
            @foreach(['account', 'reference'] as $field)
                @if(!empty($filters[$field]))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
            @endforeach
            @foreach($filters['conditions'] ?? [] as $index => $condition)
                @foreach($condition as $part => $content)<input type="hidden" name="conditions[{{ $index }}][{{ $part }}]" value="{{ $content }}">@endforeach
            @endforeach
            <div data-report-selection></div>
            @if($accountActivityMode ?? false)<input type="hidden" name="account" data-activity-report-account><input type="hidden" name="selected_accounts[]" data-activity-selected-account>@endif
            <div class="finance-activity-controls">
                <div class="finance-activity-row">
                    <label class="finance-activity-field"><span>{{ __('finance.fields.transaction_scope') }}</span><select name="type_category"><option value="">{{ __('finance.records.activity_all_types') }}</option>@foreach($activityCategories as $category)<option value="{{ $category }}">{{ \App\Portals\Finance\FinanceCatalog::simplified((string) ($category)) }}</option>@endforeach</select></label>
                    <label class="finance-activity-field finance-activity-field--format"><span>{{ __('finance.records.activity_format') }}</span><select name="export_format"><option value="xlsx">XLSX</option><option value="xls">XLS</option></select></label>
                    <button class="finance-activity-create" data-export-submit disabled aria-describedby="finance-report-selection-hint">{{ __('finance.records.activity_create') }}</button>
                </div>
                <div class="finance-activity-custom-dates" id="finance-report-custom-dates">
                    <label>{{ __('finance.fields.from') }}<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" required></label>
                    <label>{{ __('finance.fields.to') }}<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" min="{{ $filters['from'] ?? '' }}" required></label>
                </div>
                <details class="finance-activity-fields"><summary>{{ __('finance.records.activity_custom_fields') }}</summary><div class="finance-activity-column-list">
                    @foreach($reportHeaders as $index => $header)<label><input type="checkbox" name="report_columns[]" value="{{ $index }}" checked> {{ \App\Portals\Finance\FinanceCatalog::simplified($header) }}</label>@endforeach
                </div></details>
            </div>
        </form>
        <p class="finance-activity-updated">{{ __('finance.records.activity_updated') }} <span data-activity-last-updated>@if($lastUpdatedAt)<time>{{ \Carbon\CarbonImmutable::parse($lastUpdatedAt, 'UTC')->setTimezone($filters['timezone'])->format('Y年m月d日 H:i:s P') }}</time>@else{{ __('finance.records.activity_no_data') }}@endif</span></p>
        <p class="finance-activity-selection" id="finance-report-selection-hint">{{ __(($accountActivityMode ?? false) ? 'finance.activity.account_selection' : 'finance.records.activity_selection') }}</p>
    </div>
</section>
@push('scripts')
<script>
(function(){
    var form=document.getElementById('finance-activity-report');
    var from=form.elements.namedItem('from'),to=form.elements.namedItem('to');
    from.addEventListener('change',function(){to.min=from.value;});
    form.addEventListener('submit',function(event){
        if(!form.querySelector('input[name="report_columns[]"]:checked')){
            event.preventDefault();form.querySelector('details').open=true;form.querySelector('input[name="report_columns[]"]').focus();
        }
    });
})();
</script>
@endpush
