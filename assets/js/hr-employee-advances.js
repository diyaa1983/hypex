(function () {
    'use strict';

    var page = document.querySelector('.hr-adv-grid-page');
    if (!page) return;

    var editor = document.getElementById('hr-adv-editor');
    var editorTitle = document.getElementById('hr-adv-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-adv-editor-form');
    var editorId = document.getElementById('hr-adv-editor-id');
    var editorCodeDisplay = document.getElementById('hr-adv-editor-code-display');
    var editorCodeHint = document.getElementById('hr-adv-editor-code-hint');
    var nextCode = page.getAttribute('data-next-code') || '';
    var editorEmployee = document.getElementById('hr-adv-editor-employee');
    var editorAmount = document.getElementById('hr-adv-editor-amount');
    var editorDeductDate = document.getElementById('hr-adv-editor-deduct-date');
    var editorStart = document.getElementById('hr-adv-editor-start');
    var editorEnd = document.getElementById('hr-adv-editor-end');
    var editorNotes = document.getElementById('hr-adv-editor-notes');
    var datesOnce = document.getElementById('hr-adv-dates-once');
    var datesLong = document.getElementById('hr-adv-dates-long');
    var typeRadios = document.querySelectorAll('.hr-adv-type-radio');
    var btnAdd = document.getElementById('hr-adv-btn-add');
    var btnEdit = document.getElementById('hr-adv-btn-edit');
    var btnClose = document.getElementById('hr-adv-editor-close');
    var btnCancel = document.getElementById('hr-adv-editor-cancel');
    var delForm = document.getElementById('hr-adv-delete-form');
    var delIdInp = document.getElementById('hr-adv-delete-id');
    var filterEmployee = document.getElementById('hr-adv-filter-employee');
    var filterEmployeeIdInp = document.getElementById('hr-adv-filter-employee-id');
    var deleteFilterEmployeeIdInp = document.getElementById('hr-adv-delete-filter-employee-id');
    var pickerCode = document.getElementById('hr-adv-picker-code');
    var pickerCount = document.getElementById('hr-adv-picker-count');
    var listUrl = page.getAttribute('data-list-url') || '';
    var filterEmpId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10);
    var tbody = document.getElementById('hr-adv-grid-body');
    var selectedRow = null;
    var exitUrl = page.getAttribute('data-exit-url') || '';
    var editorSnapshot = null;
    var formSubmitting = false;

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

    function syncFilterHiddenFields() {
        var id = filterEmployee ? parseInt(filterEmployee.value || '0', 10) : filterEmpId;
        if (isNaN(id) || id < 0) {
            id = 0;
        }
        if (filterEmployeeIdInp) {
            filterEmployeeIdInp.value = String(id);
        }
        if (deleteFilterEmployeeIdInp) {
            deleteFilterEmployeeIdInp.value = String(id);
        }
    }

    function syncPickerDisplay() {
        if (!filterEmployee) return;
        var op = filterEmployee.options[filterEmployee.selectedIndex];
        if (pickerCode) {
            if (!op || !op.value) {
                pickerCode.value = '—';
            } else {
                var code = op.getAttribute('data-emp-code') || '';
                pickerCode.value = code !== '' ? code : '—';
            }
        }
        if (pickerCount) {
            if (!op || !op.value) {
                pickerCount.textContent = '—';
            } else {
                pickerCount.textContent = String(op.getAttribute('data-advance-count') || '0');
            }
        }
        if (btnAdd) {
            btnAdd.disabled = !op || !op.value;
        }
    }

    function employeeFilterUrl(employeeId) {
        var id = parseInt(String(employeeId || '0'), 10);
        if (isNaN(id) || id < 1) {
            return listUrl;
        }
        return listUrl + '&employee_id=' + encodeURIComponent(String(id));
    }

    function navigateToEmployeeFilter(employeeId, previousValue) {
        var url = employeeFilterUrl(employeeId);
        confirmUnsavedChanges(function () {
            window.location.href = url;
        }, function () {
            if (filterEmployee) {
                filterEmployee.value = previousValue || '';
            }
            syncPickerDisplay();
            syncFilterHiddenFields();
        });
    }

    function getFilterEmployeeId() {
        return parseInt(page.getAttribute('data-filter-employee-id') || '0', 10) || 0;
    }

    function isEmployeeFieldLocked() {
        return getFilterEmployeeId() > 0;
    }

    function setEditorEmployeeLocked(locked) {
        if (!editorEmployee) return;
        editorEmployee.disabled = !!locked;
        syncOraLovButtons();
    }

    function syncOraLovButtons() {
        page.querySelectorAll('.hr-adv-ora-lov').forEach(function (wrap) {
            var sel = wrap.querySelector('select');
            var btn = wrap.querySelector('.hr-adv-ora-lov-btn');
            if (btn && sel) {
                btn.disabled = !!sel.disabled;
            }
        });
    }

    function openOraLovSelect(btn) {
        var wrap = btn.closest('.hr-adv-ora-lov');
        if (!wrap) return;
        var sel = wrap.querySelector('select');
        if (!sel || sel.disabled) return;
        sel.focus();
        try {
            if (typeof sel.showPicker === 'function') {
                sel.showPicker();
                return;
            }
        } catch (e) {
            /* ignored */
        }
        sel.click();
    }

    page.querySelectorAll('.hr-adv-ora-lov-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openOraLovSelect(btn);
        });
    });

    function isEditorOpen() {
        return !!(editor && !editor.hidden);
    }

    function isEditorReadOnly() {
        if (!editorForm) return false;
        var id = editorId ? parseInt(editorId.value || '0', 10) : 0;
        if (id < 1) return false;
        return !!(selectedRow && selectedRow.getAttribute('data-linked') === '1');
    }

    function captureEditorSnapshot() {
        return {
            type: getSelectedType(),
            employeeId: editorEmployee ? String(editorEmployee.value || '') : '',
            amount: editorAmount ? String(editorAmount.value || '').trim() : '',
            deductDate: editorDeductDate ? String(editorDeductDate.value || '').trim() : '',
            start: editorStart ? String(editorStart.value || '').trim() : '',
            end: editorEnd ? String(editorEnd.value || '').trim() : '',
            notes: editorNotes ? String(editorNotes.value || '').trim() : '',
        };
    }

    function syncEditorSnapshot() {
        editorSnapshot = captureEditorSnapshot();
    }

    function hasUnsavedChanges() {
        if (formSubmitting || !isEditorOpen() || isEditorReadOnly()) {
            return false;
        }
        if (!editorSnapshot) {
            return true;
        }
        return JSON.stringify(captureEditorSnapshot()) !== JSON.stringify(editorSnapshot);
    }

    function resolveExitUrl() {
        if (exitUrl) return exitUrl;
        var bar = document.getElementById('master-toolbar');
        return bar ? (bar.getAttribute('data-exit-url') || '') : '';
    }

    function confirmUnsavedChanges(onProceed, onCancel) {
        if (!hasUnsavedChanges()) {
            if (onProceed) onProceed();
            return;
        }

        var showFallback = function () {
            if (window.confirm('هل تريد حفظ التغييرات قبل المغادرة؟')) {
                submitEditorSave();
            } else if (onProceed) {
                onProceed();
            } else if (onCancel) {
                onCancel();
            }
        };

        if (!window.AppDialog || typeof AppDialog.confirmSaveDiscard !== 'function') {
            showFallback();
            return;
        }

        AppDialog.confirmSaveDiscard('هل تريد حفظ التغييرات قبل مغادرة الشاشة؟', {
            title: 'حفظ التغييرات',
            saveText: 'نعم، احفظ',
            discardText: 'لا، بدون حفظ',
            cancelText: 'إلغاء',
            theme: 'oracle',
        }).then(function (choice) {
            if (choice === 'save') {
                submitEditorSave();
                return;
            }
            if (choice === 'discard' && onProceed) {
                syncEditorSnapshot();
                onProceed();
                return;
            }
            if (onCancel) {
                onCancel();
            }
        });
    }

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            code: tr.getAttribute('data-code') || '',
            employeeId: parseInt(tr.getAttribute('data-employee-id') || '0', 10),
            type: tr.getAttribute('data-type') || 'once',
            amount: tr.getAttribute('data-amount') || '',
            start: tr.getAttribute('data-start') || '',
            end: tr.getAttribute('data-end') || '',
            deduct: tr.getAttribute('data-deduct') || '',
            notes: tr.getAttribute('data-notes') || '',
            status: tr.getAttribute('data-status') || '',
        };
    }

    function getSelectedType() {
        var r = document.querySelector('.hr-adv-type-radio:checked');
        return r ? r.value : 'once';
    }

    function syncTypeUi() {
        var t = getSelectedType();
        var isOnce = t === 'once';
        if (datesOnce) datesOnce.hidden = !isOnce;
        if (datesLong) datesLong.hidden = isOnce;
        if (editorDeductDate) {
            editorDeductDate.required = isOnce;
            if (!isOnce) editorDeductDate.value = '';
        }
        if (editorStart) {
            editorStart.required = !isOnce;
            if (isOnce) editorStart.value = '';
        }
        if (editorEnd) {
            editorEnd.required = !isOnce;
            if (isOnce) editorEnd.value = '';
        }
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-adv-row') || tr.classList.contains('hr-adv-row--empty')) {
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
    }

    function clearSelection() {
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow = null;
        }
        if (btnEdit) {
            btnEdit.disabled = true;
        }
    }

    function setTypeRadio(type) {
        typeRadios.forEach(function (r) {
            r.checked = r.value === type;
        });
        syncTypeUi();
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        if (editorTitle) {
            editorTitle.textContent = isAdd ? 'إضافة سلفة' : 'تعديل سلفة';
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
                : 'الرقم ثابت';
        }

        if (isAdd) {
            setTypeRadio('once');
            var filterId = getFilterEmployeeId();
            if (editorEmployee) {
                editorEmployee.value = filterId > 0 ? String(filterId) : '';
            }
            if (editorAmount) editorAmount.value = '';
            if (editorDeductDate) editorDeductDate.value = '';
            if (editorStart) editorStart.value = '';
            if (editorEnd) editorEnd.value = '';
            if (editorNotes) editorNotes.value = '';
        } else {
            var rd = getRowData(selectedRow);
            setTypeRadio(rd.type || 'once');
            if (editorEmployee) editorEmployee.value = String(rd.employeeId || '');
            if (editorAmount) editorAmount.value = rd.amount || '';
            if (editorNotes) editorNotes.value = rd.notes || '';
            if (rd.type === 'long') {
                if (editorStart) editorStart.value = rd.start || '';
                if (editorEnd) editorEnd.value = rd.end || '';
            } else {
                if (editorDeductDate) editorDeductDate.value = rd.deduct || rd.start || '';
            }
        }

        var linked = !isAdd && selectedRow && selectedRow.getAttribute('data-linked') === '1';
        if (editorForm) {
            editorForm.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'hidden') return;
                el.disabled = linked;
            });
        }
        if (!linked && isEmployeeFieldLocked()) {
            setEditorEmployeeLocked(true);
        }
        syncOraLovButtons();

        editor.hidden = false;
        page.classList.add('is-editing');
        syncTypeUi();
        syncEditorSnapshot();
        if (editorEmployee && !linked) {
            editorEmployee.focus();
        }
    }

    function closeEditor(force) {
        if (!force && hasUnsavedChanges()) {
            confirmUnsavedChanges(function () {
                closeEditor(true);
            });
            return;
        }
        if (editor) {
            editor.hidden = true;
            if (editorForm) {
                editorForm.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.disabled = false;
                });
            }
            syncOraLovButtons();
        }
        page.classList.remove('is-editing');
        editorSnapshot = null;
    }

    function startAdd() {
        if (getFilterEmployeeId() < 1) {
            appDialogAlert('اختر موظفاً من القائمة أعلاه ثم اضغط «إضافة سلفة».', 'warning');
            if (filterEmployee) {
                filterEmployee.focus();
            }
            return;
        }
        confirmUnsavedChanges(function () {
            clearSelection();
            openEditor('add');
        });
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد سلفة من الجدول ثم اضغط «تعديل».', 'warning');
            return;
        }
        if (selectedRow.getAttribute('data-status') === 'cancelled') {
            appDialogAlert('السلفة ملغاة ولا يمكن تعديلها.', 'warning');
            return;
        }
        confirmUnsavedChanges(function () {
            openEditor('edit');
        });
    }

    function submitEditorSave() {
        if (!editorForm) return;
        syncTypeUi();
        syncFilterHiddenFields();
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            formSubmitting = false;
            return;
        }
        if (isEmployeeFieldLocked() && editorEmployee) {
            editorEmployee.disabled = false;
        }
        formSubmitting = true;
        syncEditorSnapshot();
        if (typeof editorForm.requestSubmit === 'function') {
            editorForm.requestSubmit();
        } else {
            editorForm.submit();
        }
    }

    function navigateAway(url) {
        if (!url) return;
        confirmUnsavedChanges(function () {
            window.location.href = url;
        });
    }

    function submitDelete() {
        if (!delForm || !delIdInp) return;
        if (!selectedRow) {
            appDialogAlert('حدد سلفة من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) return;
        if (selectedRow.getAttribute('data-linked') === '1') {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg') || 'لا يمكن حذف السلفة بعد اقتطاعها من الراتب.',
                'warning'
            );
            return;
        }
        var label = data.code ? 'سلفة #' + data.code : 'هذه السلفة';
        appDialogConfirm('حذف «' + label + '» نهائياً؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                syncFilterHiddenFields();
                delForm.submit();
            }
        });
    }

    if (filterEmployee) {
        syncPickerDisplay();
        syncFilterHiddenFields();
        filterEmployee.addEventListener('focus', function () {
            filterEmployee.setAttribute('data-prev-value', filterEmployee.value);
        });
        filterEmployee.addEventListener('change', function () {
            var prev = filterEmployee.getAttribute('data-prev-value') || '';
            var newVal = filterEmployee.value;
            if (newVal === prev) {
                return;
            }
            syncPickerDisplay();
            syncFilterHiddenFields();
            navigateToEmployeeFilter(newVal, prev);
        });
    }

    typeRadios.forEach(function (r) {
        r.addEventListener('change', syncTypeUi);
    });

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnClose) btnClose.addEventListener('click', closeEditor);
    if (btnCancel) btnCancel.addEventListener('click', closeEditor);

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var tr = e.target.closest('tr.hr-adv-row');
            if (tr) selectRow(tr);
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-adv-row');
            if (tr) {
                selectRow(tr);
                startEdit();
            }
        });
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_employee_advances') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                appDialogAlert('افتح «إضافة سلفة» أو «تعديل» ثم احفظ.', 'warning');
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete();
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            startAdd();
        } else if (action === 'exit') {
            e.preventDefault();
            e.stopImmediatePropagation();
            navigateAway(resolveExitUrl());
        }
    }, true);

    document.addEventListener('click', function (e) {
        var bar = document.getElementById('master-toolbar');
        if ((bar ? bar.getAttribute('data-active-route') || '' : '') !== 'hr_employee_advances') {
            return;
        }
        var exitLink = e.target.closest('.ora12-title-bar__close, .hr-ora-title-bar__close, .nav-exit-btn');
        if (!exitLink) return;
        if ((exitLink.classList.contains('ora12-title-bar__close') || exitLink.classList.contains('hr-ora-title-bar__close')) && !page.contains(exitLink)) {
            return;
        }
        var href = exitLink.getAttribute('href');
        if (!href) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        navigateAway(href);
    }, true);

    window.addEventListener('beforeunload', function (e) {
        if (!hasUnsavedChanges()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    syncTypeUi();
    syncOraLovButtons();
})();
