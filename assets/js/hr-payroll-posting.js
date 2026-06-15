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

    var filterDept = qs('#hr-pr-post-filter-dept');
    var filterEmp = qs('#hr-pr-post-filter-emp');
    var periodForm = qs('#hr-pr-post-period-form');
    var filterEmployees = [];
    var deptNames = {};

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
        allOpt.textContent = '— جميع الموظفين —';
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
    }

    if (filterDept) {
        filterDept.addEventListener('change', function () {
            rebuildEmployeeSelect(true);
            syncPayrollFilters();
        });
    }
    if (filterEmp) {
        filterEmp.addEventListener('change', syncPayrollFilters);
    }
    rebuildEmployeeSelect(false);
    syncPayrollFilters();
    syncOraLovButtons();
    if (periodForm) {
        periodForm.addEventListener('submit', syncPayrollFilters);
    }

    function openOraLovSelect(btn) {
        var wrap = btn.closest('.hr-pr-post-ora-lov');
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

    qsa('.hr-pr-post-ora-lov-btn', page).forEach(function (btn) {
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

    function slipPrintEnabled() {
        return !!(selectedRow
            && parseInt(selectedRow.getAttribute('data-salary-id') || '0', 10) > 0);
    }

    function updateSlipButton() {
        var slipBtn = document.querySelector(
            '#master-toolbar [data-master-action="print_slip"]'
        );
        if (!slipBtn) return;
        var ok = slipPrintEnabled();
        slipBtn.disabled = !ok;
        slipBtn.classList.toggle('is-inactive', !ok);
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

        updateSlipButton();
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

    function submitAction(action, confirmMsg) {
        var form = qs('#hr-pr-post-action-form');
        var act = qs('#hr-pr-post-action');
        if (!form || !act) return;

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
            form.submit();
        };

        if (confirmMsg) {
            runConfirm(confirmMsg, go);
        } else {
            go();
        }
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
        if (!selectedRow) return;
        var sid = parseInt(selectedRow.getAttribute('data-salary-id') || '0', 10);
        if (sid < 1) return;
        var base = page.getAttribute('data-slip-base') || '';
        var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(String(sid));
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
            return tr && tr.getAttribute('data-can-select') === '1';
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
            if (!slipPrintEnabled()) {
                showAlert('حدد موظفاً من الجدول ثم اطبع كشف الراتب.', 'warning');
                return;
            }
            printSlip();
        } else if (action === 'select_pending') {
            selectPendingEmployees();
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
        });
    }

    qsa('.hr-pr-post-emp-chk').forEach(function (cb) {
        cb.addEventListener('change', syncCheckAllState);
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
            if (!slipPrintEnabled()) {
                showAlert('حدد موظفاً من الجدول ثم اطبع كشف الراتب.', 'warning');
                return;
            }
            printSlip();
        }
    }, true);
})();
