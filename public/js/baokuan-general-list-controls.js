(() => {
    'use strict';

    const pickers = [...document.querySelectorAll('[data-baokuan-list-picker]')];
    for (const picker of pickers) {
        const summary = picker.querySelector('summary');
        const search = picker.querySelector('[data-picker-search]');
        const options = [...picker.querySelectorAll('[data-picker-option]')];
        const empty = picker.querySelector('[data-picker-empty]');

        function filterOptions() {
            const keyword = search?.value.trim().toLocaleLowerCase() || '';
            let count = 0;
            for (const option of options) {
                const key = option.textContent + ' ' + option.dataset.pickerKeywords;
                option.hidden = !key.toLocaleLowerCase().includes(keyword);
                if (!option.hidden) count++;
            }
            empty.hidden = count !== 0;
        }

        function updateSummary() {
            const selected = picker.querySelector('input[type=radio]:checked');
            if (!selected) return;
            summary.textContent = selected.closest('label').querySelector('span').textContent;
            summary.title = summary.textContent;
        }

        search?.addEventListener('input', filterOptions);
        picker.addEventListener('change', (event) => {
            if (!event.target.matches('input[type=radio]')) return;
            updateSummary();
            picker.open = false;
            summary.focus();
        });
        picker.addEventListener('toggle', () => {
            if (!picker.open) return;
            for (const other of pickers) {
                if (other !== picker) other.open = false;
            }
            if (search) {
                search.value = '';
                search.focus();
            }
            filterOptions();
        });
        picker.addEventListener('keydown', (event) => {
            if (!picker.open || event.key !== 'Escape') return;
            event.preventDefault();
            event.stopPropagation();
            picker.open = false;
            summary.focus();
        });
        window.addEventListener('pageshow', updateSummary);
        updateSummary();
    }

    document.addEventListener('click', (event) => {
        for (const picker of pickers) {
            if (!picker.contains(event.target)) picker.open = false;
        }
    });
    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-baokuan-list-page-size]')) event.target.form.requestSubmit();
    });
})();
