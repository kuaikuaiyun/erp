(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    ready(function () {
        var form = document.getElementById('ot-dashboard-form');
        if (!form) return;

        var from = form.querySelector('[name=from]');
        var to = form.querySelector('[name=to]');
        var iso = function (date) { return date.toISOString().slice(0, 10); };

        document.querySelectorAll('[data-range]').forEach(function (button) {
            button.addEventListener('click', function () {
                var now = new Date();
                var key = button.getAttribute('data-range');
                if (key === 'today') from.value = to.value = iso(now);
                if (key === 'yesterday') {
                    now.setDate(now.getDate() - 1);
                    from.value = to.value = iso(now);
                }
                if (key === 'week') {
                    var start = new Date();
                    start.setDate(start.getDate() - 6);
                    from.value = iso(start);
                    to.value = iso(now);
                }
                if (key === 'all') {
                    from.value = '2000-01-01';
                    to.value = iso(now);
                }
                form.submit();
            });
        });

        document.querySelectorAll('[data-dashboard-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                var tab = button.getAttribute('data-dashboard-tab');
                document.querySelectorAll('[data-dashboard-tab]').forEach(function (node) {
                    node.classList.toggle('is-active', node === button);
                });
                document.querySelectorAll('[data-dashboard-pane]').forEach(function (node) {
                    node.classList.toggle('is-active', node.getAttribute('data-dashboard-pane') === tab);
                });
            });
        });

        var planTeam = document.getElementById('ot-plan-team');
        var planUser = document.getElementById('ot-plan-user');
        var planMode = document.getElementById('ot-plan-mode');
        if (planTeam && planUser) {
            planTeam.addEventListener('change', function () {
                Array.prototype.forEach.call(planUser.options, function (option, index) {
                    if (index) option.hidden = option.getAttribute('data-team') !== planTeam.value;
                });
                planUser.value = '';
            });
        }
        if (planMode) {
            var refreshTargets = function () {
                document.querySelectorAll('.ot-plan-custom').forEach(function (field) {
                    var enabled = planMode.value === 'mixed';
                    field.hidden = !enabled;
                    field.querySelector('input').required = enabled;
                });
            };
            planMode.addEventListener('change', refreshTargets);
            refreshTargets();
        }
    });
}());
