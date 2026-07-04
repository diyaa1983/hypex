(function () {
    'use strict';

    function parseEmployeeList() {
        var el = document.getElementById('hr-att-punch-rpt-picker-json');
        if (!el) {
            return [];
        }
        try {
            return JSON.parse(el.textContent || '[]');
        } catch (e) {
            return [];
        }
    }

    function filterByDepartment(list, deptId) {
        var id = parseInt(deptId, 10) || 0;
        if (id < 1) {
            return list.slice();
        }
        return list.filter(function (emp) {
            return (parseInt(emp.department_id, 10) || 0) === id;
        });
    }

    function initEmployeePicker(fullList, deptSel) {
        if (!document.getElementById('hr-att-punch-rpt-employee-id') || !window.EmployeePickerModal) {
            setTimeout(function () {
                initEmployeePicker(fullList, deptSel);
            }, 40);
            return null;
        }

        var deptId = deptSel ? parseInt(deptSel.value || '0', 10) || 0 : 0;
        var hidden = document.getElementById('hr-att-punch-rpt-employee-id');
        var initialId = parseInt(hidden.value || '0', 10);
        if (isNaN(initialId)) {
            initialId = 0;
        }
        var filtered = filterByDepartment(fullList, deptId);
        if (
            initialId > 0 &&
            !filtered.some(function (emp) {
                return parseInt(emp.id, 10) === initialId;
            })
        ) {
            initialId = 0;
            hidden.value = '0';
        }

        return EmployeePickerModal.bind({
            hidden: 'hr-att-punch-rpt-employee-id',
            open: 'hr-att-punch-rpt-employee-id_open',
            display: 'hr-att-punch-rpt-employee-id_display',
            employees: filtered,
            allowAll: true,
            allLabel: 'جميع الموظفين',
            placeholder: 'جميع الموظفين — أو اضغط للبحث',
            initialId: initialId,
            displayField: 'name_ar',
            listNameField: 'name_ar',
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var fullList = parseEmployeeList();
        var deptSel = document.querySelector('.hr-att-punch-rpt-filters select[name="department_id"]');
        var picker = initEmployeePicker(fullList, deptSel);

        if (deptSel && picker) {
            deptSel.addEventListener('change', function () {
                initEmployeePicker(fullList, deptSel);
            });
        }
    });
})();
