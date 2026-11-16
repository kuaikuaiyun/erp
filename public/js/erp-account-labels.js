(function () {
    'use strict';
    if (window.ErpAccountLabels) return;
    var dialog;
    function makeDialog() {
        if (dialog && dialog.isConnected) return dialog;
        dialog = document.createElement('dialog');
        dialog.className = 'erp-account-label-dialog';
        var header = document.createElement('header'),
            title = document.createElement('h4'),
            close = document.createElement('button'),
            list = document.createElement('ul');
        title.id = 'erp-account-label-title';
        close.type = 'button';
        close.textContent = '×';
        close.onclick = function () {
            dialog.close();
        };
        header.append(title, close);
        dialog.append(header, list);
        dialog.setAttribute('aria-labelledby', title.id);
        document.body.append(dialog);
        return dialog;
    }
    function render(parent, labels, title) {
        parent.replaceChildren();
        var group = document.createElement('span');
        group.className = 'erp-account-tags';
        group.dataset.accountTags = JSON.stringify(labels);
        group.dataset.tagTitle = title;
        labels.slice(0, 2).forEach(function (label) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'erp-account-tag';
            b.textContent = label;
            b.title = label;
            group.append(b);
        });
        if (labels.length > 2) {
            var more = document.createElement('button');
            more.type = 'button';
            more.className = 'erp-account-more';
            more.textContent = '＋' + (labels.length - 2);
            group.append(more);
        }
        parent.append(group);
    }
    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-account-tags] button');
        if (!target) return;
        event.preventDefault();
        var group = target.closest('[data-account-tags]'),
            d = makeDialog();
        d.querySelector('h4').textContent = group.dataset.tagTitle;
        var source = document.querySelector('[data-ding-messages]');
        if (source) {
            var m = JSON.parse(source.textContent);
            d.querySelector('header button').setAttribute('aria-label', m.close);
        }
        var list = d.querySelector('ul');
        list.replaceChildren();
        JSON.parse(group.dataset.accountTags).forEach(function (text) {
            var li = document.createElement('li');
            li.textContent = text;
            list.append(li);
        });
        if (!d.open) d.showModal();
    });
    window.ErpAccountLabels = { render: render };
})();
