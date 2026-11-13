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
