(function () {
    'use strict';

    var page = document.querySelector('.hr-emp-leave-page');
    if (!page) return;

    var editorForm = document.getElementById('hr-emp-leave-editor-form');
    var postForm = document.getElementById('hr-emp-leave-post-form');
    var unpostForm = document.getElementById('hr-emp-leave-unpost-form');
    var deleteForm = document.getElementById('hr-emp-leave-delete-form');
    var deleteBtn = document.getElementById('hr-emp-leave-btn-delete');
    var fromEl = document.getElementById('hr-emp-leave-from');
    var toEl = document.getElementById('hr-emp-leave-to');
    var daysEl = document.getElementById('hr-emp-leave-days');
    var voucherInput = document.getElementById('hr-emp-leave-voucher-no');
    var voucherForm = document.getElementById('hr-emp-leave-voucher-form');
    var voucherInitial = voucherInput ? String(voucherInput.value || '').trim() : '';

    function appDialogConfirm(message, title) {
        if (window.AppDialog && AppDialog.confirm) {
            return AppDialog.confirm(message, { title: title || 'تأكيد', theme: 'oracle' });
        }
        return Promise.resolve(window.confirm(message));
    }

    function appDialogAlert(message, type) {
        if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(message, { type: type || 'warning', title: 'تنبيه', theme: 'oracle' });
        } else {
            window.alert(message);
        }
    }

    function currentId() {
        return parseInt(page.getAttribute('data-selected-id') || '0', 10);
    }

    function voucherNo() {
        return String(page.getAttribute('data-voucher-no') || '').trim();
    }

    function isPosted() {
        return page.getAttribute('data-is-posted') === '1';
    }

    function canPost() {
        return page.getAttribute('data-can-post') === '1';
    }

    function canUnpost() {
        return page.getAttribute('data-can-unpost') === '1';
    }

    function canDelete() {
        return page.getAttribute('data-can-delete') === '1';
    }

    function submitForm(form) {
        if (!form) return;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function syncVoucherStatusClass() {
        if (!voucherInput) return;
        voucherInput.classList.remove('is-posted', 'is-unposted');
        if (currentId() < 1) return;
        voucherInput.classList.add(isPosted() ? 'is-posted' : 'is-unposted');
    }

    function syncMasterToolbar() {
        var postBtn = document.querySelector('#master-toolbar [data-master-action="post"]');
        var unpostBtn = document.querySelector('#master-toolbar [data-master-action="unpost"]');
        var id = currentId();
        var no = voucherNo() || String(id || '');

        if (postBtn) {
            var postEnabled = canPost() && id > 0;
            postBtn.disabled = !postEnabled;
            postBtn.classList.toggle('is-inactive', !postEnabled);
            postBtn.title = postEnabled
                ? 'ترحيل سند الإجازة رقم ' + no
                : isPosted()
                    ? 'سند الإجازة مرحّل'
                    : 'احفظ السند أولاً ثم رحّل';
        }

        if (unpostBtn) {
            var unpostEnabled = canUnpost() && id > 0;
            unpostBtn.disabled = false;
            unpostBtn.classList.toggle('is-inactive', !unpostEnabled);
            unpostBtn.title = unpostEnabled
                ? 'فك ترحيل سند الإجازة رقم ' + no
                : id < 1
                    ? 'اختر سنداً من الجدول أولاً'
                    : !isPosted()
                        ? 'السند غير مرحّل'
                        : 'لا تملك صلاحية فك الترحيل';
        }
    }

    function submitSave() {
        if (!editorForm) return;
        if (isPosted()) {
            appDialogAlert('سند الإجازة مرحّل — لا يمكن التعديل إلا بعد فك الترحيل.', 'warning');
            return;
        }
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            return;
        }
        submitForm(editorForm);
    }

    function submitPostLeave() {
        var id = currentId();
        if (id < 1) {
            appDialogAlert('احفظ سند الإجازة أولاً ثم اضغط ترحيل.', 'warning');
            return;
        }
        if (!canPost()) {
            if (isPosted()) {
                appDialogAlert('سند الإجازة مرحّل مسبقاً.', 'warning');
            } else {
                appDialogAlert('لا يمكن ترحيل هذا السند حالياً.', 'warning');
            }
            return;
        }
        if (!postForm) return;

        var no = voucherNo() || String(id);
        var msg = 'ترحيل سند الإجازة رقم ' + no + '؟\n'
            + 'ستظهر في كشف حركة دوام الموظفين ويُخصم من رصيد الإجازات.';
        appDialogConfirm(msg, 'ترحيل الإجازة').then(function (ok) {
            if (!ok) return;
            submitForm(postForm);
        });
    }

    function submitUnpostLeave() {
        var id = currentId();
        if (id < 1) {
            var postedRow = document.querySelector('.hr-emp-leave-row[data-leave-posted="1"]');
            if (postedRow) {
                var rowUrl = postedRow.getAttribute('data-leave-url') || '';
                appDialogAlert('اختر السند المرحّل من الجدول أولاً (اضغط على صف السند).', 'warning');
                if (rowUrl) {
                    window.location.href = rowUrl;
                }
                return;
            }
            appDialogAlert('لا يوجد سند إجازة محدد.', 'warning');
            return;
        }
        if (!canUnpost()) {
            if (!isPosted()) {
                appDialogAlert('السند المعروض غير مرحّل — اختر السند المرحّل من الجدول.', 'warning');
            } else {
                appDialogAlert('لا تملك صلاحية فك ترحيل الإجازة.', 'warning');
            }
            return;
        }
        if (!unpostForm) return;

        var no = voucherNo() || String(id);
        appDialogConfirm('فك ترحيل سند الإجازة رقم ' + no + '؟', 'فك الترحيل').then(function (ok) {
            if (!ok) return;
            submitForm(unpostForm);
        });
    }

    function submitDeleteLeave() {
        var id = currentId();
        if (id < 1) {
            appDialogAlert('لا يوجد سند إجازة للحذف.', 'warning');
            return;
        }
        if (!canDelete()) {
            appDialogAlert('لا يمكن حذف هذا السند — فك الترحيل أولاً إن كان مرحّلاً.', 'warning');
            return;
        }
        if (!deleteForm) return;

        var no = voucherNo() || String(id);
        appDialogConfirm('حذف سند الإجازة رقم ' + no + '؟', 'حذف').then(function (ok) {
            if (!ok) return;
            submitForm(deleteForm);
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            submitDeleteLeave();
        });
    }

    if (voucherInput && voucherForm) {
        voucherInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var val = String(voucherInput.value || '').trim();
                if (val === '' || val === voucherInitial) {
                    return;
                }
                voucherForm.submit();
            }
        });
        voucherInput.addEventListener('blur', function () {
            var val = String(voucherInput.value || '').trim();
            if (val === '' || val === voucherInitial) {
                return;
            }
            voucherForm.submit();
        });
    }

    document.querySelectorAll('.hr-emp-leave-row[data-leave-url]').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a')) {
                return;
            }
            var url = row.getAttribute('data-leave-url') || '';
            if (url) {
                window.location.href = url;
            }
        });
    });

    if (fromEl && toEl && daysEl) {
        function parseDmy(str) {
            if (!str) return null;
            var parts = String(str).trim().split(/[\/\-\.]/);
            if (parts.length !== 3) return null;
            var d = parseInt(parts[0], 10);
            var m = parseInt(parts[1], 10);
            var y = parseInt(parts[2], 10);
            if (!d || !m || !y) return null;
            if (y < 100) y += 2000;
            return new Date(y, m - 1, d);
        }

        function calcDays() {
            var from = parseDmy(fromEl.value);
            var to = parseDmy(toEl.value);
            if (!from || !to || to < from) return;
            var diff = Math.round((to - from) / 86400000) + 1;
            daysEl.value = String(diff);
        }

        fromEl.addEventListener('change', calcDays);
        toEl.addEventListener('change', calcDays);
        fromEl.addEventListener('blur', calcDays);
        toEl.addEventListener('blur', calcDays);
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_employee_leaves') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitSave();
        } else if (action === 'post') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitPostLeave();
        } else if (action === 'unpost') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitUnpostLeave();
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDeleteLeave();
        }
    }, true);

    syncVoucherStatusClass();
    syncMasterToolbar();
})();
