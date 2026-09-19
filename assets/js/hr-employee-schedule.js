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
    var scheduleLoaded = page.getAttribute('data-schedule-loaded') === '1';

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

    function parseDmYToIso(str) {
        if (window.AppDatePicker && typeof AppDatePicker.parseDmYToIso === 'function') {
            return AppDatePicker.parseDmYToIso(str);
        }
        var raw = String(str || '').trim();
        var digitsOnly = raw.replace(/\D/g, '');
        if (digitsOnly.length === 8) {
            raw = digitsOnly.slice(0, 2) + '-' + digitsOnly.slice(2, 4) + '-' + digitsOnly.slice(4);
        }
        var m = raw.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
        if (!m) return '';
        var d = parseInt(m[1], 10);
        var mo = parseInt(m[2], 10);
        var y = parseInt(m[3], 10);
        if (d < 1 || d > 31 || mo < 1 || mo > 12) return '';
        return String(y) + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    function formatIsoToDmY(iso) {
        if (window.AppDatePicker && typeof AppDatePicker.formatIsoToDmY === 'function') {
            return AppDatePicker.formatIsoToDmY(iso);
        }
        var m = String(iso || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return '';
        return (
            String(parseInt(m[3], 10)).padStart(2, '0') +
            '-' +
            String(parseInt(m[2], 10)).padStart(2, '0') +
            '-' +
            m[1]
        );
    }

    function isoToDate(iso) {
        var m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
    }

    function dateToIso(date) {
        if (!date || isNaN(date.getTime())) return '';
        return (
            String(date.getFullYear()) + '-' +
            String(date.getMonth() + 1).padStart(2, '0') + '-' +
            String(date.getDate()).padStart(2, '0')
        );
    }

    function weekDayIndex(iso) {
        var d = isoToDate(iso);
        if (!d) return -1;
        return (d.getDay() + 1) % 7;
    }

    function addDaysIso(iso, days) {
        var d = isoToDate(iso);
        if (!d) return '';
        d.setDate(d.getDate() + days);
        return dateToIso(d);
    }

    var dateFromInp = document.getElementById('hr-emp-sch-date-from');
    var dateToInp = document.getElementById('hr-emp-sch-date-to');
    var weekHintEl = document.getElementById('hr-emp-sch-week-hint');

    function daysBetweenInclusive(fromIso, toIso) {
        var from = isoToDate(fromIso);
        var to = isoToDate(toIso);
        if (!from || !to) return 0;
        var ms = to.getTime() - from.getTime();
        return Math.round(ms / 86400000) + 1;
    }

    function updateWeekHint() {
        if (!dateFromInp) return;
        var fromIso = parseDmYToIso(dateFromInp.value);
        if (!fromIso || weekDayIndex(fromIso) !== 0) {
            if (weekHintEl) weekHintEl.textContent = '';
            page.querySelectorAll('.hr-emp-sch-day-date').forEach(function (el) {
                el.textContent = '—';
            });
            return;
        }
        if (weekHintEl) {
            weekHintEl.textContent = 'الفترة: 7 أيام (سبت → جمعة) — عيّن شفتاً لكل يوم أو اترك الافتراضي.';
        }
        page.querySelectorAll('.hr-emp-sch-day-date').forEach(function (el) {
            var offset = parseInt(el.getAttribute('data-day-offset') || '0', 10) || 0;
            el.textContent = formatIsoToDmY(addDaysIso(fromIso, offset)) || '—';
        });
    }

    function validateWeeklyDates() {
        if (!dateFromInp || !dateToInp) return true;
        if (window.AppDatePicker && typeof AppDatePicker.formatDmYFromDigits === 'function') {
            var fromDigits = String(dateFromInp.value || '').replace(/\D/g, '');
            var toDigits = String(dateToInp.value || '').replace(/\D/g, '');
            if (fromDigits.length === 8) {
                dateFromInp.value = AppDatePicker.formatDmYFromDigits(fromDigits);
            }
            if (toDigits.length === 8) {
                dateToInp.value = AppDatePicker.formatDmYFromDigits(toDigits);
            }
        }
        if (!String(dateFromInp.value || '').trim()) {
            appDialogAlert('أدخل تاريخ بداية الأسبوع (يوم السبت).', 'warning');
            dateFromInp.focus();
            return false;
        }
        if (!String(dateToInp.value || '').trim()) {
            appDialogAlert('أدخل تاريخ نهاية الأسبوع (يوم الجمعة).', 'warning');
            dateToInp.focus();
            return false;
        }
        var fromIso = parseDmYToIso(dateFromInp.value);
        var toIso = parseDmYToIso(dateToInp.value);
        if (!fromIso || !toIso) {
            appDialogAlert('صيغة التاريخ غير صحيحة. استخدم يوم-شهر-سنة.', 'warning');
            return false;
        }
        if (fromIso > toIso) {
            appDialogAlert('تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.', 'warning');
            return false;
        }
        if (weekDayIndex(fromIso) !== 0) {
            appDialogAlert('تاريخ البداية يجب أن يكون يوم سبت.', 'warning');
            dateFromInp.focus();
            return false;
        }
        if (weekDayIndex(toIso) !== 6) {
            appDialogAlert('تاريخ النهاية يجب أن يكون يوم جمعة.', 'warning');
            dateToInp.focus();
            return false;
        }
        var expectedEnd = addDaysIso(fromIso, 6);
        if (toIso !== expectedEnd) {
            appDialogAlert(
                'مدة الفترة يجب أن تكون 7 أيام. لتاريخ البداية المدخل يجب أن يكون تاريخ النهاية: '
                + formatIsoToDmY(expectedEnd) + '.',
                'warning'
            );
            dateToInp.focus();
            return false;
        }
        if (daysBetweenInclusive(fromIso, toIso) !== 7) {
            appDialogAlert('مدة الفترة يجب أن تكون 7 أيام بالضبط (من السبت إلى الجمعة).', 'warning');
            return false;
        }
        return true;
    }

    function onWeeklyDateChange() {
        updateWeekHint();
    }

    if (dateFromInp) {
        dateFromInp.addEventListener('change', onWeeklyDateChange);
        dateFromInp.addEventListener('blur', onWeeklyDateChange);
    }
    if (dateToInp) {
        dateToInp.addEventListener('change', onWeeklyDateChange);
        dateToInp.addEventListener('blur', onWeeklyDateChange);
    }
    onWeeklyDateChange();

    var periodNav = page.querySelector('.hr-emp-sch-period-nav');
    if (periodNav) {
        periodNav.querySelectorAll('a.hr-emp-sch-nav-btn').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (weeklyForm && weeklyFormHasChanges()) {
                    e.preventDefault();
                    appDialogConfirm('توجد تغييرات غير محفوظة. الانتقال دون حفظ؟').then(function (ok) {
                        if (ok) window.location.href = link.getAttribute('href') || '';
                    });
                }
            });
        });
    }

    page.querySelectorAll('a.hr-emp-sch-new-week-btn').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (weeklyForm && weeklyFormHasChanges()) {
                e.preventDefault();
                appDialogConfirm('توجد تغييرات غير محفوظة. الانتقال دون حفظ؟').then(function (ok) {
                    if (ok) window.location.href = link.getAttribute('href') || '';
                });
            }
        });
    });

    var weeklyFormInitial = weeklyForm ? serializeWeeklyForm(weeklyForm) : '';

    function serializeWeeklyForm(form) {
        if (!form) return '';
        var data = new FormData(form);
        var parts = [];
        data.forEach(function (value, key) {
            parts.push(key + '=' + String(value));
        });
        parts.sort();
        return parts.join('&');
    }

    function weeklyFormHasChanges() {
        if (!weeklyForm) return false;
        return serializeWeeklyForm(weeklyForm) !== weeklyFormInitial;
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
        if (!scheduleLoaded) {
            appDialogAlert('اختر الموظف ثم اضغط «عرض» لتحميل بيانات الدوام.', 'warning');
            return;
        }
        var form = activeTab === 'default' ? defaultForm : weeklyForm;
        if (!form) {
            appDialogAlert('افتح تبويب الجدول الأسبوعي أو الشفت الافتراضي ثم احفظ.', 'warning');
            return;
        }
        if (form === weeklyForm && !validateWeeklyDates()) return;
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    }

    if (weeklyForm) {
        weeklyForm.addEventListener('submit', function (e) {
            if (!scheduleLoaded) {
                e.preventDefault();
                appDialogAlert('اختر الموظف ثم اضغط «عرض» لتحميل بيانات الدوام.', 'warning');
                return;
            }
            if (!validateWeeklyDates()) {
                e.preventDefault();
            }
        });
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
            return;
        }
        if (activeTab !== 'weekly' || !periodNav) return;
        if (e.key === 'ArrowRight') {
            var prevLink = periodNav.querySelector('a.hr-emp-sch-nav-btn--prev');
            if (prevLink) {
                e.preventDefault();
                prevLink.click();
            }
        } else if (e.key === 'ArrowLeft') {
            var nextLink = periodNav.querySelector('a.hr-emp-sch-nav-btn--next');
            if (nextLink) {
                e.preventDefault();
                nextLink.click();
            }
        }
    });

    initEmployeePickerModal();
})();
