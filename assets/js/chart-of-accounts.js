(function () {
  'use strict';

  var mapEl = document.getElementById('coa-client-map');
  var split = document.getElementById('coa-split');
  if (!mapEl || !split) return;

  var STORAGE_KEY = 'manager_coa_tree_state';

  var map;
  try {
    map = JSON.parse(mapEl.textContent || '{}');
  } catch (e) {
    map = { nodes: {}, childrenOf: { 0: [] } };
  }

  var nodes = map.nodes || {};
  var childrenOf = map.childrenOf || { 0: [] };
  var listBase = split.getAttribute('data-list-url') || '';
  var shouldRestore = split.getAttribute('data-restore-tree') === '1';

  var detailTitle = document.getElementById('coa-detail-title');
  var detailBody = document.getElementById('coa-detail-body');
  var actAdd = document.getElementById('coa-act-add');
  var actEdit = document.getElementById('coa-act-edit');
  var actDel = document.getElementById('coa-act-del');
  var delIdInput = document.getElementById('coa-del-id');

  var selectedTreeId = 0;
  var selectedRowId = 0;
  var detailParentId = 0;
  var persistEnabled = true;
  var searchInput = document.getElementById('coa-tree-search');
  var searchHint = document.getElementById('coa-search-hint');
  var searchActive = false;

  function nodeById(id) {
    return nodes[String(id)] || nodes[id] || null;
  }

  function childIds(parentId) {
    var key = String(parentId);
    return childrenOf[key] || childrenOf[parentId] || [];
  }

  function clearTreeState() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (e) {
      // ignore
    }
  }

  function loadTreeState() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || typeof data !== 'object') return null;
      return {
        expanded: Array.isArray(data.expanded) ? data.expanded : [],
        treeId: parseInt(data.treeId, 10) || 0,
        rowId: parseInt(data.rowId, 10) || 0,
      };
    } catch (e) {
      return null;
    }
  }

  function saveTreeState() {
    if (!persistEnabled) return;
    try {
      sessionStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          expanded: collectExpandedIds(),
          treeId: selectedTreeId,
          rowId: selectedRowId,
        })
      );
    } catch (e) {
      // ignore
    }
  }

  function collectExpandedIds() {
    var ids = [];
    document.querySelectorAll('.coa-ref-node').forEach(function (nodeEl) {
      var exp = nodeEl.querySelector(':scope > .coa-ref-line > .coa-ref-exp:not(.coa-ref-exp--spacer)');
      if (!exp || exp.getAttribute('aria-expanded') !== 'true') return;
      var id = parseInt(nodeEl.getAttribute('data-id'), 10);
      if (id > 0) ids.push(id);
    });
    return ids;
  }

  function setBranchExpanded(nodeId, expanded) {
    var nodeEl = document.querySelector('.coa-ref-node[data-id="' + nodeId + '"]');
    if (!nodeEl) return;
    var exp = nodeEl.querySelector(':scope > .coa-ref-line > .coa-ref-exp:not(.coa-ref-exp--spacer)');
    var wrap = nodeEl.querySelector(':scope > .coa-ref-children-wrap');
    if (!exp || !wrap) return;
    exp.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    var ico = exp.querySelector('.coa-ref-exp-ico');
    if (ico) ico.textContent = expanded ? '−' : '+';
    wrap.classList.toggle('is-collapsed', !expanded);
  }

  function expandAncestors(id) {
    var n = nodeById(id);
    var guard = 0;
    while (n && n.parent_id && guard < 64) {
      setBranchExpanded(n.parent_id, true);
      n = nodeById(n.parent_id);
      guard += 1;
    }
  }

  function applyExpandedIds(ids) {
    (ids || []).forEach(function (id) {
      setBranchExpanded(id, true);
    });
  }

  function normSearch(s) {
    return String(s || '')
      .trim()
      .toLowerCase();
  }

  function digitsOnly(s) {
    return String(s || '').replace(/\D/g, '');
  }

  function nodeMatchesSearch(n, needle, needleDigits) {
    if (!needle && !needleDigits) return true;
    var name = normSearch(n.name_ar);
    var code = normSearch(n.code);
    var codeDigits = digitsOnly(n.code);
    if (needle && (name.indexOf(needle) >= 0 || code.indexOf(needle) >= 0)) {
      return true;
    }
    if (needleDigits && codeDigits.indexOf(needleDigits) >= 0) {
      return true;
    }
    return false;
  }

  function ancestorIds(id) {
    var out = [];
    var n = nodeById(id);
    var guard = 0;
    while (n && n.parent_id && guard < 64) {
      out.push(n.parent_id);
      n = nodeById(n.parent_id);
      guard += 1;
    }
    return out;
  }

  function clearSearchUi() {
    searchActive = false;
    document.querySelectorAll('.coa-ref-node').forEach(function (el) {
      el.classList.remove('coa-search-hidden', 'coa-search-match');
    });
    if (searchHint) {
      searchHint.hidden = true;
      searchHint.textContent = '';
      searchHint.className = 'coa-search-hint';
    }
  }

  function applyTreeSearch(query) {
    var needle = normSearch(query);
    var needleDigits = digitsOnly(query);
    if (!needle && !needleDigits) {
      clearSearchUi();
      return;
    }

    searchActive = true;
    var matchIds = [];
    Object.keys(nodes).forEach(function (key) {
      var n = nodes[key];
      if (!n) return;
      if (nodeMatchesSearch(n, needle, needleDigits)) {
        matchIds.push(parseInt(n.id, 10));
      }
    });

    var visibleSet = {};
    matchIds.forEach(function (id) {
      visibleSet[id] = true;
      ancestorIds(id).forEach(function (aid) {
        visibleSet[aid] = true;
      });
    });

    document.querySelectorAll('.coa-ref-node').forEach(function (el) {
      var id = parseInt(el.getAttribute('data-id'), 10);
      el.classList.toggle('coa-search-hidden', !visibleSet[id]);
      el.classList.toggle('coa-search-match', matchIds.indexOf(id) >= 0);
    });

    matchIds.forEach(function (id) {
      expandAncestors(id);
    });

    if (searchHint) {
      searchHint.hidden = false;
      if (!matchIds.length) {
        searchHint.textContent = 'لا يوجد حساب مطابق';
        searchHint.className = 'coa-search-hint is-empty';
      } else {
        searchHint.textContent =
          matchIds.length === 1 ? 'حساب واحد' : matchIds.length + ' حسابات';
        searchHint.className = 'coa-search-hint is-ok';
      }
    }

    if (matchIds.length) {
      var firstId = matchIds[0];
      var firstNode = nodeById(firstId);
      var treePick = firstId;
      var rowPick = 0;
      if (firstNode && firstNode.parent_id) {
        var siblings = childIds(firstNode.parent_id);
        if (siblings.indexOf(firstId) >= 0) {
          treePick = firstNode.parent_id;
          rowPick = firstId;
        }
      }
      selectTreeNode(treePick, rowPick);
      var firstEl = document.querySelector('.coa-ref-node[data-id="' + firstId + '"]');
      if (firstEl && typeof firstEl.scrollIntoView === 'function') {
        firstEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }
  }

  function setTreeSelection(id) {
    selectedTreeId = id;
    document.querySelectorAll('.coa-ref-node.is-selected').forEach(function (el) {
      el.classList.remove('is-selected');
      el.setAttribute('aria-selected', 'false');
    });
    var node = document.querySelector('.coa-ref-node[data-id="' + id + '"]');
    if (node) {
      node.classList.add('is-selected');
      node.setAttribute('aria-selected', 'true');
    }
    saveTreeState();
  }

  function setRowSelection(id, skipSave) {
    selectedRowId = id;
    if (!detailBody) return;
    detailBody.querySelectorAll('tr.is-selected').forEach(function (tr) {
      tr.classList.remove('is-selected');
    });
    if (id > 0) {
      var row = detailBody.querySelector('tr[data-id="' + id + '"]');
      if (row) row.classList.add('is-selected');
    }
    if (actEdit && listBase) {
      actEdit.href = id > 0 ? listBase + '&action=edit&id=' + id : '#';
      actEdit.classList.toggle('is-disabled', id < 1);
    }
    if (actDel) {
      var canDel = false;
      var blockReason = 'اختر حساباً للحذف.';
      if (id > 0) {
        var selNode = nodeById(id);
        if (selNode && selNode.can_delete) {
          canDel = true;
          blockReason = '';
        } else if (selNode) {
          blockReason =
            selNode.delete_block_reason || 'لا يمكن حذف الحساب: يوجد عليه حركات.';
        }
      }
      actDel.disabled = false;
      actDel.classList.toggle('is-disabled', !canDel);
      actDel.setAttribute('data-can-delete', canDel ? '1' : '0');
      actDel.title = blockReason;
      actDel.setAttribute('aria-disabled', canDel ? 'false' : 'true');
    }
    if (delIdInput) delIdInput.value = id > 0 ? String(id) : '';
    if (!skipSave) saveTreeState();
  }

  function updateDetailActions(parentId) {
    detailParentId = parentId;
    if (actAdd && listBase) {
      if (parentId > 0) {
        actAdd.href = listBase + '&action=add&parent_id=' + parentId;
        actAdd.textContent = '+ إضافة فرع';
      } else {
        actAdd.href = listBase + '&action=add';
        actAdd.textContent = '+ إضافة حساب';
      }
    }
  }

  function renderDetailTable(parentId, preferredRowId) {
    if (!detailBody) return;
    var parent = parentId > 0 ? nodeById(parentId) : null;
    var ids = childIds(parentId);

    if (detailTitle) {
      if (parent) {
        detailTitle.textContent = parent.name_ar + ' — ' + parent.code;
      } else if (parentId === 0) {
        detailTitle.textContent = 'الحسابات الرئيسية';
      } else {
        detailTitle.textContent = '—';
      }
    }

    updateDetailActions(parentId);
    detailBody.innerHTML = '';

    if (!ids.length) {
      var empty = document.createElement('tr');
      empty.className = 'coa-detail-placeholder';
      var td = document.createElement('td');
      td.colSpan = 2;
      td.textContent =
        parentId > 0 && parent && parent.is_leaf
          ? 'حساب نهائي — لا توجد فروع'
          : 'لا توجد حسابات فرعية';
      empty.appendChild(td);
      detailBody.appendChild(empty);
      // حساب نهائي: نربط الإجراءات (حذف/تعديل) بالحساب نفسه وليس بصف فرعي.
      if (parentId > 0 && parent && parent.is_leaf) {
        setRowSelection(parentId, true);
      } else {
        setRowSelection(0);
      }
      return;
    }

    ids.forEach(function (cid, idx) {
      var n = nodeById(cid);
      if (!n) return;
      var tr = document.createElement('tr');
      tr.setAttribute('data-id', String(n.id));
      if (idx % 2 === 1) tr.classList.add('coa-row-even');

      var tdName = document.createElement('td');
      tdName.textContent = n.name_ar;
      var tdCode = document.createElement('td');
      tdCode.className = 'coa-row-code';
      tdCode.textContent = n.code;

      tr.appendChild(tdName);
      tr.appendChild(tdCode);
      tr.addEventListener('click', function () {
        setRowSelection(n.id);
      });
      tr.addEventListener('dblclick', function () {
        saveTreeState();
        if (listBase) window.location.href = listBase + '&action=edit&id=' + n.id;
      });
      detailBody.appendChild(tr);
    });

    var pickRow = 0;
    if (preferredRowId > 0 && ids.indexOf(preferredRowId) !== -1) {
      pickRow = preferredRowId;
    } else if (ids.length) {
      pickRow = ids[0];
    }
    setRowSelection(pickRow, true);
    saveTreeState();
  }

  function selectTreeNode(id, preferredRowId) {
    if (!nodeById(id)) return;
    expandAncestors(id);
    setTreeSelection(id);
    renderDetailTable(id, preferredRowId || 0);
  }

  function initDefaultView() {
    var roots = childIds(0);
    if (roots.length) {
      selectTreeNode(roots[0], 0);
    }
    saveTreeState();
  }

  function initFromSavedState(state) {
    applyExpandedIds(state.expanded);
    var treeId = state.treeId;
    if (treeId < 1 || !nodeById(treeId)) {
      initDefaultView();
      return;
    }
    if (state.rowId > 0) {
      expandAncestors(state.rowId);
    }
    expandAncestors(treeId);
    selectTreeNode(treeId, state.rowId);
  }

  split.addEventListener('click', function (e) {
    if (e.target.closest('.coa-ref-name-edit')) {
      saveTreeState();
      return;
    }
    var exp = e.target.closest('.coa-ref-exp:not(.coa-ref-exp--spacer)');
    if (exp) {
      e.preventDefault();
      e.stopPropagation();
      var branch = exp.closest('.coa-ref-node');
      if (!branch) return;
      var wrap = branch.querySelector(':scope > .coa-ref-children-wrap');
      if (!wrap) return;
      var open = exp.getAttribute('aria-expanded') !== 'false';
      exp.setAttribute('aria-expanded', open ? 'false' : 'true');
      var ico = exp.querySelector('.coa-ref-exp-ico');
      if (ico) ico.textContent = open ? '+' : '−';
      wrap.classList.toggle('is-collapsed', open);
      saveTreeState();
      return;
    }

    var labelBtn = e.target.closest('.coa-ref-label');
    var nodeEl = e.target.closest('.coa-ref-node');
    if (!nodeEl) return;
    var id = parseInt(nodeEl.getAttribute('data-id'), 10);
    if (id < 1) return;
    if (labelBtn || !e.target.closest('.coa-ref-exp')) {
      selectTreeNode(id, 0);
    }
  });

  if (actEdit) {
    actEdit.addEventListener('click', function (e) {
      if (selectedRowId < 1) {
        e.preventDefault();
        return;
      }
      saveTreeState();
    });
  }

  function showDeleteBlockMessage(reason) {
    var msg = reason || 'لا يمكن حذف الحساب: يوجد عليه حركات.';
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert(msg, { type: 'warning' });
    } else {
      window.alert(msg);
    }
  }

  var delForm = document.getElementById('coa-act-del-form');
  if (delForm) {
    delForm.addEventListener('submit', function (e) {
      if (!actDel || actDel.getAttribute('data-can-delete') !== '1' || selectedRowId < 1) {
        e.preventDefault();
        showDeleteBlockMessage(actDel ? actDel.title : '');
        return;
      }
      if (!window.confirm('حذف الحساب المحدد؟')) {
        e.preventDefault();
      }
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      applyTreeSearch(searchInput.value);
    });
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        searchInput.value = '';
        clearSearchUi();
        searchInput.blur();
      }
    });
  }

  if (shouldRestore) {
    var saved = loadTreeState();
    if (saved && saved.treeId > 0) {
      initFromSavedState(saved);
    } else {
      initDefaultView();
    }
  } else {
    clearTreeState();
    initDefaultView();
  }
})();
