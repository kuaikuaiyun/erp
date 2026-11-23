<div class="flt" data-frontend-list-template>
    <div hidden data-flt-texts>{{ json_encode(__('frontend_list_template'), JSON_UNESCAPED_UNICODE) }}</div>
    <section class="flt-hero">
        <div><span class="flt-eyebrow">{{ __('frontend_list_template.eyebrow') }}</span><h2>{{ __('frontend_list_template.heading') }}</h2><p>{{ __('frontend_list_template.intro') }}</p></div>
        <span class="flt-demo"><i class="fa fa-flask" aria-hidden="true"></i> {{ __('frontend_list_template.demo') }}</span>
    </section>
    <p class="flt-notice"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ __('frontend_list_template.notice') }}</p>
    <form class="flt-card flt-filters" data-flt-filter data-loading="off">
        <div class="flt-filter-grid">
            <div><span class="flt-field-label" id="flt-company-label">{{ __('frontend_list_template.company_multi') }}</span><div data-flt-picker="companies" data-multiple="1" aria-labelledby="flt-company-label"></div></div>
            <label>{{ __('frontend_list_template.status_single') }}<select name="status"><option value="">{{ __('frontend_list_template.all') }}</option>@foreach (__('frontend_list_template.statuses') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label>{{ __('frontend_list_template.keyword') }}<input name="keyword" type="search" maxlength="100" placeholder="{{ __('frontend_list_template.keyword_placeholder') }}"></label>
            <div><span class="flt-field-label" id="flt-department-label">{{ __('frontend_list_template.department_search_multi') }}</span><div data-flt-picker="departments" data-multiple="1" data-searchable="1" aria-labelledby="flt-department-label"></div></div>
            <div><span class="flt-field-label" id="flt-owner-label">{{ __('frontend_list_template.owner_search_single') }}</span><div data-flt-picker="owners" data-searchable="1" aria-labelledby="flt-owner-label"></div></div>
            <div class="flt-time-range"><label>{{ __('frontend_list_template.start') }}<input name="start" type="datetime-local"></label><label>{{ __('frontend_list_template.end') }}<input name="end" type="datetime-local"></label></div>
        </div>
        <div class="flt-filter-actions"><button type="submit" class="flt-btn flt-primary"><i class="fa fa-search" aria-hidden="true"></i> {{ __('frontend_list_template.query') }}</button><button type="reset" class="flt-btn">{{ __('frontend_list_template.reset') }}</button></div>
    </form>
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
        <div class="flt-table-region" data-flt-region aria-busy="false"><div class="flt-loading" data-flt-loading role="status" hidden><span class="flt-spinner" aria-hidden="true"></span><span>{{ __('frontend_list_template.loading') }}</span></div><div class="flt-table-scroll"><table><caption class="flt-sr-only">{{ __('frontend_list_template.table_caption') }}</caption><thead><tr><th class="flt-check"><input type="checkbox" data-flt-all aria-label="{{ __('frontend_list_template.check_page') }}"></th>@foreach (__('frontend_list_template.columns') as $key => $label)<th scope="col">{{ $label }}</th>@endforeach</tr></thead><tbody data-flt-rows></tbody></table></div>
        <div class="flt-empty" data-flt-empty hidden><i class="fa fa-search" aria-hidden="true"></i><h4>{{ __('frontend_list_template.empty_title') }}</h4><p>{{ __('frontend_list_template.empty_hint') }}</p></div>
        </div>
        <nav class="flt-pagination" aria-label="{{ __('frontend_list_template.pagination') }}">
            <label>{{ __('frontend_list_template.per_page') }} <select data-flt-size aria-label="{{ __('frontend_list_template.page_size') }}"><option>20</option><option selected>50</option><option>100</option></select></label>
            <button type="button" class="flt-btn" data-flt-previous>{{ __('frontend_list_template.previous') }}</button><strong data-flt-page-label></strong><button type="button" class="flt-btn" data-flt-next>{{ __('frontend_list_template.next') }}</button>
            <form data-flt-jump data-loading="off" novalidate><label>{{ __('frontend_list_template.jump_to') }} <input type="number" min="1" step="1" required placeholder="{{ __('frontend_list_template.page_placeholder') }}" aria-label="{{ __('frontend_list_template.page_placeholder') }}"></label><button type="submit" class="flt-btn flt-primary">{{ __('frontend_list_template.jump') }}</button></form>
        </nav>
    </section>
    <dialog class="flt-dialog" data-flt-dialog aria-labelledby="flt-dialog-title">
        <form data-flt-editor data-loading="off" novalidate><header><h3 id="flt-dialog-title"></h3><button type="button" class="flt-icon-close" data-flt-close aria-label="{{ __('frontend_list_template.close') }}">×</button></header>
            <div class="flt-dialog-content" data-flt-dialog-content></div>
            <footer><button type="button" class="flt-btn" data-flt-close>{{ __('frontend_list_template.cancel') }}</button><button type="submit" class="flt-btn flt-primary" data-flt-dialog-submit></button></footer>
        </form>
    </dialog>
</div>
