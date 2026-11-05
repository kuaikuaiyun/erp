(function () {
    'use strict';
    var root = document.querySelector('[data-loss-browser]');
    if (!root) return;
    var monthFrom = document.getElementById('loss-month-from');
    var monthTo = document.getElementById('loss-month-to');
    if (monthFrom && monthTo) {
        monthFrom.addEventListener('change', function () { monthTo.min = monthFrom.value; });
        monthTo.addEventListener('change', function () { monthFrom.max = monthTo.value; });
    }
    var panels = JSON.parse(root.querySelector('[data-loss-panels]').textContent);
    var scopes = root.querySelector('.loss-scope-tabs');
    var categories = root.querySelector('.loss-category-tabs');
    var results = root.querySelector('.loss-comparison');
    var title = root.querySelector('.loss-selection');
    var period = title.querySelector('span');
    var categoriesByScope = JSON.parse(root.querySelector('[data-loss-categories]').textContent);

    function activate(url, push) {
        var scope = url.searchParams.get('loss_scope');
        var validScopes = Array.from(scopes.querySelectorAll('a')).map(function (link) { return link.textContent; });
        if (!validScopes.includes(scope)) scope = validScopes[0];
        var options = categoriesByScope[scope] || ['总计'];
        var category = url.searchParams.get('loss_category');
        if (!options.includes(category)) category = options[0];
        url.searchParams.set('loss_scope', scope);
        url.searchParams.set('loss_category', category);
        scopes.querySelectorAll('a').forEach(function (link) {
            var active = link.textContent === scope;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        categories.replaceChildren();
        options.forEach(function (name) {
            var link = document.createElement('a');
            var target = new URL(url);
            target.searchParams.set('loss_category', name);
            link.href = target.href;
            link.textContent = name;
            if (name === category) {
                link.className = 'is-active';
                link.setAttribute('aria-current', 'page');
            }
            categories.append(link);
        });
        title.replaceChildren(document.createTextNode(scope + ' / ' + category), period);
        results.replaceChildren();
        panels.filter(function (panel) {
            return panel.scope === scope && panel.category === category;
        }).forEach(function (panel) {
            var box = document.createElement('div');
            box.className = 'box loss-month-panel';
            box.append(root.querySelector('#' + panel.template).content.cloneNode(true));
            results.append(box);
        });
        if (!results.children.length) {
            var empty = document.createElement('div');
            empty.className = 'box overview-empty';
            empty.textContent = '当前分类暂无数据，可切换分类或调整月份与筛选条件';
            results.append(empty);
        }
        document.querySelector('[name="loss_scope"]').value = scope;
        document.querySelector('[name="loss_category"]').value = category;
        if (push) window.history.pushState(null, '', url);
    }

    root.addEventListener('click', function (event) {
        var link = event.target.closest('.loss-scope-tabs a, .loss-category-tabs a');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        activate(new URL(link.href), true);
    });
    window.addEventListener('popstate', function () { activate(new URL(window.location.href), false); });
}());
