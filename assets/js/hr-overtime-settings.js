(function () {
    'use strict';

    var page = document.querySelector('.hr-ot-set-page');
    if (!page) return;

    var configForm = document.getElementById(page.getAttribute('data-config-form-id') || 'hr-ot-config-form');
    if (!configForm) return;

    function submitConfigSave() {
        if (typeof configForm.reportValidity === 'function' && !configForm.reportValidity()) {
            return;
        }
        if (typeof configForm.requestSubmit === 'function') {
            configForm.requestSubmit();
        } else {
            configForm.submit();
        }
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        if ((bar ? bar.getAttribute('data-active-route') : '') !== 'hr_overtime_settings') return;

        if (e.detail.action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitConfigSave();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            submitConfigSave();
        }
    });
})();
