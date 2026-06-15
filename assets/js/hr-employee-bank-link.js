(function () {
    'use strict';

    var page = document.querySelector('.hr-emp-bank-grid-page');
    if (!page) return;

    var editor = document.getElementById('hr-emp-bank-editor');
    var editorTitle = document.getElementById('hr-emp-bank-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-emp-bank-editor-form');
    var editorCodeDisplay = document.getElementById('hr-emp-bank-editor-code-display');
    var editorEmployee = document.getElementById('hr-emp-bank-editor-employee');
    var editorBank = document.getElementById('hr-emp-bank-editor-bank');
    var editorIban = document.getElementById('hr-emp-bank-editor-iban');
    var editorActive = document.getElementById('hr-emp-bank-editor-active');
    var btnAdd = document.getElementById('hr-emp-bank-btn-add');
    var btnEdit = document.getElementById('hr-emp-bank-btn-edit');
    var btnDel = document.getElementById('hr-emp-bank-btn-del');
    var btnClose = document.getElementById('hr-emp-bank-editor-close');
    var btnCancel = document.getElementById('hr-emp-bank-editor-cancel');
    var delForm = document.getElementById('hr-emp-bank-delete-form');
    var delIdInp = document.getElementById('hr-emp-bank-delete-id');
    var tbody = document.getElementById('hr-emp-bank-grid-body');
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
            bankId: tr.getAttribute('data-bank-id') || '0',
            account: tr.getAttribute('data-account') || '',
            active: tr.getAttribute('data-active') === '1',
            hasLink: tr.getAttribute('data-has-link') === '1',
        };
    }

    function updateCodeFromEmployee() {
        if (!editorEmployee || !editorCodeDisplay) return;
        var opt = editorEmployee.options[editorEmployee.selectedIndex];
        if (opt && opt.value) {
            editorCodeDisplay.textContent = opt.getAttribute('data-emp-code') || '—';
            if (editorActive && opt.getAttribute('data-active') !== null) {
                editorActive.checked = opt.getAttribute('data-active') === '1';
            }
        } else {
            editorCodeDisplay.textContent = '—';
        }
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-emp-bank-row') || tr.classList.contains('hr-emp-bank-row--empty')) {
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

    function syncDeleteButton() {
        if (!btnDel) return;
        if (!selectedRow) {
            btnDel.disabled = true;
            btnDel.title = 'حدد موظفاً من الجدول';
            return;
        }
        var linked = selectedRow.getAttribute('data-linked') === '1';
        btnDel.disabled = linked;
        btnDel.title = linked
            ? (selectedRow.getAttribute('data-linked-msg')
                || 'لا يمكن إزالة الربط لوجود حركات مرتبطة')
            : 'حذف ربط البنك عن الموظف المحدد';
    }

    function setEmployeeLocked(locked) {
        if (!editorEmployee) return;
        editorEmployee.disabled = locked;
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        if (editorTitle) {
            editorTitle.textContent = isAdd ? 'إضافة ربط بنك' : 'تعديل ربط بنك';
        }

        if (isAdd) {
            setEmployeeLocked(false);
            if (editorEmployee) {
                editorEmployee.value = '';
            }
            if (editorCodeDisplay) {
                editorCodeDisplay.textContent = '—';
            }
            if (editorBank) {
                editorBank.value = '';
            }
            if (editorIban) {
                editorIban.value = '';
            }
            if (editorActive) {
                editorActive.checked = true;
            }
        } else {
            var rd = getRowData(selectedRow);
            setEmployeeLocked(true);
            if (editorEmployee && rd) {
                editorEmployee.value = String(rd.id);
            }
            if (editorCodeDisplay && rd) {
                editorCodeDisplay.textContent = rd.code || '—';
            }
            if (editorBank && rd) {
                editorBank.value = rd.bankId && parseInt(rd.bankId, 10) > 0 ? String(rd.bankId) : '';
            }
            if (editorIban && rd) {
                editorIban.value = rd.account || '';
            }
            if (editorActive && rd) {
                editorActive.checked = rd.active;
            }
        }

        editor.hidden = false;
        page.classList.add('is-editing');
        if (isAdd && editorEmployee) {
            editorEmployee.focus();
        } else if (editorBank) {
            editorBank.focus();
        }
    }

    function closeEditor() {
        if (editor) {
            editor.hidden = true;
        }
        page.classList.remove('is-editing');
        setEmployeeLocked(false);
    }

    function startAdd() {
        clearSelection();
        openEditor('add');
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد موظفاً من الجدول ثم اضغط «تعديل».', 'warning');
            return;
        }
        openEditor('edit');
    }

    function submitEditorSave() {
        if (!editorForm) return;
        if (!editorEmployee || !editorEmployee.value) {
            appDialogAlert('اختر الموظف أولاً.', 'warning');
            return;
        }
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            return;
        }
        if (typeof editorForm.requestSubmit === 'function') {
            editorForm.requestSubmit();
        } else {
            editorForm.submit();
        }
    }

    function confirmClearLink(empId, label, linkedMsg) {
        if (empId < 1) return;
        if (linkedMsg) {
            appDialogAlert(linkedMsg, 'warning');
            return;
        }
        appDialogConfirm('حذف ربط البنك عن «' + (label || 'هذا الموظف') + '»؟').then(function (ok) {
            if (ok && delForm && delIdInp) {
                delIdInp.value = String(empId);
                delForm.submit();
            }
        });
    }

    function submitDelete() {
        if (!selectedRow) {
            appDialogAlert('حدد موظفاً من الجدول ثم اضغط «حذف الربط».', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) {
            return;
        }
        var linkedMsg = selectedRow.getAttribute('data-linked') === '1'
            ? (selectedRow.getAttribute('data-linked-msg') || 'لا يمكن إزالة الربط لوجود حركات مرتبطة.')
            : '';
        confirmClearLink(data.id, data.name, linkedMsg);
    }

    if (editorEmployee) {
        editorEmployee.addEventListener('change', updateCodeFromEmployee);
    }

    if (btnAdd) {
        btnAdd.addEventListener('click', startAdd);
    }
    if (btnEdit) {
        btnEdit.addEventListener('click', startEdit);
    }
    if (btnDel) {
        btnDel.addEventListener('click', submitDelete);
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
            if (!cb || !cb.classList.contains('hr-emp-bank-active-cb')) return;
            var toggleForm = cb.closest('form.hr-emp-bank-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
        tbody.addEventListener('click', function (e) {
            var rowDel = e.target.closest('.hr-emp-bank-row-del');
            if (rowDel && !rowDel.disabled) {
                e.stopPropagation();
                var empId = parseInt(rowDel.getAttribute('data-id') || '0', 10);
                var empName = rowDel.getAttribute('data-name') || '';
                confirmClearLink(empId, empName, '');
                return;
            }
            if (e.target.closest('.hr-emp-bank-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-emp-bank-row');
            if (tr) {
                selectRow(tr);
            }
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-emp-bank-row');
            if (tr) {
                selectRow(tr);
                startEdit();
            }
        });
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-emp-bank-row');
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
        if (route !== 'hr_employee_bank_link') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                appDialogAlert('افتح «ربط جديد» أو «تعديل» ثم احفظ.', 'warning');
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
