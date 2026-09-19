(function () {
    'use strict';

    function apiBase() {
        var page = document.querySelector('.report-trial-balance-page');
        var fromPage = page ? page.getAttribute('data-tb-detail-api') : '';
        if (fromPage) {
            return fromPage;
        }
        return 'api/trial_balance_account_detail.php';
    }

    function alertMsg(msg) {
        if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert(msg, { type: 'info' });
        }
    }

    function getPageDates() {
        var page = document.querySelector('.report-trial-balance-page');
        if (!page) {
            return { from: '', to: '' };
        }
        return {
            from: page.getAttribute('data-from-iso') || page.getAttribute('data-from-dmy') || '',
            to: page.getAttribute('data-to-iso') || page.getAttribute('data-to-dmy') || '',
        };
    }

    function detailRowFor(dataRow) {
        var next = dataRow.nextElementSibling;
        if (next && next.classList.contains('tb-account-detail-row')) {
            return next;
        }
        return null;
    }

    function setExpanded(dataRow, expanded) {
        dataRow.classList.toggle('tb-row-expanded', expanded);
        var mark = dataRow.querySelector('.tb-expand-mark');
        if (mark) {
            mark.textContent = expanded ? '▾' : '▸';
        }
        var detail = detailRowFor(dataRow);
        if (detail) {
            detail.hidden = !expanded;
        }
    }

    function insertDetailRow(dataRow, html) {
        if (!html) {
            return null;
        }
        var existing = detailRowFor(dataRow);
        if (existing) {
            existing.outerHTML = html;
            return detailRowFor(dataRow);
        }
        dataRow.insertAdjacentHTML('afterend', html);
        return detailRowFor(dataRow);
    }

    function fetchDetail(dataRow) {
        var accountId = parseInt(dataRow.getAttribute('data-account-id') || '0', 10);
        var dates = getPageDates();
        var table = dataRow.closest('.report-trial-balance-table');
        if (accountId < 1 || !dates.from || !dates.to || !table) {
            return Promise.resolve(null);
        }
        if (dataRow.getAttribute('data-tb-detail-loaded') === '1') {
            return Promise.resolve(detailRowFor(dataRow));
        }
        if (dataRow.getAttribute('data-tb-detail-loading') === '1') {
            return Promise.resolve(null);
        }
        dataRow.setAttribute('data-tb-detail-loading', '1');

        var colspan = parseInt(table.getAttribute('data-detail-colspan') || '9', 10);
        var url =
            apiBase() +
            '?account_id=' +
            encodeURIComponent(String(accountId)) +
            '&date_from=' +
            encodeURIComponent(dates.from) +
            '&date_to=' +
            encodeURIComponent(dates.to) +
            '&colspan=' +
            encodeURIComponent(String(colspan));

        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                dataRow.removeAttribute('data-tb-detail-loading');
                var data = result.data;
                if (!result.ok || !data || !data.ok) {
                    alertMsg('تعذّر تحميل تفاصيل الحساب.');
                    return null;
                }
                dataRow.setAttribute('data-tb-detail-loaded', '1');
                if (!data.has_detail || !data.html) {
                    dataRow.setAttribute('data-tb-detail-empty', '1');
                    return null;
                }
                return insertDetailRow(dataRow, data.html);
            })
            .catch(function () {
                dataRow.removeAttribute('data-tb-detail-loading');
                alertMsg('تعذّر تحميل تفاصيل الحساب.');
                return null;
            });
    }

    function toggleRow(dataRow) {
        if (dataRow.getAttribute('data-tb-detail-empty') === '1') {
            alertMsg('لا توجد حركة تفصيلية لهذا الحساب في الفترة.');
            return;
        }
        var isExpanded = dataRow.classList.contains('tb-row-expanded');
        if (isExpanded) {
            setExpanded(dataRow, false);
            return;
        }
        fetchDetail(dataRow).then(function (detail) {
            if (!detail) {
                if (dataRow.getAttribute('data-tb-detail-empty') === '1') {
                    alertMsg('لا توجد حركة تفصيلية لهذا الحساب في الفترة.');
                }
                return;
            }
            setExpanded(dataRow, true);
        });
    }

    function expandAllRows() {
        var rows = document.querySelectorAll('.report-trial-balance-table tr.tb-data-row[data-account-id]:not([hidden])');
        var chain = Promise.resolve();
        rows.forEach(function (row) {
            chain = chain.then(function () {
                if (row.getAttribute('data-tb-detail-empty') === '1') {
                    return null;
                }
                return fetchDetail(row).then(function (detail) {
                    if (detail) {
                        setExpanded(row, true);
                    }
                });
            });
        });
    }

    function collapseAllRows() {
        document.querySelectorAll('.report-trial-balance-table tr.tb-data-row.tb-row-expanded').forEach(function (row) {
            setExpanded(row, false);
        });
    }

    function bindTable(table) {
        table.addEventListener('click', function (e) {
            var target = e.target;
            if (!target || target.closest('.col-act') || target.closest('a') || target.closest('button')) {
                return;
            }
            var row = target.closest('tr.tb-data-row');
            if (!row || !table.contains(row) || !row.getAttribute('data-account-id')) {
                return;
            }
            e.preventDefault();
            toggleRow(row);
        });
    }

    function bindToolbar() {
        var expandBtn = document.getElementById('tb-expand-all');
        var collapseBtn = document.getElementById('tb-collapse-all');
        if (expandBtn) {
            expandBtn.addEventListener('click', expandAllRows);
        }
        if (collapseBtn) {
            collapseBtn.addEventListener('click', collapseAllRows);
        }
    }

    function init() {
        var page = document.querySelector('.report-trial-balance-page');
        if (!page) {
            return;
        }
        var table = page.querySelector('.report-trial-balance-table');
        if (!table) {
            return;
        }
        bindTable(table);
        bindToolbar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
