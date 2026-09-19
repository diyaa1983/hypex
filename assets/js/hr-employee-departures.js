(function () {
    'use strict';

    var page = document.querySelector('.hr-emp-dep-page');
    if (!page) return;

    var editorForm = document.getElementById('hr-emp-dep-editor-form');
    var postForm = document.getElementById('hr-emp-dep-post-form');
    var unpostForm = document.getElementById('hr-emp-dep-unpost-form');
    var deleteForm = document.getElementById('hr-emp-dep-delete-form');
    var deleteBtn = document.getElementById('hr-emp-dep-btn-delete');
    var voucherInput = document.getElementById('hr-emp-dep-voucher-no');
    var voucherForm = document.getElementById('hr-emp-dep-voucher-form');
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
                ? 'ترحيل سند المغادرة رقم ' + no
                : isPosted()
                    ? 'سند المغادرة مرحّل'
                    : 'احفظ السند أولاً ثم رحّل';
        }

        if (unpostBtn) {
            var unpostEnabled = canUnpost() && id > 0;
            unpostBtn.disabled = !unpostEnabled;
            unpostBtn.classList.toggle('is-inactive', !unpostEnabled);
            unpostBtn.title = unpostEnabled
                ? 'فك ترحيل سند المغادرة رقم ' + no
                : !isPosted()
                    ? 'السند غير مرحّل'
                    : 'غير متاح';
        }
    }

    function submitSave() {
        if (!editorForm) return;
        if (isPosted()) {
            appDialogAlert('سند المغادرة مرحّل — لا يمكن التعديل إلا بعد فك الترحيل.', 'warning');
            return;
        }
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            return;
        }
        submitForm(editorForm);
    }

    function submitPostDeparture() {
        var id = currentId();
        if (id < 1) {
            appDialogAlert('احفظ سند المغادرة أولاً ثم اضغط ترحيل.', 'warning');
            return;
        }
        if (!canPost()) {
            if (isPosted()) {
                appDialogAlert('سند المغادرة مرحّل مسبقاً.', 'warning');
            } else {
                appDialogAlert('لا يمكن ترحيل هذا السند حالياً.', 'warning');
            }
            return;
        }
        if (!postForm) return;

        var no = voucherNo() || String(id);
        var msg = 'ترحيل سند المغادرة رقم ' + no + '؟\n'
            + 'ستظهر في كشف حركة دوام الموظفين.';
        appDialogConfirm(msg, 'ترحيل المغادرة').then(function (ok) {
            if (!ok) return;
            submitForm(postForm);
        });
    }

    function submitUnpostDeparture() {
        var id = currentId();
        if (id < 1) {
            appDialogAlert('لا يوجد سند مغادرة محدد.', 'warning');
            return;
        }
        if (!canUnpost()) {
            appDialogAlert('سند المغادرة غير مرحّل.', 'warning');
            return;
        }
        if (!unpostForm) return;

        var no = voucherNo() || String(id);
        appDialogConfirm('فك ترحيل سند المغادرة رقم ' + no + '؟', 'فك الترحيل').then(function (ok) {
            if (!ok) return;
            submitForm(unpostForm);
        });
    }

    function submitDeleteDeparture() {
        var id = currentId();
        if (id < 1) {
            appDialogAlert('لا يوجد سند مغادرة للحذف.', 'warning');
            return;
        }
        if (!canDelete()) {
            appDialogAlert('لا يمكن حذف هذا السند — فك الترحيل أولاً إن كان مرحّلاً.', 'warning');
            return;
        }
        if (!deleteForm) return;

        var no = voucherNo() || String(id);
        appDialogConfirm('حذف سند المغادرة رقم ' + no + '؟', 'حذف').then(function (ok) {
            if (!ok) return;
            submitForm(deleteForm);
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            submitDeleteDeparture();
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

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_employee_departures') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitSave();
        } else if (action === 'post') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitPostDeparture();
        } else if (action === 'unpost') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitUnpostDeparture();
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDeleteDeparture();
        }
    }, true);

    syncVoucherStatusClass();
    syncMasterToolbar();
})();
