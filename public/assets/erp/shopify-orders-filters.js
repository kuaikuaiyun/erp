(function () {
    const searchableFields = new Set([
        'store_name', 'owner_assignment', 'group_names', 'source_shop_domain',
        'subscription_type', 'payment_method', 'payment_gateway', 'collection_account',
        'logistics_status', 'data_status',
    ]);
    const initialized = new WeakSet();

    function init(form) {
        if (initialized.has(form)) return;
        initialized.add(form);
        const pickers = [];
        const advanced = form.querySelector('[data-orders-advanced]');
        const advancedToggle = form.querySelector('[data-orders-advanced-toggle]');
        const advancedCount = advancedToggle?.querySelector('[data-orders-advanced-count]');
        const advancedInputs = advanced ? Array.from(advanced.querySelectorAll('input[name], select[name]')) : [];

        function updateAdvancedCount() {
            if (!advancedCount) return;
            const count = advancedInputs.filter(field => field.value.trim() !== '').length;
            advancedCount.textContent = advancedCount.dataset.template.replace('__count__', count);
            advancedCount.hidden = count === 0;
        }

        advanced?.addEventListener('input', updateAdvancedCount);
        advanced?.addEventListener('change', updateAdvancedCount);
        advancedToggle?.addEventListener('click', function () {
            const expanded = advanced.hidden;
            advanced.hidden = !expanded;
            advancedToggle.setAttribute('aria-expanded', String(expanded));
            if (!expanded) closeOtherPickers();
        });

        function closeOtherPickers(current) {
            pickers.forEach(function (picker) {
                if (picker !== current) picker.open = false;
            });
        }

        form.querySelectorAll('.flt-filter-grid select').forEach(function (select) {
            const fieldLabel = select.labels[0];
            const emptyOption = Array.from(select.options).find(option => option.value === '');
            if (!fieldLabel || !emptyOption) return;

            const picker = document.createElement('details');
            picker.className = 'flt-picker';
            const summary = document.createElement('summary');
            summary.id = select.id + '-summary';
            fieldLabel.id = select.id + '-label';
            summary.setAttribute('aria-labelledby', fieldLabel.id + ' ' + summary.id);

            const panel = document.createElement('div');
            panel.className = 'flt-picker-panel';
            const search = (select.hasAttribute('data-filter-searchable') || searchableFields.has(select.name)) ? document.createElement('input') : null;
            if (search) {
                search.type = 'search';
                search.placeholder = form.dataset.searchOptions;
                search.setAttribute('aria-label', form.dataset.searchOptions);
                search.addEventListener('input', drawOptions);
                panel.append(search);
            }

            const options = document.createElement('div');
            options.className = 'flt-picker-options';
            options.setAttribute('role', 'group');
            options.setAttribute('aria-labelledby', fieldLabel.id);
            const clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'flt-picker-clear';
            clear.textContent = form.dataset.clearChoice;
            clear.addEventListener('click', () => choose(''));
            panel.append(options, clear);

            function updateSummary() {
                const selected = select.selectedOptions[0] || emptyOption;
                summary.textContent = selected.label;
                summary.title = selected.label;
            }

            function choose(value) {
                select.value = value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                picker.open = false;
                summary.focus();
            }

            function drawOptions() {
                const query = search ? search.value.trim().toLocaleLowerCase() : '';
                const visible = Array.from(select.options).filter(function (option) {
                    return option.value !== '' && option.label.toLocaleLowerCase().includes(query);
                });

                options.replaceChildren();
                if (!visible.length) {
                    const message = document.createElement('p');
                    message.textContent = form.dataset.noOptions;
                    options.append(message);
                }

                visible.forEach(function (option) {
                    const label = document.createElement('label');
                    const radio = document.createElement('input');
                    const text = document.createElement('span');
                    radio.type = 'radio';
                    radio.checked = option.selected;
                    radio.disabled = option.disabled;
                    radio.addEventListener('click', () => choose(option.value));
                    text.textContent = option.label;
                    label.append(radio, text);
                    options.append(label);
                });
            }

            picker.addEventListener('toggle', function () {
                if (!picker.open) return;
                closeOtherPickers(picker);
                if (search) search.value = '';
                drawOptions();
                if (search) search.focus();
            });
            picker.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && event.target === search) event.preventDefault();
                if (event.key !== 'Escape' || !picker.open) return;
                event.preventDefault();
                picker.open = false;
                summary.focus();
            });
            select.addEventListener('change', updateSummary);
            fieldLabel.addEventListener('click', function (event) {
                event.preventDefault();
                picker.open = !picker.open;
                summary.focus();
            });

            updateSummary();
            picker.append(summary, panel);
            select.after(picker);
            select.hidden = true;
            pickers.push(picker);
        });
    }

    document.querySelectorAll('[data-logistics-filters]').forEach(init);
    document.addEventListener('logistics:initialize-filters', function (event) {
        if (event.target.matches('[data-logistics-filters]')) init(event.target);
    });

    document.addEventListener('click', function (event) {
        document.querySelectorAll('[data-logistics-filters] .flt-picker[open]').forEach(function (picker) {
            if (!picker.parentElement.contains(event.target)) picker.open = false;
        });
    });

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-logistics-filters] .flt-picker[open]').forEach(function (picker) {
            picker.open = false;
        });
        document.querySelectorAll('[data-logistics-filters] .flt-filter-grid select[hidden]').forEach(function (select) {
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
}());
