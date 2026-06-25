(function (global) {
    'use strict';

    var DEFAULT_MSG = 'هل تريد حفظ التغييرات قبل مغادرة الشاشة؟';
    var registry = new WeakMap();
    var boundPages = new Set();

    function isUnloadAllowed() {
        return !!global.__managerAllowUnload;
    }

    function setAllowUnload(value) {
        global.__managerAllowUnload = !!value;
    }

    function getActiveGuard() {
        var active = null;
        boundPages.forEach(function (page) {
            if (!page || !page.isConnected) {
                return;
            }
            var api = registry.get(page);
            if (api && api.hasUnsavedChanges()) {
                active = api;
            }
        });
        return active;
    }

    function prepareForMinimize() {
        var detail = { dirty: false };
        global.document.dispatchEvent(new CustomEvent('manager:before-minimize', { detail: detail }));
        if (!detail.dirty) {
            if (global.ManagerScreenExit && global.ManagerScreenExit.hasUnsaved && global.ManagerScreenExit.hasUnsaved()) {
                detail.dirty = true;
            } else {
                var guard = getActiveGuard();
                if (guard && guard.hasUnsavedChanges()) {
                    detail.dirty = true;
                }
            }
        }
        setAllowUnload(true);
        return detail;
    }

    function clearAllowUnload() {
        setAllowUnload(false);
    }

    function confirmTaskbarClose(onSave, onDiscard, onCancel) {
        showLeaveDialog(onSave, onDiscard, onCancel);
    }

    function showLeaveDialog(onSave, onDiscard, onCancel, message) {
        message = message || DEFAULT_MSG;
        if (!global.AppDialog || typeof global.AppDialog.confirmSaveDiscard !== 'function') {
            if (global.confirm(message)) {
                if (onSave) {
                    onSave();
                }
            } else if (onDiscard) {
                onDiscard();
            } else if (onCancel) {
                onCancel();
            }
            return;
        }

        global.AppDialog.confirmSaveDiscard(message, {
            title: 'حفظ التغييرات',
            saveText: 'نعم، احفظ',
            discardText: 'لا، بدون حفظ',
            cancelText: 'إلغاء',
            theme: 'oracle',
        }).then(function (choice) {
            if (choice === 'save' && onSave) {
                onSave();
                return;
            }
            if (choice === 'discard' && onDiscard) {
                onDiscard();
                return;
            }
            if (onCancel) {
                onCancel();
            }
        });
    }

    function confirmSaveDiscardLeave(options) {
        options = options || {};
        var when = options.when || function () {
            return true;
        };
        if (!when()) {
            if (options.onProceed) {
                options.onProceed();
            }
            return;
        }
        showLeaveDialog(
            function () {
                if (options.onSave) {
                    options.onSave(options.onProceed);
                } else if (options.onProceed) {
                    options.onProceed();
                }
            },
            function () {
                if (options.onDiscard) {
                    options.onDiscard();
                }
                if (options.onProceed) {
                    options.onProceed();
                }
            },
            options.onCancel || function () {},
            options.message
        );
    }

    function navigateTop(href) {
        if (!href) {
            return;
        }
        if (
            global.AppScreenWindows &&
            typeof global.AppScreenWindows.exitCurrentPage === 'function' &&
            global.AppScreenWindows.hasCurrentFullPageWindow &&
            global.AppScreenWindows.hasCurrentFullPageWindow()
        ) {
            global.AppScreenWindows.exitCurrentPage(href);
            return;
        }
        if (global.APP_EMBED && global.parent && global.parent !== global) {
            global.parent.postMessage(
                { type: 'manager:mdi-parent-nav', href: href },
                global.location.origin
            );
            return;
        }
        global.location.href = href;
    }

    function confirmLeave(onProceed, onCancel) {
        onCancel = onCancel || function () {};

        if (global.ManagerScreenExit && typeof global.ManagerScreenExit.confirmLeave === 'function') {
            if (global.ManagerScreenExit.hasUnsaved && !global.ManagerScreenExit.hasUnsaved()) {
                if (onProceed) {
                    onProceed();
                }
                return;
            }
            global.ManagerScreenExit.confirmLeave(onProceed, onCancel);
            return;
        }

        var guard = getActiveGuard();
        if (guard && guard.hasUnsavedChanges()) {
            guard.confirmUnsavedChanges(onProceed, onCancel);
            return;
        }

        if (onProceed) {
            onProceed();
        }
    }

    function registerScreenExit(api) {
        global.ManagerScreenExit = api || null;
    }

    function registerScreenExitDeferred(api) {
        function apply() {
            registerScreenExit(api);
        }
        apply();
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', apply);
        } else {
            global.setTimeout(apply, 0);
        }
    }

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

    function pageHasAnyUnsaved() {
        if (global.ManagerScreenExit && global.ManagerScreenExit.hasUnsaved && global.ManagerScreenExit.hasUnsaved()) {
            return true;
        }
        var guard = getActiveGuard();
        return !!(guard && guard.hasUnsavedChanges());
    }

    function isInternalNavLink(anchor) {
        if (!anchor || anchor.tagName !== 'A') {
            return false;
        }
        if (anchor.target === '_blank' || anchor.hasAttribute('download')) {
            return false;
        }
        if (anchor.closest('.app-mdi-window, .app-mdi-taskbar, .app-mdi-screen-minimize-btn, .fin-voucher-archive-modal, .ui-dialog-root')) {
            return false;
        }
        var href = anchor.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') {
            return false;
        }
        if (href.indexOf('logout.php') >= 0) {
            return false;
        }
        try {
            var u = new URL(anchor.href, global.location.href);
            if (u.origin !== global.location.origin) {
                return false;
            }
            var cur = new URL(global.location.href);
            if (u.pathname === cur.pathname && u.search === cur.search) {
                return false;
            }
        } catch (e) {
            return false;
        }
        return true;
    }

    function onGlobalNavClick(e) {
        if (isUnloadAllowed()) {
            return;
        }
        var anchor = e.target.closest ? e.target.closest('a[href]') : null;
        if (!anchor || !isInternalNavLink(anchor)) {
            return;
        }
        if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        if (anchor.closest('.ora12-title-bar__close, .hr-ora-title-bar__close, .nav-exit-btn')) {
            return;
        }
        if (!pageHasAnyUnsaved()) {
            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        var href = anchor.href;
        confirmLeave(function () {
            navigateTop(href);
        });
    }

    function findPageRoot(el) {
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

            showLeaveDialog(
                function () {
                    onSave();
                },
                function () {
                    delete page.dataset.screenExitDirty;
                    resetSnapshot();
                    if (onProceed) {
                        onProceed();
                    }
                },
                onCancel || function () {},
                message
            );
        }

        function navigateAway(url, onCancel) {
            if (!url) {
                return;
            }
            confirmUnsavedChanges(function () {
                navigateTop(url);
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
            if (isUnloadAllowed() || !hasUnsavedChanges()) {
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

        confirmLeave(function () {
            navigateTop(href);
        });
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
    global.document.addEventListener('click', onGlobalNavClick, true);

    function init() {
        clearAllowUnload();
        autoBindAll();
    }

    function initAfterLoad() {
        clearAllowUnload();
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
        getActiveGuard: getActiveGuard,
        prepareForMinimize: prepareForMinimize,
        clearAllowUnload: clearAllowUnload,
        confirmTaskbarClose: confirmTaskbarClose,
        confirmLeave: confirmLeave,
        confirmSaveDiscardLeave: confirmSaveDiscardLeave,
        registerScreenExit: registerScreenExit,
        registerScreenExitDeferred: registerScreenExitDeferred,
        isUnloadAllowed: isUnloadAllowed,
        navigateExit: navigateTop,
    };
    global.HrOraUnsaved = { bind: bind };
})(typeof window !== 'undefined' ? window : globalThis);
