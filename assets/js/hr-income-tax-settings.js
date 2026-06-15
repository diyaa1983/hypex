(function () {
    'use strict';

    var page = document.querySelector('.hr-it-grid-page');
    if (!page) return;

    var prefix = 'hr-it';
    var editor = document.getElementById(prefix + '-editor');
    var editorTitle = document.getElementById(prefix + '-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || prefix + '-editor-form');
    var configForm = document.getElementById(page.getAttribute('data-config-form-id') || prefix + '-config-form');
    var formAction = document.getElementById(prefix + '-form-action');
    var pendingJson = document.getElementById(prefix + '-pending-json');
    var editorId = document.getElementById(prefix + '-editor-id');
    var editorStatus = document.getElementById(prefix + '-editor-status');
    var editorSeqDisplay = document.getElementById(prefix + '-editor-seq-display');
    var editorSeqHint = document.getElementById(prefix + '-editor-seq-hint');
    var editorFrom = document.getElementById(prefix + '-editor-from');
    var editorTo = document.getElementById(prefix + '-editor-to');
    var editorPct = document.getElementById(prefix + '-editor-pct');
    var btnAdd = document.getElementById(prefix + '-btn-add');
    var btnEdit = document.getElementById(prefix + '-btn-edit');
    var btnDelete = document.getElementById(prefix + '-btn-delete');
    var btnReplace = document.getElementById(prefix + '-btn-replace');
    var btnInlineAdd = document.getElementById(prefix + '-btn-inline-add');
    var btnClose = document.getElementById(prefix + '-editor-close');
    var btnSaveBracket = document.getElementById(prefix + '-btn-save-bracket');
    var btnCancel = document.getElementById(prefix + '-btn-cancel');
    var delForm = document.getElementById(prefix + '-delete-form');
    var delIdInp = document.getElementById(prefix + '-delete-id');
    var delStatusInp = document.getElementById(prefix + '-delete-status');
    var delReplaceInp = document.getElementById(prefix + '-delete-replace');
    var editorHint = document.getElementById(prefix + '-editor-hint');
    var activeStatusLabel = document.getElementById(prefix + '-active-status-label');
    var bracketPanels = page.querySelectorAll('.hr-it-bracket-panel');
    var rowClass = prefix + '-row';
    var selectedRow = null;
    var pendingItems = [];
    var pendingKeySeq = 0;
    var activeStatus = page.getAttribute('data-default-status') || 'single';

    var tbodies = {
        single: document.getElementById(prefix + '-grid-body-single'),
        married: document.getElementById(prefix + '-grid-body-married'),
    };
    var pendingBodies = {
        single: document.getElementById(prefix + '-pending-body-single'),
        married: document.getElementById(prefix + '-pending-body-married'),
    };

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

    function parseNum(val) {
        var n = parseFloat(String(val || '').replace(/,/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function formatAmt(n) {
        if (Math.abs(n - Math.round(n)) < 0.0005) {
            return String(Math.round(n));
        }
        return n.toFixed(3);
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function statusLabel(status) {
        return status === 'married' ? 'متزوج' : 'أعزب';
    }

    function normalizeStatus(status) {
        return status === 'married' ? 'married' : 'single';
    }

    function isEditMode() {
        return page.classList.contains('is-edit-mode');
    }

    function tbodyFor(status) {
        return tbodies[normalizeStatus(status)] || null;
    }

    function pendingBodyFor(status) {
        return pendingBodies[normalizeStatus(status)] || null;
    }

    function visibleRows(status) {
        status = normalizeStatus(status || activeStatus);
        var body = tbodyFor(status);
        if (!body) return [];
        return Array.prototype.slice.call(
            body.querySelectorAll('tr.' + rowClass + ':not(.' + rowClass + '--empty)')
        );
    }

    function nextSeq(status) {
        status = normalizeStatus(status || activeStatus);
        return visibleRows(status).length
            + pendingItems.filter(function (p) { return p.marital_status === status; }).length
            + 1;
    }

    function syncPendingJson() {
        if (!pendingJson) return;
        pendingJson.value = JSON.stringify(pendingItems.map(function (p) {
            return {
                salary_from: p.salary_from,
                salary_to: p.salary_to,
                tax_percent: p.tax_percent,
            };
        }));
    }

    function refreshEmptyRows(status) {
        ['single', 'married'].forEach(function (st) {
            if (status && st !== status) return;
            var body = tbodyFor(st);
            if (!body) return;
            var rows = body.querySelectorAll('tr.' + rowClass + ':not(.' + rowClass + '--empty)');
            var empty = body.querySelector('tr.' + rowClass + '--empty');
            var pendingCount = pendingItems.filter(function (p) { return p.marital_status === st; }).length;
            if (empty) {
                empty.hidden = rows.length > 0 || pendingCount > 0;
            }
        });
    }

    function updateActivePanelUi() {
        bracketPanels.forEach(function (panel) {
            var on = panel.getAttribute('data-status') === activeStatus;
            panel.classList.toggle('is-active', on);
        });
        if (activeStatusLabel) {
            activeStatusLabel.textContent = 'الجدول النشط: ' + statusLabel(activeStatus);
        }
        if (editorStatus) {
            editorStatus.value = activeStatus;
        }
    }

    function setActiveStatus(status, keepSelection) {
        status = normalizeStatus(status);
        if (isEditMode() && editor && !editor.hidden) {
            return;
        }
        if (
            !keepSelection
            || (selectedRow && normalizeStatus(selectedRow.getAttribute('data-marital')) !== status)
        ) {
            clearSelection();
        }
        activeStatus = status;
        updateActivePanelUi();
        refreshEmptyRows();
    }

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            seq: tr.getAttribute('data-seq') || '',
            salary_from: tr.getAttribute('data-salary-from') || '0',
            salary_to: tr.getAttribute('data-salary-to') || '',
            tax_percent: tr.getAttribute('data-tax-percent') || '0',
            marital: tr.getAttribute('data-marital') || activeStatus,
        };
    }

    function bracketKey(from, to, pct) {
        return parseNum(from) + '|' + (to === '' ? 'inf' : parseNum(to)) + '|' + parseNum(pct);
    }

    function bracketExists(from, to, pct, excludeId, status) {
        var key = bracketKey(from, to, pct);
        excludeId = parseInt(excludeId || '0', 10) || 0;
        status = normalizeStatus(status || activeStatus);
        var all = visibleRows(status);
        for (var i = 0; i < all.length; i++) {
            var d = getRowData(all[i]);
            if (!d) continue;
            if (excludeId > 0 && d.id === excludeId) continue;
            if (bracketKey(d.salary_from, d.salary_to, d.tax_percent) === key) {
                return true;
            }
        }
        for (var j = 0; j < pendingItems.length; j++) {
            var p = pendingItems[j];
            if (p.marital_status !== status) continue;
            if (bracketKey(p.salary_from, p.salary_to, p.tax_percent) === key) {
                return true;
            }
        }
        return false;
    }

    function readEditorBracket() {
        var from = parseNum(editorFrom ? editorFrom.value : 0);
        var toRaw = editorTo ? String(editorTo.value || '').trim() : '';
        var to = toRaw === '' ? null : parseNum(toRaw);
        var pct = parseNum(editorPct ? editorPct.value : 0);
        if (from <= 0 && (to === null || to <= 0) && pct <= 0) {
            return null;
        }
        if (to !== null && to > 0 && to < from) {
            throw new Error('«إلى» يجب أن يكون أكبر من «من».');
        }
        return { salary_from: from, salary_to: to, tax_percent: pct };
    }

    function renderPendingRows() {
        ['single', 'married'].forEach(function (st) {
            var pendingBody = pendingBodyFor(st);
            if (pendingBody) pendingBody.innerHTML = '';
        });

        pendingItems.forEach(function (item) {
            var st = normalizeStatus(item.marital_status);
            var pendingBody = pendingBodyFor(st);
            if (!pendingBody) return;
            var tr = document.createElement('tr');
            tr.className = rowClass + ' ' + rowClass + '--pending is-pending';
            tr.setAttribute('data-pending', '1');
            tr.setAttribute('data-pending-key', item.key);
            tr.setAttribute('data-marital', st);
            tr.setAttribute('data-salary-from', String(item.salary_from));
            tr.setAttribute('data-salary-to', item.salary_to === null ? '' : String(item.salary_to));
            tr.setAttribute('data-tax-percent', String(item.tax_percent));
            var toDisp = item.salary_to === null || item.salary_to === '' ? '∞' : formatAmt(item.salary_to);
            tr.innerHTML =
                '<td dir="ltr">' + escapeHtml(String(item.seq)) + '</td>' +
                '<td class="hr-it-amt-cell" dir="ltr">' + escapeHtml(formatAmt(item.salary_from)) + '</td>' +
                '<td class="hr-it-amt-cell" dir="ltr">' + escapeHtml(toDisp) + '</td>' +
                '<td class="hr-it-pct-cell" dir="ltr">' + escapeHtml(formatAmt(item.tax_percent)) + '%' +
                ' <button type="button" class="btn btn-ghost btn-sm hr-it-pending-remove" title="إزالة">✕</button></td>';
            pendingBody.appendChild(tr);
        });
        syncPendingJson();
        refreshEmptyRows();
    }

    function addPendingFromInput() {
        if (isEditMode()) return;
        var bracket;
        try {
            bracket = readEditorBracket();
        } catch (err) {
            appDialogAlert(err.message || 'تحقق من بيانات الشريحة.', 'warning');
            return;
        }
        if (!bracket) {
            appDialogAlert('أدخل من — إلى — النسبة ثم اضغط «إضافة للجدول».', 'warning');
            if (editorFrom) editorFrom.focus();
            return;
        }
        var toVal = bracket.salary_to;
        if (bracketExists(bracket.salary_from, toVal === null ? '' : toVal, bracket.tax_percent, 0, activeStatus)) {
            appDialogAlert('هذه الشريحة موجودة بالفعل في جدول «' + statusLabel(activeStatus) + '».', 'warning');
            return;
        }
        pendingKeySeq += 1;
        pendingItems.push({
            key: 'p' + pendingKeySeq,
            marital_status: activeStatus,
            salary_from: bracket.salary_from,
            salary_to: bracket.salary_to,
            tax_percent: bracket.tax_percent,
            seq: nextSeq(activeStatus),
        });
        if (editorFrom) editorFrom.value = '';
        if (editorTo) editorTo.value = '';
        if (editorPct) editorPct.value = '';
        if (editorFrom) editorFrom.focus();
        if (editorSeqDisplay) editorSeqDisplay.textContent = String(nextSeq(activeStatus));
        renderPendingRows();
    }

    function clearPending() {
        pendingItems = [];
        pendingKeySeq = 0;
        renderPendingRows();
        if (editorSeqDisplay && !isEditMode()) {
            editorSeqDisplay.textContent = String(nextSeq(activeStatus));
        }
    }

    function updateSelectionButtons() {
        if (btnEdit) btnEdit.disabled = !selectedRow;
        if (btnDelete) btnDelete.disabled = !selectedRow;
        if (btnReplace) btnReplace.disabled = !selectedRow;
    }

    function clearSelectionInAll() {
        Object.keys(tbodies).forEach(function (st) {
            var body = tbodies[st];
            if (!body) return;
            body.querySelectorAll('tr.is-selected').forEach(function (tr) {
                tr.classList.remove('is-selected');
            });
        });
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains(rowClass) || tr.classList.contains(rowClass + '--empty')) return;
        if (tr.classList.contains('is-pending')) return;
        var st = tr.getAttribute('data-marital') || activeStatus;
        setActiveStatus(st, true);
        clearSelectionInAll();
        selectedRow = tr;
        selectedRow.classList.add('is-selected');
        updateSelectionButtons();
    }

    function clearSelection() {
        clearSelectionInAll();
        selectedRow = null;
        updateSelectionButtons();
    }

    function setEditorMode(mode) {
        page.classList.toggle('is-edit-mode', mode !== 'add');
        if (btnInlineAdd) btnInlineAdd.hidden = mode !== 'add';
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        var rowData = isAdd ? null : getRowData(selectedRow);
        var editStatus = isAdd ? activeStatus : normalizeStatus((rowData || {}).marital || activeStatus);
        setEditorMode(mode);
        if (!isAdd) {
            activeStatus = editStatus;
        }
        if (editorTitle) {
            editorTitle.textContent = isAdd
                ? 'إضافة شرائح — ' + statusLabel(activeStatus)
                : 'تعديل شريحة — ' + statusLabel(editStatus);
        }
        if (editorStatus) editorStatus.value = editStatus;
        if (editorId) {
            editorId.value = isAdd ? '0' : String((rowData || {}).id || 0);
        }
        if (formAction) {
            formAction.value = isAdd ? 'save_batch' : 'save_one';
        }
        if (editorSeqDisplay) {
            editorSeqDisplay.textContent = isAdd
                ? String(nextSeq(activeStatus))
                : ((rowData || {}).seq || '—');
        }
        if (editorSeqHint) {
            editorSeqHint.textContent = isAdd
                ? 'يُعيَّن الرقم تلقائياً عند الحفظ'
                : 'رقم العرض حسب ترتيب الشرائح — لا يمكن نقل الشريحة بين الجدولين';
        }
        if (editorFrom) {
            editorFrom.value = isAdd ? '' : ((rowData || {}).salary_from || '');
        }
        if (editorTo) {
            editorTo.value = isAdd ? '' : ((rowData || {}).salary_to || '');
        }
        if (editorPct) {
            editorPct.value = isAdd ? '' : ((rowData || {}).tax_percent || '');
        }
        if (editorHint) {
            editorHint.textContent = isAdd
                ? 'أدخل بيانات الشريحة ثم «حفظ الشريحة»، أو أضف عدة شرائح للجدول ثم «حفظ» من شريط الأدوات.'
                : 'عدّل «من — إلى — النسبة» ثم اضغط «حفظ الشريحة».';
        }
        editor.hidden = false;
        page.classList.add('is-editing');
        updateActivePanelUi();
        if (typeof editor.scrollIntoView === 'function') {
            editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (editorFrom) editorFrom.focus();
    }

    function closeEditor() {
        if (editor) editor.hidden = true;
        page.classList.remove('is-editing', 'is-edit-mode');
    }

    function startAdd() {
        clearSelection();
        clearPending();
        openEditor('add');
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد شريحة من أحد الجدولين ثم اضغط «تعديل».', 'warning');
            return;
        }
        clearPending();
        openEditor('edit');
    }

    function submitEditorSave() {
        if (!editorForm) return;
        var id = editorId ? parseInt(editorId.value || '0', 10) : 0;

        if (id > 0) {
            if (formAction) formAction.value = 'save_one';
            var bracket;
            try {
                bracket = readEditorBracket();
            } catch (err) {
                appDialogAlert(err.message || 'تحقق من بيانات الشريحة.', 'warning');
                return;
            }
            if (!bracket) {
                appDialogAlert('أدخل بيانات الشريحة (من — إلى — النسبة).', 'warning');
                if (editorFrom) editorFrom.focus();
                return;
            }
            var editStatus = editorStatus ? normalizeStatus(editorStatus.value) : activeStatus;
            var toVal = bracket.salary_to;
            if (bracketExists(bracket.salary_from, toVal === null ? '' : toVal, bracket.tax_percent, id, editStatus)) {
                appDialogAlert('هذه الشريحة موجودة بالفعل في جدول «' + statusLabel(editStatus) + '».', 'warning');
                return;
            }
            if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
                return;
            }
            editorForm.submit();
            return;
        }

        try {
            var bracketAdd = readEditorBracket();
            if (bracketAdd) {
                var toValAdd = bracketAdd.salary_to;
                if (!bracketExists(bracketAdd.salary_from, toValAdd === null ? '' : toValAdd, bracketAdd.tax_percent, 0, activeStatus)) {
                    pendingKeySeq += 1;
                    pendingItems.push({
                        key: 'p' + pendingKeySeq,
                        marital_status: activeStatus,
                        salary_from: bracketAdd.salary_from,
                        salary_to: bracketAdd.salary_to,
                        tax_percent: bracketAdd.tax_percent,
                        seq: nextSeq(activeStatus),
                    });
                    renderPendingRows();
                }
            }
        } catch (err2) {
            appDialogAlert(err2.message || 'تحقق من بيانات الشريحة.', 'warning');
            return;
        }

        var countPending = pendingItems.filter(function (p) { return p.marital_status === activeStatus; }).length;

        if (countPending < 1) {
            var singleBracket;
            try {
                singleBracket = readEditorBracket();
            } catch (err3) {
                appDialogAlert(err3.message || 'تحقق من بيانات الشريحة.', 'warning');
                return;
            }
            if (!singleBracket) {
                appDialogAlert('أدخل من — إلى — النسبة ثم احفظ.', 'warning');
                if (editorFrom) editorFrom.focus();
                return;
            }
            var toSingle = singleBracket.salary_to;
            if (bracketExists(singleBracket.salary_from, toSingle === null ? '' : toSingle, singleBracket.tax_percent, 0, activeStatus)) {
                appDialogAlert('هذه الشريحة موجودة بالفعل في جدول «' + statusLabel(activeStatus) + '».', 'warning');
                return;
            }
            if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
                return;
            }
            if (formAction) formAction.value = 'save_one';
            if (editorId) editorId.value = '0';
            editorForm.submit();
            return;
        }

        if (formAction) formAction.value = 'save_batch';
        syncPendingJson();
        editorForm.submit();
    }

    function submitConfigSave() {
        if (!configForm) return;
        configForm.submit();
    }

    function submitDeleteRequest(id, marital, seq, replaceMode) {
        if (!delForm || !delIdInp) return;
        id = parseInt(id || '0', 10) || 0;
        if (id < 1) {
            appDialogAlert('تعذر تحديد الشريحة. حدّث الصفحة (Ctrl+F5) ثم أعد المحاولة.', 'warning');
            return;
        }
        marital = normalizeStatus(marital || activeStatus);
        var label = 'الشريحة رقم ' + (seq || id) + ' (' + statusLabel(marital) + ')';
        var msg = replaceMode
            ? 'حذف «' + label + '» ثم فتح نموذج إنشاء شريحة جديدة؟'
            : 'حذف «' + label + '» نهائياً؟';
        appDialogConfirm(msg, replaceMode ? {} : { danger: true }).then(function (ok) {
            if (!ok) return;
            delIdInp.value = String(id);
            if (delStatusInp) delStatusInp.value = marital;
            if (delReplaceInp) delReplaceInp.value = replaceMode ? '1' : '0';
            if (typeof delForm.requestSubmit === 'function') {
                delForm.requestSubmit();
            } else {
                delForm.submit();
            }
        });
    }

    function submitDelete(replaceMode) {
        if (!selectedRow) {
            appDialogAlert('حدد شريحة من أحد الجدولين ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data) return;
        submitDeleteRequest(data.id, data.marital, data.seq, !!replaceMode);
    }

    function bindPendingRemove(pendingBody) {
        if (!pendingBody) return;
        pendingBody.addEventListener('click', function (e) {
            var btn = e.target.closest('.hr-it-pending-remove');
            if (!btn) return;
            e.stopPropagation();
            var tr = btn.closest('tr.is-pending');
            if (!tr) return;
            var key = tr.getAttribute('data-pending-key') || '';
            var st = normalizeStatus(tr.getAttribute('data-marital') || activeStatus);
            pendingItems = pendingItems.filter(function (p) { return p.key !== key; });
            var seq = visibleRows(st).length;
            pendingItems.forEach(function (p) {
                if (p.marital_status !== st) return;
                seq += 1;
                p.seq = seq;
            });
            renderPendingRows();
            if (editorSeqDisplay) editorSeqDisplay.textContent = String(nextSeq(activeStatus));
        });
    }

    function bindGridBody(body) {
        if (!body) return;
        body.addEventListener('click', function (e) {
            var delBtn = e.target.closest('.hr-it-row-delete');
            if (delBtn) {
                e.preventDefault();
                e.stopPropagation();
                submitDeleteRequest(
                    delBtn.getAttribute('data-id'),
                    delBtn.getAttribute('data-marital'),
                    delBtn.getAttribute('data-seq'),
                    false
                );
                return;
            }
            var tr = e.target.closest('tr.' + rowClass);
            if (tr && !tr.classList.contains(rowClass + '--empty')) {
                selectRow(tr);
            }
        });
        body.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.' + rowClass);
            if (tr && !tr.classList.contains('is-pending') && !tr.classList.contains(rowClass + '--empty')) {
                selectRow(tr);
                startEdit();
            }
        });
        body.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.' + rowClass);
            if (tr && !tr.classList.contains('is-pending')) {
                e.preventDefault();
                selectRow(tr);
                startEdit();
            }
        });
    }

    bracketPanels.forEach(function (panel) {
        panel.addEventListener('click', function (e) {
            if (e.target.closest('tr.' + rowClass)) return;
            if (e.target.closest('.hr-it-pending-remove')) return;
            setActiveStatus(panel.getAttribute('data-status') || 'single', false);
        });
    });

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnDelete) btnDelete.addEventListener('click', function () { submitDelete(false); });
    if (btnReplace) btnReplace.addEventListener('click', function () { submitDelete(true); });
    if (btnInlineAdd) btnInlineAdd.addEventListener('click', addPendingFromInput);
    if (btnClose) btnClose.addEventListener('click', closeEditor);
    if (btnCancel) btnCancel.addEventListener('click', closeEditor);
    if (btnSaveBracket) btnSaveBracket.addEventListener('click', submitEditorSave);

    bindPendingRemove(pendingBodies.single);
    bindPendingRemove(pendingBodies.married);
    bindGridBody(tbodies.single);
    bindGridBody(tbodies.married);

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        if ((bar ? bar.getAttribute('data-active-route') : '') !== 'hr_income_tax_settings') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                submitConfigSave();
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete(false);
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            startAdd();
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else {
                submitConfigSave();
            }
        }
    });

    setActiveStatus(activeStatus, false);
    refreshEmptyRows();

    var openAdd = page.getAttribute('data-open-add') || '';
    if (openAdd === 'single' || openAdd === 'married') {
        setActiveStatus(openAdd, false);
        startAdd();
    }
})();
