(function () {
    'use strict';

    var page = document.querySelector('.hr-sal-page');
    if (!page) return;

    var canEdit = page.getAttribute('data-can-edit') === '1';
    var baseSalary = parseFloat(page.getAttribute('data-base-salary') || '0') || 0;
    var masterForm = document.getElementById(page.getAttribute('data-master-form-id') || 'hr-sal-master-form');
    var lineForm = document.getElementById(page.getAttribute('data-line-form-id') || 'hr-sal-line-form');
    var lineBase = document.getElementById('hr-sal-line-base');
    var lineComponent = document.getElementById('hr-sal-line-component');
    var linePrevComponent = document.getElementById('hr-sal-line-prev-component');
    var lineAmount = document.getElementById('hr-sal-line-amount');
    var delForm = document.getElementById('hr-sal-delete-form');
    var delComponent = document.getElementById('hr-sal-delete-component');
    var clearForm = document.getElementById('hr-sal-clear-form');
    var pickForm = document.getElementById('hr-sal-pick-form');
    var filterEmployee = document.getElementById('hr-sal-filter-employee');
    var masterEmpCode = document.getElementById('hr-sal-emp-code');
    var masterBase = document.getElementById('hr-sal-base-salary');
    var tbody = document.getElementById('hr-sal-allow-tbody');
    var hint = document.getElementById('hr-sal-allow-hint');
    var slipUrl = page.getAttribute('data-slip-url') || '';

    var allowComponents = [];
    try {
        allowComponents = JSON.parse(document.getElementById('hr-sal-allow-components-json').textContent || '[]');
    } catch (e) {
        allowComponents = [];
    }

    var selectedRow = null;
    var editingMode = null;

    var btnAdd = page.querySelector('.hr-sal-btn-add');
    var btnSave = page.querySelector('.hr-sal-btn-save');
    var btnCancel = page.querySelector('.hr-sal-btn-cancel');
    var btnEdit = page.querySelector('.hr-sal-btn-edit');
    var btnDelete = page.querySelector('.hr-sal-btn-delete');

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

    function getBaseSalary() {
        if (masterBase) {
            var n = parseFloat(String(masterBase.value || '0').replace(',', '.'));
            if (!isNaN(n)) {
                return n;
            }
        }
        return baseSalary;
    }

    function syncMasterEmpCode() {
        if (!filterEmployee || !masterEmpCode) return;
        var opt = filterEmployee.options[filterEmployee.selectedIndex];
        masterEmpCode.value = opt && opt.value ? (opt.getAttribute('data-emp-code') || '') : '';
    }

    function syncLineBase() {
        if (lineBase) {
            lineBase.value = String(getBaseSalary());
        }
    }

    function setPageEditing(on) {
        if (on) {
            page.classList.add('is-editing-allow');
        } else {
            page.classList.remove('is-editing-allow');
        }
    }

    function syncOraLovButtons() {
        page.querySelectorAll('.hr-sal-ora-lov').forEach(function (wrap) {
            var sel = wrap.querySelector('select');
            var btn = wrap.querySelector('.hr-sal-ora-lov-btn');
            if (btn && sel) {
                btn.disabled = !!sel.disabled;
            }
        });
    }

    function openOraLovSelect(btn) {
        var wrap = btn.closest('.hr-sal-ora-lov');
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

    page.querySelectorAll('.hr-sal-ora-lov-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openOraLovSelect(btn);
        });
    });
    syncOraLovButtons();

    function updateToolbar() {
        var editing = !!editingMode;
        if (btnAdd) btnAdd.disabled = !canEdit || editing;
        if (btnSave) btnSave.disabled = !canEdit || !editing;
        if (btnCancel) btnCancel.disabled = !canEdit || !editing;
        if (btnEdit) btnEdit.disabled = !canEdit || editing || !selectedRow || selectedRow.classList.contains('hr-sal-row--empty');
        if (btnDelete) btnDelete.disabled = !canEdit || editing || !selectedRow || selectedRow.classList.contains('hr-sal-row--empty');
    }

    function clearSelection() {
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow = null;
        }
        updateToolbar();
    }

    function selectRow(tr) {
        if (!tr || tr.classList.contains('hr-sal-row--empty') || tr.classList.contains('hr-sal-row--entry')) {
            return;
        }
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
        }
        selectedRow = tr;
        selectedRow.classList.add('is-selected');
        updateToolbar();
    }

    function removeEntryRow() {
        if (!tbody) return;
        var entry = tbody.querySelector('tr.hr-sal-row--entry');
        if (entry) {
            entry.remove();
        }
        if (hint) {
            hint.hidden = true;
            hint.textContent = '';
        }
    }

    function buildComponentSelect(selectedId) {
        var sel = document.createElement('select');
        sel.className = 'input input-compact hr-sal-inline-component';
        sel.setAttribute('aria-label', 'العلاوة');

        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '— اختر —';
        sel.appendChild(empty);

        allowComponents.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = (c.comp_code ? c.comp_code + ' — ' : '') + (c.name_ar || '');
            opt.setAttribute('data-is-percent', String(c.is_percent || 0));
            opt.setAttribute('data-default', String(c.default_amount || 0));
            opt.setAttribute('data-comp-code', String(c.comp_code || ''));
            if (selectedId && String(selectedId) === String(c.id)) {
                opt.selected = true;
            }
            sel.appendChild(opt);
        });

        sel.addEventListener('change', function () {
            syncInlineHint();
            var op = sel.options[sel.selectedIndex];
            var amountInp = sel.closest('tr') && sel.closest('tr').querySelector('.hr-sal-inline-amount');
            var isNew = sel.closest('tr') && sel.closest('tr').classList.contains('hr-sal-row--entry');
            if (amountInp && op && op.value && isNew) {
                amountInp.value = op.getAttribute('data-default') || '0';
            }
            var codeTd = sel.closest('tr') && sel.closest('tr').querySelector('.hr-sal-col-num');
            if (codeTd && op) {
                codeTd.textContent = op.getAttribute('data-comp-code') || '—';
            }
        });

        return sel;
    }

    function syncInlineHint() {
        if (!hint) return;
        var entry = tbody && tbody.querySelector('tr.hr-sal-row--entry');
        if (!entry || entry.hidden) {
            hint.hidden = true;
            hint.textContent = '';
            return;
        }
        var sel = entry.querySelector('.hr-sal-inline-component');
        var op = sel && sel.options[sel.selectedIndex];
        if (op && op.getAttribute('data-is-percent') === '1') {
            hint.hidden = false;
            hint.textContent = 'هذه العلاوة نسبة مئوية من الراتب الأساسي.';
        } else {
            hint.hidden = true;
            hint.textContent = '';
        }
    }

    function renderEntryRow(data) {
        if (!tbody) return null;
        removeEntryRow();

        var tr = document.createElement('tr');
        tr.className = 'hr-sal-row hr-sal-row--entry is-editing';
        tr.setAttribute('data-prev-component-id', data && data.componentId ? String(data.componentId) : '0');

        var tdCode = document.createElement('td');
        tdCode.className = 'hr-sal-col-num';
        tdCode.dir = 'ltr';
        tdCode.textContent = (data && data.compCode) || '—';

        var tdName = document.createElement('td');
        var compSel = buildComponentSelect(data && data.componentId);
        tdName.appendChild(compSel);

        var tdAmt = document.createElement('td');
        tdAmt.className = 'hr-sal-col-amount';
        var amtInp = document.createElement('input');
        amtInp.type = 'number';
        amtInp.className = 'input input-compact hr-sal-inline-amount';
        amtInp.step = '0.001';
        amtInp.min = '0';
        amtInp.dir = 'ltr';
        amtInp.inputMode = 'decimal';
        amtInp.value = (data && data.amount) || '0';
        amtInp.setAttribute('aria-label', 'المبلغ');
        tdAmt.appendChild(amtInp);

        tr.appendChild(tdCode);
        tr.appendChild(tdName);
        tr.appendChild(tdAmt);

        var empty = tbody.querySelector('tr.hr-sal-row--empty');
        if (empty) {
            empty.remove();
        }
        tbody.insertBefore(tr, tbody.firstChild);
        syncInlineHint();
        compSel.focus();
        return tr;
    }

    function startAdd() {
        if (!canEdit) return;
        clearSelection();
        editingMode = 'add';
        setPageEditing(true);
        renderEntryRow(null);
        updateToolbar();
    }

    function startEdit() {
        if (!canEdit || !selectedRow) {
            appDialogAlert('حدد علاوة من الجدول ثم اضغط «تعديل».', 'warning');
            return;
        }
        editingMode = 'edit';
        setPageEditing(true);
        renderEntryRow({
            componentId: selectedRow.getAttribute('data-component-id'),
            compCode: selectedRow.getAttribute('data-comp-code'),
            amount: selectedRow.getAttribute('data-amount'),
        });
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow.hidden = true;
        }
        updateToolbar();
    }

    function cancelEdit() {
        removeEntryRow();
        if (selectedRow) {
            selectedRow.hidden = false;
        }
        editingMode = null;
        setPageEditing(false);
        if (tbody && !tbody.querySelector('tr.hr-sal-row:not(.hr-sal-row--entry)')) {
            var tr = document.createElement('tr');
            tr.className = 'hr-sal-row hr-sal-row--empty';
            tr.innerHTML = '<td colspan="3" class="muted">لا توجد علاوات — اضغط «إضافة» واختر العلاوة من الجدول.</td>';
            tbody.appendChild(tr);
        }
        updateToolbar();
    }

    function submitLineSave() {
        if (!lineForm || !tbody) return;
        var entry = tbody.querySelector('tr.hr-sal-row--entry');
        if (!entry) return;

        var sel = entry.querySelector('.hr-sal-inline-component');
        var amtInp = entry.querySelector('.hr-sal-inline-amount');
        var compId = sel ? sel.value : '';
        var amount = amtInp ? amtInp.value : '0';

        if (!compId) {
            appDialogAlert('اختر العلاوة من القائمة.', 'warning');
            if (sel) sel.focus();
            return;
        }
        if (amount === '' || isNaN(parseFloat(amount))) {
            appDialogAlert('أدخل المبلغ.', 'warning');
            if (amtInp) amtInp.focus();
            return;
        }

        syncLineBase();
        if (lineComponent) lineComponent.value = compId;
        if (lineAmount) lineAmount.value = amount;
        if (linePrevComponent) {
            linePrevComponent.value = entry.getAttribute('data-prev-component-id') || '0';
        }
        lineForm.submit();
    }

    function submitDelete() {
        if (!selectedRow || !delForm || !delComponent) {
            appDialogAlert('حدد علاوة من الجدول ثم اضغط «حذف».', 'warning');
            return;
        }
        var compId = selectedRow.getAttribute('data-component-id');
        if (!compId) return;
        appDialogConfirm('حذف هذه العلاوة من راتب الموظف؟').then(function (ok) {
            if (ok) {
                delComponent.value = compId;
                delForm.submit();
            }
        });
    }

    function submitMasterSave() {
        if (!masterForm) {
            appDialogAlert('اختر موظفاً أولاً.', 'warning');
            return;
        }
        if (typeof masterForm.reportValidity === 'function' && !masterForm.reportValidity()) {
            return;
        }
        syncLineBase();
        if (typeof masterForm.requestSubmit === 'function') {
            masterForm.requestSubmit();
        } else {
            masterForm.submit();
        }
    }

    function submitClearSalary() {
        if (!clearForm) return;
        appDialogConfirm('مسح إعداد راتب هذا الموظف (الأساسي والعلاوات)؟').then(function (ok) {
            if (ok) {
                clearForm.submit();
            }
        });
    }

    function getSlipPrintFrame() {
        var frame = document.getElementById('hr-slip-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'hr-slip-print-frame';
            frame.className = 'sales-inv-print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute('tabindex', '-1');
            document.body.appendChild(frame);
        }
        return frame;
    }

    function printSlip() {
        var empId = page.getAttribute('data-filter-employee-id') || '0';
        if (parseInt(empId, 10) < 1) {
            appDialogAlert('اختر موظفاً أولاً.', 'warning');
            return;
        }
        var url = slipUrl + (slipUrl.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(empId);
        var frame = getSlipPrintFrame();
        var printed = false;
        frame.onload = function () {
            if (printed) return;
            printed = true;
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                /* ignored */
            }
        };
        frame.src = url;
    }

    if (filterEmployee) {
        filterEmployee.addEventListener('change', syncMasterEmpCode);
        syncMasterEmpCode();
    }

    if (masterBase) {
        masterBase.addEventListener('input', syncLineBase);
    }

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnSave) btnSave.addEventListener('click', submitLineSave);
    if (btnCancel) btnCancel.addEventListener('click', cancelEdit);
    if (btnDelete) btnDelete.addEventListener('click', submitDelete);

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var tr = e.target.closest('tr.hr-sal-row');
            if (!tr || tr.classList.contains('hr-sal-row--entry') || editingMode) return;
            selectRow(tr);
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-sal-row');
            if (!tr || tr.classList.contains('hr-sal-row--entry') || tr.classList.contains('hr-sal-row--empty')) return;
            selectRow(tr);
            startEdit();
        });
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_salaries') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editingMode) {
                submitLineSave();
            } else {
                submitMasterSave();
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editingMode) {
                cancelEdit();
            } else {
                submitClearSalary();
            }
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            startAdd();
        } else if (action === 'print_slip') {
            e.preventDefault();
            e.stopImmediatePropagation();
            printSlip();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (editingMode) {
                submitLineSave();
            } else {
                submitMasterSave();
            }
        }
    });

    updateToolbar();
})();
