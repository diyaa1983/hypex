(function () {
  'use strict';

  var form = document.getElementById('permissions-form');
  var groupSelect = document.getElementById('permissions-group-select');
  var groupForm = document.getElementById('permissions-group-form');
  var domainSelect = document.getElementById('perm-domain-select');
  var subgroupSelect = document.getElementById('perm-subgroup-select');
  var searchInput = document.getElementById('perm-search-input');
  var globalEmpty = document.getElementById('perm-global-empty');

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
      window.location.href = url.pathname + url.search;
    });
    groupForm.addEventListener('submit', function (e) {
      e.preventDefault();
    });
  }

  if (form && domainSelect && subgroupSelect) {
    var domainBlocks = Array.prototype.slice.call(
      form.querySelectorAll('.perm-domain-block[data-perm-domain-id]')
    );

    if (form.getAttribute('data-mobile-only-group') === '1') {
      domainSelect.value = 'mobile';
      domainSelect.disabled = true;
    }

    function normalizeSearchText(value) {
      return String(value || '').toLowerCase().trim();
    }

    function clearSubgroupOptions() {
      subgroupSelect.innerHTML = '';
      var baseOption = document.createElement('option');
      baseOption.value = '';
      baseOption.textContent = 'كل القوائم';
      subgroupSelect.appendChild(baseOption);
    }

    function collectDomainSubgroups(domainId) {
      var map = {};
      domainBlocks.forEach(function (block) {
        if ((block.getAttribute('data-perm-domain-id') || '') !== domainId) return;

        var details = block.querySelectorAll('.perm-subfold[data-perm-subgroup-id]');
        if (details.length > 0) {
          Array.prototype.forEach.call(details, function (fold) {
            var sid = fold.getAttribute('data-perm-subgroup-id') || '';
            if (!sid) return;
            if (map[sid]) return;
            map[sid] = fold.getAttribute('data-perm-subgroup-title') || sid;
          });
          return;
        }

        var blockSubId = block.getAttribute('data-perm-subgroup-id') || '';
        if (blockSubId) {
          map[blockSubId] = block.getAttribute('data-perm-subgroup-title') || blockSubId;
        }
      });

      return Object.keys(map).map(function (sid) {
        return { id: sid, title: map[sid] };
      });
    }

    function tableMatchesSearch(table, searchTerm) {
      var tbody = table ? table.tBodies && table.tBodies[0] : null;
      if (!tbody) return false;

      var entryRows = Array.prototype.slice.call(tbody.querySelectorAll('tr.perm-row-entry'));
      var staticEmpty = tbody.querySelector('tr.perm-row-empty-static');
      var dynamicEmpty = tbody.querySelector('tr.perm-row-empty-search');
      var visibleCount = 0;

      entryRows.forEach(function (row) {
        var hit = !searchTerm || normalizeSearchText(row.textContent).indexOf(searchTerm) !== -1;
        row.hidden = !hit;
        if (hit) visibleCount += 1;
      });

      if (staticEmpty) {
        staticEmpty.hidden = searchTerm !== '' || visibleCount > 0;
      }

      if (!dynamicEmpty) {
        dynamicEmpty = document.createElement('tr');
        dynamicEmpty.className = 'perm-row-empty-search';
        dynamicEmpty.hidden = true;
        dynamicEmpty.innerHTML =
          '<td colspan="4" class="muted" style="text-align:center;">لا توجد نتائج مطابقة للبحث.</td>';
        tbody.appendChild(dynamicEmpty);
      }

      var shouldShowSearchEmpty = searchTerm !== '' && entryRows.length > 0 && visibleCount === 0;
      dynamicEmpty.hidden = !shouldShowSearchEmpty;

      if (entryRows.length > 0) {
        return visibleCount > 0;
      }
      if (staticEmpty && !staticEmpty.hidden) {
        return true;
      }
      return false;
    }

    function rebuildSubgroupOptions() {
      var domainId = domainSelect.value || '';
      var previousValue = subgroupSelect.value || '';
      clearSubgroupOptions();

      if (!domainId) {
        subgroupSelect.value = '';
        subgroupSelect.disabled = true;
        return;
      }

      var items = collectDomainSubgroups(domainId);
      items.forEach(function (item) {
        var opt = document.createElement('option');
        opt.value = item.id;
        opt.textContent = item.title;
        subgroupSelect.appendChild(opt);
      });
      subgroupSelect.disabled = items.length === 0;

      if (previousValue && items.some(function (it) { return it.id === previousValue; })) {
        subgroupSelect.value = previousValue;
      } else {
        subgroupSelect.value = '';
      }
    }

    function applyFilters() {
      var domainId = domainSelect.value || '';
      var subgroupId = subgroupSelect.value || '';
      var searchTerm = normalizeSearchText(searchInput ? searchInput.value : '');
      var visibleBlocksCount = 0;

      domainBlocks.forEach(function (block) {
        var blockDomain = block.getAttribute('data-perm-domain-id') || '';
        var domainPass = !domainId || blockDomain === domainId;
        var showBlock = domainPass;
        var subFolds = block.querySelectorAll('.perm-subfold[data-perm-subgroup-id]');

        if (subFolds.length > 0) {
          var hasVisibleFold = false;
          Array.prototype.forEach.call(subFolds, function (fold) {
            var foldSubId = fold.getAttribute('data-perm-subgroup-id') || '';
            var subgroupPass = !subgroupId || foldSubId === subgroupId;
            var tables = fold.querySelectorAll('table.perm-table');
            var tablePass = false;

            Array.prototype.forEach.call(tables, function (tbl) {
              if (tableMatchesSearch(tbl, searchTerm)) {
                tablePass = true;
              }
            });

            var showFold = domainPass && subgroupPass && tablePass;
            fold.hidden = !showFold;
            if (showFold) hasVisibleFold = true;
          });
          showBlock = hasVisibleFold;
        } else {
          var blockSub = block.getAttribute('data-perm-subgroup-id') || '';
          var subgroupPass = !subgroupId || blockSub === subgroupId;
          var blockTables = block.querySelectorAll('table.perm-table');
          var blockTablePass = false;

          Array.prototype.forEach.call(blockTables, function (tbl) {
            if (tableMatchesSearch(tbl, searchTerm)) {
              blockTablePass = true;
            }
          });

          showBlock = domainPass && subgroupPass && blockTablePass;
        }

        if (!domainPass) {
          Array.prototype.forEach.call(subFolds, function (fold) {
            fold.hidden = false;
          });
          if (searchTerm === '') {
            var resetTables = block.querySelectorAll('table.perm-table');
            Array.prototype.forEach.call(resetTables, function (tbl) {
              tableMatchesSearch(tbl, '');
            });
          } else {
            var hideTables = block.querySelectorAll('table.perm-table');
            Array.prototype.forEach.call(hideTables, function (tbl) {
              tableMatchesSearch(tbl, searchTerm);
            });
          }
        }

        block.hidden = !showBlock;
        if (showBlock) {
          visibleBlocksCount += 1;
        }
      });

      if (globalEmpty) {
        globalEmpty.hidden = visibleBlocksCount > 0;
      }
    }

    domainSelect.addEventListener('change', function () {
      rebuildSubgroupOptions();
      applyFilters();
    });

    subgroupSelect.addEventListener('change', function () {
      applyFilters();
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        applyFilters();
      });
    }

    rebuildSubgroupOptions();
    applyFilters();
  }

  if (!form) return;

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
