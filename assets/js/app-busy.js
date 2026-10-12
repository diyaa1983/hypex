(function (global) {
    'use strict';

    var depth = 0;
    var busyEl = null;
    var msgEl = null;
    var DEFAULT_MSG = 'جاري التنفيذ...';
    var DEFAULT_HINT = 'يرجى الانتظار — لا تغلق المتصفح حتى انتهاء العملية';

    function ensureDom() {
        if (busyEl) {
            return;
        }
        busyEl = document.getElementById('app-busy');
        msgEl = document.getElementById('app-busy-msg');
    }

    function dispatchBusyEvent(active) {
        try {
            document.dispatchEvent(
                new CustomEvent('manager:invoice-busy', { detail: { busy: !!active } })
            );
        } catch (e) {
            /* ignore */
        }
    }

    function render(active) {
        ensureDom();
        if (!busyEl) {
            return;
        }
        if (active) {
            busyEl.hidden = false;
            busyEl.removeAttribute('hidden');
            document.body.classList.add('app-is-busy');
        } else {
            busyEl.hidden = true;
            busyEl.setAttribute('hidden', '');
            document.body.classList.remove('app-is-busy');
        }
    }

    function setMessage(message) {
        ensureDom();
        if (msgEl && message) {
            msgEl.textContent = message;
        }
    }

    function show(message) {
        ensureDom();
        depth += 1;
        if (msgEl && message) {
            msgEl.textContent = message;
        } else if (msgEl && depth === 1) {
            msgEl.textContent = DEFAULT_MSG;
        }
        render(true);
        dispatchBusyEvent(true);
    }

    function hide(force) {
        if (force) {
            depth = 0;
        } else if (depth > 0) {
            depth -= 1;
        }
        if (depth <= 0) {
            depth = 0;
            render(false);
            dispatchBusyEvent(false);
        }
    }

    function isActive() {
        return depth > 0;
    }

    function setSaveBusy(busy, message, saveAction) {
        var action = saveAction || 'save';
        var saveBtn = document.querySelector('#master-toolbar [data-master-action="' + action + '"]');
        if (saveBtn) {
            saveBtn.disabled = !!busy;
        }
        // أزرار شريط مستندات المبيعات (طلب شراء / فاتورة)
        document.querySelectorAll('#co-doc-bar .si-tb, #si-doc-bar .si-tb').forEach(function (btn) {
            if (!btn) return;
            if (busy) {
                if (!btn.hasAttribute('data-app-busy-was')) {
                    btn.setAttribute('data-app-busy-was', btn.disabled ? '1' : '0');
                }
                btn.disabled = true;
            } else {
                var was = btn.getAttribute('data-app-busy-was');
                if (was != null) {
                    btn.disabled = was === '1';
                    btn.removeAttribute('data-app-busy-was');
                }
            }
        });
        if (busy) {
            show(message || 'جاري الحفظ...');
        } else {
            hide(true);
        }
    }

    function wrapFetch(input, init, message) {
        show(message || DEFAULT_MSG);
        return fetch(input, init).finally(function () {
            hide();
        });
    }

    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) {
            return;
        }
        var form = e.target;
        if (!form || !form.classList || !form.classList.contains('master-page-form')) {
            return;
        }
        if (form.getAttribute('data-app-busy-skip') === '1') {
            return;
        }
        var msg = form.getAttribute('data-app-busy-msg') || 'جاري الحفظ...';
        show(msg);
    }, false);

    document.addEventListener('manager:show-busy', function (e) {
        var detail = e && e.detail ? e.detail : {};
        show(detail.message || DEFAULT_MSG);
    });

    document.addEventListener('manager:hide-busy', function () {
        hide();
    });

    global.AppBusy = {
        show: show,
        hide: hide,
        isActive: isActive,
        setMessage: setMessage,
        setSaveBusy: setSaveBusy,
        wrapFetch: wrapFetch,
        DEFAULT_MSG: DEFAULT_MSG,
        DEFAULT_HINT: DEFAULT_HINT,
    };
})(typeof window !== 'undefined' ? window : self);
