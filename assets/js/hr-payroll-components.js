(function () {
    'use strict';

    var page = document.querySelector('.hr-pc-split-page');
    if (!page) return;

    var editor = document.getElementById('hr-pc-editor');
    var editorTitle = document.getElementById('hr-pc-editor-title');
    var editorBadge = document.getElementById('hr-pc-editor-badge');
    var editorForm = document.getElementById(page.getAttribute('data-editor-form-id') || 'hr-pc-editor-form');
    var editorId = document.getElementById('hr-pc-editor-id');
    var editorType = document.getElementById('hr-pc-editor-type');
    var editorCodeDisplay = document.getElementById('hr-pc-editor-code-display');
    var editorCodeHint = document.getElementById('hr-pc-editor-code-hint');
    var editorCodeLabel = document.getElementById('hr-pc-editor-code-label');
    var editorNameLabel = document.getElementById('hr-pc-editor-name-label');
    var editorName = document.getElementById('hr-pc-editor-name');
    var editorAmount = document.getElementById('hr-pc-editor-amount');
    var editorNotes = document.getElementById('hr-pc-editor-notes');
    var editorActive = document.getElementById('hr-pc-editor-active');
    var editorActiveLabel = document.getElementById('hr-pc-editor-active-label');
    var editorSaveBtn = document.getElementById('hr-pc-editor-save-btn');
    var btnClose = document.getElementById('hr-pc-editor-close');
    var btnCancel = document.getElementById('hr-pc-editor-cancel');
    var delForm = document.getElementById('hr-pc-delete-form');
    var delIdInp = document.getElementById('hr-pc-delete-id');

    var nextAllow = page.getAttribute('data-next-code-allow') || '';
    var nextDeduct = page.getAttribute('data-next-code-deduct') || '';

    var activePanelType = 'allowance';
    var selectedRow = null;
    var selectedPanelType = null;

    var LABELS = {
        allowance: {
            badge: 'علاوة',
            addTitle: 'إضافة علاوة',
            editTitle: 'تعديل علاوة',
            codeLabel: 'رقم العلاوة',
            nameLabel: 'اسم العلاوة',
            saveBtn: 'حفظ العلاوة',
            activeLabel: 'علاوة مفعّلة',
            emptySelect: 'حدد علاوة من الجدول ثم اضغط «تعديل».',
            deleteType: 'علاوة',
        },
        deduction: {
            badge: 'اقتطاع',
            addTitle: 'إضافة اقتطاع',
            editTitle: 'تعديل اقتطاع',
            codeLabel: 'رقم الاقتطاع',
            nameLabel: 'اسم الاقتطاع',
            saveBtn: 'حفظ الاقتطاع',
            activeLabel: 'اقتطاع مفعّل',
            emptySelect: 'حدد اقتطاعاً من الجدول ثم اضغط «تعديل».',
            deleteType: 'اقتطاع',
        },
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

    function labelsFor(type) {
        return LABELS[type === 'deduction' ? 'deduction' : 'allowance'];
    }

    function nextCodeFor(type) {
        return type === 'deduction' ? nextDeduct : nextAllow;
    }

    function getRowData(tr) {
        if (!tr) return null;
        return {
            id: parseInt(tr.getAttribute('data-id') || '0', 10),
            code: tr.getAttribute('data-code') || '',
            name: tr.getAttribute('data-name') || '',
            description: tr.getAttribute('data-description') || '',
            amount: tr.getAttribute('data-amount') || '0',
            compType: tr.getAttribute('data-comp-type') || 'allowance',
            active: tr.getAttribute('data-active') === '1',
        };
    }

    function setPageEditingClass(type) {
        page.classList.remove('is-editing-allow', 'is-editing-deduct');
        if (type === 'deduction') {
            page.classList.add('is-editing-deduct');
        } else {
            page.classList.add('is-editing-allow');
        }
    }

    function clearPanelSelection(panelType) {
        var tbody = document.querySelector('.hr-pc-panel-body[data-panel-type="' + panelType + '"]');
        if (!tbody) return;
        tbody.querySelectorAll('.hr-pc-row.is-selected').forEach(function (tr) {
            tr.classList.remove('is-selected');
        });
        var editBtn = document.querySelector('.hr-pc-btn-edit[data-type="' + panelType + '"]');
        if (editBtn) {
            editBtn.disabled = true;
        }
    }

    function clearAllSelections() {
        clearPanelSelection('allowance');
        clearPanelSelection('deduction');
        selectedRow = null;
        selectedPanelType = null;
    }

    function selectRow(tr, panelType) {
        if (!tr || !tr.classList.contains('hr-pc-row') || tr.classList.contains('hr-pc-row--empty')) {
            return;
        }
        clearAllSelections();
        selectedRow = tr;
        selectedPanelType = panelType;
        activePanelType = panelType;
        tr.classList.add('is-selected');
        var editBtn = document.querySelector('.hr-pc-btn-edit[data-type="' + panelType + '"]');
        if (editBtn) {
            editBtn.disabled = false;
        }
    }

    function applyEditorLabels(type) {
        var L = labelsFor(type);
        if (editorCodeLabel) editorCodeLabel.textContent = L.codeLabel;
        if (editorNameLabel) editorNameLabel.textContent = L.nameLabel;
        if (editorActiveLabel) editorActiveLabel.textContent = L.activeLabel;
        if (editorSaveBtn) editorSaveBtn.textContent = L.saveBtn;
        if (editorBadge) editorBadge.textContent = L.badge;
    }

    function openEditor(mode, panelType) {
        if (!editor || !editorForm) return;
        activePanelType = panelType;
        var L = labelsFor(panelType);
        var isAdd = mode === 'add';
        if (editorTitle) {
            editorTitle.textContent = isAdd ? L.addTitle : L.editTitle;
        }
        if (editorType) {
            editorType.value = panelType;
        }
        applyEditorLabels(panelType);
        if (editorId) {
            editorId.value = isAdd ? '0' : String(getRowData(selectedRow).id || 0);
        }
        if (editorCodeDisplay) {
            if (isAdd) {
                editorCodeDisplay.textContent = nextCodeFor(panelType) || '—';
            } else {
                var rowData = getRowData(selectedRow);
                editorCodeDisplay.textContent = (rowData && rowData.code) ? rowData.code : '—';
            }
        }
        if (editorCodeHint) {
            var nc = nextCodeFor(panelType);
            editorCodeHint.textContent = isAdd
                ? 'سيُعيَّن الرقم ' + (nc || '—') + ' عند الحفظ'
                : 'الرقم ثابت ولا يمكن تعديله';
        }
        if (editorName) {
            editorName.value = isAdd ? '' : (getRowData(selectedRow).name || '');
        }
        if (editorAmount) {
            editorAmount.value = isAdd ? '0' : (getRowData(selectedRow).amount || '0');
        }
        if (editorNotes) {
            editorNotes.value = isAdd ? '' : (getRowData(selectedRow).description || '');
        }
        if (editorActive) {
            if (isAdd) {
                editorActive.checked = true;
            } else {
                var rd = getRowData(selectedRow);
                editorActive.checked = rd ? rd.active : true;
            }
        }
        editor.hidden = false;
        setPageEditingClass(panelType);
        if (editorName) {
            editorName.focus();
        }
    }

    function closeEditor() {
        if (editor) {
            editor.hidden = true;
        }
        page.classList.remove('is-editing-allow', 'is-editing-deduct');
    }

    function startAdd(panelType) {
        clearAllSelections();
        activePanelType = panelType;
        openEditor('add', panelType);
    }

    function startEdit(panelType) {
        if (!selectedRow || selectedPanelType !== panelType) {
            appDialogAlert(labelsFor(panelType).emptySelect, 'warning');
            return;
        }
        openEditor('edit', panelType);
    }

    function submitEditorSave() {
        if (!editorForm) return;
        if (typeof editorForm.reportValidity === 'function' && !editorForm.reportValidity()) {
            return;
        }
        if (typeof editorForm.requestSubmit === 'function') {
            editorForm.requestSubmit();
        } else {
            editorForm.submit();
        }
    }

    function submitDelete() {
        if (!delForm || !delIdInp) return;
        if (!selectedRow) {
            appDialogAlert('حدد بنداً من أحد الجدولين ثم اضغط حذف.', 'warning');
            return;
        }
        var data = getRowData(selectedRow);
        if (!data || data.id < 1) {
            return;
        }
        if (selectedRow.getAttribute('data-linked') === '1') {
            appDialogAlert(
                selectedRow.getAttribute('data-linked-msg')
                    || 'لا يمكن حذف هذا البند لأنه مرتبط بحركات في النظام.',
                'warning'
            );
            return;
        }
        var L = labelsFor(data.compType);
        var label = data.name || L.deleteType;
        appDialogConfirm('حذف «' + label + '» نهائياً من النظام؟').then(function (ok) {
            if (ok) {
                delIdInp.value = String(data.id);
                delForm.submit();
            }
        });
    }

    document.querySelectorAll('.hr-pc-btn-add').forEach(function (btn) {
        btn.addEventListener('click', function () {
            startAdd(btn.getAttribute('data-type') || 'allowance');
        });
    });

    document.querySelectorAll('.hr-pc-btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            startEdit(btn.getAttribute('data-type') || 'allowance');
        });
    });

    if (btnClose) {
        btnClose.addEventListener('click', closeEditor);
    }
    if (btnCancel) {
        btnCancel.addEventListener('click', closeEditor);
    }

    document.querySelectorAll('.hr-pc-panel-body').forEach(function (tbody) {
        var panelType = tbody.getAttribute('data-panel-type') || 'allowance';

        tbody.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb || !cb.classList.contains('hr-pc-active-cb')) return;
            var toggleForm = cb.closest('form.hr-pc-toggle-form');
            if (toggleForm) {
                e.stopPropagation();
                toggleForm.submit();
            }
        });

        tbody.addEventListener('click', function (e) {
            if (e.target.closest('.hr-pc-toggle-form')) {
                e.stopPropagation();
                return;
            }
            var tr = e.target.closest('tr.hr-pc-row');
            if (tr) {
                selectRow(tr, panelType);
            }
        });

        tbody.addEventListener('dblclick', function (e) {
            var tr = e.target.closest('tr.hr-pc-row');
            if (tr) {
                selectRow(tr, panelType);
                startEdit(panelType);
            }
        });

        tbody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var tr = e.target.closest('tr.hr-pc-row');
            if (tr) {
                e.preventDefault();
                selectRow(tr, panelType);
                startEdit(panelType);
            }
        });
    });

    document.addEventListener('master-toolbar', function (e) {
        if (!e.detail) return;
        var bar = document.getElementById('master-toolbar');
        var route = bar ? bar.getAttribute('data-active-route') || '' : '';
        if (route !== 'hr_payroll_components') return;

        var action = e.detail.action;
        if (action === 'save') {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (editor && !editor.hidden) {
                submitEditorSave();
            } else if (selectedRow && selectedPanelType) {
                startEdit(selectedPanelType);
            } else {
                appDialogAlert('افتح «إضافة» أو «تعديل» من جدول العلاوات أو الاقتطاعات ثم احفظ.', 'warning');
            }
        } else if (action === 'delete') {
            e.preventDefault();
            e.stopImmediatePropagation();
            submitDelete();
        } else if (action === 'new') {
            e.preventDefault();
            e.stopImmediatePropagation();
            startAdd(activePanelType || 'allowance');
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
})();
