(function () {
    'use strict';

    function checkboxes(picker) {
        return Array.prototype.slice.call(picker.querySelectorAll('.assistant-team-picker-option input[type="checkbox"]'));
    }

    function closePicker(picker) {
        var toggle = picker.querySelector('[data-team-picker-toggle]');
        var menu = picker.querySelector('[data-team-picker-menu]');

        picker.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        menu.hidden = true;
    }

    function updateSummary(picker) {
        var selected = checkboxes(picker).filter(function (input) { return input.checked; });
        var summary = picker.querySelector('[data-team-picker-summary]');
        var emptyLabel = picker.getAttribute('data-empty-label') || '';
        var selectedLabel = picker.getAttribute('data-selected-label') || '__count__';
        var names = selected.map(function (input) { return input.value; });

        if (names.length === 0) {
            summary.textContent = emptyLabel;
            summary.removeAttribute('title');
            return;
        }

        summary.textContent = names.length <= 2
            ? names.join('、')
            : selectedLabel.replace('__count__', String(names.length));
        summary.setAttribute('title', names.join('、'));
    }

    function initialize(picker) {
        if (picker.getAttribute('data-team-picker-ready') === '1') {
            return;
        }

        picker.setAttribute('data-team-picker-ready', '1');

        var toggle = picker.querySelector('[data-team-picker-toggle]');
        var menu = picker.querySelector('[data-team-picker-menu]');
        var clear = picker.querySelector('[data-team-picker-clear]');

        toggle.addEventListener('click', function () {
            var opening = !picker.classList.contains('is-open');

            document.querySelectorAll('[data-team-picker].is-open').forEach(function (otherPicker) {
                if (otherPicker !== picker) {
                    closePicker(otherPicker);
                }
            });

            if (opening) {
                picker.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
                menu.hidden = false;
            } else {
                closePicker(picker);
            }
        });

        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        checkboxes(picker).forEach(function (input) {
            input.addEventListener('change', function () {
                updateSummary(picker);
            });
        });

        clear.addEventListener('click', function () {
            checkboxes(picker).forEach(function (input) {
                input.checked = false;
            });
            updateSummary(picker);
        });

        updateSummary(picker);
    }

    document.addEventListener('click', function (event) {
        document.querySelectorAll('[data-team-picker].is-open').forEach(function (picker) {
            if (!picker.contains(event.target)) {
                closePicker(picker);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('[data-team-picker].is-open').forEach(closePicker);
    });

    function boot() {
        document.querySelectorAll('[data-team-picker]').forEach(initialize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
