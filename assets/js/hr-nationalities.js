(function () {
    'use strict';

    var page = document.querySelector('.hr-nat-grid-page');
    if (!page) return;

    var editor = document.getElementById('hr-nat-editor');
    var editorTitle = document.getElementById('hr-nat-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-nat-editor-form');
    var formAction = document.getElementById('hr-nat-form-action');
    var pendingJson = document.getElementById('hr-nat-pending-json');
    var editorId = document.getElementById('hr-nat-editor-id');
    var editorCodeWrap = document.getElementById('hr-nat-editor-code-wrap');
    var editorCodeDisplay = document.getElementById('hr-nat-editor-code-display');
    var editorCodeHint = document.getElementById('hr-nat-editor-code-hint');
    var baseNextCode = parseInt(page.getAttribute('data-next-code') || '1', 10) || 1;
    var editorName = document.getElementById('hr-nat-editor-name');
    var editorActive = document.getElementById('hr-nat-editor-active');
    var btnAdd = document.getElementById('hr-nat-btn-add');
    var btnEdit = document.getElementById('hr-nat-btn-edit');
    var btnDelete = document.getElementById('hr-nat-btn-delete');
    var btnInlineAdd = document.getElementById('hr-nat-btn-inline-add');
    var btnClose = document.getElementById('hr-nat-editor-close');
    var delForm = document.getElementById('hr-nat-delete-form');
    var delIdInp = document.getElementById('hr-nat-delete-id');
    var tbody = document.getElementById('hr-nat-grid-body');
    var pendingBody = document.getElementById('hr-nat-pending-body');
    var emptyRow = document.getElementById('hr-nat-row-empty');
    var editorHint = document.getElementById('hr-nat-editor-hint');
    var selectedRow = null;
    var pendingItems = [];
    var pendingKeySeq = 0;

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

    function normName(name) {
        return (name || '').replace(/\s+/g, ' ').trim();
    }

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            code: tr.getAttribute('data-code') || '',
            name: tr.getAttribute('data-name') || '',
            active: tr.getAttribute('data-active') === '1',
            pending: tr.getAttribute('data-pending') === '1',
        };
    }

    function isEditMode() {
        return page.classList.contains('is-edit-mode');
    }

    function syncPendingJson() {
        if (!pendingJson) return;
        pendingJson.value = JSON.stringify(pendingItems.map(function (p) {
            return { name: p.name };
        }));
    }

    function refreshEmptyRow() {
        if (!emptyRow) return;
        var hasSaved = tbody && tbody.querySelector('tr.hr-nat-row:not(.hr-nat-row--empty)');
        var hasPending = pendingItems.length > 0;
        emptyRow.hidden = !!(hasSaved || hasPending);
    }

    /** @param {number} excludeId السجل الحالي عند التعديل — يُستثنى من المقارنة */
    function nameExistsInGrid(name, excludeId) {
        var n = normName(name).toLowerCase();
        if (!n) return false;
        excludeId = parseInt(excludeId || '0', 10) || 0;
        var i;
        var rows = document.querySelectorAll('#hr-nat-grid-body tr.hr-nat-row[data-name], #hr-nat-pending-body tr.hr-nat-row[data-name]');
        for (i = 0; i < rows.length; i++) {
            var rowId = parseInt(rows[i].getAttribute('data-id') || '0', 10);
            if (excludeId > 0 && rowId === excludeId) {
                continue;
            }
            var existing = normName(rows[i].getAttribute('data-name') || '').toLowerCase();
            if (existing === n) {
                return true;
            }
        }
        return false;
    }

    function nextPendingCode() {
        return String(baseNextCode + pendingItems.length);
    }

    function renderPendingRows() {
        if (!pendingBody) return;
        pendingBody.innerHTML = '';
        pendingItems.forEach(function (item) {
            var tr = document.createElement('tr');
            tr.className = 'hr-nat-row hr-nat-row--pending is-pending';
            tr.setAttribute('data-pending', '1');
            tr.setAttribute('data-pending-key', item.key);
            tr.setAttribute('data-code', item.code);
            tr.setAttribute('data-name', item.name);
            tr.setAttribute('tabindex', '0');
            tr.innerHTML =
                '<td dir="ltr">' + escapeHtml(item.code) + '</td>' +
                '<td>' + escapeHtml(item.name) + ' <span class="muted">(جديد)</span></td>' +
                '<td class="hr-nat-cell-active">' +
                '<button type="button" class="btn btn-ghost btn-sm hr-nat-pending-remove" title="إزالة">✕</button>' +
                '</td>';
            pendingBody.appendChild(tr);
        });
        syncPendingJson();
        refreshEmptyRow();
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function addPendingFromInput() {
        if (isEditMode()) return;
        var name = normName(editorName ? editorName.value : '');
        if (!name) {
            appDialogAlert('اكتب اسم الجنسية ثم اضغط «إضافة».', 'warning');
            if (editorName) editorName.focus();
            return;
        }
        if (nameExistsInGrid(name)) {
            appDialogAlert('هذه الجنسية موجودة بالفعل في القائمة.', 'warning');
            if (editorName) editorName.focus();
            return;
        }
        pendingKeySeq += 1;
        pendingItems.push({
            key: 'p' + pendingKeySeq,
            name: name,
            code: nextPendingCode(),
        });
        if (editorName) {
            editorName.value = '';
            editorName.focus();
        }
        if (editorCodeDisplay) {
            editorCodeDisplay.textContent = nextPendingCode();
        }
        renderPendingRows();
    }

    function clearPending() {
        pendingItems = [];
        pendingKeySeq = 0;
        renderPendingRows();
        if (editorCodeDisplay && !isEditMode()) {
            editorCodeDisplay.textContent = String(baseNextCode);
        }
    }

    function updateSelectionButtons() {
        if (btnEdit) {
            btnEdit.disabled = !selectedRow;
        }
        if (!btnDelete) {
            return;
        }
        if (!selectedRow) {
            btnDelete.disabled = true;
            btnDelete.title = 'حدد جنسية من الجدول';
            return;
        }
        var linked = selectedRow.getAttribute('data-linked') === '1';
        btnDelete.disabled = linked;
        btnDelete.title = linked
            ? (selectedRow.getAttribute('data-linked-msg')
                || 'لا يمكن الحذف: الجنسية مرتبطة بموظفين')
            : 'حذف الجنسية المحددة';
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains('hr-nat-row') || tr.classList.contains('hr-nat-row--empty')) {
            return;
        }
        if (tr.classList.contains('is-pending')) {
            return;
        }
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
        }
        selectedRow = tr;
        selectedRow.classList.add('is-selected');
        updateSelectionButtons();
    }

    function clearSelection() {
        if (selectedRow) {
            selectedRow.classList.remove('is-selected');
            selectedRow = null;
        }
        updateSelectionButtons();
    }

    function setEditorMode(mode) {
        var isAdd = mode === 'add';
        page.classList.toggle('is-edit-mode', !isAdd);
        if (btnInlineAdd) {
            btnInlineAdd.hidden = !isAdd;
        }
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        setEditorMode(mode);
        if (editorTitle) {
            editorTitle.textContent = isAdd ? 'إضافة جنسيات' : 'تعديل جنسية';
        }
        if (editorId) {
            editorId.value = isAdd ? '0' : String(getRowData(selectedRow).id || 0);
        }
        if (formAction) {
            formAction.value = isAdd ? 'save_batch' : 'save_one';
        }
        if (editorCodeDisplay) {
            if (isAdd) {
                editorCodeDisplay.textContent = nextPendingCode();
            } else {
                var rowData = getRowData(selectedRow);
                editorCodeDisplay.textContent = (rowData && rowData.code) ? rowData.code : '—';
            }
        }
        if (editorCodeHint) {
            editorCodeHint.textContent = isAdd
                ? 'يُعيَّن الرقم تلقائياً عند الحفظ من شريط الأدوات'
                : 'الرقم ثابت ولا يمكن تعديله';
        }
        if (editorName) {
            editorName.value = isAdd ? '' : (getRowData(selectedRow).name || '');
        }
        if (editorActive) {
            if (isAdd) {
                editorActive.checked = true;
            } else {
                var rd = getRowData(selectedRow);
                editorActive.checked = rd ? rd.active : true;
            }
        }
        if (editorHint) {
            editorHint.textContent = isAdd
                ? 'أضف كل الجنسيات المطلوبة للجدول، ثم اضغط «حفظ» في شريط الأدوات.'
                : 'عدّل الاسم أو التنشيط ثم اضغط «حفظ» في شريط الأدوات (لا تستخدم زر إضافة).';
        }
        editor.hidden = false;
        page.classList.add('is-editing');
        if (editorName) {
            editorName.focus();
        }
    }

    function closeEditor() {
        if (editor) {
            editor.hidden = true;
        }
        page.classList.remove('is-editing');
        page.classList.remove('is-edit-mode');
    }

    function startAdd() {
        clearSelection();
        clearPending();
        openEditor('add');
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد جنسية من الجدول ثم اضغط «تعديل».', 'warning');
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
            var editName = normName(editorName ? editorName.value : '');
            if (!editName) {
                appDialogAlert('اسم الجنسية مطلوب.', 'warning');
                if (editorName) editorName.focus();
                return;
            }
            if (nameExistsInGrid(editName, id)) {
                appDialogAlert('اسم الجنسية مستخدم لسجل آخر. اختر اسماً مختلفاً.', 'warning');
                if (editorName) editorName.focus();
                return;
            }
            editorForm.submit();
            return;
        }

        var name = normName(editorName ? editorName.value : '');
        if (name && !nameExistsInGrid(name)) {
            addPendingFromInput();
        }

        if (pendingItems.length < 1) {
            appDialogAlert('أضف جنسية واحدة على الأقل ثم اضغط «حفظ» في شريط الأدوات.', 'warning');
            if (editorName) editorName.focus();
            return;
        }

        if (formAction) formAction.value = 'save_batch';
        syncPendingJson();
        editorForm.submit();
    }

    function submitDelete() {
        if (!delForm || !delIdInp) return;
        if (!selectedRow) {
            appDialogAlert('حدد جنسية من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) {
            return;
        }
        if (selectedRow.getAttribute('data-linked') === '1') {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg')
                    || 'لا يمكن حذف هذه الجنسية لأنها مرتبطة بموظفين في النظام.',
                'warning'
            );
            return;
        }
        var label = data.name || 'هذه الجنسية';
        appDialogConfirm('حذف «' + label + '» نهائياً من النظام؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                delForm.submit();
            }
        });
    }

    if (btnAdd) {
        btnAdd.addEventListener('click', startAdd);
    }
    if (btnEdit) {
        btnEdit.addEventListener('click', startEdit);
    }
    if (btnDelete) {
        btnDelete.addEventListener('click', submitDelete);
    }
    if (btnInlineAdd) {
        btnInlineAdd.addEventListener('click', addPendingFromInput);
    }
    if (btnClose) {
        btnClose.addEventListener('click', closeEditor);
    }
    if (editorName) {
        editorName.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !isEditMode()) {
                e.preventDefault();
                addPendingFromInput();
            }
        });
    }

    if (pendingBody) {
        pendingBody.addEventListener('click', function (e) {
            var btn = e.target.closest('.hr-nat-pending-remove');
            if (!btn) return;
            e.stopPropagation();
            var tr = btn.closest('tr.hr-nat-row--pending');
            if (!tr) return;
            var key = tr.getAttribute('data-pending-key') || '';
            pendingItems = pendingItems.filter(function (p) {
                return p.key !== key;
            });
            pendingItems.forEach(function (p, idx) {
                p.code = String(baseNextCode + idx);
            });
            renderPendingRows();
            if (editorCodeDisplay) {
                editorCodeDisplay.textContent = nextPendingCode();
            }
        });
    }

    if (tbody) {
        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-nat-active-cb')) return;
            var toggleForm = cb.closest('form.hr-nat-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.hr-nat-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-nat-row');
            if (tr) {
                selectRow(tr);
            }
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-nat-row');
            if (tr && !tr.classList.contains('is-pending')) {
                selectRow(tr);
                startEdit();
            }
        });
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-nat-row');
            if (tr && !tr.classList.contains('is-pending')) {
                e.preventDefault();
                selectRow(tr);
                startEdit();
            }
        });
    }

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_nationalities') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow) {
                startEdit();
            } else {
                startAdd();
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete();
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
            }
        }
    });

    refreshEmptyRow();
})();
