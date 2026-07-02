(function () {
    'use strict';

    var page = document.querySelector('.hr-att-shift-page');
    if (!page) return;

    var editorForm = document.getElementById('hr-att-shift-editor-form');
    var idEl = document.getElementById('hr-att-shift-id');
    var codeEl = document.getElementById('hr-att-shift-code');
    var nameEl = document.getElementById('hr-att-shift-name');
    var startEl = document.getElementById('hr-att-shift-start');
    var endEl = document.getElementById('hr-att-shift-end');
    var activeEl = document.getElementById('hr-att-shift-active');
    var resetBtn = document.getElementById('hr-att-shift-reset');
    var holidayBtn = document.getElementById('hr-att-shift-set-holiday');
    var delForm = document.getElementById('hr-att-shift-delete-form');
    var delIdInp = document.getElementById('hr-att-shift-delete-id');
    var nextCode = page.getAttribute('data-next-code') || '';

    function appDialogConfirm(message) {
        if (window.AppDialog && AppDialog.confirm) {
            return AppDialog.confirm(message, { title: 'تأكيد', theme: 'oracle' });
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

    function computeNextCodeFromGrid() {
        var maxCode = 0;
        var maxId = 0;
        document.querySelectorAll('.hr-att-shift-table tbody tr').forEach(function (tr) {
            var btn = tr.querySelector('.hr-att-shift-edit');
            if (!btn) return;
            var code = parseInt(btn.getAttribute('data-code') || '0', 10);
            var id = parseInt(btn.getAttribute('data-id') || '0', 10);
            if (code > maxCode) maxCode = code;
            if (id > maxId) maxId = id;
        });
        var next = Math.max(maxCode, maxId) + 1;

        return next > 0 ? String(next) : '';
    }

    function resolveNextCode() {
        return nextCode || computeNextCodeFromGrid() || '1';
    }

    function normalizeTimeInput(value) {
        var raw = String(value || '').trim();
        if (!raw) return '';
        var m = raw.match(/^(\d{1,2}):(\d{2})$/);
        if (!m) return raw;
        var h = parseInt(m[1], 10);
        var min = parseInt(m[2], 10);
        if (h < 0 || h > 23 || min < 0 || min > 59) return raw;
        return (h < 10 ? '0' : '') + h + ':' + (min < 10 ? '0' : '') + min;
    }

    function clearEditingRows() {
        document.querySelectorAll('.hr-att-shift-table tbody tr').forEach(function (tr) {
            tr.classList.remove('is-editing');
        });
    }

    function resetForm() {
        clearEditingRows();
        if (idEl) idEl.value = '0';
        if (codeEl) {
            codeEl.value = resolveNextCode();
            codeEl.readOnly = true;
        }
        if (nameEl) nameEl.value = '';
        if (startEl) startEl.value = '07:00';
        if (endEl) endEl.value = '15:00';
        if (activeEl) activeEl.checked = true;
        if (nameEl) nameEl.focus();
    }

    function submitSave() {
        if (!editorForm) return;
        if (startEl) startEl.value = normalizeTimeInput(startEl.value) || startEl.value;
        if (endEl) endEl.value = normalizeTimeInput(endEl.value) || endEl.value;
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            return;
        }
        if (typeof editorForm.requestSubmit === 'function') {
            editorForm.requestSubmit();
        } else {
            editorForm.submit();
        }
    }

    function submitDeleteCurrent() {
        var id = idEl ? parseInt(idEl.value || '0', 10) : 0;
        if (id < 1) {
            appDialogAlert('اختر شفتاً للتعديل ثم احذف، أو استخدم زر الحذف من الجدول.', 'warning');
            return;
        }
        if (!delForm || !delIdInp) return;

        var code = codeEl ? String(codeEl.value || '').trim() : '';
        var name = nameEl ? String(nameEl.value || '').trim() : '';
        var label = 'الشفت رقم ' + (code || id) + (name ? ' — ' + name : '');
        appDialogConfirm('حذف «' + label + '» نهائياً؟').then(function (ok) {
            if (!ok) return;
            delIdInp.value = String(id);
            delForm.submit();
        });
    }

    function bindTimeInputs() {
        [startEl, endEl].forEach(function (inp) {
            if (!inp) return;
            inp.addEventListener('blur', function () {
                var norm = normalizeTimeInput(inp.value);
                if (norm) inp.value = norm;
            });
        });
    }

    document.querySelectorAll('.hr-att-shift-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            clearEditingRows();
            var row = btn.closest('tr');
            if (row) row.classList.add('is-editing');
            if (idEl) idEl.value = btn.getAttribute('data-id') || '0';
            if (codeEl) {
                codeEl.value = btn.getAttribute('data-code') || '';
                codeEl.readOnly = true;
            }
            if (nameEl) nameEl.value = btn.getAttribute('data-name') || '';
            if (startEl) startEl.value = btn.getAttribute('data-start') || '';
            if (endEl) endEl.value = btn.getAttribute('data-end') || '';
            if (activeEl) activeEl.checked = (btn.getAttribute('data-active') || '0') === '1';
            if (nameEl) nameEl.focus();
        });
    });

    document.querySelectorAll('.hr-att-shift-table tbody').forEach(function (tbody) {
        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-att-shift-active-cb')) return;
            var toggleForm = cb.closest('form.hr-att-shift-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
    });

    if (holidayBtn) {
        holidayBtn.addEventListener('click', function () {
            if (startEl) startEl.value = '00:00';
            if (endEl) endEl.value = '00:00';
            if (nameEl && !String(nameEl.value || '').trim()) {
                nameEl.value = 'عطلة';
                nameEl.focus();
            }
        });
    }

    if (resetBtn) resetBtn.addEventListener('click', resetForm);

    bindTimeInputs();

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_attendance_settings') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitSave();
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            resetForm();
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDeleteCurrent();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            submitSave();
        }
    });
})();
