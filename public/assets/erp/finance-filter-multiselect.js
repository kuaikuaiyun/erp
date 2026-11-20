(function () {
    'use strict';
    document.querySelectorAll('[data-finance-filter-picker]').forEach(function (picker) {
        var caption = picker.querySelector('[data-filter-caption]');
        var choices = picker.querySelector('[data-filter-choices]');
        var loading = false, loaded = false;
        function update() {
            var labels = Array.from(choices.querySelectorAll('input:checked'), function (input) { return input.dataset.filterLabel; });
            caption.textContent = labels.length ? labels.join('、') : picker.dataset.placeholder;
            caption.title = caption.textContent;
        }
        picker.addEventListener('change', update);
        picker.querySelector('[data-filter-clear]').addEventListener('click', function () {
            choices.querySelectorAll('input').forEach(function (input) { input.checked = false; });
            update();
        });
        picker.addEventListener('toggle', function () {
            if (!picker.open) return;
            document.querySelectorAll('[data-finance-filter-picker]').forEach(function (other) { if (other !== picker) other.open = false; });
            if (!picker.dataset.optionsUrl || loading || loaded) return;
            loading = true;
            fetch(picker.dataset.optionsUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
                .then(function (response) { if (!response.ok) throw new Error('Unable to load filter options'); return response.json(); })
                .then(function (values) {
                    var known = new Set(Array.from(choices.querySelectorAll('input'), function (input) { return input.value; }));
                    values.forEach(function (value) {
                        if (!value || value === '未匹配' || known.has(value)) return;
                        var label = document.createElement('label'), input = document.createElement('input'), text = document.createElement('span');
                        input.type = 'checkbox'; input.name = choices.dataset.name + '[]'; input.value = value; input.dataset.filterLabel = value;
                        text.textContent = value; label.append(input, text); choices.append(label); known.add(value);
                    });
                    loaded = true;
                }).catch(function () {}).finally(function () { loading = false; });
        });
        document.addEventListener('click', function (event) { if (!picker.contains(event.target)) picker.open = false; });
        picker.addEventListener('keydown', function (event) { if (event.key === 'Escape') { picker.open = false; caption.focus(); } });
        update();
    });
}());
