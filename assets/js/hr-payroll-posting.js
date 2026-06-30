(function () {
    'use strict';

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    var page = document.querySelector('.hr-pr-post-classic');
    if (!page) return;

    var companyLogoUrl = document.body.getAttribute('data-company-logo-url') || '';

    var filterDept = qs('#hr-pr-post-filter-dept');
    var filterEmp = qs('#hr-pr-post-filter-emp');
    var periodForm = qs('#hr-pr-post-period-form');
    var monthInput = periodForm ? qs('#hr-pr-post-filter-month', periodForm) : null;
    var yearInput = periodForm ? qs('#hr-pr-post-filter-year', periodForm) : null;
    var filterEmployees = [];
    var deptNames = {};
    var smartLovApis = {};
    var suppressAutoSubmit = true;
    var autoSubmitInProgress = false;
    var busyEl = qs('#hr-pr-post-busy');
    var busyMsgEl = qs('#hr-pr-post-busy-msg');
    var payrollBusyActive = false;

    try {
        filterEmployees = JSON.parse(page.getAttribute('data-filter-employees') || '[]');
        if (!Array.isArray(filterEmployees)) {
            filterEmployees = [];
        }
    } catch (e) {
        filterEmployees = [];
    }

    try {
        deptNames = JSON.parse(page.getAttribute('data-dept-names') || '{}');
        if (!deptNames || typeof deptNames !== 'object') {
            deptNames = {};
        }
    } catch (e2) {
        deptNames = {};
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

    function buildSmartLovItems(selectEl) {
        var items = [];
        if (!selectEl) return items;
        Array.prototype.forEach.call(selectEl.options || [], function (opt) {
            if (!opt) return;
            var value = String(opt.value || '').trim();
            var label = String(opt.textContent || '').trim();
            if (label === '') return;
            var extra = String(opt.getAttribute('data-dept-name') || '').trim();
            items.push({
                value: value,
                label: label,
                search: normalizeSearchText(label + ' ' + value + ' ' + extra),
            });
        });
        return items;
    }

    function setupSmartLovSelect(selectEl, lovOptions) {
        if (!selectEl) return null;
        var wrap = selectEl.closest('.hr-pr-post-ora-lov');
        if (!wrap) return null;

        lovOptions = lovOptions || {};
        var emptyValues = lovOptions.emptyValues || ['0', ''];
        var placeholder = lovOptions.placeholder || 'ابحث أو اختر من القائمة';

        var closeTimer = null;
        var isOpen = false;
        var activeIndex = -1;
        var items = [];

        selectEl.hidden = true;
        selectEl.setAttribute('aria-hidden', 'true');
        selectEl.tabIndex = -1;
        selectEl.style.setProperty('display', 'none', 'important');

        var inputEl = document.createElement('input');
        inputEl.type = 'text';
        inputEl.className = 'input hr-pr-post-inline-input hr-pr-post-ora-smart-input';
        inputEl.id = (selectEl.id || 'hr-pr-post-lov') + '-smart';
        inputEl.autocomplete = 'off';
        inputEl.placeholder = placeholder;
        inputEl.setAttribute('aria-label', selectEl.getAttribute('aria-label') || 'اختيار');
        wrap.insertBefore(inputEl, wrap.firstChild);

        if (selectEl.id) {
            var linkedLabel = document.querySelector('label[for="' + selectEl.id + '"]');
            if (linkedLabel) {
                linkedLabel.setAttribute('for', inputEl.id);
            }
        }

        var listEl = document.createElement('div');
        listEl.className = 'hr-pr-post-ora-smart-list';
        listEl.hidden = true;
        wrap.appendChild(listEl);

        function refreshItems() {
            items = buildSmartLovItems(selectEl);
        }

        function displayValueForSelect() {
            if (selectEl.disabled) {
                return '';
            }
            var op = selectEl.options[selectEl.selectedIndex];
            if (!op) return '';
            var val = String(op.value || '').trim();
            if (emptyValues.indexOf(val) >= 0) {
                return '';
            }
            return String(op.textContent || '').trim();
        }

        function syncInputFromSelect() {
            inputEl.value = displayValueForSelect();
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
            var buttons = listEl.querySelectorAll('.hr-pr-post-ora-smart-item[data-value]');
            Array.prototype.forEach.call(buttons, function (btn, idx) {
                btn.classList.toggle('is-active', idx === activeIndex);
            });
            if (activeIndex >= 0 && buttons[activeIndex]) {
                buttons[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function applySelection(value, emitChange) {
            var nextValue = String(value || '');
            var prevValue = String(selectEl.value || '');
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
            if (!items.length) {
                refreshItems();
            }
            var needle = browseAll ? '' : normalizeSearchText(queryText);
            var matches = items.filter(function (item) {
                return needle === '' || item.search.indexOf(needle) >= 0;
            });

            listEl.innerHTML = '';
            activeIndex = -1;

            if (!matches.length) {
                var empty = document.createElement('div');
                empty.className = 'hr-pr-post-ora-smart-empty';
                empty.textContent = 'لا توجد نتائج مطابقة';
                listEl.appendChild(empty);
            } else {
                matches.slice(0, 160).forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'hr-pr-post-ora-smart-item';
                    btn.setAttribute('data-value', item.value);
                    btn.innerHTML = '<span>' + escapeHtml(item.label) + '</span>';
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

        function syncDisabledState() {
            inputEl.disabled = !!selectEl.disabled;
            if (selectEl.disabled) {
                closeList();
            }
            wrap.classList.toggle('is-smart-disabled', !!selectEl.disabled);
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
            syncInputFromSelect();
        });
        inputEl.addEventListener('keydown', function (e) {
            if (!isOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault();
                openList(true);
                return;
            }
            var buttons = listEl.querySelectorAll('.hr-pr-post-ora-smart-item[data-value]');
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
            if (wrap.contains(e.target)) {
                return;
            }
            closeList();
        });

        refreshItems();
        syncInputFromSelect();
        syncDisabledState();
        selectEl.addEventListener('change', syncInputFromSelect);

        return {
            open: openList,
            close: closeList,
            refresh: refreshItems,
            sync: syncInputFromSelect,
            syncDisabled: syncDisabledState,
        };
    }

    function selectedDeptName() {
        if (!filterDept) return '';
        var opt = filterDept.options[filterDept.selectedIndex];
        if (!opt) return '';
        return (opt.getAttribute('data-dept-name') || opt.textContent || '').trim();
    }

    function employeeMatchesDept(emp, deptId, deptName) {
        if (deptId < 1) return true;
        var empDeptId = parseInt(emp.department_id || '0', 10);
        if (empDeptId === deptId) return true;
        if (empDeptId < 1 && deptName !== '') {
            return String(emp.department || '').trim() === deptName;
        }
        return false;
    }

    function rebuildEmployeeSelect(resetIfInvalid) {
        if (!filterEmp) return;
        var deptId = filterDept ? parseInt(filterDept.value || '0', 10) : 0;
        var deptName = deptId > 0 ? (deptNames[String(deptId)] || selectedDeptName()) : '';
        var current = parseInt(filterEmp.value || '0', 10);
        var list = deptId > 0
            ? filterEmployees.filter(function (emp) {
                return employeeMatchesDept(emp, deptId, deptName);
            })
            : filterEmployees.slice();

        filterEmp.innerHTML = '';
        var allOpt = document.createElement('option');
        allOpt.value = '0';
        allOpt.textContent = 'جميع الموظفين';
        filterEmp.appendChild(allOpt);

        list.forEach(function (emp) {
            var opt = document.createElement('option');
            opt.value = String(emp.id);
            opt.textContent = emp.name_ar && emp.name_ar !== '' ? emp.name_ar : '—';
            if (current > 0 && parseInt(emp.id, 10) === current) {
                opt.selected = true;
            }
            filterEmp.appendChild(opt);
        });

        if (resetIfInvalid && current > 0) {
            var stillThere = list.some(function (emp) {
                return parseInt(emp.id, 10) === current;
            });
            if (!stillThere) {
                filterEmp.value = '0';
            }
        }
        if (smartLovApis.emp) {
            smartLovApis.emp.refresh();
            smartLovApis.emp.sync();
        }
    }

    function syncPayrollFilters() {
        if (!filterDept || !filterEmp) return;
        var empChosen = parseInt(filterEmp.value || '0', 10) > 0;
        filterDept.disabled = empChosen;
        if (empChosen) {
            filterDept.removeAttribute('name');
        } else {
            filterDept.setAttribute('name', 'dept_id');
        }
        var hiddenDept = qs('#hr-pr-post-filter-dept-hidden');
        if (hiddenDept) {
            hiddenDept.value = empChosen ? '0' : String(filterDept.value || '0');
        }
        syncOraLovButtons();
    }

    function syncOraLovButtons() {
        qsa('.hr-pr-post-ora-lov', page).forEach(function (wrap) {
            var sel = wrap.querySelector('select');
            var btn = wrap.querySelector('.hr-pr-post-ora-lov-btn');
            if (btn && sel) {
                btn.disabled = !!sel.disabled;
            }
        });
        if (smartLovApis.dept) {
            smartLovApis.dept.syncDisabled();
            smartLovApis.dept.sync();
        }
        if (smartLovApis.emp) {
            smartLovApis.emp.syncDisabled();
            smartLovApis.emp.sync();
        }
    }

    function submitFiltersAuto() {
        if (suppressAutoSubmit || autoSubmitInProgress || !periodForm) return;
        autoSubmitInProgress = true;
        syncPayrollFilters();
        if (typeof periodForm.requestSubmit === 'function') {
            periodForm.requestSubmit();
        } else {
            periodForm.submit();
        }
    }

    smartLovApis.dept = setupSmartLovSelect(filterDept, {
        placeholder: 'جميع الأقسام — ابحث أو اختر',
        emptyValues: ['0', ''],
    });
    smartLovApis.emp = setupSmartLovSelect(filterEmp, {
        placeholder: 'جميع الموظفين — ابحث أو اختر',
        emptyValues: ['0', ''],
    });

    if (periodForm && window.HrMonthChipStrip) {
        HrMonthChipStrip.bind(periodForm, {
            monthInputId: 'hr-pr-post-filter-month',
            autoSubmit: false,
            onSelect: function () {
                submitFiltersAuto();
            },
        });
    }

    if (filterDept) {
        filterDept.addEventListener('change', function () {
            syncSlipFilterScopeAttrs();
            rebuildEmployeeSelect(true);
            syncPayrollFilters();
            submitFiltersAuto();
        });
    }
    if (filterEmp) {
        filterEmp.addEventListener('change', function () {
            syncSlipFilterScopeAttrs();
            syncPayrollFilters();
            submitFiltersAuto();
        });
    }
    if (yearInput) {
        yearInput.addEventListener('change', submitFiltersAuto);
        yearInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitFiltersAuto();
            }
        });
    }
    rebuildEmployeeSelect(false);
    syncPayrollFilters();
    syncSlipFilterScopeAttrs();
    syncOraLovButtons();
    suppressAutoSubmit = false;
    if (periodForm) {
        periodForm.addEventListener('submit', function () {
            syncPayrollFilters();
            if (!payrollBusyActive) {
                showPayrollLoadBusy();
            }
        });
    }

    function openOraLovSelect(btn) {
        var wrap = btn.closest('.hr-pr-post-ora-lov');
        if (!wrap) return;
        var sel = wrap.querySelector('select');
        if (!sel || sel.disabled) return;
        var input = wrap.querySelector('.hr-pr-post-ora-smart-input');
        var key = sel === filterDept ? 'dept' : (sel === filterEmp ? 'emp' : '');
        var api = key !== '' ? smartLovApis[key] : null;
        if (api && typeof api.open === 'function') {
            if (input) {
                input.focus();
            }
            api.open(true);
            return;
        }
        sel.focus();
    }

    qsa('.hr-pr-post-ora-lov-btn', page).forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openOraLovSelect(btn);
        });
    });

    var tbody = document.getElementById('hr-pr-post-grid-body');
    var selectedRow = null;

    function showAlert(message, type) {
        if (typeof window.appDialogAlert === 'function') {
            window.appDialogAlert(message, type || 'warning');
        } else if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(message, { type: type || 'warning' });
        } else {
            window.alert(message);
        }
    }

    function runConfirm(message, onOk) {
        if (typeof window.appDialogConfirm === 'function') {
            window.appDialogConfirm(message, onOk);
        } else if (window.AppDialog && AppDialog.confirm) {
            AppDialog.confirm(message).then(function (ok) {
                if (ok && onOk) onOk();
            });
        } else if (window.confirm(message)) {
            onOk();
        }
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-pr-post-row') || tr.classList.contains('hr-pr-post-row--empty')) {
            return;
        }
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
        }
        selectedRow = tr;
        selectedRow.classList.add('is-selected');
        updateSlipButton();
    }

    function slipEligibleRow(tr) {
        return !!(tr && tr.getAttribute('data-can-slip') === '1');
    }

    function slipVisibleEmployeeIds() {
        if (!tbody) {
            return [];
        }
        var ids = [];
        qsa('tr.hr-pr-post-row', tbody).forEach(function (tr) {
            if (!slipEligibleRow(tr)) {
                return;
            }
            var eid = parseInt(tr.getAttribute('data-id') || '0', 10);
            if (eid > 0) {
                ids.push(eid);
            }
        });
        return ids;
    }

    function slipFilteredScopeActive() {
        var deptId = filterDept
            ? parseInt(filterDept.value || '0', 10)
            : parseInt(page.getAttribute('data-filter-dept') || '0', 10);
        var empId = filterEmp
            ? parseInt(filterEmp.value || '0', 10)
            : parseInt(page.getAttribute('data-filter-emp') || '0', 10);
        return deptId > 0 || empId > 0;
    }

    function syncSlipFilterScopeAttrs() {
        if (filterDept) {
            page.setAttribute('data-filter-dept', String(parseInt(filterDept.value || '0', 10)));
        }
        if (filterEmp) {
            page.setAttribute('data-filter-emp', String(parseInt(filterEmp.value || '0', 10)));
        }
    }

    function resolveSlipPrintEmployeeIds() {
        var checkedIds = slipCheckedEmployeeIds();
        if (checkedIds.length > 0) {
            return checkedIds;
        }
        if (slipFilteredScopeActive()) {
            var visibleIds = slipVisibleEmployeeIds();
            if (visibleIds.length > 0) {
                return visibleIds;
            }
        }
        if (slipEligibleRow(selectedRow)) {
            var selId = parseInt(selectedRow.getAttribute('data-id') || '0', 10);
            if (selId > 0) {
                return [selId];
            }
        }
        return [];
    }

    function resolveSlipPrintSalaryIds() {
        var checkedIds = slipCheckedSalaryIds();
        if (checkedIds.length > 0) {
            return checkedIds;
        }
        if (slipFilteredScopeActive()) {
            var visibleIds = slipVisibleSalaryIds();
            if (visibleIds.length > 0) {
                return visibleIds;
            }
        }
        if (slipEligibleRow(selectedRow)) {
            var sid = parseInt(selectedRow.getAttribute('data-salary-id') || '0', 10);
            if (sid > 0) {
                return [sid];
            }
        }
        return [];
    }

    function slipCheckedEmployeeIds() {
        if (!tbody) {
            return [];
        }
        var ids = [];
        qsa('tr.hr-pr-post-row', tbody).forEach(function (tr) {
            if (!slipEligibleRow(tr)) {
                return;
            }
            var cb = tr.querySelector('.hr-pr-post-emp-chk:checked');
            if (!cb) {
                return;
            }
            var eid = parseInt(tr.getAttribute('data-id') || '0', 10);
            if (eid > 0) {
                ids.push(eid);
            }
        });
        return ids;
    }

    function slipSelectedEmployeeIds() {
        return resolveSlipPrintEmployeeIds();
    }

    function slipPayPeriod() {
        return {
            year: page.getAttribute('data-year') || '0',
            month: page.getAttribute('data-month') || '0',
        };
    }

    function slipPrintEnabled() {
        if (page.getAttribute('data-list-shown') !== '1') {
            return false;
        }
        if (slipSelectedEmployeeIds().length > 0) {
            return true;
        }
        return checkedEmployees().length > 0;
    }

    function docPrintWatermarkStyles() {
        var dh = window.DocumentHeader;
        return dh && companyLogoUrl && dh.buildPrintWatermarkStyles
            ? dh.buildPrintWatermarkStyles(companyLogoUrl)
            : '';
    }

    function parsePrintAmount(text) {
        var raw = normalizeDigits(String(text || '')).replace(/[^\d.,-]/g, '').replace(/,/g, '');
        var n = parseFloat(raw);
        return isNaN(n) ? 0 : n;
    }

    function formatPrintAmount(value) {
        return parsePrintAmount(String(value)).toFixed(3);
    }

    function checkedEmployeeIds() {
        if (!tbody) {
            return [];
        }
        var ids = [];
        qsa('tr.hr-pr-post-row', tbody).forEach(function (tr) {
            var cb = tr.querySelector('.hr-pr-post-emp-chk:checked');
            if (!cb) {
                return;
            }
            var eid = parseInt(tr.getAttribute('data-id') || '0', 10);
            if (eid > 0) {
                ids.push(eid);
            }
        });
        return ids;
    }

    function printReportEmployeeIdSet() {
        var set = {};
        qsa('.hr-pr-post-report-print-host .hr-pr-month-rpt-table tbody tr[data-employee-id]').forEach(function (tr) {
            var id = tr.getAttribute('data-employee-id') || '';
            if (id !== '') {
                set[id] = true;
            }
        });
        return set;
    }

    function checkedPrintEmployeeIds() {
        var reportIds = printReportEmployeeIdSet();
        return checkedEmployeeIds().filter(function (id) {
            return !!reportIds[String(id)];
        });
    }

    function preparePrintDocClone() {
        var doc = document.querySelector('.hr-pr-post-report-print-host .hr-pr-month-rpt-doc');
        if (!doc) {
            return null;
        }
        var clone = doc.cloneNode(true);
        clone.querySelectorAll('.no-print').forEach(function (el) {
            el.remove();
        });
        return clone;
    }

    function filterPrintDocByEmployeeIds(docClone, employeeIds) {
        var tbodyEl = docClone.querySelector('.hr-pr-month-rpt-table tbody');
        var tfootEl = docClone.querySelector('.hr-pr-month-rpt-table tfoot');
        if (!tbodyEl || !tfootEl) {
            return false;
        }

        var idSet = {};
        employeeIds.forEach(function (id) {
            idSet[String(id)] = true;
        });

        var kept = [];
        qsa('tr[data-employee-id]', tbodyEl).forEach(function (tr) {
            var eid = tr.getAttribute('data-employee-id') || '';
            if (idSet[eid]) {
                kept.push(tr);
            } else {
                tr.remove();
            }
        });

        if (!kept.length) {
            return false;
        }

        var totals = {
            base: 0,
            perm: 0,
            month: 0,
            deductions: 0,
            ss: 0,
            ssEr: 0,
            tax: 0,
            net: 0,
        };

        kept.forEach(function (tr, idx) {
            var cells = tr.querySelectorAll('td');
            if (cells[0]) {
                cells[0].textContent = String(idx + 1);
            }
            if (cells.length < 11) {
                return;
            }
            totals.base += parsePrintAmount(cells[3].textContent);
            totals.perm += parsePrintAmount(cells[4].textContent);
            totals.month += parsePrintAmount(cells[5].textContent);
            totals.deductions += parsePrintAmount(cells[6].textContent);
            totals.ss += parsePrintAmount(cells[7].textContent);
            totals.ssEr += parsePrintAmount(cells[8].textContent);
            totals.tax += parsePrintAmount(cells[9].textContent);
            totals.net += parsePrintAmount(cells[10].textContent);
        });

        var footCells = tfootEl.querySelectorAll('td');
        if (footCells.length >= 11) {
            footCells[3].textContent = formatPrintAmount(totals.base);
            footCells[4].textContent = formatPrintAmount(totals.perm);
            footCells[5].textContent = formatPrintAmount(totals.month);
            footCells[6].textContent = formatPrintAmount(totals.deductions);
            footCells[7].textContent = formatPrintAmount(totals.ss);
            footCells[8].textContent = formatPrintAmount(totals.ssEr);
            footCells[9].textContent = formatPrintAmount(totals.tax);
            footCells[10].textContent = formatPrintAmount(totals.net);
        }

        return true;
    }

    function getPrintAreaInnerHtml(docClone) {
        var clone = docClone || preparePrintDocClone();
        if (!clone) {
            return '';
        }
        var html = clone.innerHTML;
        if (companyLogoUrl && window.DocumentHeader && DocumentHeader.wrapPrintContent) {
            html = DocumentHeader.wrapPrintContent(html, companyLogoUrl);
        }
        return html;
    }

    function getPrintFrameStyles() {
        var dh = window.DocumentHeader || {};
        return (
            docPrintWatermarkStyles() +
            (dh.css || '') +
            (dh.printBoldCss || '') +
            '@page{size:A4 landscape;margin:7mm;}' +
            'body{margin:0;padding:8px;background:#fff;direction:rtl;font-family:Arial,Helvetica,sans-serif;color:#0f172a;}' +
            '.hr-pr-month-rpt-doc{max-width:none;margin:0;padding:0;border:0;box-shadow:none;font-size:11px;}' +
            '.hr-pr-month-rpt-move{width:100%;border-collapse:collapse;margin:0.55rem 0 0.75rem;font-size:0.82rem;}' +
            '.hr-pr-month-rpt-move th,.hr-pr-month-rpt-move td{border:1px solid #64748b;padding:0.3rem 0.45rem;}' +
            '.hr-pr-month-rpt-move th{background:#e2e8f0;text-align:center;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pr-month-rpt-table{width:100%;border-collapse:collapse;font-size:9px;}' +
            '.hr-pr-month-rpt-table th,.hr-pr-month-rpt-table td{border:1px solid #475569;padding:0.16rem 0.2rem;text-align:center;vertical-align:middle;}' +
            '.hr-pr-month-rpt-table thead th{background:#d9d9d9;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pr-month-rpt-table td.num{text-align:end;font-variant-numeric:tabular-nums;white-space:nowrap;}' +
            '.hr-pr-month-rpt-table tbody td:nth-child(3){text-align:start;}' +
            '.hr-pr-month-rpt-table tfoot td{background:#e2e8f0;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pr-month-rpt-empty{text-align:center;padding:1.5rem 1rem;}' +
            '.muted{color:#64748b;}'
        );
    }

    function buildStandalonePrintHtml(docClone) {
        var innerHtml = getPrintAreaInnerHtml(docClone);
        if (!innerHtml) {
            return '';
        }
        var bodyAttrs =
            window.DocumentHeader && DocumentHeader.bodyPrintAttrs
                ? DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
                : '';
        return (
            '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>تقرير قيود الرواتب حسب الشهر</title><style>' +
            getPrintFrameStyles() +
            '</style></head><body' +
            bodyAttrs +
            '><div class="hr-pr-month-rpt-doc report-sales-print-area">' +
            innerHtml +
            '</div></body></html>'
        );
    }

    function getPayrollPostPrintFrame() {
        var frame = document.getElementById('hr-pr-post-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'hr-pr-post-print-frame';
            frame.className = 'sales-inv-print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute('tabindex', '-1');
            document.body.appendChild(frame);
        }
        return frame;
    }

    function printHtmlInFrame(fullHtml) {
        var frame = getPayrollPostPrintFrame();
        var win = frame.contentWindow;
        win.document.open();
        win.document.write(fullHtml);
        win.document.close();
        var triggerPrint = function () {
            try {
                win.focus();
                win.print();
            } catch (e) {
                /* ignored */
            }
        };
        var waitForImages = function () {
            try {
                var doc = win.document;
                var imgs = doc.images ? Array.prototype.slice.call(doc.images) : [];
                var pending = imgs.filter(function (im) {
                    return im && !im.complete;
                });
                if (pending.length === 0) {
                    triggerPrint();
                    return;
                }
                var remaining = pending.length;
                var done = function () {
                    remaining--;
                    if (remaining <= 0) {
                        triggerPrint();
                    }
                };
                pending.forEach(function (im) {
                    im.addEventListener('load', done, { once: true });
                    im.addEventListener('error', done, { once: true });
                });
                setTimeout(triggerPrint, 4000);
            } catch (e2) {
                triggerPrint();
            }
        };
        setTimeout(waitForImages, 200);
    }

    function printPayrollPosting() {
        if (page.getAttribute('data-print-ready') !== '1') {
            showAlert(
                'لا توجد قيود رواتب محتسبة أو مرحّلة لهذا الشهر — اختر الشهر ثم احتسب أو رحّل قبل الطباعة.',
                'warning'
            );
            return;
        }
        var html = buildStandalonePrintHtml();
        if (!html) {
            showAlert('لا يوجد محتوى للطباعة.', 'warning');
            return;
        }
        printHtmlInFrame(html);
    }

    function printPayrollPostingSelected() {
        if (page.getAttribute('data-print-ready') !== '1') {
            showAlert(
                'لا توجد قيود رواتب محتسبة أو مرحّلة لهذا الشهر — اختر الشهر ثم احتسب أو رحّل قبل الطباعة.',
                'warning'
            );
            return;
        }
        if (checkedEmployeeIds().length < 1) {
            showAlert(
                'حدّد موظفاً واحداً أو أكثر بمربع التحديد ثم اضغط «طباعة».',
                'warning'
            );
            return;
        }
        var printableIds = checkedPrintEmployeeIds();
        if (!printableIds.length) {
            showAlert(
                'الموظفون المحدّدون ليسوا محتسبين أو مرحّلين — لا يوجد ما يُطبَع. '
                    + 'استخدم «تحديد القسائم» أو حدّد موظفاً بحالة محتسب أو مرحّل.',
                'warning'
            );
            return;
        }
        var clone = preparePrintDocClone();
        if (!clone || !filterPrintDocByEmployeeIds(clone, printableIds)) {
            showAlert('لا يوجد محتوى للطباعة.', 'warning');
            return;
        }
        var html = buildStandalonePrintHtml(clone);
        if (!html) {
            showAlert('لا يوجد محتوى للطباعة.', 'warning');
            return;
        }
        printHtmlInFrame(html);
    }

    function updateSelectedPrintButton() {
        qsa('[data-side-action="print_selected"]', page).forEach(function (btn) {
            var listShown = page.getAttribute('data-list-shown') === '1';
            var ready = page.getAttribute('data-print-ready') === '1';
            var ok = listShown && ready && checkedEmployeeIds().length > 0;
            btn.disabled = !ok;
            btn.classList.toggle('is-inactive', !ok);
        });
    }

    function updatePrintButton() {
        var printBtn = document.querySelector(
            '#master-toolbar [data-master-action="print"]'
        );
        if (!printBtn) return;
        var ok = page.getAttribute('data-print-ready') === '1';
        printBtn.disabled = !ok;
        printBtn.classList.toggle('is-inactive', !ok);
    }

    function updateSlipButton() {
        var slipBtn = document.querySelector(
            '#master-toolbar [data-master-action="print_slip"]'
        );
        if (!slipBtn) return;
        var listShown = page.getAttribute('data-list-shown') === '1';
        var ok = slipPrintEnabled();
        slipBtn.disabled = !listShown;
        slipBtn.classList.toggle('is-inactive', !listShown);
        if (listShown) {
            var hint = ok && slipCheckedEmployeeIds().length === 0 && slipEligibleRow(selectedRow)
                ? 'عرض قسيمة الموظف المحدّد بالنقر (صف محتسب أو مرحّل)'
                : 'عرض قسيمة راتب لكل موظف محدّد بمربع التحديد (محتسب أو مرحّل)';
            slipBtn.setAttribute('title', hint);
        }
    }

    function syncMasterPayrollToolbar() {
        var gateOk = page.getAttribute('data-gate-ok') === '1';
        var calculated = parseInt(page.getAttribute('data-calculated') || '0', 10);
        var canUnpost = page.getAttribute('data-can-unpost') === '1';

        var calcBtn = document.querySelector(
            '#master-toolbar [data-master-action="payroll_calculate"]'
        );
        var cancelBtn = document.querySelector(
            '#master-toolbar [data-master-action="payroll_cancel_calc"]'
        );
        var postBtn = document.querySelector(
            '#master-toolbar [data-master-action="payroll_post"]'
        );
        var unpostBtn = document.querySelector(
            '#master-toolbar [data-master-action="payroll_unpost"]'
        );
        var selectPendingBtn = document.querySelector(
            '#master-toolbar [data-master-action="select_pending"]'
        );
        var selectSlipBtn = document.querySelector(
            '#master-toolbar [data-master-action="select_slip"]'
        );

        if (calcBtn) {
            calcBtn.disabled = !gateOk;
            calcBtn.classList.toggle('is-inactive', !gateOk);
        }
        if (cancelBtn) {
            var cancelOk = gateOk && calculated > 0;
            cancelBtn.disabled = !cancelOk;
            cancelBtn.classList.toggle('is-inactive', !cancelOk);
        }
        if (postBtn) {
            var postOk = gateOk && calculated > 0;
            postBtn.disabled = !postOk;
            postBtn.classList.toggle('is-inactive', !postOk);
        }
        if (unpostBtn) {
            unpostBtn.disabled = !canUnpost;
            unpostBtn.classList.toggle('is-inactive', !canUnpost);
        }
        if (selectPendingBtn) {
            var listShown = page.getAttribute('data-list-shown') === '1';
            selectPendingBtn.disabled = !listShown;
            selectPendingBtn.classList.toggle('is-inactive', !listShown);
        }
        if (selectSlipBtn) {
            var slipRows = qsa('tr.hr-pr-post-row[data-can-slip="1"]', page);
            var slipListShown = page.getAttribute('data-list-shown') === '1';
            var slipSelectOk = slipListShown && slipRows.length > 0;
            selectSlipBtn.disabled = !slipSelectOk;
            selectSlipBtn.classList.toggle('is-inactive', !slipSelectOk);
        }

        qsa('[data-side-action="payroll_calculate"]', page).forEach(function (btn) {
            btn.disabled = !gateOk;
        });
        qsa('[data-side-action="payroll_cancel_calc"]', page).forEach(function (btn) {
            btn.disabled = !(gateOk && calculated > 0);
        });
        qsa('[data-side-action="payroll_post"]', page).forEach(function (btn) {
            btn.disabled = !(gateOk && calculated > 0);
        });
        qsa('[data-side-action="payroll_unpost"]', page).forEach(function (btn) {
            btn.disabled = !canUnpost;
        });
        qsa('[data-side-action="print"]', page).forEach(function (btn) {
            btn.disabled = page.getAttribute('data-print-ready') !== '1';
        });

        updateSelectedPrintButton();
        updateSlipButton();
        updatePrintButton();
    }

    function checkedEmployees() {
        return qsa('.hr-pr-post-emp-chk:checked');
    }

    function checkedCalculatedEmployees() {
        return checkedEmployees().filter(function (cb) {
            var tr = cb.closest('tr');
            return tr && tr.getAttribute('data-can-cancel') === '1';
        });
    }

    function payrollBusyMessage(action) {
        var messages = {
            load: 'جاري تحميل بيانات الشهر...',
            calculate: 'جاري احتساب الرواتب...',
            cancel_calculate: 'جاري إلغاء الاحتساب...',
            post: 'جاري ترحيل الرواتب...',
            unpost: 'جاري فك الترحيل...',
        };
        return messages[action] || 'جاري التنفيذ...';
    }

    function showPayrollBusy(action) {
        if (payrollBusyActive) {
            return;
        }
        payrollBusyActive = true;
        if (busyMsgEl) {
            busyMsgEl.textContent = payrollBusyMessage(action);
        }
        if (busyEl) {
            busyEl.hidden = false;
            busyEl.removeAttribute('hidden');
        }
        document.body.classList.add('hr-pr-post-is-busy');
    }

    function showPayrollLoadBusy() {
        showPayrollBusy('load');
    }

    function submitAction(action, confirmMsg) {
        var form = qs('#hr-pr-post-action-form');
        var act = qs('#hr-pr-post-action');
        if (!form || !act) return;

        if (
            (action === 'calculate' || action === 'cancel_calculate' || action === 'post')
            && page.getAttribute('data-gate-ok') !== '1'
        ) {
            showAlert(
                page.getAttribute('data-gate-message') || 'لا يمكن تنفيذ هذا الإجراء على الشهر المحدد.',
                'warning'
            );
            return;
        }

        if (action === 'calculate' && checkedEmployees().length < 1) {
            showAlert('اختر موظفاً واحداً على الأقل للاحتساب.', 'warning');
            return;
        }

        if (action === 'cancel_calculate') {
            var calcChk = checkedCalculatedEmployees();
            if (calcChk.length < 1) {
                showAlert(
                    'اختر موظفاً واحداً على الأقل بحالة «محتسب» لإلغاء الاحتساب.',
                    'warning'
                );
                return;
            }
            qsa('.hr-pr-post-emp-chk').forEach(function (cb) {
                var tr = cb.closest('tr');
                cb.checked = tr && tr.getAttribute('data-can-cancel') === '1'
                    && calcChk.indexOf(cb) >= 0;
            });
        }

        var go = function () {
            act.value = action;
            showPayrollBusy(action);
            form.submit();
        };

        if (confirmMsg) {
            runConfirm(confirmMsg, go);
        } else {
            go();
        }
    }

    function slipCheckedSalaryIds() {
        if (!tbody) {
            return [];
        }
        var ids = [];
        qsa('tr.hr-pr-post-row', tbody).forEach(function (tr) {
            if (!slipEligibleRow(tr)) {
                return;
            }
            var cb = tr.querySelector('.hr-pr-post-emp-chk:checked');
            if (!cb) {
                return;
            }
            var sid = parseInt(tr.getAttribute('data-salary-id') || '0', 10);
            if (sid > 0) {
                ids.push(sid);
            }
        });
        return ids;
    }

    function slipVisibleSalaryIds() {
        if (!tbody) {
            return [];
        }
        var ids = [];
        qsa('tr.hr-pr-post-row', tbody).forEach(function (tr) {
            if (!slipEligibleRow(tr)) {
                return;
            }
            var sid = parseInt(tr.getAttribute('data-salary-id') || '0', 10);
            if (sid > 0) {
                ids.push(sid);
            }
        });
        return ids;
    }

    function buildSlipBatchPrintUrl(employeeIds, salaryIds, year, month) {
        var base = page.getAttribute('data-slip-base') || 'index.php?r=hr_payroll_slip';
        var url = base;
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        url += sep
            + 'batch=1'
            + '&year=' + encodeURIComponent(String(year))
            + '&month=' + encodeURIComponent(String(month));
        if (Array.isArray(employeeIds) && employeeIds.length) {
            url += '&employee_ids=' + encodeURIComponent(employeeIds.join(','));
            url += '&nav=' + encodeURIComponent(employeeIds.join(','));
        }
        if (Array.isArray(salaryIds) && salaryIds.length) {
            url += '&salary_ids=' + encodeURIComponent(salaryIds.join(','));
        }
        return url;
    }

    var slipPreviewInnerHtml = '';

    function getSlipReportCssHref() {
        var link = document.querySelector('link[href*="hr-payroll-slip-report.css"]');
        return link && link.href ? link.href.split('?')[0] : '';
    }

    function getDocHeaderCssHref() {
        var link = document.querySelector('link[href*="document-header.css"]');
        return link && link.href ? link.href.split('?')[0] : '';
    }

    function getSlipPrintFrameStyles() {
        var dh = window.DocumentHeader || {};
        return (
            docPrintWatermarkStyles() +
            (dh.css || '') +
            (dh.printBoldCss || '') +
            '@page{margin:8mm 10mm;size:A4 portrait;}' +
            'body{margin:0;padding:8px;background:#fff;direction:rtl;}' +
            '.hr-pslip-print-wrap{max-width:none;margin:0;}' +
            '.hr-pslip-print-batch-head{margin:0 0 0.75rem;padding:0.55rem 0.75rem;background:#e2e8f0;border:1px solid #94a3b8;border-radius:6px;font-weight:700;text-align:center;}' +
            '.hr-pslip-print-page{page-break-inside:avoid;break-inside:avoid-page;page-break-after:always;break-after:page;}' +
            '.hr-pslip-print-page:last-child{page-break-after:auto;break-after:auto;}' +
            '.hr-pslip-doc{background:#fff;border:none;padding:0;font-family:Arial,Helvetica,Tahoma,sans-serif;font-size:13px;font-weight:700;color:#0f172a;direction:rtl;max-width:none;}' +
            '.hr-pslip-header{text-align:center;margin-bottom:0.5rem;}' +
            '.hr-pslip-header-cols{display:grid;grid-template-columns:1fr auto 1fr;align-items:start;gap:0.5rem 1rem;margin-bottom:0.35rem;text-align:start;font-size:0.78rem;line-height:1.45;}' +
            '.hr-pslip-header-en{text-align:start;}.hr-pslip-header-ar{text-align:end;}' +
            '.hr-pslip-co-en,.hr-pslip-co-ar{font-size:0.92rem;font-weight:800;margin-bottom:0.15rem;}' +
            '.hr-pslip-header-logo{display:flex;align-items:center;justify-content:center;min-width:72px;}' +
            '.hr-pslip-header-logo img{max-height:72px;max-width:72px;object-fit:contain;}' +
            '.hr-pslip-title{margin:0.35rem 0 0.15rem;font-size:1.35rem;font-weight:800;text-align:center;}' +
            '.hr-pslip-period-line{margin:0;font-size:0.95rem;text-align:center;}' +
            '.hr-pslip-rule{border:none;border-top:1px solid #334155;margin:0.65rem 0;}' +
            '.hr-pslip-rule--thick{border-top-width:3px;}.hr-pslip-rule--double{border-top:3px double #334155;margin-top:1rem;}' +
            '.hr-pslip-emp-grid{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem 1.5rem;margin-bottom:0.75rem;}' +
            '.hr-pslip-emp-table{width:100%;border-collapse:collapse;font-size:0.88rem;}' +
            '.hr-pslip-emp-table th{text-align:start;font-weight:700;white-space:nowrap;padding:0.15rem 0 0.15rem 0.35rem;width:38%;vertical-align:top;}' +
            '.hr-pslip-emp-table td{padding:0.15rem 0;vertical-align:top;}' +
            '.hr-pslip-summary-cols{display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;}' +
            '.hr-pslip-summary-h{margin:0 0 0.35rem;text-align:center;font-size:0.92rem;font-weight:800;text-decoration:underline;}' +
            '.hr-pslip-sum-table{width:100%;border-collapse:collapse;font-size:0.85rem;}' +
            '.hr-pslip-sum-table th,.hr-pslip-sum-table td{border:1px solid #64748b;padding:0.3rem 0.45rem;}' +
            '.hr-pslip-sum-table thead th{background:#e2e8f0;text-align:center;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pslip-sum-table td.num{text-align:left;font-variant-numeric:tabular-nums;white-space:nowrap;width:42%;}' +
            '.hr-pslip-sum-total td{background:#f1f5f9;font-weight:800;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
            '.hr-pslip-workdays{margin:0.4rem 0 0;font-size:0.82rem;text-align:start;}' +
            '.hr-pslip-net-box{margin-top:0.5rem;border:2px solid #334155;padding:0.35rem 0.55rem;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;background:#fff;}' +
            '.hr-pslip-net-label,.hr-pslip-net-value{font-weight:800;}.hr-pslip-net-value{font-size:1.05rem;font-variant-numeric:tabular-nums;}' +
            '.hr-pslip-detail-cols{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:0.75rem;margin-inline-start:1.25rem;}' +
            '.hr-pslip-detail-h{margin:0 0 0.4rem;font-size:0.9rem;font-weight:800;text-decoration:underline;}' +
            '.hr-pslip-detail-list{list-style:none;margin:0;padding:0;font-size:0.82rem;line-height:1.55;}' +
            '.hr-pslip-detail-list li{display:flex;justify-content:space-between;gap:0.5rem;padding:0.12rem 0;border-bottom:1px dotted #cbd5e1;}' +
            '.hr-pslip-detail-amt{font-variant-numeric:tabular-nums;white-space:nowrap;}.muted{color:#64748b;}' +
            '.hr-pslip-doc,.hr-pslip-summary-block,.hr-pslip-detail-block,.hr-pslip-net-box{page-break-inside:avoid;}'
        );
    }

    function buildSlipStandaloneHtml(innerHtml) {
        var bodyAttrs =
            window.DocumentHeader && DocumentHeader.bodyPrintAttrs
                ? DocumentHeader.bodyPrintAttrs(companyLogoUrl, true)
                : '';
        var cssHref = getSlipReportCssHref();
        var docHeaderCssHref = getDocHeaderCssHref();
        var linkTag = cssHref ? '<link rel="stylesheet" href="' + cssHref + '">' : '';
        var docHeaderLinkTag = docHeaderCssHref
            ? '<link rel="stylesheet" href="' + docHeaderCssHref + '">'
            : '';
        var wrapped = innerHtml;
        if (companyLogoUrl && window.DocumentHeader && DocumentHeader.wrapPrintContent) {
            wrapped = DocumentHeader.wrapPrintContent(innerHtml, companyLogoUrl);
        }
        return (
            '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>\u0642\u0633\u0627\u0626\u0645 \u0627\u0644\u0631\u0627\u062A\u0628</title>' +
            linkTag +
            docHeaderLinkTag +
            '<style>' +
            getSlipPrintFrameStyles() +
            '</style></head><body' +
            bodyAttrs +
            '>' +
            wrapped +
            '</body></html>'
        );
    }

    function parseSlipPrintContent(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var bodyText = (doc.body && doc.body.textContent) ? doc.body.textContent.trim() : '';
        if (bodyText.indexOf('\u0642\u064A\u062F \u0627\u0644\u0631\u0627\u062A\u0628 \u063A\u064A\u0631 \u0645\u0648\u062C\u0648\u062F') >= 0) {
            return '';
        }
        var parts = [];
        var batchHead = doc.querySelector('.hr-pslip-print-batch-head');
        if (batchHead) {
            parts.push(batchHead.outerHTML);
        }
        var wrap = doc.querySelector('.hr-pslip-print-wrap');
        if (!wrap) {
            return '';
        }
        parts.push(wrap.outerHTML);
        return parts.join('');
    }

    function closeSlipPrintPreview() {
        var overlay = qs('#hr-pr-post-slip-print-overlay');
        if (overlay) {
            overlay.hidden = true;
        }
    }

    function slipPrintPreviewOpen() {
        var overlay = qs('#hr-pr-post-slip-print-overlay');
        return !!(overlay && !overlay.hidden && slipPreviewInnerHtml);
    }

    function openSlipPrintPreview(url, slipCount) {
        var preview = qs('#hr-pr-post-slip-print-preview');
        var overlay = qs('#hr-pr-post-slip-print-overlay');
        var title = overlay ? overlay.querySelector('.sales-inv-print-overlay-title') : null;
        if (!preview || !overlay) {
            showAlert('\u0644\u0627 \u062A\u0648\u062C\u062F \u0648\u0627\u062C\u0647\u0629 \u0645\u0639\u0627\u064A\u0646\u0629 \u0627\u0644\u0637\u0628\u0627\u0639\u0629.', 'warning');
            return;
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('load failed');
                }
                return res.text();
            })
            .then(function (html) {
                var inner = parseSlipPrintContent(html);
                if (!inner) {
                    showAlert('\u0642\u064A\u062F \u0627\u0644\u0631\u0627\u062A\u0628 \u063A\u064A\u0631 \u0645\u0648\u062C\u0648\u062F.', 'warning');
                    return;
                }
                slipPreviewInnerHtml = inner;
                if (overlay.parentNode !== document.body) {
                    document.body.appendChild(overlay);
                }
                preview.innerHTML = inner;
                if (title) {
                    title.textContent = slipCount > 1
                        ? '\u0645\u0639\u0627\u064A\u0646\u0629 \u0642\u0633\u0627\u0626\u0645 \u0627\u0644\u0631\u0627\u062A\u0628 \u2014 '
                            + slipCount + ' \u0645\u0648\u0638\u0641 \u2014 \u0627\u0636\u063A\u0637 \u00AB\u0642\u0633\u064A\u0645\u0629 \u0627\u0644\u0631\u0627\u062A\u0628\u00BB \u0644\u0644\u0637\u0628\u0627\u0639\u0629'
                        : '\u0645\u0639\u0627\u064A\u0646\u0629 \u0642\u0633\u064A\u0645\u0629 \u0627\u0644\u0631\u0627\u062A\u0628 \u2014 \u0627\u0636\u063A\u0637 \u00AB\u0642\u0633\u064A\u0645\u0629 \u0627\u0644\u0631\u0627\u062A\u0628\u00BB \u0644\u0644\u0637\u0628\u0627\u0639\u0629';
                }
                overlay.removeAttribute('hidden');
                overlay.hidden = false;
                overlay.style.display = 'flex';
                overlay.style.zIndex = '10050';
            })
            .catch(function () {
                showAlert('\u062A\u0639\u0630\u0651\u0631 \u062A\u062D\u0645\u064A\u0644 \u0642\u0633\u0627\u0626\u0645 \u0627\u0644\u0631\u0627\u062A\u0628.', 'warning');
            });
    }

    function runSlipPrintFromPreview() {
        if (!slipPreviewInnerHtml) {
            return;
        }
        printHtmlInFrame(buildSlipStandaloneHtml(slipPreviewInnerHtml));
    }

    function openSlipBatchPrint(employeeIds, salaryIds, year, month) {
        var hasEmployees = Array.isArray(employeeIds) && employeeIds.length > 0;
        var hasSalaries = Array.isArray(salaryIds) && salaryIds.length > 0;
        if (!hasEmployees && !hasSalaries) {
            return false;
        }
        openSlipBatchWindow(buildSlipBatchPrintUrl(employeeIds, salaryIds, year, month), employeeIds.length);
        return true;
    }

    function openSlipBatchWindow(url, slipCount) {
        openSlipPrintPreview(url, slipCount || 0);
        return true;
    }


    function resolveSlipPrintTargets() {
        var checkedSlipIds = slipCheckedEmployeeIds();
        var anyChecked = checkedEmployees().length > 0;

        if (checkedSlipIds.length > 0) {
            return {
                employeeIds: checkedSlipIds,
                skippedOpen: anyChecked ? Math.max(0, checkedEmployees().length - checkedSlipIds.length) : 0,
            };
        }
        if (slipFilteredScopeActive()) {
            return {
                employeeIds: slipVisibleEmployeeIds(),
                skippedOpen: 0,
            };
        }
        if (slipEligibleRow(selectedRow)) {
            var selId = parseInt(selectedRow.getAttribute('data-id') || '0', 10);
            if (selId > 0) {
                return { employeeIds: [selId], skippedOpen: 0 };
            }
        }
        if (!anyChecked) {
            selectSlipEmployees();
            checkedSlipIds = slipCheckedEmployeeIds();
            if (checkedSlipIds.length > 0) {
                return { employeeIds: checkedSlipIds, skippedOpen: 0 };
            }
        }
        return {
            employeeIds: [],
            skippedOpen: anyChecked ? checkedEmployees().length : 0,
        };
    }

    function printSlip() {
        if (slipPrintPreviewOpen()) {
            runSlipPrintFromPreview();
            return;
        }
        var targets = resolveSlipPrintTargets();
        var employeeIds = targets.employeeIds;
        var anyChecked = checkedEmployees().length > 0;
        var period = slipPayPeriod();

        if (!employeeIds.length) {
            if (anyChecked) {
                showAlert(
                    'الموظفون المحدّدون ليسوا محتسبين أو مرحّلين — لا توجد قسيمة راتب لهم. '
                        + 'استخدم «تحديد القسائم» أو حدّد موظفاً بحالة محتسب أو مرحّل.',
                    'warning'
                );
            } else {
                showAlert(
                    'حدّد موظفاً واحداً أو أكثر (محتسب أو مرحّل) بواسطة مربع التحديد، '
                        + 'أو اختر قسماً/موظفاً من الفلتر ثم اضغط «قسيمة الراتب».',
                    'warning'
                );
            }
            return;
        }

        openSlipBatchPrint(employeeIds, [], period.year, period.month);
    }

    var detailModal = qs('#hr-pr-post-detail-modal');
    var detailTitle = qs('#hr-pr-post-detail-title');
    var detailTbody = qs('#hr-pr-post-detail-tbody');
    var detailEmpty = qs('#hr-pr-post-detail-empty');
    var detailSrcHead = qs('#hr-pr-post-detail-src-head');
    var detailCloseBtn = qs('#hr-pr-post-detail-close');

    var DRILL_LABELS = {
        perm: 'تفاصيل العلاوات الدائمة',
        month: 'تفاصيل العلاوات الشهرية',
        deduct: 'تفاصيل الاقتطاعات',
    };

    function closeDetailModal() {
        if (!detailModal) return;
        detailModal.hidden = true;
        detailModal.setAttribute('aria-hidden', 'true');
    }

    function openDetailModal(row, drillType) {
        if (!detailModal || !detailTbody || !detailTitle) return;
        var raw = row.getAttribute('data-detail') || '{}';
        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            data = {};
        }
        var lines = [];
        if (drillType === 'perm') {
            lines = data.perm || [];
        } else if (drillType === 'month') {
            lines = data.month || [];
        } else if (drillType === 'deduct') {
            lines = data.deduct || [];
        }
        var empName = row.getAttribute('data-emp-name') || '';
        detailTitle.textContent = (DRILL_LABELS[drillType] || 'تفاصيل')
            + (empName ? ' — ' + empName : '');

        detailTbody.innerHTML = '';
        var showSource = drillType === 'deduct';
        if (detailSrcHead) {
            detailSrcHead.hidden = !showSource;
        }

        if (!lines.length) {
            if (detailEmpty) {
                detailEmpty.hidden = false;
            }
        } else {
            if (detailEmpty) {
                detailEmpty.hidden = true;
            }
            lines.forEach(function (line) {
                var tr = document.createElement('tr');
                var tdCode = document.createElement('td');
                tdCode.dir = 'ltr';
                tdCode.textContent = line.code || '—';
                var tdName = document.createElement('td');
                tdName.textContent = line.name || '—';
                tr.appendChild(tdCode);
                tr.appendChild(tdName);
                if (showSource) {
                    var tdSrc = document.createElement('td');
                    tdSrc.textContent = line.source || '';
                    tr.appendChild(tdSrc);
                }
                var tdAmt = document.createElement('td');
                tdAmt.dir = 'ltr';
                tdAmt.className = 'num';
                tdAmt.textContent = line.display || (line.amount != null ? String(line.amount) : '—');
                tr.appendChild(tdAmt);
                detailTbody.appendChild(tr);
            });
        }

        detailModal.hidden = false;
        detailModal.setAttribute('aria-hidden', 'false');
        if (detailCloseBtn) {
            detailCloseBtn.focus();
        }
    }

    if (detailCloseBtn) {
        detailCloseBtn.addEventListener('click', closeDetailModal);
    }
    if (detailModal) {
        detailModal.addEventListener('click', function (e) {
            if (e.target && e.target.getAttribute('data-detail-close') === '1') {
                closeDetailModal();
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && detailModal && !detailModal.hidden) {
            closeDetailModal();
        }
    });

    function handleDrillActivate(cell) {
        if (!cell || !cell.classList.contains('hr-pr-post-drill')) {
            return;
        }
        var row = cell.closest('tr.hr-pr-post-row');
        var drillType = cell.getAttribute('data-drill') || '';
        if (!row || !drillType) {
            return;
        }
        openDetailModal(row, drillType);
    }

    function selectableCheckboxes() {
        return qsa('.hr-pr-post-emp-chk').filter(function (cb) {
            var tr = cb.closest('tr');
            if (!tr) {
                return false;
            }
            return tr.getAttribute('data-can-select') === '1'
                || tr.getAttribute('data-can-slip') === '1';
        });
    }

    function syncCheckAllState() {
        var checkAll = qs('#hr-pr-post-check-all');
        if (!checkAll) return;
        var boxes = selectableCheckboxes();
        if (boxes.length === 0) {
            checkAll.checked = false;
            checkAll.indeterminate = false;
            checkAll.disabled = true;
            return;
        }
        checkAll.disabled = false;
        var checked = boxes.filter(function (cb) {
            return cb.checked;
        });
        checkAll.checked = checked.length === boxes.length;
        checkAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    function selectPendingEmployees() {
        qsa('.hr-pr-post-emp-chk').forEach(function (cb) {
            var tr = cb.closest('tr');
            cb.checked = !!(tr
                && tr.getAttribute('data-can-select') === '1'
                && tr.getAttribute('data-status') === 'none');
        });
        syncCheckAllState();
        updateSlipButton();
        updateSelectedPrintButton();
    }

    function selectSlipEmployees() {
        qsa('.hr-pr-post-emp-chk').forEach(function (cb) {
            var tr = cb.closest('tr');
            cb.checked = !!(tr && tr.getAttribute('data-can-slip') === '1');
        });
        syncCheckAllState();
        updateSlipButton();
        updateSelectedPrintButton();
    }

    function handlePayrollAction(action) {
        if (action === 'payroll_calculate') {
            if (page.getAttribute('data-list-shown') !== '1') {
                showAlert('اختر السنة والشهر ثم اضغط «عرض» قبل الاحتساب.', 'warning');
                return;
            }
            submitAction('calculate', 'احتساب رواتب الموظفين المحددين لهذا الشهر؟');
        } else if (action === 'payroll_cancel_calc') {
            submitAction(
                'cancel_calculate',
                'إلغاء احتساب الموظفين المحددين (محتسب فقط)؟ سيتم حذف قيود الراتب غير المرحّلة.'
            );
        } else if (action === 'payroll_post') {
            submitAction(
                'post',
                'ترحيل جميع القيود المحتسبة لهذا الشهر؟ سيتم اقتطاع ضمان الموظف وقيد حصة الشركة.'
            );
        } else if (action === 'payroll_unpost') {
            submitAction(
                'unpost',
                'فك الترحيل يلغي احتساب رواتب هذا الشهر بالكامل. متابعة؟'
            );
        } else if (action === 'print_slip') {
            if (page.getAttribute('data-list-shown') !== '1') {
                showAlert('اختر السنة والشهر ثم اضغط «عرض» قبل طباعة القسيمة.', 'warning');
                return;
            }
            printSlip();
        } else if (action === 'print') {
            printPayrollPosting();
        } else if (action === 'print_selected') {
            printPayrollPostingSelected();
        } else if (action === 'select_pending') {
            selectPendingEmployees();
        } else if (action === 'select_slip') {
            selectSlipEmployees();
        }
    }

    var checkAll = qs('#hr-pr-post-check-all');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var on = checkAll.checked;
            selectableCheckboxes().forEach(function (cb) {
                cb.checked = on;
            });
            checkAll.indeterminate = false;
            updateSlipButton();
            updateSelectedPrintButton();
        });
    }

    qsa('.hr-pr-post-emp-chk').forEach(function (cb) {
        cb.addEventListener('change', function () {
            syncCheckAllState();
            updateSlipButton();
            updateSelectedPrintButton();
        });
    });
    syncCheckAllState();

    qsa('[data-side-action]', page).forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) {
                return;
            }
            handlePayrollAction(btn.getAttribute('data-side-action') || '');
        });
    });

    if (tbody) {
        tbody.addEventListener('click', function (e) {
            var drillCell = e.target.closest('.hr-pr-post-drill');
            if (drillCell) {
                e.preventDefault();
                e.stopPropagation();
                handleDrillActivate(drillCell);
                return;
            }
            if (e.target.closest('.hr-pr-post-chk-cell')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-pr-post-row');
            if (tr) {
                selectRow(tr);
            }
        });
        tbody.addEventListener('keydown', function (e) {
            var drillCell = e.target.closest('.hr-pr-post-drill');
            if (drillCell && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                e.stopPropagation();
                handleDrillActivate(drillCell);
                return;
            }
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-pr-post-row');
            if (tr) {
                e.preventDefault();
                selectRow(tr);
            }
        });
    }

    syncMasterPayrollToolbar();

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_payroll_posting') return;

        var action = e.detail.action;
        if (action === 'payroll_calculate') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (page.getAttribute('data-list-shown') !== '1') {
                showAlert('اختر السنة والشهر ثم اضغط «عرض» قبل الاحتساب.', 'warning');
                return;
            }
            submitAction('calculate', 'احتساب رواتب الموظفين المحددين لهذا الشهر؟');
        } else if (action === 'payroll_cancel_calc') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitAction(
                'cancel_calculate',
                'إلغاء احتساب الموظفين المحددين (محتسب فقط)؟ سيتم حذف قيود الراتب غير المرحّلة.'
            );
        } else if (action === 'payroll_post') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitAction(
                'post',
                'ترحيل جميع القيود المحتسبة لهذا الشهر؟ سيتم اقتطاع ضمان الموظف وقيد حصة الشركة.'
            );
        } else if (action === 'payroll_unpost') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitAction(
                'unpost',
                'فك الترحيل يلغي احتساب رواتب هذا الشهر بالكامل. متابعة؟'
            );
        } else if (action === 'print_slip') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (page.getAttribute('data-list-shown') !== '1') {
                showAlert('اختر السنة والشهر ثم اضغط «عرض» قبل طباعة القسيمة.', 'warning');
                return;
            }
            printSlip();
        } else if (action === 'print') {
            e.preventDefault();
            e.stopImmediatePropagation();
            printPayrollPosting();
        } else if (action === 'select_pending') {
            e.preventDefault();
            e.stopImmediatePropagation();
            selectPendingEmployees();
        } else if (action === 'select_slip') {
            e.preventDefault();
            e.stopImmediatePropagation();
            selectSlipEmployees();
        }
    }, true);

    var slipOverlayEl = qs('#hr-pr-post-slip-print-overlay');
    var slipCloseEl = qs('#hr-pr-post-slip-print-close');
    if (slipCloseEl) {
        slipCloseEl.addEventListener('click', closeSlipPrintPreview);
    }
    if (slipOverlayEl) {
        slipOverlayEl.addEventListener('click', function (e) {
            if (e.target === slipOverlayEl) {
                closeSlipPrintPreview();
            }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        if (slipPrintPreviewOpen()) {
            closeSlipPrintPreview();
        }
    });
})();
