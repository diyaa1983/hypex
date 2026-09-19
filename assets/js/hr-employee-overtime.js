(function () {
    'use strict';

    var page = document.querySelector('.hr-ot-page');
    if (!page) return;

    var grossSalary = parseFloat(page.getAttribute('data-gross-salary') || page.getAttribute('data-base-salary') || '0') || 0;
    var monthlyDays = parseFloat(page.getAttribute('data-monthly-days') || '30') || 30;
    var dailyHours = parseFloat(page.getAttribute('data-daily-hours') || page.getAttribute('data-monthly-hours') || '8') || 8;
    var multiplierRadios = Array.prototype.slice.call(document.querySelectorAll('input[name="hour_multiplier"]'));
    var multiplierDisplay = document.getElementById('hr-ot-multiplier-display');
    var multiplierLabelEl = document.getElementById('hr-ot-multiplier-label');
    var filterForm = document.getElementById('hr-ot-filter-form');
    var hoursInput = document.getElementById('hr-ot-hours-input');
    var amountDisplay = document.getElementById('hr-ot-amount-display');
    var hourlyRateEl = document.getElementById('hr-ot-hourly-rate');
    var overtimeHourlyRateEl = document.getElementById('hr-ot-overtime-hourly-rate');
    var pickerId = document.getElementById('hr-ot-picker-id');
    var pickerOpen = document.getElementById('hr-ot-picker-id_open');
    var pickerDisplay = document.getElementById('hr-ot-picker-id_display');
    var masterEmpCode = document.getElementById('hr-ot-master-emp-code');
    var filterYear = document.getElementById('hr-ot-filter-year');
    var filterMonth = document.getElementById('hr-ot-filter-month');
    var filterMonthName = document.getElementById('hr-ot-filter-month-name');
    var filterMonthStatus = document.getElementById('hr-ot-filter-month-status');
    var listUrl = page.getAttribute('data-list-url') || '';

    function normalizeDigits(value) {
        return String(value || '')
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
    }

    var employeeItems = [];
    var employeesById = {};
    var employeeCodeMap = {};

    try {
        employeeItems = JSON.parse(document.getElementById('hr-ot-picker-json')?.textContent || '[]');
    } catch (e) {
        employeeItems = [];
    }

    employeeItems.forEach(function (emp) {
        var id = parseInt(emp.id || '0', 10);
        var code = normalizeDigits(String(emp.code || emp.emp_code || '').trim());
        if (id > 0) {
            employeesById[id] = emp;
            if (code !== '') {
                employeeCodeMap[code] = id;
            }
        }
    });

    function selectedEmployeeId() {
        var fromPage = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10) || 0;
        if (fromPage > 0) return fromPage;
        if (!pickerId) return 0;
        return parseInt(pickerId.value || '0', 10) || 0;
    }

    function codeToEmployeeId(rawCode) {
        var code = normalizeDigits(String(rawCode || '').trim());
        if (code === '') return 0;
        return parseInt(employeeCodeMap[code] || '0', 10) || 0;
    }

    function formatAmount(n) {
        if (!isFinite(n)) return '0.000';
        return n.toFixed(3);
    }

    function multiplierLabel(value) {
        if (Math.abs(value - 1.25) < 0.001) return 'ساعة = ساعة وربع';
        if (Math.abs(value - 1.5) < 0.001) return 'ساعة = ساعة ونصف';
        if (Math.abs(value - 2) < 0.001) return 'ساعة = ساعتان';
        if (Math.abs(value - 1) < 0.001) return 'ساعة = ساعة';
        return 'ساعة = ' + value.toFixed(3).replace(/\.?0+$/, '') + ' ساعة';
    }

    function selectedMultiplier() {
        var checked = multiplierRadios.find(function (r) { return r.checked; });
        if (checked) {
            return parseFloat(checked.value || '0') || 0;
        }
        return parseFloat(page.getAttribute('data-hour-multiplier') || '1.25') || 1.25;
    }

    function calcHourlyRate() {
        if (grossSalary <= 0 || monthlyDays <= 0 || dailyHours <= 0) return 0;
        return grossSalary / monthlyDays / dailyHours;
    }

    function calcAmount(hours) {
        if (hours <= 0 || grossSalary <= 0 || monthlyDays <= 0 || dailyHours <= 0) return 0;
        var hourly = calcHourlyRate();
        var overtimeHourly = hourly * selectedMultiplier();
        return Math.round(hours * overtimeHourly * 1000) / 1000;
    }

    function updatePreview() {
        if (!hoursInput || !amountDisplay) return;
        var hours = parseFloat(hoursInput.value || '0') || 0;
        var mult = selectedMultiplier();
        amountDisplay.value = formatAmount(calcAmount(hours));
        var hourly = calcHourlyRate();
        if (hourlyRateEl && hourly > 0) {
            hourlyRateEl.textContent = formatAmount(hourly);
        }
        if (overtimeHourlyRateEl && hourly > 0) {
            overtimeHourlyRateEl.textContent = formatAmount(hourly * mult);
        }
        if (multiplierDisplay) {
            multiplierDisplay.textContent = mult.toFixed(2);
        }
        if (multiplierLabelEl) {
            multiplierLabelEl.textContent = multiplierLabel(mult);
        }
    }

    function getSelectedYear() {
        var pageYear = parseInt(page.getAttribute('data-pay-year') || '0', 10) || new Date().getFullYear();
        var year = parseInt(filterYear && filterYear.value ? filterYear.value : String(pageYear), 10);
        return isNaN(year) || year < 2000 || year > 2100 ? pageYear : year;
    }

    function getSelectedMonth() {
        var pageMonth = parseInt(page.getAttribute('data-pay-month') || '0', 10) || 1;
        var month = parseInt(filterMonth && filterMonth.value ? filterMonth.value : String(pageMonth), 10);
        return isNaN(month) || month < 1 || month > 12 ? pageMonth : month;
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

    function syncCodeInputFromEmployee(emp) {
        if (!masterEmpCode) return;
        if (!emp || !emp.id) {
            masterEmpCode.value = '';
            return;
        }
        masterEmpCode.value = String(emp.code || emp.emp_code || '').trim();
    }

    function appDialogAlert(msg, type) {
        if (window.AppDialog && typeof AppDialog.alert === 'function') {
            AppDialog.alert(msg, { type: type || 'warning' });
            return;
        }
        window.alert(msg);
    }

    function initEmployeePickerModal() {
        if (!pickerId || !pickerOpen || !pickerDisplay) return;
        if (!window.EmployeePickerModal) {
            setTimeout(initEmployeePickerModal, 40);
            return;
        }
        var currentId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10) || 0;
        EmployeePickerModal.bind({
            hidden: 'hr-ot-picker-id',
            open: 'hr-ot-picker-id_open',
            display: 'hr-ot-picker-id_display',
            jsonId: 'hr-ot-picker-json',
            employees: employeeItems,
            allowNew: false,
            placeholder: 'اضغط لاختيار الموظف',
            initialId: currentId || '',
            onSelect: function (emp) {
                syncCodeInputFromEmployee(emp);
                var nextId = emp && emp.id ? parseInt(emp.id, 10) : 0;
                if (nextId === currentId) return;
                navigateToFilter(nextId);
            },
        });
        if (currentId > 0 && employeesById[currentId]) {
            syncCodeInputFromEmployee(employeesById[currentId]);
        }
    }

    if (hoursInput) {
        hoursInput.addEventListener('input', updatePreview);
        updatePreview();
    }

    multiplierRadios.forEach(function (radio) {
        radio.addEventListener('change', updatePreview);
    });

    function syncMonthMetaFields(activeChip) {
        var chip = activeChip || null;
        if (!chip && filterForm) {
            chip = filterForm.querySelector('.hr-mchip-chip.is-active');
        }
        if (!chip) return;
        var statusKey = chip.getAttribute('data-status') || 'empty';
        if (filterMonthName) {
            filterMonthName.value = chip.getAttribute('data-name') || chip.textContent.trim();
        }
        if (filterMonthStatus) {
            var labels = {
                posted: 'مرحّل',
                mixed: 'مرحّل/محتسب',
                calculated: 'محتسب',
                open: 'مفتوح',
                empty: '—',
            };
            filterMonthStatus.value = labels[statusKey] || '—';
            filterMonthStatus.setAttribute('data-status', statusKey);
        }
    }

    if (window.HrMonthChipStrip && filterForm) {
        HrMonthChipStrip.bind(filterForm, {
            monthInputId: 'hr-ot-filter-month',
            autoSubmit: false,
            onSelect: function (_month, chip) {
                syncMonthMetaFields(chip);
                navigateToFilter(selectedEmployeeId());
            },
        });
        syncMonthMetaFields();
    }

    if (filterYear) {
        filterYear.addEventListener('change', function () {
            filterYear.value = String(getSelectedYear());
            navigateToFilter(selectedEmployeeId());
        });
        filterYear.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
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

    if (masterEmpCode) {
        var codeOnFocus = '';
        masterEmpCode.addEventListener('focus', function () {
            codeOnFocus = normalizeDigits(String(masterEmpCode.value || '').trim());
        });
        masterEmpCode.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
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
            if (typedCode === prevCode) return;
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

    initEmployeePickerModal();

    if (masterEmpCode && masterEmpCode.value === '') {
        var selectedEmp = employeesById[selectedEmployeeId()] || null;
        if (selectedEmp) {
            syncCodeInputFromEmployee(selectedEmp);
        }
    }

    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-ot-editor-form');
    var deleteForm = document.getElementById('hr-ot-delete-form');

    function submitEditorSave() {
        if (!editorForm) {
            appDialogAlert('اختر الموظف ثم أدخل ساعات العمل الإضافي.', 'warning');
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

    function submitDelete() {
        if (!deleteForm) {
            appDialogAlert('لا يوجد سجل لحذفه.', 'warning');
            return;
        }
        var confirmFn = window.AppDialog && typeof AppDialog.confirm === 'function'
            ? AppDialog.confirm.bind(AppDialog)
            : function (msg) { return Promise.resolve(window.confirm(msg)); };
        confirmFn('حذف سجل العمل الإضافي لهذا الشهر؟', { danger: true }).then(function (ok) {
            if (!ok) return;
            deleteForm.submit();
        });
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        if ((bar ? bar.getAttribute('data-active-route') : '') !== 'hr_employee_overtime') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitEditorSave();
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            submitEditorSave();
        }
    });
})();
