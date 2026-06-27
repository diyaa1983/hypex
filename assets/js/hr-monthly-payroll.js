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
    var pickerId = document.getElementById('hr-mpa-picker-id');
    var pickerOpen = document.getElementById('hr-mpa-picker-id_open');
    var pickerDisplay = document.getElementById('hr-mpa-picker-id_display');
    var masterEmpCode = document.getElementById('hr-mpa-master-emp-code');
    var filterYear = document.getElementById('hr-mpa-filter-year');
    var filterMonth = document.getElementById('hr-mpa-filter-month');
    var filterMonthName = document.getElementById('hr-mpa-filter-month-name');
    var filterMonthStatus = document.getElementById('hr-mpa-filter-month-status');
    var listUrl = page.getAttribute('data-list-url') || '';
    var pickerApi = null;

    var monthStatusLabels = { empty: '—' };
    var statusLabelsEl = document.getElementById('hr-mpa-month-status-labels-json');
    if (statusLabelsEl) {
        try {
            monthStatusLabels = JSON.parse(statusLabelsEl.textContent || '{}');
        } catch (eStatus) {
            monthStatusLabels = { empty: '—' };
        }
    }

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

    function normalizeDigits(value) {
        return String(value || '')
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
    }

    function normalizeSearchText(value) {
        return normalizeDigits(String(value || '')).trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function parseEmployeeList() {
        var el = document.getElementById('hr-mpa-picker-json');
        if (!el) return [];
        try {
            return JSON.parse(el.textContent || '[]') || [];
        } catch (err) {
            return [];
        }
    }

    var employeeItems = parseEmployeeList();
    var employeesById = {};
    var employeeCodeMap = {};
    employeeItems.forEach(function (emp) {
        var id = parseInt(emp.id, 10);
        var code = normalizeDigits(String(emp.code || '').trim());
        if (id > 0) {
            employeesById[id] = emp;
            if (code !== '') {
                employeeCodeMap[code] = id;
            }
        }
    });

    function selectedEmployeeId() {
        if (!pickerId) return 0;
        return parseInt(pickerId.value || '0', 10) || 0;
    }

    function getSelectedYear() {
        var pageYear = parseInt(page.getAttribute('data-pay-year') || '0', 10) || new Date().getFullYear();
        var year = parseInt(filterYear && filterYear.value ? filterYear.value : String(pageYear), 10);
        if (isNaN(year) || year < 2000 || year > 2100) {
            year = pageYear;
        }
        return year;
    }

    function getSelectedMonth() {
        var pageMonth = parseInt(page.getAttribute('data-pay-month') || '0', 10) || 1;
        var month = parseInt(filterMonth && filterMonth.value ? filterMonth.value : String(pageMonth), 10);
        if (isNaN(month) || month < 1 || month > 12) {
            month = pageMonth;
        }
        return month;
    }

    function syncMonthMetaFields() {
        if (!filterMonth) {
            return;
        }
        var opt = filterMonth.options[filterMonth.selectedIndex];
        if (!opt) {
            return;
        }
        var statusKey = opt.getAttribute('data-status') || 'empty';
        if (filterMonthName) {
            filterMonthName.value = opt.getAttribute('data-name') || opt.textContent.trim();
        }
        if (filterMonthStatus) {
            filterMonthStatus.value = monthStatusLabels[statusKey] || monthStatusLabels.empty || '—';
            filterMonthStatus.setAttribute('data-status', statusKey);
        }
    }

    function filterUrl(employeeId) {
        var url = listUrl;
        url += '&year=' + encodeURIComponent(String(getSelectedYear()));
        url += '&month=' + encodeURIComponent(String(getSelectedMonth()));
        var id = parseInt(String(employeeId || '0'), 10);
        if (!isNaN(id) && id > 0) {
            url += '&employee_id=' + encodeURIComponent(String(id));
        }
        return url;
    }

    function navigateToFilter(employeeId) {
        window.location.href = filterUrl(employeeId);
    }

    function codeToEmployeeId(rawCode) {
        var code = normalizeDigits(String(rawCode || '').trim());
        if (code === '' || code === '—') {
            return 0;
        }
        return parseInt(employeeCodeMap[code] || '0', 10) || 0;
    }

    function printCurrentScreen() {
        if (selectedEmployeeId() < 1) {
            appDialogAlert('اختر موظفاً أولاً ثم اطبع.', 'warning');
            return;
        }
        if (editingPanelType) {
            appDialogAlert('احفظ أو ألغِ التعديل أولاً قبل الطباعة.', 'warning');
            return;
        }
        window.print();
    }

    function syncCodeInputFromEmployee(emp) {
        if (!masterEmpCode) return;
        if (!emp || !emp.id) {
            masterEmpCode.value = '';
            return;
        }
        masterEmpCode.value = String(emp.code || '').trim();
    }

    function initEmployeePickerModal() {
        if (!pickerId || !pickerOpen || !pickerDisplay) return;
        if (!window.EmployeePickerModal) {
            setTimeout(initEmployeePickerModal, 40);
            return;
        }
        pickerApi = EmployeePickerModal.bind({
            hidden: 'hr-mpa-picker-id',
            open: 'hr-mpa-picker-id_open',
            display: 'hr-mpa-picker-id_display',
            jsonId: 'hr-mpa-picker-json',
            employees: employeeItems,
            allowNew: false,
            placeholder: 'اضغط لاختيار الموظف',
            initialId: selectedEmployeeId() || '',
            onSelect: function (emp) {
                syncCodeInputFromEmployee(emp);
                var nextId = emp && emp.id ? parseInt(emp.id, 10) : 0;
                var currentId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10) || 0;
                var currentYear = parseInt(page.getAttribute('data-pay-year') || '0', 10) || getSelectedYear();
                var currentMonth = parseInt(page.getAttribute('data-pay-month') || '0', 10) || getSelectedMonth();
                if (nextId === currentId && getSelectedYear() === currentYear && getSelectedMonth() === currentMonth) {
                    return;
                }
                navigateToFilter(nextId);
            },
        });
        if (pickerApi) {
            syncCodeInputFromEmployee(pickerApi.getEmployee());
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

    function setupInlineComponentPicker(selectEl, inputEl, listEl, toggleBtn) {
        if (!selectEl || !inputEl || !listEl) return null;
        var closeTimer = null;
        var isOpen = false;
        var activeIndex = -1;
        var optionItems = [];

        function rebuildItems() {
            optionItems = [];
            Array.prototype.forEach.call(selectEl.options || [], function (opt) {
                if (!opt || !opt.value) return;
                var label = String(opt.textContent || '').trim();
                var code = String(opt.getAttribute('data-comp-code') || '').trim();
                optionItems.push({
                    value: String(opt.value),
                    label: label,
                    code: code,
                    search: normalizeSearchText((code ? code + ' ' : '') + label),
                });
            });
        }

        function syncInputFromSelect() {
            var op = selectEl.options[selectEl.selectedIndex];
            inputEl.value = op && op.value ? String(op.textContent || '').trim() : '';
        }

        function closeList() {
            clearTimeout(closeTimer);
            listEl.hidden = true;
            listEl.innerHTML = '';
            isOpen = false;
            activeIndex = -1;
        }

        function scheduleClose() {
            clearTimeout(closeTimer);
            closeTimer = setTimeout(closeList, 170);
        }

        function highlightActive() {
            var buttons = listEl.querySelectorAll('.hr-mpa-inline-component-item[data-value]');
            Array.prototype.forEach.call(buttons, function (btn, idx) {
                btn.classList.toggle('is-active', idx === activeIndex);
            });
            if (activeIndex >= 0 && buttons[activeIndex]) {
                buttons[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function applySelection(value, emitChange) {
            var nextValue = String(value || '').trim();
            if (!nextValue) return;
            var prevValue = String(selectEl.value || '').trim();
            selectEl.value = nextValue;
            syncInputFromSelect();
            closeList();
            if (emitChange && prevValue !== nextValue) {
                try {
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e) {
                    selectEl.dispatchEvent(new Event('change'));
                }
            }
        }

        function renderList(queryText, browseAll) {
            if (selectEl.disabled || inputEl.disabled) {
                closeList();
                return;
            }
            if (!optionItems.length) {
                rebuildItems();
            }
            var needle = browseAll ? '' : normalizeSearchText(queryText);
            var matches = optionItems.filter(function (item) {
                return needle === '' || item.search.indexOf(needle) >= 0;
            });

            listEl.innerHTML = '';
            activeIndex = -1;

            if (matches.length) {
                var head = document.createElement('div');
                head.className = 'hr-mpa-inline-component-list-head';
                head.innerHTML = '<span>اسم البند</span><span dir="ltr">الرقم</span>';
                listEl.appendChild(head);
            }

            if (!matches.length) {
                var empty = document.createElement('div');
                empty.className = 'hr-mpa-inline-component-empty';
                empty.textContent = needle === '' ? 'لا توجد بنود متاحة' : 'لا يوجد بند مطابق';
                listEl.appendChild(empty);
            } else {
                matches.slice(0, 120).forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'hr-mpa-inline-component-item';
                    btn.setAttribute('data-value', item.value);
                    btn.innerHTML = item.code
                        ? '<span class="hr-mpa-inline-component-name">' + escHtml(item.label) + '</span><code dir="ltr">' + escHtml(item.code) + '</code>'
                        : '<span class="hr-mpa-inline-component-name">' + escHtml(item.label) + '</span>';
                    btn.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        applySelection(item.value, true);
                    });
                    listEl.appendChild(btn);
                });
            }

            listEl.hidden = false;
            isOpen = true;
        }

        function openList(showAll) {
            renderList(inputEl.value, !!showAll);
        }

        inputEl.addEventListener('focus', function () {
            openList(true);
        });

        inputEl.addEventListener('click', function () {
            clearTimeout(closeTimer);
            openList(true);
        });

        inputEl.addEventListener('input', function () {
            openList(false);
        });

        inputEl.addEventListener('blur', function () {
            scheduleClose();
        });

        inputEl.addEventListener('keydown', function (e) {
            if (!isOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                openList(false);
                return;
            }
            var buttons = listEl.querySelectorAll('.hr-mpa-inline-component-item[data-value]');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!buttons.length) return;
                activeIndex = activeIndex < buttons.length - 1 ? activeIndex + 1 : 0;
                highlightActive();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!buttons.length) return;
                activeIndex = activeIndex > 0 ? activeIndex - 1 : buttons.length - 1;
                highlightActive();
                return;
            }
            if (e.key === 'Enter') {
                if (!isOpen) return;
                e.preventDefault();
                if (!buttons.length) return;
                var pickBtn = activeIndex >= 0 ? buttons[activeIndex] : buttons[0];
                if (pickBtn) {
                    applySelection(pickBtn.getAttribute('data-value') || '', true);
                }
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                closeList();
            }
        });

        listEl.addEventListener('mousedown', function (e) {
            e.preventDefault();
            clearTimeout(closeTimer);
        });

        document.addEventListener('click', function (e) {
            if (!isOpen) return;
            if (e.target === inputEl || listEl.contains(e.target)) {
                return;
            }
            if (toggleBtn && (e.target === toggleBtn || toggleBtn.contains(e.target))) {
                return;
            }
            closeList();
        });

        if (toggleBtn) {
            toggleBtn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                clearTimeout(closeTimer);
            });
            toggleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (selectEl.disabled || inputEl.disabled) {
                    return;
                }
                inputEl.focus();
                openList(true);
            });
        }

        function syncToggleDisabled() {
            if (!toggleBtn) {
                return;
            }
            toggleBtn.disabled = !!(selectEl.disabled || inputEl.disabled);
        }

        syncToggleDisabled();

        rebuildItems();
        syncInputFromSelect();

        return {
            syncFromSelect: syncInputFromSelect,
            refresh: rebuildItems,
            close: closeList,
            open: openList,
            syncToggleDisabled: syncToggleDisabled,
        };
    }

    function buildComponentSelect(type, selectedId) {
        var wrap = document.createElement('div');
        wrap.className = 'hr-mpa-inline-picker';

        var sel = document.createElement('select');
        sel.className = 'input input-compact hr-mpa-inline-component';
        sel.setAttribute('aria-label', labelsFor(type).compLabel);
        sel.hidden = true;
        sel.setAttribute('aria-hidden', 'true');
        sel.tabIndex = -1;
        sel.style.setProperty('display', 'none', 'important');

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

        function syncAmountFromComponent() {
            var row = sel.closest('tr');
            var amountInp = row && row.querySelector('.hr-mpa-inline-amount');
            if (!amountInp) return;
            var op = sel.options[sel.selectedIndex];
            if (op && op.value) {
                amountInp.value = op.getAttribute('data-default') || '0';
            } else {
                amountInp.value = '0';
            }
        }

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'input input-compact hr-mpa-ora-lov-field hr-mpa-inline-component-smart';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('placeholder', labelsFor(type).compLabel);
        input.setAttribute('aria-label', labelsFor(type).compLabel);

        var lov = document.createElement('div');
        lov.className = 'hr-mpa-ora-lov';

        var toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'hr-mpa-ora-lov-btn';
        toggleBtn.tabIndex = -1;
        toggleBtn.setAttribute('aria-label', 'اختيار ' + labelsFor(type).compLabel);
        toggleBtn.title = 'اختيار ' + labelsFor(type).compLabel;

        var list = document.createElement('div');
        list.className = 'hr-mpa-inline-component-list';
        list.hidden = true;

        sel.addEventListener('change', function () {
            syncInlineHint(type);
            var op = sel.options[sel.selectedIndex];
            var row = sel.closest('tr');
            var codeTd = row && row.querySelector('.hr-mpa-col-num');
            if (codeTd && op) {
                codeTd.textContent = op.getAttribute('data-comp-code') || '—';
            }
            syncAmountFromComponent();
        });

        lov.appendChild(input);
        lov.appendChild(toggleBtn);
        wrap.appendChild(lov);
        wrap.appendChild(sel);
        wrap.appendChild(list);
        setupInlineComponentPicker(sel, input, list, toggleBtn);

        wrap._hrMpaSyncAmount = syncAmountFromComponent;
        return wrap;
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
            ? 'القيمة تُسحب تلقائياً كنسبة من تعريف البند.'
            : 'القيمة تُسحب تلقائياً من تعريف البند.';
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
        amtInp.readOnly = true;
        amtInp.setAttribute('readonly', 'readonly');
        amtInp.setAttribute('aria-label', 'المبلغ');
        tdAmt.appendChild(amtInp);
        tr.appendChild(tdAmt);

        var pickerWrap = tdComp.querySelector('.hr-mpa-inline-picker');
        if (pickerWrap && typeof pickerWrap._hrMpaSyncAmount === 'function') {
            pickerWrap._hrMpaSyncAmount();
        }
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
        amtInp.readOnly = true;
        amtInp.setAttribute('readonly', 'readonly');
        tdAmt.appendChild(amtInp);
        tr.appendChild(tdAmt);

        var pickerWrap = tdComp.querySelector('.hr-mpa-inline-picker');
        if (pickerWrap && typeof pickerWrap._hrMpaSyncAmount === 'function') {
            pickerWrap._hrMpaSyncAmount();
        }
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

        var input = tr.querySelector('.hr-mpa-inline-component-smart');
        if (input) input.focus();
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

        var compInput = selectedRow.querySelector('.hr-mpa-inline-component-smart');
        if (compInput) {
            compInput.focus();
            return;
        }
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

    initEmployeePickerModal();

    if (masterEmpCode) {
        var codeOnFocus = '';
        masterEmpCode.addEventListener('focus', function () {
            codeOnFocus = String(masterEmpCode.value || '').trim();
            masterEmpCode.select();
        });
        masterEmpCode.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }
            e.preventDefault();
            var typedCode = normalizeDigits(String(masterEmpCode.value || '').trim());
            if (typedCode === '') {
                navigateToFilter(0);
                return;
            }
            var matchedId = codeToEmployeeId(typedCode);
            if (matchedId < 1) {
                appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
                masterEmpCode.value = codeOnFocus;
                return;
            }
            navigateToFilter(matchedId);
        });
        masterEmpCode.addEventListener('blur', function () {
            var typedCode = normalizeDigits(String(masterEmpCode.value || '').trim());
            var prevCode = normalizeDigits(String(codeOnFocus || '').trim());
            if (typedCode === prevCode) {
                return;
            }
            if (typedCode === '') {
                navigateToFilter(0);
                return;
            }
            var matchedId = codeToEmployeeId(typedCode);
            if (matchedId < 1) {
                appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
                masterEmpCode.value = codeOnFocus;
                return;
            }
            navigateToFilter(matchedId);
        });
    }

    if (filterMonth) {
        syncMonthMetaFields();
        filterMonth.addEventListener('change', function () {
            syncMonthMetaFields();
            navigateToFilter(selectedEmployeeId());
        });
    }

    if (filterYear) {
        filterYear.addEventListener('change', function () {
            filterYear.value = String(getSelectedYear());
            navigateToFilter(selectedEmployeeId());
        });
        filterYear.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }
            e.preventDefault();
            filterYear.value = String(getSelectedYear());
            navigateToFilter(selectedEmployeeId());
        });
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            navigateToFilter(selectedEmployeeId());
        });
    }

    if (!masterEmpCode || masterEmpCode.value === '') {
        var selectedEmp = employeesById[selectedEmployeeId()] || null;
        if (selectedEmp) {
            syncCodeInputFromEmployee(selectedEmp);
        }
    }
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
        } else if (action === 'print') {
            e.preventDefault();
            e.stopImmediatePropagation();
            printCurrentScreen();
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
