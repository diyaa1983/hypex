(function () {
    'use strict';

    var page = document.querySelector('.hr-dept-grid-page');
    if (!page) return;

    var prefix = 'hr-dept';
    var editor = document.getElementById(prefix + '-editor');
    var editorTitle = document.getElementById(prefix + '-editor-title');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || prefix + '-editor-form');
    var formAction = document.getElementById(prefix + '-form-action');
    var pendingJson = document.getElementById(prefix + '-pending-json');
    var editorId = document.getElementById(prefix + '-editor-id');
    var editorCodeDisplay = document.getElementById(prefix + '-editor-code-display');
    var editorCodeHint = document.getElementById(prefix + '-editor-code-hint');
    var baseNextCode = parseInt(page.getAttribute('data-next-code') || '1', 10) || 1;
    var editorName = document.getElementById(prefix + '-editor-name');
    var editorNotes = document.getElementById(prefix + '-editor-notes');
    var editorActive = document.getElementById(prefix + '-editor-active');
    var btnAdd = document.getElementById(prefix + '-btn-add');
    var btnEdit = document.getElementById(prefix + '-btn-edit');
    var btnDelete = document.getElementById(prefix + '-btn-delete');
    var btnInlineAdd = document.getElementById(prefix + '-btn-inline-add');
    var btnClose = document.getElementById(prefix + '-editor-close');
    var delForm = document.getElementById(prefix + '-delete-form');
    var delIdInp = document.getElementById(prefix + '-delete-id');
    var tbody = document.getElementById(prefix + '-grid-body');
    var pendingBody = document.getElementById(prefix + '-pending-body');
    var emptyRow = document.getElementById(prefix + '-row-empty');
    var editorHint = document.getElementById(prefix + '-editor-hint');
    var rowClass = prefix + '-row';
    var selectedRow = null;
    var pendingItems = [];
    var pendingKeySeq = 0;
    var unsaved = null;

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

    function normText(text) {
        return (text || '').replace(/\s+/g, ' ').trim();
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            code: tr.getAttribute('data-code') || '',
            name: tr.getAttribute('data-name') || '',
            description: tr.getAttribute('data-description') || '',
            active: tr.getAttribute('data-active') === '1',
        };
    }

    function isEditMode() {
        return page.classList.contains('is-edit-mode');
    }

    function syncPendingJson() {
        if (!pendingJson) return;
        pendingJson.value = JSON.stringify(pendingItems.map(function (p) {
            return { name: p.name, description: p.description };
        }));
    }

    function refreshEmptyRow() {
        if (!emptyRow) return;
        var hasSaved = tbody && tbody.querySelector('tr.' + rowClass + ':not(.' + rowClass + '--empty)');
        emptyRow.hidden = !!(hasSaved || pendingItems.length > 0);
    }

    function nameExistsInGrid(name, excludeId) {
        var n = normText(name).toLowerCase();
        if (!n) return false;
        excludeId = parseInt(excludeId || '0', 10) || 0;
        var rows = document.querySelectorAll(
            '#' + prefix + '-grid-body tr.' + rowClass + '[data-name], #' + prefix + '-pending-body tr.' + rowClass + '[data-name]'
        );
        for (var i = 0; i < rows.length; i++) {
            var rowId = parseInt(rows[i].getAttribute('data-id') || '0', 10);
            if (excludeId > 0 && rowId === excludeId) continue;
            if (normText(rows[i].getAttribute('data-name') || '').toLowerCase() === n) {
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
            tr.className = rowClass + ' ' + rowClass + '--pending is-pending';
            tr.setAttribute('data-pending', '1');
            tr.setAttribute('data-pending-key', item.key);
            tr.setAttribute('data-code', item.code);
            tr.setAttribute('data-name', item.name);
            tr.setAttribute('data-description', item.description);
            tr.innerHTML =
                '<td dir="ltr">' + escapeHtml(item.code) + '</td>' +
                '<td>' + escapeHtml(item.name) + ' <span class="muted">(جديد)</span></td>' +
                '<td>' + escapeHtml(item.description || '—') + '</td>' +
                '<td class="hr-dept-cell-active">' +
                '<button type="button" class="btn btn-ghost btn-sm hr-dept-pending-remove" title="إزالة">✕</button>' +
                '</td>';
            pendingBody.appendChild(tr);
        });
        syncPendingJson();
        refreshEmptyRow();
    }

    function addPendingFromInput() {
        if (isEditMode()) return;
        var name = normText(editorName ? editorName.value : '');
        if (!name) {
            appDialogAlert('اكتب اسم القسم ثم اضغط «إضافة».', 'warning');
            if (editorName) editorName.focus();
            return;
        }
        if (nameExistsInGrid(name)) {
            appDialogAlert('هذا القسم موجود بالفعل في القائمة.', 'warning');
            if (editorName) editorName.focus();
            return;
        }
        pendingKeySeq += 1;
        pendingItems.push({
            key: 'p' + pendingKeySeq,
            name: name,
            description: normText(editorNotes ? editorNotes.value : ''),
            code: nextPendingCode(),
        });
        if (editorName) {
            editorName.value = '';
            editorName.focus();
        }
        if (editorNotes) {
            editorNotes.value = '';
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
        if (btnEdit) btnEdit.disabled = !selectedRow;
        if (!btnDelete) return;
        if (!selectedRow) {
            btnDelete.disabled = true;
            btnDelete.title = 'حدد قسماً من الجدول';
            return;
        }
        var linked = selectedRow.getAttribute('data-linked') === '1';
        btnDelete.disabled = linked;
        btnDelete.title = linked
            ? (selectedRow.getAttribute('data-linked-msg') || 'لا يمكن الحذف: القسم مرتبط')
            : 'حذف القسم المحدد';
    }

    function selectRow(tr) {
        if (!tr || !tr.classList.contains(rowClass) || tr.classList.contains(rowClass + '--empty')) return;
        if (tr.classList.contains('is-pending')) return;
        if (selectedRow) selectedRow.classList.remove('is-selected');
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
        page.classList.toggle('is-edit-mode', mode !== 'add');
        if (btnInlineAdd) btnInlineAdd.hidden = mode !== 'add';
    }

    function openEditor(mode) {
        if (!editor || !editorForm) return;
        var isAdd = mode === 'add';
        setEditorMode(mode);
        if (editorTitle) {
            editorTitle.textContent = isAdd ? 'إضافة أقسام' : 'تعديل قسم';
        }
        if (editorId) {
            editorId.value = isAdd ? '0' : String(getRowData(selectedRow).id || 0);
        }
        if (formAction) {
            formAction.value = isAdd ? 'save_batch' : 'save_one';
        }
        if (editorCodeDisplay) {
            editorCodeDisplay.textContent = isAdd
                ? nextPendingCode()
                : ((getRowData(selectedRow) || {}).code || '—');
        }
        if (editorCodeHint) {
            editorCodeHint.textContent = isAdd
                ? 'يُعيَّن الرقم تلقائياً عند الحفظ من شريط الأدوات'
                : 'الرقم ثابت ولا يمكن تعديله';
        }
        if (editorName) {
            editorName.value = isAdd ? '' : (getRowData(selectedRow).name || '');
        }
        if (editorNotes) {
            editorNotes.value = isAdd ? '' : (getRowData(selectedRow).description || '');
        }
        if (editorActive) {
            editorActive.checked = isAdd ? true : ((getRowData(selectedRow) || {}).active !== false);
        }
        if (editorHint) {
            editorHint.textContent = isAdd
                ? 'أضف كل الأقسام المطلوبة للجدول، ثم اضغط «حفظ» في شريط الأدوات.'
                : 'عدّل الاسم أو الملاحظات أو التنشيط ثم اضغط «حفظ» في شريط الأدوات (لا تستخدم زر إضافة).';
        }
        editor.hidden = false;
        page.classList.add('is-editing');
        if (editorName) editorName.focus();
        if (unsaved) unsaved.syncSnapshot();
    }

    function closeEditor(force) {
        var doClose = function () {
            if (editor) editor.hidden = true;
            page.classList.remove('is-editing', 'is-edit-mode');
        };
        if (unsaved) {
            unsaved.tryClose(doClose, force);
        } else {
            doClose();
        }
    }

    function guardEditorAction(fn) {
        if (unsaved && unsaved.hasUnsavedChanges()) {
            unsaved.confirmUnsavedChanges(fn);
            return;
        }
        fn();
    }

    function startAdd() {
        guardEditorAction(function () {
            clearSelection();
            clearPending();
            openEditor('add');
        });
    }

    function startEdit() {
        if (!selectedRow) {
            appDialogAlert('حدد قسماً من الجدول ثم اضغط «تعديل».', 'warning');
            return;
        }
        guardEditorAction(function () {
            clearPending();
            openEditor('edit');
        });
    }

    function submitEditorSave() {
        if (!editorForm) return;
        var id = editorId ? parseInt(editorId.value || '0', 10) : 0;

        if (id > 0) {
            if (formAction) formAction.value = 'save_one';
            var editName = normText(editorName ? editorName.value : '');
            if (!editName) {
                appDialogAlert('اسم القسم مطلوب.', 'warning');
                if (editorName) editorName.focus();
                return;
            }
            if (nameExistsInGrid(editName, id)) {
                appDialogAlert('اسم القسم مستخدم لسجل آخر. اختر اسماً مختلفاً.', 'warning');
                if (editorName) editorName.focus();
                return;
            }
            if (unsaved) unsaved.markSubmitting(true);
            editorForm.submit();
            return;
        }

        var name = normText(editorName ? editorName.value : '');
        if (name && !nameExistsInGrid(name)) {
            addPendingFromInput();
        }

        if (pendingItems.length < 1) {
            appDialogAlert('أضف قسماً واحداً على الأقل ثم اضغط «حفظ» في شريط الأدوات.', 'warning');
            if (editorName) editorName.focus();
            return;
        }

        if (formAction) formAction.value = 'save_batch';
        syncPendingJson();
        if (unsaved) unsaved.markSubmitting(true);
        editorForm.submit();
    }

    function submitDelete() {
        if (!delForm || !delIdInp) return;
        if (!selectedRow) {
            appDialogAlert('حدد قسماً من الجدول ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) return;
        if (selectedRow.getAttribute('data-linked') === '1') {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg')
                    || 'لا يمكن حذف هذا القسم لأنه مرتبط بموظفين أو مسميات وظيفية.',
                'warning'
            );
            return;
        }
        var label = data.name || 'هذا القسم';
        appDialogConfirm('حذف «' + label + '» نهائياً من النظام؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                delForm.submit();
            }
        });
    }

    if (btnAdd) btnAdd.addEventListener('click', startAdd);
    if (btnEdit) btnEdit.addEventListener('click', startEdit);
    if (btnDelete) btnDelete.addEventListener('click', submitDelete);
    if (btnInlineAdd) btnInlineAdd.addEventListener('click', addPendingFromInput);
    if (btnClose) btnClose.addEventListener('click', closeEditor);

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
            var btn = e.target.closest('.hr-dept-pending-remove');
            if (!btn) return;
            e.stopPropagation();
            var tr = btn.closest('tr.is-pending');
            if (!tr) return;
            var key = tr.getAttribute('data-pending-key') || '';
            pendingItems = pendingItems.filter(function (p) { return p.key !== key; });
            pendingItems.forEach(function (p, idx) { p.code = String(baseNextCode + idx); });
            renderPendingRows();
            if (editorCodeDisplay) editorCodeDisplay.textContent = nextPendingCode();
        });
    }

    if (tbody) {
        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-dept-active-cb')) return;
            var toggleForm = cb.closest('form.hr-dept-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });
        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.hr-dept-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.' + rowClass);
            if (tr) selectRow(tr);
        });
        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.' + rowClass);
            if (tr && !tr.classList.contains('is-pending')) {
                selectRow(tr);
                startEdit();
            }
        });
        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.' + rowClass);
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
        if ((bar ? bar.getAttribute('data-active-route') : '') !== 'hr_departments') return;

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
        if ((e.ctrlKey || e.metaKey) && e.key === 's' && editor && !editor.hidden) {
            e.preventDefault();
            submitEditorSave();
        }
    });

    if (window.HrOraUnsaved) {
        unsaved = window.HrOraUnsaved.bind({
            page: page,
            route: 'hr_departments',
            isActive: function () {
                return pendingItems.length > 0 || !!(editor && !editor.hidden);
            },
            getSnapshot: function () {
                return {
                    id: editorId ? editorId.value : '0',
                    name: normText(editorName ? editorName.value : ''),
                    notes: normText(editorNotes ? editorNotes.value : ''),
                    active: editorActive ? !!editorActive.checked : false,
                    pending: pendingItems.map(function (p) {
                        return p.name + '\t' + p.description;
                    }),
                };
            },
            onSave: submitEditorSave,
        });
    }

    refreshEmptyRow();
})();
