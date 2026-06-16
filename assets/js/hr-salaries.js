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
    var salPickerId = document.getElementById('hr-sal-picker-id');
    var salPickerOpen = document.getElementById('hr-sal-picker-id_open');
    var salPickerDisplay = document.getElementById('hr-sal-picker-id_display');
    var filterEmployee = document.getElementById('hr-sal-filter-employee');
    var filterEmployeeSmart = document.getElementById('hr-sal-filter-employee-smart');
    var filterEmployeeList = document.getElementById('hr-sal-filter-employee-list');
    var filterEmployeeToggle = document.getElementById('hr-sal-filter-employee-toggle');
    var masterEmpCode = document.getElementById('hr-sal-emp-code');
    var empPrevBtn = document.getElementById('hr-sal-emp-prev');
    var empNextBtn = document.getElementById('hr-sal-emp-next');
    var masterBase = document.getElementById('hr-sal-base-salary');
    var allowTotalField = document.getElementById('hr-sal-allow-total');
    var grossTotalField = document.getElementById('hr-sal-gross-total');
    var tbody = document.getElementById('hr-sal-allow-tbody');
    var hint = document.getElementById('hr-sal-allow-hint');
    var slipUrl = page.getAttribute('data-slip-url') || '';
    var initialAllowTotal = parseFloat(page.getAttribute('data-allow-total') || '0') || 0;
    var employeePickerItems = [];
    var employeePickerOpen = false;
    var employeePickerActiveIndex = -1;
    var employeePickerCloseTimer = null;
    var employeePickerApi = null;

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

    function normText(value) {
        return String(value || '').trim().toLowerCase();
    }

    function normalizeDigits(value) {
        return String(value || '')
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
    }

    function normalizeSearchText(value) {
        return normalizeDigits(String(value || '')).trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
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

    function roundAmount(value) {
        var n = parseFloat(String(value || '0'));
        if (isNaN(n)) return 0;
        return Math.round((n + Number.EPSILON) * 1000) / 1000;
    }

    function formatAmount(value) {
        var n = roundAmount(value);
        return n.toLocaleString('en-US', {
            minimumFractionDigits: 3,
            maximumFractionDigits: 3,
        });
    }

    function parseAllowanceTotalFromRows() {
        if (!tbody) {
            return initialAllowTotal;
        }
        var base = getBaseSalary();
        var total = 0;

        tbody.querySelectorAll('tr.hr-sal-row').forEach(function (tr) {
            if (tr.hidden || tr.classList.contains('hr-sal-row--empty')) {
                return;
            }
            if (tr.classList.contains('hr-sal-row--entry')) {
                var sel = tr.querySelector('.hr-sal-inline-component');
                var op = sel && sel.options ? sel.options[sel.selectedIndex] : null;
                if (!op || !op.value) {
                    return;
                }
                var amtInp = tr.querySelector('.hr-sal-inline-amount');
                var rawEntry = parseFloat(String(amtInp && amtInp.value ? amtInp.value : '0').replace(',', '.'));
                if (isNaN(rawEntry)) rawEntry = 0;
                total += op.getAttribute('data-is-percent') === '1'
                    ? (base * rawEntry / 100)
                    : rawEntry;
                return;
            }

            var raw = parseFloat(String(tr.getAttribute('data-amount') || '0').replace(',', '.'));
            if (isNaN(raw)) raw = 0;
            total += tr.getAttribute('data-is-percent') === '1'
                ? (base * raw / 100)
                : raw;
        });

        return roundAmount(total);
    }

    function syncSalaryTotals() {
        if (!allowTotalField && !grossTotalField) {
            return;
        }
        var base = roundAmount(getBaseSalary());
        var allow = parseAllowanceTotalFromRows();
        var gross = roundAmount(base + allow);
        if (allowTotalField) {
            allowTotalField.value = formatAmount(allow);
        }
        if (grossTotalField) {
            grossTotalField.value = formatAmount(gross);
        }
    }

    function syncMasterEmpCode(preserveSmartInput) {
        if (!filterEmployee || !masterEmpCode) return;
        var opt = filterEmployee.options[filterEmployee.selectedIndex];
        masterEmpCode.value = opt && opt.value ? (opt.getAttribute('data-emp-code') || '') : '';
        if (filterEmployeeSmart && !preserveSmartInput) {
            filterEmployeeSmart.value = opt && opt.value ? String(opt.textContent || '').trim() : '';
        }
    }

    function parseEmployeeList() {
        var el = document.getElementById('hr-salaries-picker-json');
        if (!el) return [];
        try {
            return JSON.parse(el.textContent || '[]') || [];
        } catch (err) {
            return [];
        }
    }

    function buildCodeIndex() {
        var map = {};
        var byId = {};
        parseEmployeeList().forEach(function (emp) {
            var id = parseInt(emp.id, 10);
            var code = normalizeDigits(String(emp.code || '').trim());
            if (id > 0) {
                byId[id] = code;
            }
            if (code !== '') {
                map[code] = id;
            }
        });
        return { map: map, byId: byId };
    }

    function employeeFilterUrlById(employeeId) {
        var id = parseInt(String(employeeId || '0'), 10);
        if (isNaN(id) || id < 1) {
            return page.getAttribute('data-list-url') || '';
        }
        return (page.getAttribute('data-list-url') || '') + '&employee_id=' + encodeURIComponent(String(id));
    }

    function codeToUrl(rawCode) {
        var code = normalizeDigits(String(rawCode || '').trim());
        var base = page.getAttribute('data-list-url') || '';
        if (code === '' || code === '—') {
            return base;
        }
        var idx = buildCodeIndex();
        if (idx.map[code]) {
            return base + '&employee_id=' + encodeURIComponent(String(idx.map[code]));
        }
        return '';
    }

    function navigateToEmployeeUrl(url) {
        if (!url) return;
        window.location.href = url;
    }

    function employeeIndexByCurrentId() {
        var currentId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10);
        var employees = parseEmployeeList();
        var idx = -1;
        employees.forEach(function (emp, i) {
            if (parseInt(emp.id, 10) === currentId) {
                idx = i;
            }
        });
        return {
            employees: employees,
            index: idx,
        };
    }

    function syncEmployeeNavButtons() {
        var state = employeeIndexByCurrentId();
        var hasCurrent = state.index >= 0;
        if (empPrevBtn) {
            empPrevBtn.disabled = !hasCurrent || state.index <= 0;
        }
        if (empNextBtn) {
            empNextBtn.disabled = !hasCurrent || state.index >= state.employees.length - 1;
        }
    }

    function navigateEmployeeByStep(step) {
        var state = employeeIndexByCurrentId();
        if (state.index < 0) {
            return;
        }
        var next = state.employees[state.index + step];
        if (!next || !next.id) {
            return;
        }
        navigateToEmployeeUrl(employeeFilterUrlById(parseInt(next.id, 10)));
    }

    function navigateByCode(rawCode, onFail) {
        var url = codeToUrl(rawCode);
        if (!url) {
            appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
            if (typeof onFail === 'function') {
                onFail();
            }
            return;
        }
        navigateToEmployeeUrl(url);
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
        if (!salPickerId || !salPickerOpen || !salPickerDisplay) {
            return;
        }
        if (!window.EmployeePickerModal) {
            setTimeout(initEmployeePickerModal, 40);
            return;
        }
        employeePickerApi = EmployeePickerModal.bind({
            hidden: 'hr-sal-picker-id',
            open: 'hr-sal-picker-id_open',
            display: 'hr-sal-picker-id_display',
            jsonId: 'hr-salaries-picker-json',
            employees: parseEmployeeList(),
            allowNew: false,
            placeholder: 'اضغط لاختيار الموظف',
            initialId: parseInt(page.getAttribute('data-filter-employee-id') || '0', 10) || '',
            onSelect: function (emp) {
                syncCodeInputFromEmployee(emp);
                if (!emp || !emp.id) {
                    navigateToEmployeeUrl(page.getAttribute('data-list-url') || '');
                    return;
                }
                var selectedId = parseInt(emp.id, 10);
                var currentId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10);
                if (selectedId === currentId) {
                    return;
                }
                navigateToEmployeeUrl(employeeFilterUrlById(selectedId));
            },
        });
        if (employeePickerApi) {
            syncCodeInputFromEmployee(employeePickerApi.getEmployee());
        }
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
        if (btn === filterEmployeeToggle || (btn && btn.id === 'hr-sal-filter-employee-toggle')) {
            if (filterEmployeeSmart) {
                filterEmployeeSmart.focus();
            }
            openEmployeePicker(true);
            return;
        }
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
    syncEmployeeNavButtons();

    if (empPrevBtn) {
        empPrevBtn.addEventListener('click', function () {
            navigateEmployeeByStep(-1);
        });
    }
    if (empNextBtn) {
        empNextBtn.addEventListener('click', function () {
            navigateEmployeeByStep(1);
        });
    }

    function buildEmployeePickerItems() {
        if (!filterEmployee) return;
        employeePickerItems = [];
        Array.prototype.forEach.call(filterEmployee.options, function (opt) {
            var val = String(opt.value || '').trim();
            if (!val) return;
            var label = String(opt.textContent || '').trim();
            var code = String(opt.getAttribute('data-emp-code') || '').trim();
            employeePickerItems.push({
                value: val,
                label: label,
                code: code,
                search: normText((code ? code + ' ' : '') + label),
            });
        });
    }

    function closeEmployeePicker() {
        clearTimeout(employeePickerCloseTimer);
        if (!filterEmployeeList) return;
        filterEmployeeList.hidden = true;
        filterEmployeeList.innerHTML = '';
        employeePickerOpen = false;
        employeePickerActiveIndex = -1;
    }

    function scheduleCloseEmployeePicker() {
        clearTimeout(employeePickerCloseTimer);
        employeePickerCloseTimer = setTimeout(closeEmployeePicker, 170);
    }

    function highlightEmployeePickerActive() {
        if (!filterEmployeeList) return;
        var buttons = filterEmployeeList.querySelectorAll('.hr-sal-emp-pick-item[data-value]');
        Array.prototype.forEach.call(buttons, function (btn, idx) {
            btn.classList.toggle('is-active', idx === employeePickerActiveIndex);
        });
        if (employeePickerActiveIndex >= 0 && buttons[employeePickerActiveIndex]) {
            buttons[employeePickerActiveIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function applyEmployeeSelection(value, submitAfter) {
        if (!filterEmployee) return;
        var nextValue = String(value || '').trim();
        if (nextValue === '') return;
        var prevValue = String(filterEmployee.value || '').trim();
        filterEmployee.value = nextValue;
        syncMasterEmpCode(false);
        closeEmployeePicker();
        if (submitAfter && pickForm && prevValue !== nextValue) {
            pickForm.submit();
        }
    }

    function renderEmployeePicker(query, showAll) {
        if (!filterEmployeeList) return;
        if (employeePickerItems.length === 0) {
            buildEmployeePickerItems();
        }
        var needle = showAll ? '' : normText(query);
        var matches = employeePickerItems.filter(function (item) {
            return needle === '' || item.search.indexOf(needle) !== -1;
        });

        filterEmployeeList.innerHTML = '';
        employeePickerActiveIndex = -1;

        if (matches.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'hr-sal-emp-pick-empty';
            empty.textContent = needle === '' ? 'لا يوجد موظفون' : 'لا يوجد موظف مطابق';
            filterEmployeeList.appendChild(empty);
        } else {
            matches.slice(0, 120).forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'hr-sal-emp-pick-item';
                btn.setAttribute('data-value', item.value);
                btn.innerHTML = item.code !== ''
                    ? '<span class="hr-sal-emp-pick-name">' + escapeHtml(item.label) + '</span><code dir="ltr">' + escapeHtml(item.code) + '</code>'
                    : '<span class="hr-sal-emp-pick-name">' + escapeHtml(item.label) + '</span>';
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    applyEmployeeSelection(item.value, true);
                });
                filterEmployeeList.appendChild(btn);
            });
        }

        filterEmployeeList.hidden = false;
        employeePickerOpen = true;
    }

    function openEmployeePicker(showAll) {
        if (!filterEmployeeList || !filterEmployeeSmart) return;
        renderEmployeePicker(filterEmployeeSmart.value, !!showAll);
    }

    function initEmployeeSmartPicker() {
        if (!filterEmployee || !filterEmployeeSmart || !filterEmployeeList) return;
        buildEmployeePickerItems();
        syncMasterEmpCode(false);

        filterEmployeeSmart.addEventListener('focus', function () {
            openEmployeePicker(false);
        });

        filterEmployeeSmart.addEventListener('click', function () {
            clearTimeout(employeePickerCloseTimer);
            openEmployeePicker(false);
        });

        filterEmployeeSmart.addEventListener('input', function () {
            openEmployeePicker(false);
        });

        filterEmployeeSmart.addEventListener('blur', function () {
            scheduleCloseEmployeePicker();
        });

        filterEmployeeSmart.addEventListener('keydown', function (e) {
            if (!employeePickerOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                openEmployeePicker(false);
                return;
            }
            var buttons = filterEmployeeList.querySelectorAll('.hr-sal-emp-pick-item[data-value]');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!buttons.length) return;
                employeePickerActiveIndex = employeePickerActiveIndex < buttons.length - 1 ? employeePickerActiveIndex + 1 : 0;
                highlightEmployeePickerActive();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!buttons.length) return;
                employeePickerActiveIndex = employeePickerActiveIndex > 0 ? employeePickerActiveIndex - 1 : buttons.length - 1;
                highlightEmployeePickerActive();
                return;
            }
            if (e.key === 'Enter') {
                if (!employeePickerOpen) return;
                e.preventDefault();
                if (!buttons.length) return;
                var pickBtn = employeePickerActiveIndex >= 0 ? buttons[employeePickerActiveIndex] : buttons[0];
                if (pickBtn) {
                    applyEmployeeSelection(pickBtn.getAttribute('data-value') || '', true);
                }
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                closeEmployeePicker();
            }
        });

        filterEmployeeList.addEventListener('mousedown', function (e) {
            e.preventDefault();
            clearTimeout(employeePickerCloseTimer);
        });

        document.addEventListener('click', function (e) {
            if (!employeePickerOpen) return;
            if (
                e.target === filterEmployeeSmart
                || e.target === filterEmployeeToggle
                || (filterEmployeeToggle && filterEmployeeToggle.contains(e.target))
                || filterEmployeeList.contains(e.target)
            ) {
                return;
            }
            closeEmployeePicker();
        });
    }

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
        syncSalaryTotals();
    }

    function setupInlineComponentPicker(selectEl, inputEl, listEl) {
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
            var buttons = listEl.querySelectorAll('.hr-sal-comp-pick-item[data-value]');
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

            if (!matches.length) {
                var empty = document.createElement('div');
                empty.className = 'hr-sal-comp-pick-empty';
                empty.textContent = needle === '' ? 'لا توجد علاوات متاحة' : 'لا توجد علاوة مطابقة';
                listEl.appendChild(empty);
            } else {
                matches.slice(0, 120).forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'hr-sal-comp-pick-item';
                    btn.setAttribute('data-value', item.value);
                    btn.innerHTML = item.code
                        ? '<span class="hr-sal-comp-pick-name">' + escapeHtml(item.label) + '</span><code dir="ltr">' + escapeHtml(item.code) + '</code>'
                        : '<span class="hr-sal-comp-pick-name">' + escapeHtml(item.label) + '</span>';
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
                openList(true);
                return;
            }
            var buttons = listEl.querySelectorAll('.hr-sal-comp-pick-item[data-value]');
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
            closeList();
        });

        rebuildItems();
        syncInputFromSelect();

        return {
            sync: syncInputFromSelect,
            refresh: rebuildItems,
            open: openList,
            close: closeList,
        };
    }

    function buildComponentSelect(selectedId) {
        var wrap = document.createElement('div');
        wrap.className = 'hr-sal-inline-picker';

        var sel = document.createElement('select');
        sel.className = 'input input-compact hr-sal-inline-component';
        sel.setAttribute('aria-label', 'العلاوة');
        sel.hidden = true;
        sel.setAttribute('aria-hidden', 'true');
        sel.tabIndex = -1;
        sel.style.setProperty('display', 'none', 'important');

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

        function syncAmountFromComponent() {
            var row = sel.closest('tr');
            var amountInp = row && row.querySelector('.hr-sal-inline-amount');
            if (!amountInp) return;
            var op = sel.options[sel.selectedIndex];
            if (op && op.value) {
                amountInp.value = op.getAttribute('data-default') || '0';
            } else {
                amountInp.value = '0';
            }
        }

        sel.addEventListener('change', function () {
            syncInlineHint();
            var op = sel.options[sel.selectedIndex];
            syncAmountFromComponent();
            var codeTd = sel.closest('tr') && sel.closest('tr').querySelector('.hr-sal-col-num');
            if (codeTd && op) {
                codeTd.textContent = op.getAttribute('data-comp-code') || '—';
            }
            syncSalaryTotals();
        });

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'input input-compact hr-sal-inline-component-smart';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('placeholder', 'بحث عن العلاوة');
        input.setAttribute('aria-label', 'بحث عن العلاوة');

        var list = document.createElement('div');
        list.className = 'hr-sal-comp-pick-list';
        list.hidden = true;

        wrap.appendChild(sel);
        wrap.appendChild(input);
        wrap.appendChild(list);
        setupInlineComponentPicker(sel, input, list);

        wrap._hrSalSyncAmount = syncAmountFromComponent;
        return wrap;
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
        amtInp.readOnly = true;
        amtInp.setAttribute('readonly', 'readonly');
        amtInp.value = (data && data.amount) || '0';
        amtInp.setAttribute('aria-label', 'المبلغ');
        amtInp.addEventListener('input', syncSalaryTotals);
        tdAmt.appendChild(amtInp);

        tr.appendChild(tdCode);
        tr.appendChild(tdName);
        tr.appendChild(tdAmt);

        var empty = tbody.querySelector('tr.hr-sal-row--empty');
        if (empty) {
            empty.remove();
        }
        tbody.insertBefore(tr, tbody.firstChild);
        if (compSel && typeof compSel._hrSalSyncAmount === 'function') {
            compSel._hrSalSyncAmount();
        }
        syncInlineHint();
        var compInput = tr.querySelector('.hr-sal-inline-component-smart');
        if (compInput) {
            compInput.focus();
        }
        syncSalaryTotals();
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

    initEmployeeSmartPicker();
    initEmployeePickerModal();

    if (masterEmpCode) {
        var codeOnFocus = '';
        masterEmpCode.addEventListener('focus', function () {
            codeOnFocus = String(masterEmpCode.value || '').trim();
            masterEmpCode.select();
        });
        masterEmpCode.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                navigateByCode(masterEmpCode.value, function () {
                    masterEmpCode.value = codeOnFocus;
                });
            }
        });
        masterEmpCode.addEventListener('blur', function () {
            var typed = normalizeDigits(String(masterEmpCode.value || '').trim());
            var oldVal = normalizeDigits(String(codeOnFocus || '').trim());
            if (typed === oldVal) {
                return;
            }
            navigateByCode(typed, function () {
                masterEmpCode.value = codeOnFocus;
            });
        });
    }

    if (masterBase) {
        masterBase.addEventListener('input', function () {
            syncLineBase();
            syncSalaryTotals();
        });
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

    syncSalaryTotals();
    updateToolbar();
})();
