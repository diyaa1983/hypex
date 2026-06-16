(function () {
    'use strict';

    var page = document.querySelector('.hr-emp-grid-page');
    if (!page) return;

    var form = document.getElementById('hr-emp-form');
    var delForm = document.getElementById('hr-emp-delete-form');
    var listUrl = page.getAttribute('data-list-url') || '';
    var printBaseUrl = page.getAttribute('data-print-base-url') || '';
    var browseUrl = page.getAttribute('data-browse-url') || '';
    var prevUrl = page.getAttribute('data-prev-url') || '';
    var nextUrl = page.getAttribute('data-next-url') || '';
    var firstUrl = page.getAttribute('data-first-url') || '';
    var lastUrl = page.getAttribute('data-last-url') || '';
    var currentId = parseInt(page.getAttribute('data-current-id') || '0', 10);
    var currentCode = page.getAttribute('data-current-code') || '';
    var isBrowse = page.getAttribute('data-browse') === '1';
    var unsaved = null;

    function normalizeDigits(value) {
        return String(value || '')
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
    }

    function normalizeSearchText(value) {
        return normalizeDigits(String(value || '')).trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function captureEmpForm() {
        if (!form) {
            return null;
        }
        var data = {};
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (!el.name || el.disabled) {
                return;
            }
            if (el.type === 'checkbox') {
                data[el.name] = el.checked;
            } else if (el.type === 'radio') {
                if (el.checked) {
                    data[el.name] = el.value;
                }
            } else {
                data[el.name] = el.value;
            }
        });
        return data;
    }

    function appDialogPrompt(opts) {
        opts = opts || {};
        if (window.AppDialog && AppDialog.prompt) {
            return AppDialog.prompt(opts.message || '', {
                title: opts.title || 'إدخال',
                okText: opts.okLabel || 'حسناً',
                cancelText: opts.cancelLabel || 'إلغاء',
                value: opts.defaultValue || '',
                placeholder: opts.placeholder || '',
                multiline: false,
            });
        }
        return Promise.resolve(window.prompt(opts.message || '', opts.defaultValue || ''));
    }

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

    function isResignationPosted() {
        return page.getAttribute('data-resignation-posted') === '1';
    }

    function submitSave() {
        if (!form) return;
        if (isResignationPosted()) {
            appDialogAlert('بطاقة موظف مستقيل مرحّلة — لا يمكن الحفظ إلا بعد فك الترحيل.', 'warning');
            return;
        }
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        if (unsaved) {
            unsaved.markSubmitting(true);
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function submitPostResignation() {
        if (currentId < 1) {
            appDialogAlert('احفظ الموظف أولاً ثم أدخل تاريخ الاستقالة واضغط ترحيل.', 'warning');
            return;
        }
        if (isResignationPosted()) {
            appDialogAlert('الموظف مرحّل كمستقيل مسبقاً.', 'warning');
            return;
        }
        var resignDateInp = document.getElementById('hr-emp-resignation-date');
        var resignChk = document.getElementById('hr-emp-is-resigned');
        var dateVal = resignDateInp ? String(resignDateInp.value || '').trim() : '';
        if (!dateVal) {
            appDialogAlert('أدخل تاريخ الاستقالة ثم اضغط ترحيل.', 'warning');
            if (resignDateInp) resignDateInp.focus();
            return;
        }
        if (resignChk) resignChk.checked = true;
        var hiddenDate = document.getElementById('hr-emp-post-resignation-date');
        var postForm = document.getElementById('hr-emp-post-resign-form');
        if (!postForm || !hiddenDate) return;
        hiddenDate.value = dateVal;
        appDialogConfirm('ترحيل استقالة الموظف؟ لن يمكن تعديل البطاقة إلا بعد فك الترحيل.').then(function (ok) {
            if (ok) postForm.submit();
        });
    }

    function submitUnpostResignation() {
        if (currentId < 1) {
            appDialogAlert('لا يوجد موظف محدد.', 'warning');
            return;
        }
        if (!isResignationPosted()) {
            appDialogAlert('الموظف غير مرحّل كمستقيل.', 'warning');
            return;
        }
        var unpostForm = document.getElementById('hr-emp-unpost-resign-form');
        if (!unpostForm) return;
        appDialogConfirm('فك ترحيل الاستقالة وإتاحة تعديل بطاقة الموظف؟').then(function (ok) {
            if (ok) unpostForm.submit();
        });
    }

    function submitDelete() {
        if (!delForm || currentId < 1) {
            appDialogAlert('لا يوجد موظف محدد للحذف. افتح بطاقة موظف ثم اضغط حذف.', 'warning');
            return;
        }
        if (isResignationPosted()) {
            appDialogAlert('لا يمكن حذف موظف مستقيل مرحّل — فك الترحيل أولاً.', 'warning');
            return;
        }
        if (page.getAttribute('data-can-delete') === '0') {
            appDialogAlert(
                page.getAttribute('data-delete-block-reason')
                    || 'لا يمكن حذف الموظف: يوجد عليه حركات في النظام.',
                'warning'
            );
            return;
        }
        appDialogConfirm('حذف الموظف نهائياً من النظام؟').then(function (ok) {
            if (ok) {
                delForm.submit();
            }
        });
    }

    function doSearch() {
        appDialogPrompt({
            title: 'بحث عن موظف',
            message: 'أدخل الرقم الوظيفي أو جزءاً من الاسم:',
            defaultValue: '',
            okLabel: 'بحث',
            cancelLabel: 'إلغاء',
        }).then(function (val) {
            if (val === null || val === undefined) return;
            var q = normalizeDigits(String(val).trim());
            if (q === '') return;
            var url;
            if (/^\d+$/.test(q)) {
                url = listUrl + '&emp_code=' + encodeURIComponent(q);
            } else {
                url = listUrl + '&action=browse&q=' + encodeURIComponent(q);
            }
            navigateTo(url);
        });
    }

    function currentPrintUrl() {
        if (!printBaseUrl || currentId < 1) {
            return '';
        }
        return printBaseUrl + '&id=' + encodeURIComponent(String(currentId));
    }

    function getEmployeePrintFrame() {
        var frame = document.getElementById('hr-emp-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'hr-emp-print-frame';
            frame.className = 'sales-inv-print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute('tabindex', '-1');
            document.body.appendChild(frame);
        }
        return frame;
    }

    function printEmployeeCard() {
        if (currentId < 1) {
            appDialogAlert('اختر موظفاً أولاً ثم اطبع.', 'warning');
            return;
        }
        var url = currentPrintUrl();
        if (!url) {
            appDialogAlert('تعذر فتح صفحة الطباعة.', 'error');
            return;
        }
        var frame = getEmployeePrintFrame();
        var printed = false;
        frame.onload = function () {
            if (printed) return;
            printed = true;
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                appDialogAlert('تعذر تنفيذ الطباعة.', 'error');
            }
        };
        frame.src = url;
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_employees') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitSave();
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete();
        } else if (action === 'search') {
            e.preventDefault();
            e.stopImmediatePropagation();
            doSearch();
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            navigateTo(listUrl);
        } else if (action === 'post') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitPostResignation();
        } else if (action === 'unpost') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitUnpostResignation();
        } else if (action === 'print') {
            e.preventDefault();
            e.stopImmediatePropagation();
            printEmployeeCard();
        }
    }, true);

    var resignDateInp = document.getElementById('hr-emp-resignation-date');
    var resignChk = document.getElementById('hr-emp-is-resigned');
    if (resignDateInp && resignChk && !isResignationPosted()) {
        resignChk.addEventListener('change', function () {
            if (!resignChk.checked) {
                resignDateInp.value = '';
            }
        });
        resignDateInp.addEventListener('change', function () {
            if (resignDateInp.value) {
                resignChk.checked = true;
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) {
            if (e.target.id === 'hr-emp-picker-code' && e.key === 'Enter') {
                return;
            }
            return;
        }
        if (e.altKey && e.key === 'ArrowRight' && prevUrl) {
            navigateTo(prevUrl);
        } else if (e.altKey && e.key === 'ArrowLeft' && nextUrl) {
            navigateTo(nextUrl);
        } else if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            submitSave();
        }
    });

    function navigateTo(url, onCancel) {
        if (!url) {
            return;
        }
        if (unsaved) {
            unsaved.navigateAway(url, onCancel);
        } else {
            window.location.href = url;
        }
    }

    function codeToUrl(rawCode) {
        var code = normalizeDigits(String(rawCode || '').trim());
        if (code === '' || code === '—') {
            return listUrl;
        }
        var idx = buildCodeIndex();
        if (idx.map[code]) {
            return listUrl + '&id=' + encodeURIComponent(String(idx.map[code]));
        }
        return listUrl + '&emp_code=' + encodeURIComponent(code);
    }

    function navigateByCode(rawCode, onCancel) {
        navigateTo(codeToUrl(rawCode), onCancel);
    }

    function parseEmployeeList() {
        var el = document.getElementById('hr-employees-picker-json');
        if (el) {
            try {
                var fromScript = JSON.parse(el.textContent || '[]') || [];
                if (fromScript.length) {
                    return fromScript;
                }
            } catch (err) {
                /* ignored */
            }
        }
        try {
            return JSON.parse(page.getAttribute('data-picker-employees') || '[]') || [];
        } catch (err2) {
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

    function bindNavBtn(id, url) {
        var btn = document.getElementById(id);
        if (!btn) {
            return;
        }
        btn.disabled = !url;
        if (!url) {
            return;
        }
        btn.addEventListener('click', function () {
            navigateTo(url);
        });
    }

    var pickerCode = document.getElementById('hr-emp-picker-code');
    var employeePickerApi = null;

    bindNavBtn('hr-emp-nav-prev', prevUrl);
    bindNavBtn('hr-emp-nav-next', nextUrl);

    function syncPickerCodeFromEmployee(emp) {
        if (!pickerCode) {
            return;
        }
        if (!emp || !emp.id) {
            pickerCode.value = '';
            return;
        }
        pickerCode.value = String(emp.code || '').trim();
    }

    function initEmployeePicker() {
        if (!window.EmployeePickerModal) {
            setTimeout(initEmployeePicker, 40);
            return;
        }
        if (employeePickerApi) {
            return;
        }
        employeePickerApi = EmployeePickerModal.bind({
            hidden: 'hr-emp-picker-id',
            open: 'hr-emp-picker-id_open',
            display: 'hr-emp-picker-id_display',
            jsonId: 'hr-employees-picker-json',
            employees: parseEmployeeList(),
            allowNew: true,
            newLabel: '— موظف جديد —',
            placeholder: 'اضغط لاختيار الموظف',
            initialId: currentId > 0 ? currentId : '',
            onSelect: function (emp) {
                if (!emp || !emp.id) {
                    navigateTo(listUrl);
                    return;
                }
                syncPickerCodeFromEmployee(emp);
                if (emp.id === currentId) {
                    return;
                }
                navigateTo(listUrl + '&id=' + encodeURIComponent(String(emp.id)));
            },
        });
        if (employeePickerApi && currentId > 0) {
            syncPickerCodeFromEmployee(employeePickerApi.getEmployee());
        }
    }

    if (!isBrowse) {
        initEmployeePicker();
    }

    function syncOraLovButtons() {
        page.querySelectorAll('.hr-emp-ora-lov').forEach(function (wrap) {
            var sel = wrap.querySelector('select');
            var btn = wrap.querySelector('.hr-emp-ora-lov-btn');
            if (btn && sel) {
                btn.disabled = !!sel.disabled;
            }
        });
    }

    function openOraLovSelect(btn) {
        var wrap = btn.closest('.hr-emp-ora-lov');
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

    page.querySelectorAll('.hr-emp-ora-lov-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openOraLovSelect(btn);
        });
    });
    syncOraLovButtons();

    function isSequentialFieldVisible(el) {
        if (!el) {
            return false;
        }
        if (el.hidden) {
            return false;
        }
        if (el.closest('[hidden]')) {
            return false;
        }
        var pane = el.closest('.hr-emp-ora-stack-pane');
        if (pane && pane.hidden) {
            return false;
        }
        var style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden';
    }

    function isSequentialField(el) {
        if (!el || el.disabled) {
            return false;
        }
        if (!/^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) {
            return false;
        }
        if (el.readOnly) {
            return false;
        }
        if (el.tagName === 'INPUT') {
            var type = String(el.type || 'text').toLowerCase();
            if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset' || type === 'image') {
                return false;
            }
        }
        return isSequentialFieldVisible(el);
    }

    function getSequentialFields() {
        if (!form) {
            return [];
        }
        var all = Array.prototype.slice.call(form.querySelectorAll('input, select, textarea'));
        return all.filter(isSequentialField);
    }

    function focusSequentialField(current, step) {
        var fields = getSequentialFields();
        if (!fields.length) {
            return;
        }
        var idx = fields.indexOf(current);
        if (idx < 0) {
            idx = 0;
        }
        var nextIdx = idx + step;
        if (nextIdx < 0 || nextIdx >= fields.length) {
            return;
        }
        var target = fields[nextIdx];
        if (!target) {
            return;
        }
        target.focus();
        if (target.tagName === 'INPUT') {
            var t = String(target.type || 'text').toLowerCase();
            if (t === 'text' || t === 'search' || t === 'email' || t === 'tel' || t === 'number') {
                target.select();
            }
        }
    }

    function bindSequentialKeyboardNav() {
        if (!form || isBrowse) {
            return;
        }
        form.addEventListener('keydown', function (e) {
            var target = e.target;
            if (!target || !form.contains(target) || !isSequentialField(target)) {
                return;
            }
            if (e.altKey || e.ctrlKey || e.metaKey) {
                return;
            }

            if (e.key === 'Enter') {
                if (target.tagName === 'TEXTAREA') {
                    return;
                }
                e.preventDefault();
                focusSequentialField(target, e.shiftKey ? -1 : 1);
                return;
            }

            if (e.shiftKey) {
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                focusSequentialField(target, 1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                focusSequentialField(target, -1);
            }
        });
    }

    function focusFirstNameField() {
        if (!form || isBrowse) {
            return;
        }
        var firstNameInput = form.querySelector('input[name="name_first"]');
        if (!isSequentialField(firstNameInput)) {
            return;
        }
        var active = document.activeElement;
        if (active && active !== document.body && active !== document.documentElement) {
            return;
        }
        window.requestAnimationFrame(function () {
            firstNameInput.focus();
            firstNameInput.select();
        });
    }

    if (pickerCode) {
        var codeOnFocus = '';
        pickerCode.addEventListener('focus', function () {
            codeOnFocus = String(pickerCode.value || '').trim();
            pickerCode.select();
        });
        pickerCode.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                navigateByCode(pickerCode.value);
            }
        });
        pickerCode.addEventListener('blur', function () {
            var typed = String(pickerCode.value || '').trim();
            if (typed === codeOnFocus) {
                return;
            }
            navigateByCode(typed, function () {
                pickerCode.value = codeOnFocus;
            });
        });
    }

    var browseBody = document.getElementById('hr-emp-browse-body');
    var browseSearch = document.querySelector('.hr-emp-picker-form input[name="q"]');
    if (browseSearch && browseBody) {
        browseSearch.addEventListener('input', function () {
            var q = normalizeSearchText(browseSearch.value || '');
            var rows = browseBody.querySelectorAll('tr.hr-emp-row:not(.hr-emp-row--empty)');
            var visible = 0;
            rows.forEach(function (tr) {
                var text = normalizeSearchText(tr.textContent || '');
                var show = q === '' || text.indexOf(q) !== -1;
                tr.hidden = !show;
                if (show) {
                    visible += 1;
                }
            });
            var emptyRow = browseBody.querySelector('tr.hr-emp-row--empty');
            if (!emptyRow && visible === 0) {
                emptyRow = document.createElement('tr');
                emptyRow.className = 'hr-emp-row hr-emp-row--empty hr-emp-row--filter-empty';
                emptyRow.innerHTML = '<td colspan="6" class="muted">لا توجد نتائج مطابقة.</td>';
                browseBody.appendChild(emptyRow);
            } else if (emptyRow && emptyRow.classList.contains('hr-emp-row--filter-empty')) {
                emptyRow.hidden = visible > 0;
            }
        });
    }
    if (browseBody) {
        browseBody.addEventListener('click', function (e) {
            var tr = e.target.closest('tr.hr-emp-row');
            if (!tr || tr.classList.contains('hr-emp-row--empty')) return;
            var href = tr.getAttribute('data-href');
            if (href) window.location.href = href;
        });
        browseBody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-emp-row');
            if (!tr || tr.classList.contains('hr-emp-row--empty')) return;
            var href = tr.getAttribute('data-href');
            if (href) {
                e.preventDefault();
                window.location.href = href;
            }
        });
    }

    var deptSel = document.getElementById('hr-emp-dept-sel');
    var jtSel = document.getElementById('hr-emp-jt-sel');

    if (deptSel && jtSel) {
        var allJobTitles = [];
        try {
            allJobTitles = JSON.parse(jtSel.getAttribute('data-job-titles') || '[]') || [];
        } catch (err) {
            allJobTitles = [];
        }

        function rebuildJobTitleOptions(deptId) {
            var currentVal = jtSel.value;
            // احتفظ بالخيار الفارغ
            jtSel.innerHTML = '';
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = '— بدون مسمى —';
            jtSel.appendChild(blank);

            allJobTitles.forEach(function (jt) {
                if (deptId > 0 && jt.dept_id && jt.dept_id !== deptId) return;
                var op = document.createElement('option');
                op.value = String(jt.id);
                op.textContent = jt.name + (jt.dept_name ? ' — ' + jt.dept_name : '');
                op.setAttribute('data-dept-id', String(jt.dept_id || 0));
                if (String(jt.id) === currentVal) op.selected = true;
                jtSel.appendChild(op);
            });
        }

        deptSel.addEventListener('change', function () {
            var deptId = parseInt(deptSel.value || '0', 10);
            // إذا كان المسمى الحالي لا ينتمي لهذا القسم → امسحه
            var selectedJtOpt = jtSel.options[jtSel.selectedIndex];
            if (deptId > 0 && selectedJtOpt) {
                var jtDept = parseInt(selectedJtOpt.getAttribute('data-dept-id') || '0', 10);
                if (jtDept > 0 && jtDept !== deptId) {
                    jtSel.value = '';
                }
            }
            rebuildJobTitleOptions(deptId);
        });

        jtSel.addEventListener('change', function () {
            var opt = jtSel.options[jtSel.selectedIndex];
            if (!opt) return;
            var jtDept = parseInt(opt.getAttribute('data-dept-id') || '0', 10);
            if (jtDept > 0 && parseInt(deptSel.value || '0', 10) !== jtDept) {
                deptSel.value = String(jtDept);
                rebuildJobTitleOptions(jtDept);
            }
        });

        rebuildJobTitleOptions(parseInt(deptSel.value || '0', 10));
        syncOraLovButtons();
    }

    var maritalSingle = document.getElementById('hr-emp-marital-single');
    var maritalMarried = document.getElementById('hr-emp-marital-married');
    var maritalHidden = document.getElementById('hr-emp-is-married-val');

    function setMaritalStatus(married) {
        if (!maritalSingle || !maritalMarried || !maritalHidden) {
            return;
        }
        maritalSingle.checked = !married;
        maritalMarried.checked = married;
        maritalHidden.value = married ? '1' : '0';
        var singleLabel = maritalSingle.closest('.hr-emp-marital-toggle');
        var marriedLabel = maritalMarried.closest('.hr-emp-marital-toggle');
        if (singleLabel) {
            singleLabel.classList.toggle('is-active', !married);
        }
        if (marriedLabel) {
            marriedLabel.classList.toggle('is-active', married);
        }
    }

    if (maritalSingle && maritalMarried && maritalHidden) {
        setMaritalStatus(maritalHidden.value === '1');
        maritalSingle.addEventListener('change', function () {
            if (maritalSingle.checked) {
                setMaritalStatus(false);
            } else if (!maritalMarried.checked) {
                maritalSingle.checked = true;
            }
        });
        maritalMarried.addEventListener('change', function () {
            if (maritalMarried.checked) {
                setMaritalStatus(true);
            } else if (!maritalSingle.checked) {
                maritalMarried.checked = true;
            }
        });
    }

    function initOraStackTabs() {
        var stack = document.getElementById('hr-emp-personal-stack');
        if (!stack) {
            return;
        }
        function activateStackTab(tabId) {
            var tabs = Array.prototype.slice.call(stack.querySelectorAll('.hr-emp-ora-stack-tab'));
            var frontIdx = -1;
            tabs.forEach(function (btn, idx) {
                if (btn.getAttribute('data-stack-tab') === tabId) {
                    frontIdx = idx;
                }
            });
            tabs.forEach(function (btn, idx) {
                var on = btn.getAttribute('data-stack-tab') === tabId;
                btn.classList.toggle('is-front', on);
                btn.classList.toggle('is-behind', !on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
                btn.tabIndex = on ? 0 : -1;
                if (on) {
                    btn.style.order = '1';
                    btn.style.zIndex = '5';
                    btn.style.marginInlineStart = '';
                } else if (frontIdx >= 0) {
                    var offset = Math.abs(idx - frontIdx);
                    btn.style.order = String(1 + offset);
                    btn.style.zIndex = String(Math.max(1, 5 - offset));
                    btn.style.marginInlineStart = offset > 0 ? '-' + (0.42 * offset) + 'rem' : '';
                }
            });
            stack.querySelectorAll('.hr-emp-ora-stack-pane').forEach(function (pane) {
                var on = pane.getAttribute('data-stack-pane') === tabId;
                pane.classList.toggle('is-active', on);
                pane.hidden = !on;
            });
        }
        stack.querySelectorAll('.hr-emp-ora-stack-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tabId = btn.getAttribute('data-stack-tab');
                if (!tabId) {
                    return;
                }
                activateStackTab(tabId);
            });
        });
    }

    if (!isBrowse) {
        initOraStackTabs();
    }
    bindSequentialKeyboardNav();
    focusFirstNameField();

    if (!isBrowse && form && window.HrOraUnsaved) {
        unsaved = window.HrOraUnsaved.bind({
            page: page,
            route: 'hr_employees',
            isActive: function () {
                return true;
            },
            isReadOnly: isResignationPosted,
            getSnapshot: captureEmpForm,
            onSave: submitSave,
        });
        unsaved.syncSnapshot();
        page.querySelectorAll('.hr-emp-toolbar a[href]').forEach(function (link) {
            unsaved.bindLeaveLink(link);
        });
    }
})();
