(function () {
    'use strict';

    var page = document.querySelector('.hr-mpa-page');
    if (!page) return;

    var canEdit = page.getAttribute('data-can-edit') === '1';
    var baseSalary = parseFloat(page.getAttribute('data-base-salary') || '0') || 0;
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-mpa-editor-form');
    var editorId = document.getElementById('hr-mpa-editor-id');
    var editorCompType = document.getElementById('hr-mpa-editor-comp-type');
    var editorComponent = document.getElementById('hr-mpa-editor-component');
    var editorAmount = document.getElementById('hr-mpa-editor-amount');
    var editorNotes = document.getElementById('hr-mpa-editor-notes');
    var delForm = document.getElementById('hr-mpa-delete-form');
    var delIdInp = document.getElementById('hr-mpa-delete-id');
    var filterForm = document.getElementById('hr-mpa-filter-form');
    var filterEmployee = document.getElementById('hr-mpa-filter-employee');
    var masterEmpCode = document.getElementById('hr-mpa-master-emp-code');

    var allowComponents = [];
    var deductComponents = [];
    try {
        allowComponents = JSON.parse(document.getElementById('hr-mpa-allow-components-json').textContent || '[]');
    } catch (e) {
        allowComponents = [];
    }
    try {
        deductComponents = JSON.parse(document.getElementById('hr-mpa-deduct-components-json').textContent || '[]');
    } catch (e2) {
        deductComponents = [];
    }

    var activePanelType = 'allowance';
    var selectedRow = null;
    var selectedPanelType = null;
    var editingPanelType = null;
    var editingMode = null;

    var LABELS = {
        allowance: {
            compLabel: 'العلاوة',
            emptySelect: 'حدد علاوة من الجدول ثم اضغط «تعديل».',
            pickComp: 'اختر العلاوة من القائمة.',
            amountRequired: 'أدخل المبلغ.',
        },
        deduction: {
            compLabel: 'الاقتطاع',
            emptySelect: 'حدد اقتطاعاً من الجدول ثم اضغط «تعديل».',
            pickComp: 'اختر الاقتطاع من القائمة.',
            amountRequired: 'أدخل المبلغ.',
        },
    };

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

    function labelsFor(type) {
        return LABELS[type === 'deduction' ? 'deduction' : 'allowance'];
    }

    function componentsFor(type) {
        return type === 'deduction' ? deductComponents : allowComponents;
    }

    function panelSection(type) {
        return document.querySelector('.hr-mpa-lines-panel[data-panel-type="' + type + '"]');
    }

    function tbodyFor(type) {
        return document.querySelector('tbody.hr-mpa-tbody[data-panel-type="' + type + '"]');
    }

    function hintFor(type) {
        return document.getElementById(type === 'deduction' ? 'hr-mpa-deduct-hint' : 'hr-mpa-allow-hint');
    }

    function toolbarBtn(type, action) {
        var section = panelSection(type);
        if (!section) return null;
        return section.querySelector('.hr-mpa-btn-' + action + '[data-type="' + type + '"]');
    }

    function setPageEditingClass(type) {
        page.classList.remove('is-editing-allow', 'is-editing-deduct');
        if (type === 'deduction') {
            page.classList.add('is-editing-deduct');
        } else if (type === 'allowance') {
            page.classList.add('is-editing-allow');
        }
    }

    function buildComponentSelect(type, selectedId) {
        var sel = document.createElement('select');
        sel.className = 'input input-compact hr-mpa-inline-component';
        sel.setAttribute('aria-label', labelsFor(type).compLabel);

        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '— اختر —';
        sel.appendChild(empty);

        componentsFor(type).forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = c.name_ar || c.comp_code || '';
            opt.setAttribute('data-is-percent', String(c.is_percent || 0));
            opt.setAttribute('data-default', String(c.default_amount || 0));
            opt.setAttribute('data-comp-code', String(c.comp_code || ''));
            if (selectedId && String(selectedId) === String(c.id)) {
                opt.selected = true;
            }
            sel.appendChild(opt);
        });

        sel.addEventListener('change', function () {
            syncInlineHint(type);
            var op = sel.options[sel.selectedIndex];
            var row = sel.closest('tr');
            var codeTd = row && row.querySelector('.hr-mpa-col-num');
            if (codeTd && op) {
                codeTd.textContent = op.getAttribute('data-comp-code') || '—';
            }
            var amountInp = row && row.querySelector('.hr-mpa-inline-amount');
            var isNew = row && row.classList.contains('hr-mpa-row--entry');
            if (amountInp && op && op.value && isNew) {
                amountInp.value = op.getAttribute('data-default') || '0';
            }
        });

        return sel;
    }

    function syncInlineHint(type) {
        var hint = hintFor(type);
        if (!hint) return;
        var entry = tbodyFor(type) && tbodyFor(type).querySelector('tr.hr-mpa-row--entry');
        if (!entry || entry.hidden) {
            hint.hidden = true;
            hint.textContent = '';
            return;
        }
        var sel = entry.querySelector('.hr-mpa-inline-component');
        var op = sel && sel.options[sel.selectedIndex];
        if (!op || !op.value) {
            hint.hidden = true;
            hint.textContent = '';
            return;
        }
        hint.hidden = false;
        hint.textContent = op.getAttribute('data-is-percent') === '1'
            ? 'أدخل النسبة المئوية من الراتب الأساسي'
            : 'مبلغ ثابت';
    }

    function removeEmptyPlaceholder(type) {
        var tbody = tbodyFor(type);
        if (!tbody) return;
        tbody.querySelectorAll('.hr-mpa-row--empty').forEach(function (tr) {
            tr.remove();
        });
    }

    function restoreEmptyPlaceholder(type) {
        var tbody = tbodyFor(type);
        if (!tbody) return;
        var hasData = tbody.querySelector('tr.hr-mpa-row:not(.hr-mpa-row--entry):not(.hr-mpa-row--empty)');
        if (hasData) return;
        if (tbody.querySelector('.hr-mpa-row--empty')) return;
        var tr = document.createElement('tr');
        tr.className = 'hr-mpa-row hr-mpa-row--empty';
        var msg = type === 'deduction'
            ? 'لا توجد اقتطاعات — اضغط «إضافة» واختر الاقتطاع من الجدول.'
            : 'لا توجد علاوات — اضغط «إضافة» واختر العلاوة من الجدول.';
        tr.innerHTML = '<td colspan="3" class="muted">' + msg + '</td>';
        tbody.appendChild(tr);
    }

    function updateToolbar(type) {
        var isEditing = editingPanelType === type;
        var addBtn = toolbarBtn(type, 'add');
        var saveBtn = toolbarBtn(type, 'save');
        var cancelBtn = toolbarBtn(type, 'cancel');
        var editBtn = toolbarBtn(type, 'edit');
        var deleteBtn = toolbarBtn(type, 'delete');

        if (addBtn) addBtn.disabled = !canEdit || isEditing;
        if (saveBtn) saveBtn.disabled = !canEdit || !isEditing;
        if (cancelBtn) cancelBtn.disabled = !canEdit || !isEditing;
        if (editBtn) {
            editBtn.disabled = !canEdit || isEditing || !selectedRow || selectedPanelType !== type;
        }
        if (deleteBtn) {
            deleteBtn.disabled = !canEdit || isEditing || !selectedRow || selectedPanelType !== type;
        }
    }

    function updateAllToolbars() {
        updateToolbar('allowance');
        updateToolbar('deduction');
    }

    function clearPanelSelection(panelType) {
        var tbody = tbodyFor(panelType);
        if (!tbody) return;
        tbody.querySelectorAll('.hr-mpa-row.is-selected').forEach(function (tr) {
            tr.classList.remove('is-selected');
        });
    }

    function clearAllSelections() {
        clearPanelSelection('allowance');
        clearPanelSelection('deduction');
        selectedRow = null;
        selectedPanelType = null;
        updateAllToolbars();
    }

    function selectRow(tr, panelType) {
        if (!tr || tr.classList.contains('hr-mpa-row--empty') || tr.classList.contains('hr-mpa-row--entry')) {
            return;
        }
        if (editingPanelType) return;
        clearAllSelections();
        tr.classList.add('is-selected');
        selectedRow = tr;
        selectedPanelType = panelType;
        activePanelType = panelType;
        updateAllToolbars();
    }

    function removeEntryRow(type) {
        var tbody = tbodyFor(type);
        if (!tbody) return;
        var entry = tbody.querySelector('tr.hr-mpa-row--entry');
        if (entry) entry.remove();
        restoreEmptyPlaceholder(type);
        syncInlineHint(type);
    }

    function cancelEditing(type) {
        if (editingPanelType !== type) return;

        if (editingMode === 'edit' && selectedRow) {
            revertRowFromEdit(selectedRow, type);
        } else {
            removeEntryRow(type);
        }

        editingPanelType = null;
        editingMode = null;
        setPageEditingClass(null);
        clearAllSelections();
        updateAllToolbars();
    }

    function cancelAllEditing() {
        if (editingPanelType === 'allowance') cancelEditing('allowance');
        if (editingPanelType === 'deduction') cancelEditing('deduction');
    }

    function createEntryRow(type, data) {
        data = data || {};
        var tr = document.createElement('tr');
        tr.className = 'hr-mpa-row hr-mpa-row--entry is-editing';
        tr.setAttribute('data-panel-type', type);

        var tdNum = document.createElement('td');
        tdNum.className = 'hr-mpa-col-num';
        tdNum.textContent = '—';
        tr.appendChild(tdNum);

        var tdComp = document.createElement('td');
        tdComp.appendChild(buildComponentSelect(type, data.componentId || ''));
        tr.appendChild(tdComp);

        var tdAmt = document.createElement('td');
        tdAmt.className = 'hr-mpa-col-amount';
        var amtInp = document.createElement('input');
        amtInp.type = 'number';
        amtInp.className = 'input input-compact hr-mpa-inline-amount';
        amtInp.step = '0.001';
        amtInp.min = '0';
        amtInp.value = data.amount != null ? String(data.amount) : '0';
        amtInp.dir = 'ltr';
        amtInp.inputMode = 'decimal';
        amtInp.setAttribute('aria-label', 'المبلغ');
        tdAmt.appendChild(amtInp);
        tr.appendChild(tdAmt);

        return tr;
    }

    function rowToEditSnapshot(tr) {
        return {
            lineId: tr.getAttribute('data-id') || '0',
            componentId: tr.getAttribute('data-component-id') || '',
            compCode: tr.getAttribute('data-comp-code') || '',
            compName: tr.getAttribute('data-comp-name') || '',
            amount: tr.getAttribute('data-amount') || '0',
            notes: tr.getAttribute('data-notes') || '',
            amountDisplay: tr.querySelector('.hr-mpa-col-amount')
                ? tr.querySelector('.hr-mpa-col-amount').textContent
                : '',
        };
    }

    function revertRowFromEdit(tr, type) {
        var snap = tr._hrMpaSnap;
        if (!snap) return;

        tr.classList.remove('is-editing');
        tr.innerHTML =
            '<td class="hr-mpa-col-num">' + escHtml(snap.compCode || '—') + '</td>' +
            '<td>' + escHtml(snap.compName || '—') + '</td>' +
            '<td class="hr-mpa-col-amount" dir="ltr">' + escHtml(snap.amountDisplay || snap.amount) + '</td>';

        tr.setAttribute('data-id', snap.lineId);
        tr.setAttribute('data-component-id', snap.componentId);
        tr.setAttribute('data-comp-code', snap.compCode);
        tr.setAttribute('data-comp-name', snap.compName);
        tr.setAttribute('data-amount', snap.amount);
        tr.setAttribute('data-notes', snap.notes);
        tr.setAttribute('data-comp-type', type);
        tr.setAttribute('tabindex', '0');
        delete tr._hrMpaSnap;
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function convertRowToEdit(tr, type) {
        var snap = rowToEditSnapshot(tr);
        tr._hrMpaSnap = snap;
        tr.classList.add('is-editing');

        tr.innerHTML = '';
        var tdNum = document.createElement('td');
        tdNum.className = 'hr-mpa-col-num';
        tdNum.textContent = snap.compCode || '—';
        tr.appendChild(tdNum);

        var tdComp = document.createElement('td');
        tdComp.appendChild(buildComponentSelect(type, snap.componentId));
        tr.appendChild(tdComp);

        var tdAmt = document.createElement('td');
        tdAmt.className = 'hr-mpa-col-amount';
        var amtInp = document.createElement('input');
        amtInp.type = 'number';
        amtInp.className = 'input input-compact hr-mpa-inline-amount';
        amtInp.step = '0.001';
        amtInp.min = '0';
        amtInp.value = snap.amount;
        amtInp.dir = 'ltr';
        amtInp.inputMode = 'decimal';
        tdAmt.appendChild(amtInp);
        tr.appendChild(tdAmt);
    }

    function readInlineRow(tr) {
        var sel = tr.querySelector('.hr-mpa-inline-component');
        var amtInp = tr.querySelector('.hr-mpa-inline-amount');
        return {
            componentId: sel ? sel.value : '',
            amount: amtInp ? amtInp.value : '',
            notes: '',
        };
    }

    function startAdd(type) {
        if (!canEdit) return;
        cancelAllEditing();

        var tbody = tbodyFor(type);
        if (!tbody) return;

        removeEmptyPlaceholder(type);
        var tr = createEntryRow(type, { amount: '0', notes: '' });
        tbody.appendChild(tr);

        editingPanelType = type;
        editingMode = 'add';
        activePanelType = type;
        setPageEditingClass(type);
        clearAllSelections();
        updateAllToolbars();
        syncInlineHint(type);

        var sel = tr.querySelector('.hr-mpa-inline-component');
        if (sel) sel.focus();
    }

    function startEdit(type) {
        if (!canEdit) return;
        if (!selectedRow || selectedPanelType !== type) {
            appDialogAlert(labelsFor(type).emptySelect, 'warning');
            return;
        }
        cancelAllEditing();

        convertRowToEdit(selectedRow, type);
        editingPanelType = type;
        editingMode = 'edit';
        activePanelType = type;
        setPageEditingClass(type);
        updateAllToolbars();

        var amt = selectedRow.querySelector('.hr-mpa-inline-amount');
        if (amt) amt.focus();
    }

    function submitInlineSave(type) {
        if (!canEdit || !editorForm) return;
        var tbody = tbodyFor(type);
        if (!tbody) return;

        var tr = editingMode === 'edit' && selectedRow
            ? selectedRow
            : tbody.querySelector('tr.hr-mpa-row--entry');
        if (!tr) return;

        var data = readInlineRow(tr);
        var lbl = labelsFor(type);

        if (!data.componentId) {
            appDialogAlert(lbl.pickComp, 'warning');
            return;
        }
        if (data.amount === '' || isNaN(parseFloat(data.amount)) || parseFloat(data.amount) < 0) {
            appDialogAlert(lbl.amountRequired, 'warning');
            return;
        }

        if (editorId) {
            editorId.value = editingMode === 'edit' ? (tr.getAttribute('data-id') || tr._hrMpaSnap && tr._hrMpaSnap.lineId || '0') : '0';
        }
        if (editorCompType) editorCompType.value = type;
        if (editorComponent) editorComponent.value = data.componentId;
        if (editorAmount) editorAmount.value = data.amount;
        if (editorNotes) editorNotes.value = data.notes;

        editorForm.submit();
    }

    function submitDelete(type) {
        if (!canEdit || !selectedRow || selectedPanelType !== type) {
            appDialogAlert('حدد بنداً من الجدول أولاً.', 'warning');
            return;
        }
        appDialogConfirm('حذف هذا البند من الشهر؟').then(function (ok) {
            if (!ok || !delForm || !delIdInp) return;
            delIdInp.value = selectedRow.getAttribute('data-id') || '0';
            delForm.submit();
        });
    }

    function syncMasterFromEmployee() {
        if (!filterEmployee) return;
        var op = filterEmployee.options[filterEmployee.selectedIndex];
        if (masterEmpCode) {
            masterEmpCode.value = op && op.value ? (op.getAttribute('data-emp-code') || '') : '';
        }
    }

    function syncOraLovButtons() {
        page.querySelectorAll('.hr-mpa-ora-lov').forEach(function (wrap) {
            var sel = wrap.querySelector('select');
            var btn = wrap.querySelector('.hr-mpa-ora-lov-btn');
            if (btn && sel) {
                btn.disabled = !!sel.disabled;
            }
        });
    }

    function openOraLovSelect(btn) {
        var wrap = btn.closest('.hr-mpa-ora-lov');
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

    page.querySelectorAll('.hr-mpa-ora-lov-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openOraLovSelect(btn);
        });
    });
    syncOraLovButtons();

    document.querySelectorAll('.hr-mpa-btn-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            startAdd(btn.getAttribute('data-type') || 'allowance');
        });
    });

    document.querySelectorAll('.hr-mpa-btn-save').forEach(function (btn) {
        btn.addEventListener('click', function () {
            submitInlineSave(btn.getAttribute('data-type') || 'allowance');
        });
    });

    document.querySelectorAll('.hr-mpa-btn-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            cancelEditing(btn.getAttribute('data-type') || 'allowance');
        });
    });

    document.querySelectorAll('.hr-mpa-btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            startEdit(btn.getAttribute('data-type') || 'allowance');
        });
    });

    document.querySelectorAll('.hr-mpa-btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            submitDelete(btn.getAttribute('data-type') || 'allowance');
        });
    });

    document.querySelectorAll('tbody.hr-mpa-tbody').forEach(function (tbody) {
        var panelType = tbody.getAttribute('data-panel-type') || 'allowance';
        tbody.addEventListener('click', function (e) {
            if (editingPanelType) return;
            var tr = e.target.closest('tr.hr-mpa-row');
            if (tr) selectRow(tr, panelType);
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-mpa-row');
            if (!tr || tr.classList.contains('hr-mpa-row--empty') || tr.classList.contains('hr-mpa-row--entry')) {
                return;
            }
            selectRow(tr, panelType);
            startEdit(panelType);
        });
    });

    if (filterForm && filterEmployee) {
        filterEmployee.addEventListener('change', syncMasterFromEmployee);
    }

    syncMasterFromEmployee();
    updateAllToolbars();

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_monthly_payroll_adjustments') return;

        var action = e.detail.action;
        var type = editingPanelType || activePanelType || 'allowance';

        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editingPanelType) {
                submitInlineSave(editingPanelType);
            } else if (selectedRow && selectedPanelType) {
                startEdit(selectedPanelType);
            } else {
                appDialogAlert('اضغط «إضافة» أو «تعديل» ثم احفظ.', 'warning');
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete(selectedPanelType || type);
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            startAdd(type);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (editingPanelType) {
                submitInlineSave(editingPanelType);
            }
        }
    });
})();
