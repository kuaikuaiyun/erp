<div class="flt" data-frontend-list-template>
    <div hidden data-flt-texts>{{ json_encode(__('frontend_list_template'), JSON_UNESCAPED_UNICODE) }}</div>
    <form class="flt-card flt-filters" data-flt-filter data-loading="off">
        <div class="flt-filter-grid">
            <div><span class="flt-field-label" id="flt-company-label">{{ __('frontend_list_template.company_multi') }}</span><div data-flt-picker="companies" data-multiple="1" aria-labelledby="flt-company-label"></div></div>
            <div><span class="flt-field-label" id="flt-status-label">{{ __('frontend_list_template.status_single') }}</span><select name="status" data-flt-status hidden><option value="">{{ __('frontend_list_template.all') }}</option>@foreach (__('frontend_list_template.statuses') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select><div data-flt-picker="statuses" aria-labelledby="flt-status-label"></div></div>
            <label>{{ __('frontend_list_template.keyword') }}<input name="keyword" type="search" maxlength="100" placeholder="{{ __('frontend_list_template.keyword_placeholder') }}"></label>
            <div><span class="flt-field-label" id="flt-department-label">{{ __('frontend_list_template.department_search_multi') }}</span><div data-flt-picker="departments" data-multiple="1" data-searchable="1" aria-labelledby="flt-department-label"></div></div>
            <div><span class="flt-field-label" id="flt-owner-label">{{ __('frontend_list_template.owner_search_single') }}</span><div data-flt-picker="owners" data-searchable="1" aria-labelledby="flt-owner-label"></div></div>
            <div class="flt-time-range"><label>{{ __('frontend_list_template.start') }}<input name="start" type="datetime-local"></label><label>{{ __('frontend_list_template.end') }}<input name="end" type="datetime-local"></label></div>
        </div>
        <div class="flt-filter-actions"><button type="submit" class="flt-btn flt-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}</button><button type="reset" class="flt-btn">{{ __('frontend_list_template.reset') }}</button></div>
    </form>
    <section class="flt-stat-grid" aria-label="{{ __('frontend_list_template.stats_title') }}">
        <article class="flt-stat" data-stat-tone="blue"><div><h3>{{ __('frontend_list_template.stats_all') }}</h3><p><strong data-flt-stat="all">0</strong><span>{{ __('frontend_list_template.stats_unit') }}</span></p><small>{{ __('frontend_list_template.stats_all_hint') }}</small></div><span class="flt-stat-icon"><i class="fa fa-list-alt" aria-hidden="true"></i></span></article>
        <article class="flt-stat" data-stat-tone="green"><div><h3>{{ __('frontend_list_template.stats_active') }}</h3><p><strong data-flt-stat="active">0</strong><span>{{ __('frontend_list_template.stats_unit') }}</span></p><small>{{ __('frontend_list_template.stats_active_hint') }}</small></div><span class="flt-stat-icon"><i class="fa fa-check-circle-o" aria-hidden="true"></i></span></article>
        <article class="flt-stat" data-stat-tone="orange"><div><h3>{{ __('frontend_list_template.stats_pending') }}</h3><p><strong data-flt-stat="pending">0</strong><span>{{ __('frontend_list_template.stats_unit') }}</span></p><small>{{ __('frontend_list_template.stats_pending_hint') }}</small></div><span class="flt-stat-icon"><i class="fa fa-clock-o" aria-hidden="true"></i></span></article>
        <article class="flt-stat" data-stat-tone="gray"><div><h3>{{ __('frontend_list_template.stats_disabled') }}</h3><p><strong data-flt-stat="disabled">0</strong><span>{{ __('frontend_list_template.stats_unit') }}</span></p><small>{{ __('frontend_list_template.stats_disabled_hint') }}</small></div><span class="flt-stat-icon"><i class="fa fa-pause-circle-o" aria-hidden="true"></i></span></article>
        <article class="flt-stat" data-stat-tone="cyan"><div><h3>{{ __('frontend_list_template.stats_selected') }}</h3><p><strong data-flt-stat="selected">0</strong><span>{{ __('frontend_list_template.stats_unit') }}</span></p><small>{{ __('frontend_list_template.stats_selected_hint') }}</small></div><span class="flt-stat-icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span></article>
    </section>
    <details class="flt-guidance">
        <summary>
            <i class="fa fa-info-circle" aria-hidden="true"></i>
            <strong>{{ __('frontend_list_template.guidance_title', ['count'=>count(__('frontend_list_template.guidance_items'))]) }}</strong>
            <span class="flt-guidance-toggle"><span class="flt-guidance-expand">{{ __('frontend_list_template.guidance_expand') }}</span><span class="flt-guidance-collapse">{{ __('frontend_list_template.guidance_collapse') }}</span></span>
        </summary>
        <ul class="flt-guidance-items">
            @foreach (__('frontend_list_template.guidance_items') as $tip)
                <li><strong>{{ $tip['title'] }}</strong>{{ $tip['body'] }}
                    @if (!empty($tip['tags']))
                        <span class="flt-guidance-tags">
                            @foreach ($tip['tags'] as $tag)
                                <span class="flt-roi-tag" data-roi-tone="{{ $tag['tone'] }}">{{ $tag['text'] }}</span>
                            @endforeach
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
    <section class="flt-card flt-records">
        <div class="flt-toolbar">
            <div class="flt-selection"><span data-flt-total></span><span class="flt-toolbar-divider" aria-hidden="true"></span><span data-flt-selection-count></span><button type="button" data-flt-clear-selected>{{ __('frontend_list_template.clear_selected') }}</button></div>
            <div class="flt-toolbar-actions">
                <button type="button" class="flt-btn flt-primary" data-flt-create><i class="fa fa-plus" aria-hidden="true"></i> {{ __('frontend_list_template.create') }}</button>
                <button type="button" class="flt-btn flt-danger-outline" data-flt-bulk-delete disabled><i class="fa fa-trash-o" aria-hidden="true"></i> {{ __('frontend_list_template.bulk_delete') }}</button>
                <button type="button" class="flt-btn" data-flt-import><i class="fa fa-upload" aria-hidden="true"></i> {{ __('frontend_list_template.import') }}</button>
                <button type="button" class="flt-btn" data-flt-export><i class="fa fa-download" aria-hidden="true"></i> {{ __('frontend_list_template.export') }}</button>
                <button type="button" class="flt-btn" data-flt-restore><i class="fa fa-refresh" aria-hidden="true"></i> {{ __('frontend_list_template.restore') }}</button>
            </div>
        </div>
        <div class="flt-table-region" data-flt-region aria-busy="false"><div class="flt-loading" data-flt-loading role="status" hidden><span class="flt-spinner" aria-hidden="true"></span><span>{{ __('frontend_list_template.loading') }}</span></div><div class="flt-table-scroll"><table><caption class="flt-sr-only">{{ __('frontend_list_template.table_caption') }}</caption><thead><tr><th class="flt-check"><input type="checkbox" data-flt-all aria-label="{{ __('frontend_list_template.check_page') }}"></th>@foreach (__('frontend_list_template.columns') as $key => $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead><tbody data-flt-rows></tbody></table>
        <div class="flt-empty-host" data-flt-empty-host hidden><div class="flt-empty" data-flt-empty hidden><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('frontend_list_template.empty_title') }}</h4><p>{{ __('frontend_list_template.empty_hint') }}</p></div></div></div>
        </div>
        <nav class="flt-pagination" aria-label="{{ __('frontend_list_template.pagination') }}">
            <label>{{ __('frontend_list_template.per_page') }} <select data-flt-size aria-label="{{ __('frontend_list_template.page_size') }}"><option>20</option><option selected>50</option><option>100</option></select></label>
            <button type="button" class="flt-btn" data-flt-previous>{{ __('frontend_list_template.previous') }}</button><strong data-flt-page-label></strong><button type="button" class="flt-btn" data-flt-next>{{ __('frontend_list_template.next') }}</button>
            <form data-flt-jump data-loading="off" novalidate><label>{{ __('frontend_list_template.jump_to') }} <input type="number" min="1" step="1" required placeholder="{{ __('frontend_list_template.page_placeholder') }}" aria-label="{{ __('frontend_list_template.page_placeholder') }}"></label><button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button></form>
        </nav>
    </section>
    <dialog class="flt-dialog" data-flt-dialog aria-labelledby="flt-dialog-title">
        <form data-flt-editor data-loading="off" novalidate><header data-flt-dialog-header><h3 id="flt-dialog-title"></h3><button type="button" class="flt-icon-close" data-flt-close aria-label="{{ __('frontend_list_template.close') }}">×</button></header>
            <div class="flt-dialog-content" data-flt-dialog-content></div>
            <footer><button type="button" class="flt-btn" data-flt-close>{{ __('frontend_list_template.cancel') }}</button><button type="submit" class="flt-btn flt-primary" data-flt-dialog-submit></button></footer>
        </form>
    </dialog>
</div>
