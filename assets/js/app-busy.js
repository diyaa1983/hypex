(function (global) {
    'use strict';

    var depth = 0;
    var busyEl = null;
    var msgEl = null;
    var showTimer = null;
    var DEFAULT_MSG = 'جاري التنفيذ…';
    var DEFAULT_HINT = 'يرجى الانتظار — لا تغلق المتصفح حتى انتهاء العملية';
    var REPORT_MSG = 'جاري تحميل التقرير…';
    var NAV_MSG = 'جاري التحميل…';

    function ensureDom() {
        if (busyEl && msgEl) {
            return;
        }
        busyEl = document.getElementById('app-busy');
        msgEl = document.getElementById('app-busy-msg');
        if (!busyEl && document.body) {
            busyEl = document.createElement('div');
            busyEl.id = 'app-busy';
            busyEl.className = 'app-busy no-print';
            busyEl.setAttribute('hidden', '');
            busyEl.setAttribute('aria-live', 'polite');
            busyEl.setAttribute('aria-busy', 'true');
            busyEl.innerHTML =
                '<div class="app-busy-panel" role="status">' +
                '<div class="app-busy-spinner" aria-hidden="true"></div>' +
                '<p class="app-busy-msg" id="app-busy-msg">' +
                DEFAULT_MSG +
                '</p>' +
                '<p class="app-busy-hint">' +
                DEFAULT_HINT +
                '</p>' +
                '</div>';
            document.body.appendChild(busyEl);
            msgEl = document.getElementById('app-busy-msg');
        }
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

    function lockScroll(active) {
        try {
            if (active) {
                if (document.documentElement.getAttribute('data-busy-scroll') == null) {
                    document.documentElement.setAttribute(
                        'data-busy-scroll',
                        String(window.scrollY || window.pageYOffset || 0)
                    );
                }
                document.documentElement.classList.add('app-busy-scroll-lock');
            } else {
                var y = document.documentElement.getAttribute('data-busy-scroll');
                document.documentElement.classList.remove('app-busy-scroll-lock');
                document.documentElement.removeAttribute('data-busy-scroll');
                if (y != null && y !== '') {
                    window.scrollTo(0, Number(y) || 0);
                }
            }
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
            busyEl.classList.add('is-visible');
            document.body.classList.add('app-is-busy');
            document.documentElement.classList.add('hypex-app-busy');
            lockScroll(true);
        } else {
            busyEl.classList.remove('is-visible');
            busyEl.hidden = true;
            busyEl.setAttribute('hidden', '');
            document.body.classList.remove('app-is-busy');
            document.documentElement.classList.remove('hypex-app-busy');
            lockScroll(false);
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
        if (showTimer) {
            clearTimeout(showTimer);
            showTimer = null;
        }
        // إظهار فوري تقريباً مع إطار رسم واحد لتقليل الارتجاج
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(function () {
                render(true);
            });
        } else {
            render(true);
        }
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
            // اختفاء سلس بعد إطارين لتجنّب وميض المحتوى
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () {
                        render(false);
                    });
                });
            } else {
                render(false);
            }
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
            show(message || 'جاري الحفظ…');
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

    function isSkippedForm(form) {
        if (!form) return true;
        if (form.getAttribute('data-app-busy-skip') === '1') return true;
        if (form.getAttribute('data-busy-skip') === '1') return true;
        var action = String(form.getAttribute('action') || '');
        if (/logout/i.test(action)) return true;
        return false;
    }

    function isReportLikeForm(form) {
        if (!form || !form.classList) return false;
        if (form.classList.contains('report-sales-filters')) return true;
        if (form.classList.contains('si-search')) return true;
        if (form.classList.contains('master-page-form')) return true;
        if (form.id === 'ora-rep-filter') return true;
        if (form.closest && form.closest('.si-report-page, .report-sales-page, .si-stage')) return true;
        if (form.querySelector && form.querySelector('input[name="run"], button[type="submit"]')) {
            var method = String(form.method || 'get').toLowerCase();
            if (method === 'post') return true;
        }
        return false;
    }

    function messageForForm(form) {
        if (!form) return DEFAULT_MSG;
        var custom = form.getAttribute('data-app-busy-msg') || form.getAttribute('data-busy-msg');
        if (custom) return custom;
        if (form.classList && form.classList.contains('master-page-form')) return 'جاري الحفظ…';
        if (isReportLikeForm(form)) return REPORT_MSG;
        return DEFAULT_MSG;
    }

    function markNavBusyStorage() {
        try {
            sessionStorage.setItem('hypex:nav-busy', String(Date.now()));
        } catch (e) {
            /* ignore */
        }
        try {
            document.documentElement.classList.add('hypex-nav-busy');
        } catch (e2) {
            /* ignore */
        }
    }

    document.addEventListener(
        'submit',
        function (e) {
            if (e.defaultPrevented) return;
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            if (isSkippedForm(form)) return;
            // كل إرسال نموذج ينقّل الصفحة أو يعيد تحميل التقرير
            var msg = messageForForm(form);
            markNavBusyStorage();
            show(msg);
        },
        true
    );

    document.addEventListener(
        'click',
        function (e) {
            if (e.defaultPrevented) return;
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var btn = e.target && e.target.closest
                ? e.target.closest(
                      'button[type="submit"], input[type="submit"], .si-btn[type="submit"], [data-app-busy="1"]'
                  )
                : null;
            if (!btn) return;
            if (btn.disabled || btn.getAttribute('aria-disabled') === 'true') return;
            if (btn.getAttribute('data-app-busy-skip') === '1') return;
            var form = btn.form || (btn.closest && btn.closest('form'));
            if (form && isSkippedForm(form)) return;
            // أزرار عرض التقرير خارج نموذج نادر — أظهر الانتظار
            var label = String(btn.textContent || btn.value || '').trim();
            var wantsBusy =
                (form && isReportLikeForm(form)) ||
                btn.getAttribute('data-app-busy') === '1' ||
                /عرض|تحديث|بحث|تصفية|حفظ|ترحيل|تنفيذ|تحميل/.test(label);
            if (!wantsBusy) return;
            // إن كان الزر داخل نموذج، submit handler سيتكفّل — لكن بعض الأزرار تمنع الافتراضي لاحقاً
            if (!form) {
                markNavBusyStorage();
                show(btn.getAttribute('data-app-busy-msg') || REPORT_MSG);
            }
        },
        true
    );

    document.addEventListener('manager:show-busy', function (e) {
        var detail = e && e.detail ? e.detail : {};
        show(detail.message || DEFAULT_MSG);
    });

    document.addEventListener('manager:hide-busy', function () {
        hide(true);
    });

    // إبقاؤها ظاهرة حتى اكتمال الرسم بعد التنقّل
    window.addEventListener('pageshow', function () {
        try {
            document.documentElement.classList.remove('hypex-nav-busy');
        } catch (e) {
            /* ignore */
        }
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
        REPORT_MSG: REPORT_MSG,
        NAV_MSG: NAV_MSG,
    };
})(typeof window !== 'undefined' ? window : self);
