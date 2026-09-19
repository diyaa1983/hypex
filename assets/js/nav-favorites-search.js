(function () {
  'use strict';

  var input = document.getElementById('nav-fav-search-input');
  var clearBtn = document.getElementById('nav-fav-search-clear');
  var hint = document.getElementById('nav-fav-search-hint');
  var grid = document.getElementById('nav-fav-grid');
  if (!input || !grid) return;

  var tiles = Array.prototype.slice.call(grid.querySelectorAll('.nav-fav-tile'));

  function normalize(text) {
    return String(text || '')
      .toLowerCase()
      .replace(/[أإآ]/g, 'ا')
      .replace(/ة/g, 'ه')
      .replace(/ى/g, 'ي')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function applyFilter() {
    var q = normalize(input.value);
    var visible = 0;

    tiles.forEach(function (tile) {
      if (!q) {
        tile.hidden = false;
        visible += 1;
        return;
      }
      var label = tile.getAttribute('data-fav-label') || '';
      var route = tile.getAttribute('data-fav-route') || '';
      var text = (tile.querySelector('.nav-fav-tile__label') || {}).textContent || '';
      var hay = normalize(label + ' ' + route + ' ' + text);
      var match = hay.indexOf(q) !== -1;
      tile.hidden = !match;
      if (match) visible += 1;
    });

    if (clearBtn) clearBtn.hidden = q === '';
    if (hint) hint.hidden = !(q !== '' && visible === 0);
    grid.classList.toggle('is-empty-filter', q !== '' && visible === 0);
  }

  input.addEventListener('input', applyFilter);
  input.addEventListener('search', applyFilter);

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      input.value = '';
      input.focus();
      applyFilter();
    });
  }

  // اختصار: تركيز البحث عند الضغط /
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    var tag = (e.target && e.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable)) return;
    e.preventDefault();
    input.focus();
    input.select();
  });
})();
