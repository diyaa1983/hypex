(function () {
    'use strict';

    var page = document.querySelector('.hr-bank-grid-page');
    if (!page) return;

    var editor = document.getElementById('hr-bank-editor');
    var editorTitle = document.getElementById('hr-bank-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-bank-editor-form');
    var editorId = document.getElementById('hr-bank-editor-id');
    var editorCodeDisplay = document.getElementById('hr-bank-editor-code-display');
    var editorCodeHint = document.getElementById('hr-bank-editor-code-hint');
    var nextCode = page.getAttribute('data-next-code') || '';
    var editorName = document.getElementById('hr-bank-editor-name');
    var editorActive = document.getElementById('hr-bank-editor-active');
    var btnAdd = document.getElementById('hr-bank-btn-add');
    var btnEdit = document.getElementById('hr-bank-btn-edit');
    var btnDelete = document.getElementById('hr-bank-btn-delete');
    var btnClose = document.getElementById('hr-bank-editor-close');
    var btnCancel = document.getElementById('hr-bank-editor-cancel');
    var delForm = document.getElementById('hr-bank-delete-form');
    var delIdInp = document.getElementById('hr-bank-delete-id');
    var tbody = document.getElementById('hr-bank-grid-body');
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
            name: tr.getAttribute('data-name') || '',
            active: tr.getAttribute('data-active') === '1',
        };
    }

    function syncDeleteButton() {
        if (!btnDelete) {
            return;
        }
        if (!selectedRow) {
            btnDelete.disabled = true;
            btnDelete.title = 'حدد بنكاً من الجدول';
            return;
        }
        var linked = selectedRow.getAttribute('data-linked') === '1';
        btnDelete.disabled = linked;
        btnDelete.title = linked
            ? (selectedRow.getAttribute('data-linked-msg')
                || 'لا يمكن الحذف: البنك مرتبط بموظفين')
            : 'حذف البنك المحدد';
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-bank-row') || tr.classList.contains('hr-bank-row--empty')) {
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
            editorTitle.textContent = isAdd ? 'إضافة بنك' : 'تعديل بنك';
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
        if (editorName) {
            editorName.value = isAdd ? '' : (getRowData(selectedRow).name || '');
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
        if (editorName) {
            editorName.focus();
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
            appDialogAlert('حدد بنكاً من الجدول ثم اضغط «تعديل».', 'warning');
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
            appDialogAlert('حدد بنكاً من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) {
            return;
        }
        if (selectedRow.getAttribute('data-linked') === '1') {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg')
                    || 'لا يمكن حذف هذا البنك لأنه مرتبط بحركات في النظام.',
                'warning'
            );
            return;
        }
        var label = data.name || 'هذا البنك';
        appDialogConfirm('حذف «' + label + '» نهائياً من النظام؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                delForm.submit();
            }
        });
    }

    if (btnAdd) {
        btnAdd.addEventListener('click', startAdd);
    }
    if (btnEdit) {
        btnEdit.addEventListener('click', startEdit);
    }
    if (btnDelete) {
        btnDelete.addEventListener('click', submitDelete);
    }
    if (btnClose) {
        btnClose.addEventListener('click', closeEditor);
    }
    if (btnCancel) {
        btnCancel.addEventListener('click', closeEditor);
    }

    if (tbody) {
        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-bank-active-cb')) return;
            var toggleForm = cb.closest('form.hr-bank-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.hr-bank-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-bank-row');
            if (tr) {
                selectRow(tr);
            }
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-bank-row');
            if (tr) {
                selectRow(tr);
                startEdit();
            }
        });
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-bank-row');
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
        if (route !== 'hr_salary_banks') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                appDialogAlert('افتح «بنك جديد» أو «تعديل» ثم احفظ من اللوحة أو شريط الأدوات.', 'warning');
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
