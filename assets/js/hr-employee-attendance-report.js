(function () {
    'use strict';

    function parseEmployeeList() {
        var el = document.getElementById('hr-att-rpt-picker-json');
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
        if (!document.getElementById('hr-att-rpt-employee-id') || !window.EmployeePickerModal) {
            setTimeout(function () {
                initEmployeePicker(fullList, deptSel);
            }, 40);
            return null;
        }

        var deptId = deptSel ? parseInt(deptSel.value || '0', 10) || 0 : 0;
        var hidden = document.getElementById('hr-att-rpt-employee-id');
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
            hidden: 'hr-att-rpt-employee-id',
            open: 'hr-att-rpt-employee-id_open',
            display: 'hr-att-rpt-employee-id_display',
            employees: filtered,
            allowAll: true,
            allLabel: 'جميع الموظفين',
            placeholder: 'جميع الموظفين — أو اضغط للبحث',
            initialId: initialId,
            displayField: 'name_ar',
            listNameField: 'name_ar',
        });
    }

    function finalizeDateInput(input) {
        if (!input) {
            return;
        }
        input.dispatchEvent(new Event('blur', { bubbles: true }));
    }

    function bindDateEnterNav(form) {
        var fromInput = document.getElementById('hr-att-rpt-date-from');
        var toInput = document.getElementById('hr-att-rpt-date-to');
        var submitBtn = document.getElementById('hr-att-rpt-submit');
        if (!fromInput || !toInput || !submitBtn) {
            return;
        }

        fromInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }
            e.preventDefault();
            finalizeDateInput(fromInput);
            toInput.focus();
            if (typeof toInput.select === 'function') {
                toInput.select();
            }
        });

        toInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') {
                return;
            }
            e.preventDefault();
            finalizeDateInput(toInput);
            submitBtn.focus();
        });
    }

    function bindReportLoader(page) {
        var form = page.querySelector('.hr-att-rpt-filters');
        var results = document.getElementById('hr-att-rpt-results');
        var overlay = document.getElementById('hr-att-rpt-loading');
        var cancelBtn = document.getElementById('hr-att-rpt-loading-cancel');
        var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
        var progressPctEl = document.getElementById('hr-att-rpt-loading-pct');
        if (!form || !results || !overlay || !cancelBtn) {
            return;
        }

        var activeController = null;
        var progressTimer = null;
        var progressFinishTimer = null;
        var progressStartedAt = 0;
        var progressValue = 1;

        function updateProgressDisplay(value) {
            var pct = Math.max(1, Math.min(100, Math.round(value)));
            progressValue = pct;
            if (progressPctEl) {
                progressPctEl.textContent = String(pct);
            }
        }

        function stopProgressTimers() {
            if (progressTimer) {
                clearInterval(progressTimer);
                progressTimer = null;
            }
            if (progressFinishTimer) {
                clearInterval(progressFinishTimer);
                progressFinishTimer = null;
            }
        }

        function resetProgress() {
            stopProgressTimers();
            progressStartedAt = 0;
            updateProgressDisplay(1);
        }

        function startProgress() {
            resetProgress();
            progressStartedAt = Date.now();
            progressTimer = setInterval(function () {
                var elapsed = Date.now() - progressStartedAt;
                var estimated = Math.min(95, Math.floor(1 + 94 * (1 - Math.exp(-elapsed / 14000))));
                if (estimated > progressValue) {
                    updateProgressDisplay(estimated);
                }
            }, 120);
        }

        function finishProgress(done) {
            stopProgressTimers();
            progressFinishTimer = setInterval(function () {
                if (progressValue >= 100) {
                    stopProgressTimers();
                    updateProgressDisplay(100);
                    if (typeof done === 'function') {
                        done();
                    }
                    return;
                }
                var step = Math.max(2, Math.ceil((100 - progressValue) / 4));
                updateProgressDisplay(Math.min(100, progressValue + step));
            }, 45);
        }

        function setLoading(active) {
            if (active) {
                overlay.hidden = false;
                overlay.removeAttribute('hidden');
                document.body.classList.add('hr-att-rpt-is-loading');
            } else {
                overlay.hidden = true;
                overlay.setAttribute('hidden', '');
                document.body.classList.remove('hr-att-rpt-is-loading');
            }
            if (submitBtn) {
                submitBtn.disabled = !!active;
            }
        }

        function hideLoading() {
            stopProgressTimers();
            resetProgress();
            setLoading(false);
            activeController = null;
        }

        function showLoading() {
            setLoading(true);
            startProgress();
        }

        cancelBtn.addEventListener('click', function () {
            if (activeController) {
                activeController.abort();
            }
            hideLoading();
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (activeController) {
                activeController.abort();
            }

            var params = new URLSearchParams(new FormData(form));
            var action = form.getAttribute('action') || window.location.pathname;
            var fullUrl = action + (action.indexOf('?') >= 0 ? '&' : '?') + params.toString();

            activeController = new AbortController();
            var signal = activeController.signal;
            showLoading();

            fetch(fullUrl, {
                method: 'GET',
                credentials: 'same-origin',
                signal: signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('report_load_failed');
                    }
                    return res.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var next = doc.getElementById('hr-att-rpt-results');
                    if (!next) {
                        throw new Error('report_parse_failed');
                    }
                    results.innerHTML = next.innerHTML;
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState(null, '', fullUrl);
                    }
                    finishProgress(function () {
                        hideLoading();
                        if (typeof results.scrollIntoView === 'function') {
                            results.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    hideLoading();
                    window.alert('تعذر تحميل التقرير. حاول مرة أخرى.');
                });
        });
    }

    function bindUi() {
        var page = document.querySelector('.hr-att-rpt-page');
        if (!page) {
            return;
        }

        var deptSel = document.getElementById('hr-att-rpt-dept');
        var fullList = parseEmployeeList();
        var pickerApi = initEmployeePicker(fullList, deptSel);

        if (deptSel && pickerApi && typeof pickerApi.setEmployees === 'function') {
            deptSel.addEventListener('change', function () {
                pickerApi.setEmployees(filterByDepartment(fullList, deptSel.value));
            });
        }

        bindReportLoader(page);
        bindDateEnterNav(page.querySelector('.hr-att-rpt-filters'));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindUi);
    } else {
        bindUi();
    }
})();
