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
    var btnDelete = document.getElementById('hr-adv-btn-delete');
    var btnClose = document.getElementById('hr-adv-editor-close');
    var btnCancel = document.getElementById('hr-adv-editor-cancel');
    var delForm = document.getElementById('hr-adv-delete-form');
    var delIdInp = document.getElementById('hr-adv-delete-id');
    var postForm = document.getElementById('hr-adv-post-form');
    var postIdInp = document.getElementById('hr-adv-post-id');
    var unpostForm = document.getElementById('hr-adv-unpost-form');
    var unpostIdInp = document.getElementById('hr-adv-unpost-id');
    var topPickerId = document.getElementById('hr-adv-picker-id');
    var topPickerOpen = document.getElementById('hr-adv-picker-id_open');
    var topPickerDisplay = document.getElementById('hr-adv-picker-id_display');
    var filterEmployee = document.getElementById('hr-adv-filter-employee');
    var filterEmployeeSmart = document.getElementById('hr-adv-filter-employee-smart');
    var filterEmployeeList = document.getElementById('hr-adv-filter-employee-list');
    var filterEmployeeToggle = document.getElementById('hr-adv-filter-employee-toggle');
    var filterEmployeeIdInp = document.getElementById('hr-adv-filter-employee-id');
    var deleteFilterEmployeeIdInp = document.getElementById('hr-adv-delete-filter-employee-id');
    var postFilterEmployeeIdInp = document.getElementById('hr-adv-post-filter-employee-id');
    var unpostFilterEmployeeIdInp = document.getElementById('hr-adv-unpost-filter-employee-id');
    var pickerCode = document.getElementById('hr-adv-picker-code');
    var pickerCount = document.getElementById('hr-adv-picker-count');
    var listUrl = page.getAttribute('data-list-url') || '';
    var filterEmpId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10);
    var filterYear = parseInt(page.getAttribute('data-filter-year') || '0', 10);
    var filterMonth = parseInt(page.getAttribute('data-filter-month') || '0', 10);
    var monthFilterActive = page.getAttribute('data-month-filter-active') === '1';
    var monthFilterEmployeeInp = document.getElementById('hr-adv-month-filter-employee');
    var monthFilterForm = document.getElementById('hr-adv-month-filter-form');
    var tbody = document.getElementById('hr-adv-grid-body');
    var selectedRow = null;
    var exitUrl = page.getAttribute('data-exit-url') || '';
    var editorSnapshot = null;
    var formSubmitting = false;
    var editorEmployeeSmart = document.getElementById('hr-adv-editor-employee-smart');
    var editorEmployeeList = document.getElementById('hr-adv-editor-employee-list');
    var editorEmployeeToggle = document.getElementById('hr-adv-editor-employee-toggle');
    var filterSmartApi = null;
    var editorSmartApi = null;
    var topPickerApi = null;

    function syncExitGuard() {
        if (window.ScreenExitGuard && typeof window.ScreenExitGuard.syncFor === 'function') {
            window.ScreenExitGuard.syncFor(page);
        }
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

    function normText(value) {
        return String(value || '').trim().toLowerCase();
    }

    function normalizeDigits(value) {
        return String(value || '')
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function buildSmartItemsFromSelect(selectEl) {
        if (!selectEl) return [];
        var items = [];
        Array.prototype.forEach.call(selectEl.options, function (opt) {
            var value = String(opt.value || '').trim();
            if (!value) return;
            var label = String(opt.textContent || '').trim();
            var code = String(opt.getAttribute('data-emp-code') || '').trim();
            if (!code && label.indexOf('—') >= 0) {
                code = String(label.split('—')[0] || '').trim();
            }
            items.push({
                value: value,
                label: label,
                code: code,
                search: normText((code ? code + ' ' : '') + label),
            });
        });
        return items;
    }

    function setupSmartEmployeePicker(opts) {
        if (!opts || !opts.select || !opts.input || !opts.list) return null;
        var selectEl = opts.select;
        var inputEl = opts.input;
        var listEl = opts.list;
        var toggleEl = opts.toggle || null;
        var closeTimer = null;
        var isOpen = false;
        var activeIndex = -1;
        var items = buildSmartItemsFromSelect(selectEl);

        function refreshItems() {
            items = buildSmartItemsFromSelect(selectEl);
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
            var buttons = listEl.querySelectorAll('.hr-adv-emp-pick-item[data-value]');
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
                selectEl.setAttribute('data-prev-value', prevValue);
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
            var needle = browseAll ? '' : normText(queryText);
            var matches = items.filter(function (item) {
                return needle === '' || item.search.indexOf(needle) >= 0;
            });

            listEl.innerHTML = '';
            activeIndex = -1;

            if (!matches.length) {
                var empty = document.createElement('div');
                empty.className = 'hr-adv-emp-pick-empty';
                empty.textContent = needle === '' ? 'لا يوجد موظفون' : 'لا يوجد موظف مطابق';
                listEl.appendChild(empty);
            } else {
                matches.slice(0, 120).forEach(function (item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'hr-adv-emp-pick-item';
                    btn.setAttribute('data-value', item.value);
                    btn.innerHTML = item.code
                        ? '<span class="hr-adv-emp-pick-name">' + escapeHtml(item.label) + '</span><code dir="ltr">' + escapeHtml(item.code) + '</code>'
                        : '<span class="hr-adv-emp-pick-name">' + escapeHtml(item.label) + '</span>';
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
            openList(false);
        });

        inputEl.addEventListener('click', function () {
            clearTimeout(closeTimer);
            openList(false);
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
            var buttons = listEl.querySelectorAll('.hr-adv-emp-pick-item[data-value]');
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
            if (
                e.target === inputEl
                || e.target === toggleEl
                || (toggleEl && toggleEl.contains(e.target))
                || listEl.contains(e.target)
            ) {
                return;
            }
            closeList();
        });

        syncInputFromSelect();

        return {
            syncFromSelect: syncInputFromSelect,
            open: openList,
            close: closeList,
            refresh: refreshItems,
        };
    }

    function parseEmployeeList() {
        var el = document.getElementById('hr-adv-picker-json');
        if (!el) return [];
        try {
            return JSON.parse(el.textContent || '[]') || [];
        } catch (err) {
            return [];
        }
    }

    function buildCodeIndex() {
        var map = {};
        parseEmployeeList().forEach(function (emp) {
            var id = parseInt(emp.id, 10);
            var code = normalizeDigits(String(emp.code || '').trim());
            if (id > 0 && code !== '') {
                map[code] = id;
            }
        });
        return map;
    }

    function codeToEmployeeId(rawCode) {
        var code = normalizeDigits(String(rawCode || '').trim());
        if (code === '' || code === '—') {
            return 0;
        }
        var map = buildCodeIndex();
        return parseInt(map[code] || '0', 10) || 0;
    }

    function syncTopPickerByEmployeeId(empId, silent) {
        if (!topPickerApi) return;
        if (!empId && monthFilterActive) {
            topPickerApi.setById(0, !!silent);
            return;
        }
        if (!empId) return;
        topPickerApi.setById(parseInt(empId, 10) || 0, !!silent);
    }

    function initTopEmployeePickerModal() {
        if (!topPickerId || !topPickerOpen || !topPickerDisplay) return;
        if (!window.EmployeePickerModal) {
            setTimeout(initTopEmployeePickerModal, 40);
            return;
        }
        var initialFilterEmpId = parseInt(page.getAttribute('data-filter-employee-id') || '0', 10) || 0;
        topPickerApi = EmployeePickerModal.bind({
            hidden: 'hr-adv-picker-id',
            open: 'hr-adv-picker-id_open',
            display: 'hr-adv-picker-id_display',
            jsonId: 'hr-adv-picker-json',
            employees: parseEmployeeList(),
            allowNew: false,
            allowAll: true,
            allLabel: '— جميع الموظفين —',
            placeholder: 'اختر موظفاً — أو جميع الموظفين',
            initialId:
                initialFilterEmpId > 0
                    ? initialFilterEmpId
                    : monthFilterActive
                      ? 0
                      : '',
            onSelect: function (emp) {
                var empId = emp && emp.id !== undefined && emp.id !== null ? parseInt(emp.id, 10) : NaN;
                var nextId = empId === 0 ? '0' : emp && emp.id ? String(emp.id) : '';
                if (filterEmployee) {
                    filterEmployee.setAttribute('data-prev-value', String(filterEmployee.value || ''));
                    filterEmployee.value = nextId;
                    try {
                        filterEmployee.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e) {
                        filterEmployee.dispatchEvent(new Event('change'));
                    }
                }
            },
        });
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
        if (postFilterEmployeeIdInp) {
            postFilterEmployeeIdInp.value = String(id);
        }
        if (unpostFilterEmployeeIdInp) {
            unpostFilterEmployeeIdInp.value = String(id);
        }
        if (monthFilterEmployeeInp) {
            monthFilterEmployeeInp.value = id > 0 ? String(id) : '';
        }
    }

    function syncPickerDisplay() {
        if (!filterEmployee) return;
        var op = filterEmployee.options[filterEmployee.selectedIndex];
        var selectedVal = filterEmployee.value;
        if (selectedVal === '0') {
            syncTopPickerByEmployeeId(0, true);
        } else {
            syncTopPickerByEmployeeId(op && op.value ? op.value : 0, true);
        }
        if (pickerCode) {
            if (!op || !op.value || op.value === '0') {
                pickerCode.value = '—';
            } else {
                var code = op.getAttribute('data-emp-code') || '';
                pickerCode.value = code !== '' ? code : '—';
            }
        }
        if (pickerCount) {
            if (selectedVal === '0' && monthFilterActive) {
                pickerCount.textContent = String(
                    parseInt(page.getAttribute('data-filter-month-advance-count') || '0', 10) || 0
                );
            } else if (!op || !op.value) {
                pickerCount.textContent = '—';
            } else {
                pickerCount.textContent = String(op.getAttribute('data-advance-count') || '0');
            }
        }
        if (btnAdd) {
            btnAdd.disabled = !(op && op.value && op.value !== '0') && !monthFilterActive;
        }
    }

    function buildListUrl(employeeId, year, month) {
        var eid = parseInt(String(employeeId || '0'), 10) || 0;
        var y = parseInt(String(typeof year === 'undefined' ? filterYear : year), 10) || 0;
        var m = parseInt(String(typeof month === 'undefined' ? filterMonth : month), 10) || 0;
        var parts = [];
        if (eid > 0) {
            parts.push('employee_id=' + encodeURIComponent(String(eid)));
        }
        if (y >= 2000 && m >= 1 && m <= 12) {
            parts.push('year=' + encodeURIComponent(String(y)));
            parts.push('month=' + encodeURIComponent(String(m)));
        }
        if (!parts.length) {
            return listUrl;
        }
        return listUrl + '&' + parts.join('&');
    }

    function employeeFilterUrl(employeeId) {
        return buildListUrl(employeeId, filterYear, filterMonth);
    }

    function navigateToEmployeeFilter(employeeId, previousValue) {
        var url = employeeFilterUrl(employeeId);
        confirmUnsavedChanges(function () {
            syncExitGuard();
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
        if (editorEmployeeSmart) {
            editorEmployeeSmart.disabled = !!locked;
        }
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
        if (btn === filterEmployeeToggle && filterSmartApi) {
            if (filterEmployeeSmart) {
                filterEmployeeSmart.focus();
            }
            filterSmartApi.open(true);
            return;
        }
        if (btn === editorEmployeeToggle && editorSmartApi) {
            if (editorEmployeeSmart) {
                editorEmployeeSmart.focus();
            }
            editorSmartApi.open(true);
            return;
        }
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

    filterSmartApi = setupSmartEmployeePicker({
        select: filterEmployee,
        input: filterEmployeeSmart,
        list: filterEmployeeList,
        toggle: filterEmployeeToggle,
    });

    editorSmartApi = setupSmartEmployeePicker({
        select: editorEmployee,
        input: editorEmployeeSmart,
        list: editorEmployeeList,
        toggle: editorEmployeeToggle,
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

    function isRowPosted(tr) {
        return !!(tr && tr.getAttribute('data-posted') === '1');
    }

    function isRowLocked(tr) {
        return isRowLinked(tr) || isRowPosted(tr);
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
            posted: tr.getAttribute('data-posted') === '1',
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
        syncRowActionButtons();
    }

    function syncRowActionButtons() {
        if (btnEdit) {
            btnEdit.disabled = !selectedRow || isRowLocked(selectedRow);
        }
        if (btnDelete) {
            var locked = !!(selectedRow && isRowLocked(selectedRow));
            btnDelete.disabled = !selectedRow || locked;
        }
    }

    function clearSelection() {
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow = null;
        }
        syncRowActionButtons();
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
        if (editorSmartApi) {
            editorSmartApi.syncFromSelect();
        }

        var locked = !isAdd && selectedRow && isRowLocked(selectedRow);
        if (editorForm) {
            editorForm.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.type === 'hidden') return;
                el.disabled = locked;
            });
        }
        if (!locked && isEmployeeFieldLocked()) {
            setEditorEmployeeLocked(true);
        }
        syncOraLovButtons();

        editor.hidden = false;
        page.classList.add('is-editing');
        syncTypeUi();
        syncEditorSnapshot();
        if (editorEmployee && !locked) {
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
            if (editorSmartApi) {
                editorSmartApi.close();
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
        if (isRowPosted(selectedRow)) {
            appDialogAlert('السلفة مرحّلة — فك الترحيل أولاً لتعديلها.', 'warning');
            return;
        }
        if (isRowLinked(selectedRow)) {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg') || 'لا يمكن تعديل السلفة بعد اقتطاعها من الراتب.',
                'warning'
            );
            return;
        }
        confirmUnsavedChanges(function () {
            openEditor('edit');
        });
    }

    function submitPostAdvance() {
        if (!selectedRow || !postForm || !postIdInp) {
            appDialogAlert('حدد سلفة من الجدول ثم اضغط «ترحيل».', 'warning');
            return;
        }
        if (isRowPosted(selectedRow)) {
            appDialogAlert('السلفة مرحّلة مسبقاً.', 'warning');
            return;
        }
        if (selectedRow.getAttribute('data-status') === 'cancelled') {
            appDialogAlert('لا يمكن ترحيل سلفة ملغاة.', 'warning');
            return;
        }
        var rd = getRowData(selectedRow);
        var msg = 'ترحيل السلفة رقم ' + (rd && rd.code ? rd.code : selectedRow.getAttribute('data-id')) + '؟\n'
            + 'سيتم إثباتها محاسبياً وتفعيلها للاقتطاع من الراتب.';
        appDialogConfirm(msg).then(function (ok) {
            if (!ok) return;
            syncFilterHiddenFields();
            postIdInp.value = String(rd.id || 0);
            formSubmitting = true;
            syncExitGuard();
            postForm.submit();
        });
    }

    function submitUnpostAdvance() {
        if (!selectedRow || !unpostForm || !unpostIdInp) {
            appDialogAlert('حدد سلفة مرحّلة من الجدول ثم اضغط «فك الترحيل».', 'warning');
            return;
        }
        if (!isRowPosted(selectedRow)) {
            appDialogAlert('السلفة غير مرحّلة.', 'warning');
            return;
        }
        if (isRowLinked(selectedRow)) {
            appDialogAlert(
                selectedRow.getAttribute('data-unpost-msg')
                    || selectedRow.getAttribute('data-linked-msg')
                    || 'لا يمكن فك ترحيل السلفة بعد اقتطاعها من الراتب.',
                'warning'
            );
            return;
        }
        var rd = getRowData(selectedRow);
        var msg = 'فك ترحيل السلفة رقم ' + (rd && rd.code ? rd.code : selectedRow.getAttribute('data-id')) + '؟\n'
            + 'سيتم إلغاء أثرها المحاسبي وإيقاف اقتطاعها من الراتب.';
        appDialogConfirm(msg).then(function (ok) {
            if (!ok) return;
            syncFilterHiddenFields();
            unpostIdInp.value = String(rd.id || 0);
            formSubmitting = true;
            syncExitGuard();
            unpostForm.submit();
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
            syncExitGuard();
            window.location.href = url;
        });
    }

    function printCurrentScreen() {
        if (getFilterEmployeeId() < 1) {
            appDialogAlert('اختر موظفاً أولاً ثم اطبع.', 'warning');
            return;
        }
        if (editor && !editor.hidden) {
            appDialogAlert('أغلق نموذج إضافة/تعديل السلفة أولاً قبل الطباعة.', 'warning');
            return;
        }
        window.print();
    }

    function submitDelete() {
        if (!delForm || !delIdInp) return;
        if (!selectedRow) {
            appDialogAlert('حدد سلفة من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) return;
        if (selectedRow.getAttribute('data-status') === 'cancelled') {
            appDialogAlert('السلفة ملغاة.', 'warning');
            return;
        }
        if (isRowPosted(selectedRow)) {
            appDialogAlert('لا يمكن حذف السلفة بعد ترحيلها — فك الترحيل أولاً.', 'warning');
            return;
        }
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

    if (editorForm) {
        editorForm.addEventListener('submit', function () {
            // Native form submit (click on "حفظ السلفة") must bypass beforeunload warnings.
            formSubmitting = true;
            syncEditorSnapshot();
            syncExitGuard();
        });
    }

    if (filterEmployee) {
        syncPickerDisplay();
        syncFilterHiddenFields();
        filterEmployee.addEventListener('focus', function () {
            filterEmployee.setAttribute('data-prev-value', filterEmployee.value);
        });
        filterEmployee.addEventListener('change', function () {
            var prev = filterEmployee.getAttribute('data-prev-value');
            if (prev === null || prev === '') {
                prev = String(page.getAttribute('data-filter-employee-id') || '');
                if (monthFilterActive && (prev === '0' || prev === '')) {
                    prev = '0';
                }
            }
            var newVal = filterEmployee.value;
            if (newVal === prev) {
                return;
            }
            syncPickerDisplay();
            syncFilterHiddenFields();
            navigateToEmployeeFilter(newVal === '0' ? 0 : newVal, prev);
        });
    }
    initTopEmployeePickerModal();

    if (pickerCode) {
        var codeOnFocus = '';
        pickerCode.addEventListener('focus', function () {
            codeOnFocus = String(pickerCode.value || '').trim();
            pickerCode.select();
        });
        pickerCode.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var matchedId = codeToEmployeeId(pickerCode.value);
                if (matchedId < 1) {
                    appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
                    pickerCode.value = codeOnFocus;
                    return;
                }
                if (filterEmployee) {
                    filterEmployee.value = String(matchedId);
                    try {
                        filterEmployee.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e2) {
                        filterEmployee.dispatchEvent(new Event('change'));
                    }
                }
            }
        });
        pickerCode.addEventListener('blur', function () {
            var typed = normalizeDigits(String(pickerCode.value || '').trim());
            var prev = normalizeDigits(String(codeOnFocus || '').trim());
            if (typed === '' || typed === prev) {
                return;
            }
            var matchedId = codeToEmployeeId(typed);
            if (matchedId < 1) {
                appDialogAlert('لا يوجد موظف بهذا الرقم.', 'warning');
                pickerCode.value = codeOnFocus;
                return;
            }
            if (filterEmployee) {
                filterEmployee.value = String(matchedId);
                try {
                    filterEmployee.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e3) {
                    filterEmployee.dispatchEvent(new Event('change'));
                }
            }
        });
    }

    if (editorEmployee) {
        editorEmployee.addEventListener('change', function () {
            if (editorSmartApi) {
                editorSmartApi.syncFromSelect();
            }
            syncEditorSnapshot();
        });
    }

    typeRadios.forEach(function (r) {
        r.addEventListener('change', syncTypeUi);
    });

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnDelete) btnDelete.addEventListener('click', submitDelete);
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
        } else if (action === 'print') {
            e.preventDefault();
            e.stopImmediatePropagation();
            printCurrentScreen();
        } else if (action === 'post') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitPostAdvance();
        } else if (action === 'unpost') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitUnpostAdvance();
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
        if (window.__managerAllowUnload) return;
        if (!hasUnsavedChanges()) return;
        e.preventDefault();
        e.returnValue = '';
    });

    document.addEventListener('manager:before-minimize', function (ev) {
        if (!hasUnsavedChanges()) return;
        if (ev.detail) ev.detail.dirty = true;
    });

    if (monthFilterForm) {
        page.querySelectorAll('.hr-adv-month-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var m = parseInt(chip.getAttribute('data-month') || '0', 10);
                var monthInp = document.getElementById('hr-adv-filter-month');
                if (monthInp && m >= 1 && m <= 12) {
                    monthInp.value = String(m);
                }
                syncFilterHiddenFields();
                monthFilterForm.submit();
            });
        });
    }

    syncTypeUi();
    syncOraLovButtons();
    syncRowActionButtons();
})();
