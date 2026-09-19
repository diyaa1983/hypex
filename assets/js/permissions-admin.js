(function () {
  'use strict';

  var form = document.getElementById('permissions-form');
  var groupSelect = document.getElementById('permissions-group-select');
  var groupForm = document.getElementById('permissions-group-form');
  var tree = document.getElementById('perm-tree');
  var treeSearch = document.getElementById('perm-tree-search');
  var treeSearchBtn = document.getElementById('perm-tree-search-btn');
  var screenSearch = document.getElementById('perm-screen-search');
  var detailTitle = document.getElementById('perm-detail-title');
  var globalEmpty = document.getElementById('perm-global-empty');
  var selectAllBtn = document.getElementById('perm-select-all');
  var clearAllBtn = document.getElementById('perm-clear-all');

  if (groupSelect && groupForm) {
    groupSelect.addEventListener('change', function () {
      var base = groupForm.getAttribute('action') || 'index.php';
      var url;
      try {
        url = new URL(base, window.location.href);
      } catch (e) {
        url = new URL(window.location.pathname, window.location.origin);
      }
      url.searchParams.set('r', 'permissions');
      url.searchParams.set('group_id', groupSelect.value);
      if (window.AppDesktopWindow && typeof window.AppDesktopWindow.allowNextUnload === 'function') {
        window.AppDesktopWindow.allowNextUnload();
      }
      window.__managerAllowUnload = true;
      window.location.href = url.pathname + url.search;
    });
    groupForm.addEventListener('submit', function (e) {
      e.preventDefault();
    });
  }

  if (!form) return;

  var panels = Array.prototype.slice.call(form.querySelectorAll('.perm-panel[data-panel-id]'));
  var treeNodes = tree
    ? Array.prototype.slice.call(tree.querySelectorAll('.perm-tree-node[data-panel-id]'))
    : [];

  function normalizeSearchText(value) {
    return String(value || '')
      .toLowerCase()
      .trim();
  }

  function currentTypeFilter() {
    var checked = form.querySelector('input[name="perm_type_filter"]:checked');
    return checked ? String(checked.value || 'all') : 'all';
  }

  function activePanel() {
    return form.querySelector('.perm-panel.is-active[data-panel-id]');
  }

  function setActivePanel(panelId) {
    if (!panelId) return;
    panels.forEach(function (panel) {
      var match = (panel.getAttribute('data-panel-id') || '') === panelId;
      panel.classList.toggle('is-active', match);
      panel.hidden = !match;
    });
    treeNodes.forEach(function (node) {
      node.classList.toggle('is-active', (node.getAttribute('data-panel-id') || '') === panelId);
    });
    var panel = activePanel();
    if (detailTitle && panel) {
      var title = panel.getAttribute('data-panel-title') || 'الشاشات / التقارير';
      detailTitle.textContent = 'الشاشات / التقارير — ' + title;
    }
    applyRowFilters();
  }

  function applyTreeSearch() {
    if (!tree) return;
    var term = normalizeSearchText(treeSearch ? treeSearch.value : '');
    var domains = tree.querySelectorAll('.perm-tree-domain');
    Array.prototype.forEach.call(domains, function (domain) {
      var nodes = domain.querySelectorAll('.perm-tree-node');
      var visible = 0;
      Array.prototype.forEach.call(nodes, function (node) {
        var hit = !term || normalizeSearchText(node.textContent).indexOf(term) !== -1;
        node.hidden = !hit;
        if (hit) visible += 1;
      });
      domain.hidden = visible === 0;
    });
  }

  function applyRowFilters() {
    var panel = activePanel();
    if (!panel) {
      if (globalEmpty) globalEmpty.hidden = true;
      return;
    }
    var term = normalizeSearchText(screenSearch ? screenSearch.value : '');
    var typeFilter = currentTypeFilter();
    var rows = panel.querySelectorAll('tr.perm-row-entry');
    var visible = 0;
    Array.prototype.forEach.call(rows, function (row) {
      var kind = row.getAttribute('data-perm-kind') || 'screen';
      var typePass = typeFilter === 'all' || kind === typeFilter;
      var textPass = !term || normalizeSearchText(row.textContent).indexOf(term) !== -1;
      var show = typePass && textPass;
      row.hidden = !show;
      if (show) visible += 1;
    });
    if (globalEmpty) {
      globalEmpty.hidden = visible > 0 || rows.length === 0;
    }
  }

  function visibleCheckboxes(panel) {
    if (!panel) return [];
    return Array.prototype.slice
      .call(panel.querySelectorAll('tr.perm-row-entry:not([hidden]) input[type="checkbox"]'))
      .filter(function (el) {
        return !el.disabled;
      });
  }

  treeNodes.forEach(function (node) {
    node.addEventListener('click', function () {
      setActivePanel(node.getAttribute('data-panel-id') || '');
    });
  });

  if (treeSearch) {
    treeSearch.addEventListener('input', applyTreeSearch);
  }
  if (treeSearchBtn) {
    treeSearchBtn.addEventListener('click', applyTreeSearch);
  }
  if (screenSearch) {
    screenSearch.addEventListener('input', applyRowFilters);
  }

  Array.prototype.forEach.call(form.querySelectorAll('input[name="perm_type_filter"]'), function (radio) {
    radio.addEventListener('change', applyRowFilters);
  });

  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function () {
      visibleCheckboxes(activePanel()).forEach(function (cb) {
        cb.checked = true;
      });
    });
  }
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', function () {
      visibleCheckboxes(activePanel()).forEach(function (cb) {
        cb.checked = false;
      });
    });
  }

  var initial = form.getAttribute('data-initial-panel') || '';
  if (initial) {
    setActivePanel(initial);
  } else {
    applyRowFilters();
  }
  applyTreeSearch();

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'save') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();
