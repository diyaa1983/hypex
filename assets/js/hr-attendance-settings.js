(function () {
    'use strict';

    var page = document.querySelector('.hr-att-shift-grid-page');
    if (!page) return;

    var editor = document.getElementById('hr-att-shift-editor');
    var editorTitle = document.getElementById('hr-att-shift-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-att-shift-editor-form');
    var editorId = document.getElementById('hr-att-shift-editor-id');
    var editorCode = document.getElementById('hr-att-shift-editor-code');
    var editorCodeHint = document.getElementById('hr-att-shift-editor-code-hint');
    var nextCode = page.getAttribute('data-next-code') || '';
    var editorName = document.getElementById('hr-att-shift-editor-name');
    var editorStart = document.getElementById('hr-att-shift-editor-start');
    var editorEnd = document.getElementById('hr-att-shift-editor-end');
    var editorActive = document.getElementById('hr-att-shift-editor-active');
    var btnHoliday = document.getElementById('hr-att-shift-set-holiday');
    var btnAdd = document.getElementById('hr-att-shift-btn-add');
    var btnEdit = document.getElementById('hr-att-shift-btn-edit');
    var btnDelete = document.getElementById('hr-att-shift-btn-delete');
    var btnClose = document.getElementById('hr-att-shift-editor-close');
    var btnCancel = document.getElementById('hr-att-shift-editor-cancel');
    var delForm = document.getElementById('hr-att-shift-delete-form');
    var delIdInp = document.getElementById('hr-att-shift-delete-id');
    var tbody = document.getElementById('hr-att-shift-grid-body');
    var selectedRow = null;

    function appDialogConfirm(message) {
        if (window.AppDialog && AppDialog.confirm) {
            return AppDialog.confirm(message);
        }
        return Promise.resolve(window.confirm(message));
    }

    function appDialogAlert(message, type) {
        if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(message, { type: type || 'warning' });
        } else {
            window.alert(message);
        }
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

    function normalizeCodeInput(value) {
        var digits = String(value || '').replace(/\D+/g, '');
        if (!digits) return '';
        var num = parseInt(digits, 10);
        return num > 0 ? String(num) : '';
    }

    function bindCodeInput() {
        if (!editorCode) return;
        editorCode.addEventListener('input', function () {
            var norm = normalizeCodeInput(editorCode.value);
            if (editorCode.value !== norm) {
                editorCode.value = norm;
            }
        });
        editorCode.addEventListener('blur', function () {
            editorCode.value = normalizeCodeInput(editorCode.value);
        });
    }

    function bindTimeInputs() {
        [editorStart, editorEnd].forEach(function (inp) {
            if (!inp) return;
            inp.addEventListener('blur', function () {
                var norm = normalizeTimeInput(inp.value);
                if (norm) inp.value = norm;
            });
        });
    }

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            code: tr.getAttribute('data-code') || '',
            name: tr.getAttribute('data-name') || '',
            start: tr.getAttribute('data-start') || '',
            end: tr.getAttribute('data-end') || '',
            active: tr.getAttribute('data-active') === '1',
        };
    }

    function syncDeleteButton() {
        if (!btnDelete) return;
        btnDelete.disabled = !selectedRow;
        btnDelete.title = selectedRow ? 'حذف الشفت المحدد' : 'حدد شفتاً من الجدول';
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-att-shift-row') || tr.classList.contains('hr-att-shift-row--empty')) {
            return;
        }
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
        }
        selectedRow = tr;
        selectedRow.classList.add('is-selected');
        if (btnEdit) btnEdit.disabled = false;
        syncDeleteButton();
    }

    function clearSelection() {
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow = null;
        }
        if (btnEdit) btnEdit.disabled = true;
        syncDeleteButton();
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        if (editorTitle) {
            editorTitle.textContent = isAdd ? 'إضافة شفت' : 'تعديل شفت';
        }
        if (editorId) {
            editorId.value = isAdd ? '0' : String(getRowData(selectedRow).id || 0);
        }
        if (editorCode) {
            if (isAdd) {
                editorCode.value = nextCode || '1';
                editorCode.readOnly = false;
                editorCode.required = true;
            } else {
                var rowDataCode = getRowData(selectedRow);
                editorCode.value = normalizeCodeInput((rowDataCode && rowDataCode.code) ? rowDataCode.code : '');
                editorCode.readOnly = true;
                editorCode.required = false;
            }
        }
        if (editorCodeHint) {
            editorCodeHint.textContent = isAdd
                ? 'أرقام فقط — يُقترح الرقم ' + (nextCode || '1')
                : 'الرقم ثابت ولا يمكن تعديله';
        }
        if (editorName) {
            editorName.value = isAdd ? '' : (getRowData(selectedRow).name || '');
        }
        if (editorStart) {
            editorStart.value = isAdd ? '07:00' : (getRowData(selectedRow).start || '');
        }
        if (editorEnd) {
            editorEnd.value = isAdd ? '15:00' : (getRowData(selectedRow).end || '');
        }
        if (editorActive) {
            if (isAdd) {
                editorActive.checked = true;
            } else {
                var rd = getRowData(selectedRow);
                editorActive.checked = rd ? rd.active : true;
            }
        }
        editor.hidden = false;
        page.classList.add('is-editing');
        if (isAdd && editorCode) {
            editorCode.focus();
        } else if (editorName) {
            editorName.focus();
        }
    }

    function closeEditor() {
        if (editor) editor.hidden = true;
        page.classList.remove('is-editing');
    }

    function startAdd() {
        clearSelection();
        openEditor('add');
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد شفتاً من الجدول ثم اضغط «تعديل».', 'warning');
            return;
        }
        openEditor('edit');
    }

    function submitEditorSave() {
        if (!editorForm) return;
        if (editorCode && !editorCode.readOnly) {
            editorCode.value = normalizeCodeInput(editorCode.value);
        }
        if (editorStart) editorStart.value = normalizeTimeInput(editorStart.value) || editorStart.value;
        if (editorEnd) editorEnd.value = normalizeTimeInput(editorEnd.value) || editorEnd.value;
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            return;
        }
        if (typeof editorForm.requestSubmit === 'function') {
            editorForm.requestSubmit();
        } else {
            editorForm.submit();
        }
    }

    function submitDelete() {
        if (!delForm || !delIdInp) return;
        if (!selectedRow) {
            appDialogAlert('حدد شفتاً من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) return;
        var label = 'الشفت رقم ' + (data.code || data.id) + (data.name ? ' — ' + data.name : '');
        appDialogConfirm('حذف «' + label + '» نهائياً؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                delForm.submit();
            }
        });
    }

    if (btnHoliday) {
        btnHoliday.addEventListener('click', function () {
            if (editorStart) editorStart.value = '00:00';
            if (editorEnd) editorEnd.value = '00:00';
            if (editorName && !String(editorName.value || '').trim()) {
                editorName.value = 'عطلة';
                editorName.focus();
            }
        });
    }

    bindCodeInput();
    bindTimeInputs();

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnDelete) btnDelete.addEventListener('click', submitDelete);
    if (btnClose) btnClose.addEventListener('click', closeEditor);
    if (btnCancel) btnCancel.addEventListener('click', closeEditor);

    if (tbody) {
        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-att-shift-active-cb')) return;
            var toggleForm = cb.closest('form.hr-att-shift-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.hr-att-shift-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-att-shift-row');
            if (tr) selectRow(tr);
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-att-shift-row');
            if (tr) {
                selectRow(tr);
                startEdit();
            }
        });
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-att-shift-row');
            if (tr) {
                e.preventDefault();
                selectRow(tr);
                startEdit();
            }
        });
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_attendance_settings') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                appDialogAlert('افتح «شفت جديد» أو «تعديل» ثم احفظ.', 'warning');
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete();
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            startAdd();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (editor && !editor.hidden) {
                submitEditorSave();
            }
        }
    });
})();
