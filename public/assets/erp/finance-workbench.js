(function () {
    'use strict';
    var root = document.getElementById('finance-workbench');
    if (!root) return;
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-balance-tab]'));
    function activate(tab) {
        var name = tab.getAttribute('data-balance-tab');
        tabs.forEach(function (button) {
            var active = button === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
            document.getElementById(button.getAttribute('aria-controls')).hidden = !active;
        });
        root.querySelectorAll('[data-balance-tab-input]').forEach(function (input) { input.value = name; });
        var url = new URL(window.location.href);
        url.searchParams.set('balance_tab', name);
        window.history.replaceState(window.history.state, '', url);
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () { activate(tab); });
        tab.addEventListener('keydown', function (event) {
            var next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            activate(tabs[next]);
            tabs[next].focus();
        });
    });
})();
