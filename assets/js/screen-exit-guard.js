(function (global) {
    'use strict';

    var DEFAULT_MSG = 'هل تريد حفظ التغييرات قبل مغادرة الشاشة؟';
    var registry = new WeakMap();
    var boundPages = new Set();

    function isSearchFilterForm(form) {
        if (!form) {
            return false;
        }
        if (form.classList.contains('no-exit-guard') || form.classList.contains('report-sales-filters')) {
            return true;
        }
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        return method === 'get' && !form.classList.contains('master-page-form');
    }

    function isDirectScreenExitClick(exitLink) {
        if (!exitLink) {
            return false;
        }
        if (!exitLink.classList.contains('ora12-title-bar__close')
            && !exitLink.classList.contains('hr-ora-title-bar__close')) {
            return false;
        }
        return !!exitLink.closest('.report-ora12-screen, .app-screen-title-bar');
    }

    function findPageRoot(el) {
        if (!el) {
            return null;
        }
        if (isDirectScreenExitClick(el.closest('.ora12-title-bar__close, .hr-ora-title-bar__close'))) {
            return null;
        }
        var marked = el.closest('[data-exit-guard-root]');
        if (marked) {
            return marked;
        }
        marked = el.closest('[data-exit-url]');
        if (marked) {
            return marked.closest('.dashboard-ora') || marked;
        }
        marked = el.closest('.dashboard-ora');
        if (marked) {
            if (marked.dataset.exitGuard === 'off') {
                return null;
            }
            if (marked.querySelector('form:not(.no-exit-guard):not(.report-sales-filters)')) {
                return marked;
            }
        }
        var form = el.closest('form');
        if (form) {
            return form.closest('.dashboard-ora') || form;
        }
        return null;
    }

    function primaryForm(root) {
        if (!root) {
            return null;
        }
        if (root.tagName === 'FORM') {
            return root;
        }
        return root.querySelector('form.master-page-form, form[id], form');
    }

    function isFormReadOnly(root, form) {
        if (!form) {
            return true;
        }
        if (form.getAttribute('data-readonly') === '1') {
            return true;
        }
        if (form.classList.contains('is-posted')) {
            return true;
        }
        if (root && (root.classList.contains('is-posted') || root.classList.contains('is-readonly'))) {
            return true;
        }
        var ro = form.querySelector('[data-readonly="1"], .is-readonly');
        if (ro && form.querySelectorAll('input:not([type="hidden"]), select, textarea').length > 0) {
            var editable = form.querySelectorAll('input:not([type="hidden"]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([readonly]):not([disabled])');
            if (!editable.length) {
                return true;
            }
        }
        return false;
    }

    function serializeForm(form) {
        if (!form) {
            return null;
        }
        var data = {};
        var els = form.querySelectorAll('input, select, textarea');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            var name = el.name;
            if (!name || name === '_csrf') {
                continue;
            }
            if (el.type === 'checkbox') {
                data[name] = el.checked ? (el.value || '1') : '';
            } else if (el.type === 'radio') {
                if (el.checked) {
                    data[name] = el.value;
                }
            } else {
                data[name] = el.value;
            }
        }
        return data;
    }

    function triggerSave(form) {
        var saveBtn = global.document.querySelector('#master-toolbar [data-master-action="save"]');
        if (saveBtn) {
            saveBtn.click();
            return;
        }
        if (!form) {
            return;
        }
        var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submitBtn && !submitBtn.disabled) {
            submitBtn.click();
            return;
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function bindToolbarLeaveLinks(page, guard) {
        if (!page || !guard || typeof guard.bindLeaveLink !== 'function') {
            return;
        }
        page.querySelectorAll(
            '.dashboard-ora-toolbar a[href], .hr-emp-toolbar a[href], .toolbar a[href], .settings-ora-actions a[href]'
        ).forEach(function (link) {
            guard.bindLeaveLink(link);
        });
    }

    function bind(opts) {
        opts = opts || {};
        var page = opts.page;
        if (!page) {
            return null;
        }

        var route = opts.route || '';
        var formSubmitting = false;
        var snapshot = null;
        var getSnapshot = opts.getSnapshot || function () {
            return serializeForm(primaryForm(page));
        };
        var isActive = opts.isActive || function () {
            return !!primaryForm(page);
        };
        var isReadOnly = opts.isReadOnly || function () {
            return isFormReadOnly(page, primaryForm(page));
        };
        var onSave = opts.onSave || function () {
            triggerSave(primaryForm(page));
        };
        var exitUrl = opts.exitUrl || page.getAttribute('data-exit-url') || '';
        var message = opts.message || DEFAULT_MSG;

        function hasUnsavedChanges() {
            if (formSubmitting || !isActive() || isReadOnly()) {
                return false;
            }
            if (page.dataset.screenExitDirty === '1') {
                return true;
            }
            if (snapshot === null) {
                return isActive();
            }
            try {
                return JSON.stringify(getSnapshot()) !== JSON.stringify(snapshot);
            } catch (e) {
                return true;
            }
        }

        function syncSnapshot() {
            snapshot = getSnapshot();
        }

        function resetSnapshot() {
            snapshot = getSnapshot();
        }

        function confirmUnsavedChanges(onProceed, onCancel) {
            if (!hasUnsavedChanges()) {
                if (onProceed) {
                    onProceed();
                }
                return;
            }

            var showFallback = function () {
                if (global.confirm('هل تريد حفظ التغييرات قبل المغادرة؟')) {
                    onSave();
                } else if (onProceed) {
                    resetSnapshot();
                    onProceed();
                } else if (onCancel) {
                    onCancel();
                }
            };

            if (!global.AppDialog || typeof global.AppDialog.confirmSaveDiscard !== 'function') {
                showFallback();
                return;
            }

            global.AppDialog.confirmSaveDiscard(message, {
                title: 'حفظ التغييرات',
                saveText: 'نعم، احفظ',
                discardText: 'لا، بدون حفظ',
                cancelText: 'إلغاء',
                theme: 'oracle',
            }).then(function (choice) {
                if (choice === 'save') {
                    onSave();
                    return;
                }
                if (choice === 'discard' && onProceed) {
                    delete page.dataset.screenExitDirty;
                    resetSnapshot();
                    onProceed();
                    return;
                }
                if (onCancel) {
                    onCancel();
                }
            });
        }

        function navigateAway(url, onCancel) {
            if (!url) {
                return;
            }
            confirmUnsavedChanges(function () {
                global.location.href = url;
            }, onCancel);
        }

        function resolveExitUrl() {
            if (exitUrl) {
                return exitUrl;
            }
            var bar = global.document.getElementById('master-toolbar');
            return bar ? (bar.getAttribute('data-exit-url') || '') : '';
        }

        function onBeforeUnload(e) {
            if (!hasUnsavedChanges()) {
                return;
            }
            e.preventDefault();
            e.returnValue = '';
        }

        function onMasterToolbar(e) {
            if (!e.detail || !route) {
                return;
            }
            var bar = global.document.getElementById('master-toolbar');
            if ((bar ? bar.getAttribute('data-active-route') || '' : '') !== route) {
                return;
            }
            if (e.detail.action === 'exit') {
                e.preventDefault();
                e.stopImmediatePropagation();
                navigateAway(e.detail.exitUrl || resolveExitUrl());
            }
        }

        function bindLeaveLink(link) {
            if (!link || link.dataset.hrOraLeaveBound === '1') {
                return;
            }
            link.dataset.hrOraLeaveBound = '1';
            link.addEventListener('click', function (e) {
                if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                    return;
                }
                if (link.target === '_blank') {
                    return;
                }
                var href = link.getAttribute('href');
                if (!href || href.charAt(0) === '#') {
                    return;
                }
                e.preventDefault();
                navigateAway(href);
            });
        }

        var api = {
            syncSnapshot: syncSnapshot,
            resetSnapshot: resetSnapshot,
            hasUnsavedChanges: hasUnsavedChanges,
            confirmUnsavedChanges: confirmUnsavedChanges,
            navigateAway: navigateAway,
            markSubmitting: function (value) {
                formSubmitting = !!value;
            },
            tryClose: function (onClose, force) {
                if (force || !hasUnsavedChanges()) {
                    resetSnapshot();
                    if (onClose) {
                        onClose();
                    }
                    return;
                }
                confirmUnsavedChanges(function () {
                    resetSnapshot();
                    if (onClose) {
                        onClose();
                    }
                });
            },
            bindLeaveLink: bindLeaveLink,
        };

        page.dataset.exitGuardBound = '1';
        registry.set(page, api);
        boundPages.add(page);

        global.window.addEventListener('beforeunload', onBeforeUnload);
        if (route) {
            global.document.addEventListener('master-toolbar', onMasterToolbar, true);
        }

        return api;
    }

    function tryAutoGuard(root, href) {
        if (!root || root.dataset.exitGuard === 'off' || root.dataset.exitGuard === 'custom') {
            return null;
        }
        var form = primaryForm(root);
        if (!form || isSearchFilterForm(form) || isFormReadOnly(root, form)) {
            return null;
        }
        var existing = registry.get(root);
        if (existing) {
            return existing;
        }
        var bar = global.document.getElementById('master-toolbar');
        var route = bar ? (bar.getAttribute('data-active-route') || '') : '';
        var guard = bind({
            page: root,
            route: route,
            exitUrl: href || root.getAttribute('data-exit-url') || '',
            getSnapshot: function () { return serializeForm(form); },
            isActive: function () { return true; },
            isReadOnly: function () { return isFormReadOnly(root, form); },
            onSave: function () { triggerSave(form); },
        });
        guard.syncSnapshot();
        if (pageHasFormEdits(root, form, guard)) {
            return guard;
        }
        return null;
    }

    function pageHasFormEdits(root, form, guard) {
        if (root.dataset.screenExitDirty === '1') {
            return true;
        }
        return guard.hasUnsavedChanges();
    }

    function onGlobalExitClick(e) {
        var exitLink = e.target.closest('.ora12-title-bar__close, .hr-ora-title-bar__close, .nav-exit-btn');
        if (!exitLink) {
            return;
        }
        var href = exitLink.getAttribute('href');
        if (!href) {
            return;
        }

        if (isDirectScreenExitClick(exitLink)) {
            e.preventDefault();
            e.stopImmediatePropagation();
            global.location.href = href;
            return;
        }

        var root = findPageRoot(exitLink);
        var guard = root ? registry.get(root) : null;

        if (guard) {
            e.preventDefault();
            e.stopImmediatePropagation();
            guard.navigateAway(href);
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        if (global.document.getElementById('master-toolbar')) {
            var ev = new CustomEvent('master-toolbar', {
                bubbles: true,
                cancelable: true,
                detail: { action: 'exit', exitUrl: href },
            });
            global.document.dispatchEvent(ev);
            if (ev.defaultPrevented) {
                return;
            }
        }

        guard = tryAutoGuard(root, href);
        if (guard && pageHasFormEdits(root, primaryForm(root), guard)) {
            guard.navigateAway(href);
            return;
        }

        global.location.href = href;
    }

    function findGuardPage(el) {
        if (!el) {
            return null;
        }
        var marked = el.closest('[data-exit-guard-root]');
        if (marked) {
            return marked;
        }
        marked = el.closest('[data-exit-url]');
        if (marked) {
            return marked.closest('.dashboard-ora') || marked;
        }
        return el.closest('.dashboard-ora');
    }

    function syncExitGuardPage(page) {
        if (!page) {
            return;
        }
        var api = registry.get(page);
        if (api) {
            api.resetSnapshot();
            delete page.dataset.screenExitDirty;
            return;
        }
        autoBindPage(page);
        api = registry.get(page);
        if (api) {
            api.resetSnapshot();
            delete page.dataset.screenExitDirty;
        }
    }

    function syncExitGuardForElement(el) {
        syncExitGuardPage(findGuardPage(el));
    }

    function resyncAllBoundPages() {
        boundPages.forEach(function (page) {
            if (!page || !page.isConnected) {
                boundPages.delete(page);
                return;
            }
            syncExitGuardPage(page);
        });
    }

    function autoBindPage(page) {
        if (!page || page.dataset.exitGuard === 'custom' || page.dataset.exitGuard === 'off') {
            return;
        }
        if (page.dataset.exitGuardBound === '1') {
            syncExitGuardPage(page);
            return;
        }
        var form = primaryForm(page);
        if (!form || isSearchFilterForm(form)) {
            return;
        }
        if (isFormReadOnly(page, form)) {
            return;
        }

        var bar = global.document.getElementById('master-toolbar');
        var route = bar ? (bar.getAttribute('data-active-route') || '') : '';

        var guard = bind({
            page: page,
            route: route,
            exitUrl: page.getAttribute('data-exit-url') || (form.getAttribute('data-exit-url') || ''),
            getSnapshot: function () { return serializeForm(form); },
            isActive: function () { return true; },
            isReadOnly: function () { return isFormReadOnly(page, form); },
            onSave: function () { triggerSave(form); },
        });
        guard.syncSnapshot();
        bindToolbarLeaveLinks(page, guard);

        form.addEventListener('input', function () {
            page.dataset.screenExitDirty = '1';
        });
        form.addEventListener('change', function () {
            page.dataset.screenExitDirty = '1';
        });
        form.addEventListener('submit', function () {
            guard.markSubmitting(true);
            delete page.dataset.screenExitDirty;
            setTimeout(function () {
                guard.markSubmitting(false);
                guard.syncSnapshot();
            }, 500);
        });
    }

    function autoBindAll() {
        global.document.querySelectorAll('[data-exit-guard-root]').forEach(autoBindPage);
        global.document.querySelectorAll('[data-exit-url]').forEach(function (el) {
            autoBindPage(el.closest('.dashboard-ora') || el);
        });
        global.document.querySelectorAll('.dashboard-ora').forEach(function (dora) {
            if (dora.dataset.exitGuard === 'off') {
                return;
            }
            if (dora.dataset.exitGuardBound !== '1' && dora.querySelector('form:not(.no-exit-guard):not(.report-sales-filters)')) {
                autoBindPage(dora);
            }
        });
    }

    global.document.addEventListener('click', onGlobalExitClick, true);

    function init() {
        autoBindAll();
    }

    function initAfterLoad() {
        autoBindAll();
        resyncAllBoundPages();
    }

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    global.window.addEventListener('load', initAfterLoad);

    global.ScreenExitGuard = {
        bind: bind,
        autoBindAll: autoBindAll,
        serializeForm: serializeForm,
        syncFor: syncExitGuardForElement,
        syncPage: syncExitGuardPage,
        resyncAll: resyncAllBoundPages,
    };
    global.HrOraUnsaved = { bind: bind };
})(typeof window !== 'undefined' ? window : globalThis);
