(function () {
    'use strict';

    var page = document.querySelector('.hr-emp-sch-page');
    if (!page) return;

    var filterForm = document.getElementById('hr-emp-sch-filter-form');
    var empCodeInp = document.getElementById('hr-emp-sch-emp-code');
    var empIdInp = document.getElementById('hr-emp-sch-employee-id');
    var empOpenBtn = document.getElementById('hr-emp-sch-employee-id_open');
    var empDisplay = document.getElementById('hr-emp-sch-employee-id_display');
    var weeklyFormId = page.getAttribute('data-weekly-form-id') || 'hr-emp-sch-weekly-form';
    var defaultFormId = page.getAttribute('data-default-form-id') || 'hr-emp-sch-default-form';
    var weeklyForm = document.getElementById(weeklyFormId);
    var defaultForm = document.getElementById(defaultFormId);
    var deleteBtn = document.getElementById('hr-emp-sch-delete-weekly');
    var deleteForm = document.getElementById('hr-emp-sch-delete-form');
    var jsonEl = document.getElementById('hr-emp-sch-picker-json');
    var employeeItems = [];
    var employeesById = {};
    var employeesByCode = {};
    var activeTab = 'weekly';

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
        return String(value || '').replace(/\D+/g, '');
    }

    function indexEmployees(list) {
        employeesById = {};
        employeesByCode = {};
        list.forEach(function (emp) {
            var id = parseInt(String(emp.id || '0'), 10) || 0;
            if (id < 1) return;
            employeesById[id] = emp;
            var code = normalizeDigits(emp.code || '');
            if (code !== '') employeesByCode[code] = emp;
        });
    }

    try {
        employeeItems = jsonEl ? JSON.parse(jsonEl.textContent || '[]') : [];
    } catch (e) {
        employeeItems = [];
    }
    indexEmployees(employeeItems);

    function selectedEmployeeId() {
        return parseInt(empIdInp ? empIdInp.value || '0' : '0', 10) || 0;
    }

    function codeToEmployeeId(code) {
        var c = normalizeDigits(code);
        if (!c || !employeesByCode[c]) return 0;
        return parseInt(String(employeesByCode[c].id || '0'), 10) || 0;
    }

    function syncCodeInputFromEmployee(emp) {
        if (!empCodeInp) return;
        empCodeInp.value = emp && emp.code ? String(emp.code) : '';
    }

    function navigateToEmployee(empId) {
        var listUrl = page.getAttribute('data-list-url') || 'index.php?r=hr_employee_schedule';
        var join = listUrl.indexOf('?') >= 0 ? '&' : '?';
        window.location.href = listUrl + join + 'employee_id=' + encodeURIComponent(String(empId || 0));
    }

    function initEmployeePickerModal() {
        if (!empIdInp || !empOpenBtn || !empDisplay) return;
        if (!window.EmployeePickerModal) {
            setTimeout(initEmployeePickerModal, 40);
            return;
        }
        var currentId = parseInt(page.getAttribute('data-employee-id') || '0', 10) || 0;
        EmployeePickerModal.bind({
            hidden: 'hr-emp-sch-employee-id',
            open: 'hr-emp-sch-employee-id_open',
            display: 'hr-emp-sch-employee-id_display',
            jsonId: 'hr-emp-sch-picker-json',
            employees: employeeItems,
            allowNew: false,
            placeholder: 'اضغط لاختيار الموظف',
            initialId: currentId || '',
            onSelect: function (emp) {
                syncCodeInputFromEmployee(emp);
                var nextId = emp && emp.id ? parseInt(emp.id, 10) : 0;
                if (nextId === currentId) return;
                navigateToEmployee(nextId);
            },
        });
        if (currentId > 0 && employeesById[currentId]) {
            syncCodeInputFromEmployee(employeesById[currentId]);
        }
    }

    if (empCodeInp) {
        var codeOnFocus = '';
        empCodeInp.addEventListener('focus', function () {
            codeOnFocus = normalizeDigits(empCodeInp.value);
        });
        empCodeInp.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();
            var typedCode = normalizeDigits(empCodeInp.value);
            if (typedCode === '') {
                navigateToEmployee(0);
                return;
            }
            var matchedId = codeToEmployeeId(typedCode);
            if (matchedId < 1) {
                appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
                empCodeInp.value = codeOnFocus;
                return;
            }
            navigateToEmployee(matchedId);
        });
        empCodeInp.addEventListener('blur', function () {
            var typedCode = normalizeDigits(empCodeInp.value);
            var prevCode = normalizeDigits(codeOnFocus);
            if (typedCode === prevCode) return;
            if (typedCode === '') return;
            var matchedId = codeToEmployeeId(typedCode);
            if (matchedId < 1) {
                appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
                empCodeInp.value = codeOnFocus;
            }
        });
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var empId = selectedEmployeeId();
            if (empId < 1) {
                appDialogAlert('اختر الموظف أولاً.', 'warning');
                return;
            }
            navigateToEmployee(empId);
        });
    }

    function switchTab(tabName) {
        activeTab = tabName;
        page.querySelectorAll('.hr-emp-sch-tabs .sales-ora-tab').forEach(function (btn) {
            var on = btn.getAttribute('data-tab') === tabName;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        page.querySelectorAll('.hr-emp-sch-tab-panel').forEach(function (panel) {
            var on = panel.getAttribute('data-panel') === tabName;
            panel.classList.toggle('is-active', on);
            if (on) panel.removeAttribute('hidden');
            else panel.setAttribute('hidden', 'hidden');
        });
    }

    page.querySelectorAll('.hr-emp-sch-tabs .sales-ora-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            switchTab(btn.getAttribute('data-tab') || 'weekly');
        });
    });

    if (deleteBtn && deleteForm) {
        deleteBtn.addEventListener('click', function () {
            appDialogConfirm('حذف هذه الفترة الأسبوعية نهائياً؟').then(function (ok) {
                if (ok) deleteForm.submit();
            });
        });
    }

    function submitActiveForm() {
        var form = activeTab === 'default' ? defaultForm : weeklyForm;
        if (!form) {
            appDialogAlert('افتح تبويب الجدول الأسبوعي أو الشفت الافتراضي ثم احفظ.', 'warning');
            return;
        }
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_employee_schedule') return;
        if (e.detail.action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitActiveForm();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            submitActiveForm();
        }
    });

    initEmployeePickerModal();
})();
