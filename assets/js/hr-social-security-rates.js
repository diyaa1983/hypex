(function () {
    'use strict';

    var page = document.querySelector('.hr-ss-rate-grid-page');
    if (!page) return;

    var editor = document.getElementById('hr-ss-rate-editor');
    var editorTitle = document.getElementById('hr-ss-rate-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-ss-rate-editor-form');
    var editorId = document.getElementById('hr-ss-rate-editor-id');
    var editorCodeDisplay = document.getElementById('hr-ss-rate-editor-code-display');
    var editorCodeHint = document.getElementById('hr-ss-rate-editor-code-hint');
    var nextCode = page.getAttribute('data-next-code') || '';
    var editorEmpPct = document.getElementById('hr-ss-rate-editor-emp-pct');
    var editorErPct = document.getElementById('hr-ss-rate-editor-er-pct');
    var editorNotes = document.getElementById('hr-ss-rate-editor-notes');
    var editorActive = document.getElementById('hr-ss-rate-editor-active');
    var btnAdd = document.getElementById('hr-ss-rate-btn-add');
    var btnEdit = document.getElementById('hr-ss-rate-btn-edit');
    var btnDelete = document.getElementById('hr-ss-rate-btn-delete');
    var btnClose = document.getElementById('hr-ss-rate-editor-close');
    var btnCancel = document.getElementById('hr-ss-rate-editor-cancel');
    var delForm = document.getElementById('hr-ss-rate-delete-form');
    var delIdInp = document.getElementById('hr-ss-rate-delete-id');
    var tbody = document.getElementById('hr-ss-rate-grid-body');
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

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            code: tr.getAttribute('data-code') || '',
            empPct: tr.getAttribute('data-emp-pct') || '0',
            erPct: tr.getAttribute('data-er-pct') || '0',
            description: tr.getAttribute('data-description') || '',
            active: tr.getAttribute('data-active') === '1',
        };
    }

    function syncDeleteButton() {
        if (!btnDelete) {
            return;
        }
        if (!selectedRow) {
            btnDelete.disabled = true;
            btnDelete.title = 'حدد نسبة من الجدول';
            return;
        }
        var linked = selectedRow.getAttribute('data-linked') === '1';
        btnDelete.disabled = linked;
        btnDelete.title = linked
            ? (selectedRow.getAttribute('data-linked-msg') || 'لا يمكن حذف هذه النسبة')
            : 'حذف النسبة المحددة';
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-ss-rate-row') || tr.classList.contains('hr-ss-rate-row--empty')) {
            return;
        }
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
        }
        selectedRow = tr;
        selectedRow.classList.add('is-selected');
        if (btnEdit) {
            btnEdit.disabled = false;
        }
        syncDeleteButton();
    }

    function clearSelection() {
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow = null;
        }
        if (btnEdit) {
            btnEdit.disabled = true;
        }
        syncDeleteButton();
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        if (editorTitle) {
            editorTitle.textContent = isAdd ? 'إضافة نسبة' : 'تعديل نسبة';
        }
        if (editorId) {
            editorId.value = isAdd ? '0' : String(getRowData(selectedRow).id || 0);
        }
        if (editorCodeDisplay) {
            if (isAdd) {
                editorCodeDisplay.textContent = nextCode || '—';
            } else {
                var rowData = getRowData(selectedRow);
                editorCodeDisplay.textContent = (rowData && rowData.code) ? rowData.code : '—';
            }
        }
        if (editorCodeHint) {
            editorCodeHint.textContent = isAdd
                ? 'سيُعيَّن الرقم ' + (nextCode || '—') + ' عند الحفظ'
                : 'الرقم ثابت ولا يمكن تعديله';
        }
        if (editorEmpPct) {
            editorEmpPct.value = isAdd ? '0' : (getRowData(selectedRow).empPct || '0');
        }
        if (editorErPct) {
            editorErPct.value = isAdd ? '0' : (getRowData(selectedRow).erPct || '0');
        }
        if (editorNotes) {
            editorNotes.value = isAdd ? '' : (getRowData(selectedRow).description || '');
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
        if (editorEmpPct) {
            editorEmpPct.focus();
        }
    }

    function closeEditor() {
        if (editor) {
            editor.hidden = true;
        }
        page.classList.remove('is-editing');
    }

    function startAdd() {
        clearSelection();
        openEditor('add');
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد نسبة من الجدول ثم اضغط «تعديل».', 'warning');
            return;
        }
        openEditor('edit');
    }

    function submitEditorSave() {
        if (!editorForm) return;
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
            appDialogAlert('حدد نسبة من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) {
            return;
        }
        if (selectedRow.getAttribute('data-linked') === '1') {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg') || 'لا يمكن حذف هذه النسبة.',
                'warning'
            );
            return;
        }
        var label = 'النسبة رقم ' + (data.code || data.id);
        appDialogConfirm('حذف «' + label + '» نهائياً؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                delForm.submit();
            }
        });
    }

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnDelete) btnDelete.addEventListener('click', submitDelete);
    if (btnClose) btnClose.addEventListener('click', closeEditor);
    if (btnCancel) btnCancel.addEventListener('click', closeEditor);

    if (tbody) {
        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-ss-rate-active-cb')) return;
            var toggleForm = cb.closest('form.hr-ss-rate-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.hr-ss-rate-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-ss-rate-row');
            if (tr) selectRow(tr);
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-ss-rate-row');
            if (tr) {
                selectRow(tr);
                startEdit();
            }
        });
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-ss-rate-row');
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
        if (route !== 'hr_social_security_rates') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                appDialogAlert('افتح «نسبة جديدة» أو «تعديل» ثم احفظ.', 'warning');
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
