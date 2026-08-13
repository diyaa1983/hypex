/**
 * كشف حركات مادة — اختيار المادة (قائمة اقتراح)
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    var search = document.getElementById('imv-item-search');
    var itemId = document.getElementById('imv-item-id');
    var form = document.getElementById('imv-form');
    var wrap = document.querySelector('.imv-item-wrap');
    if (!search || !itemId) return;

    var suggest = document.getElementById('imv-item-suggest');
    if (!suggest) {
      suggest = document.createElement('div');
      suggest.id = 'imv-item-suggest';
      suggest.className = 'si-suggest si-suggest--name imv-suggest';
      suggest.hidden = true;
      suggest.setAttribute('hidden', '');
      if (wrap) wrap.appendChild(suggest);
      else document.body.appendChild(suggest);
    }

    var lastRows = [];
    var reqSeq = 0;
    var timer = null;

    function escHtml(s) {
      return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function labelOf(c) {
      var code = c.code || c.barcode || c.sku || '';
      var name = c.name || c.name_ar || '';
      return code + (code && name ? ' — ' : '') + name;
    }

    function apiUrl(path) {
      if (typeof window.__hypexUrl === 'function') return window.__hypexUrl(path);
      var b = String(window.__HYPEX_BASE__ || '').replace(/\/$/, '');
      return b && path.charAt(0) === '/' ? b + path : path;
    }

    function showSuggest() {
      suggest.hidden = false;
      suggest.removeAttribute('hidden');
      suggest.classList.add('imv-suggest--open');
      suggest.style.display = 'block';
    }

    function closeList() {
      suggest.hidden = true;
      suggest.setAttribute('hidden', '');
      suggest.classList.remove('imv-suggest--open');
      suggest.style.display = 'none';
      suggest.innerHTML = '';
      suggest.dataset.hxUserNav = '';
    }

    function pick(c) {
      itemId.value = String(c.id || 0);
      search.value = labelOf(c);
      closeList();
    }

    function render(list) {
      lastRows = list || [];
      if (!lastRows.length) {
        suggest.innerHTML = '<div class="si-suggest-empty">لا نتائج مطابقة</div>';
      } else {
        suggest.innerHTML = lastRows
          .map(function (c) {
            var code = c.code || c.barcode || c.sku || '';
            var name = c.name || c.name_ar || '';
            return (
              '<button type="button" data-id="' +
              c.id +
              '">' +
              '<span class="imv-sug-code" dir="ltr">' +
              escHtml(code || '—') +
              '</span>' +
              '<span class="imv-sug-name">' +
              escHtml(name || '—') +
              '</span>' +
              '</button>'
            );
          })
          .join('');
      }
      showSuggest();
      suggest.querySelectorAll('button[data-id]').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
        });
        btn.addEventListener('click', function () {
          var id = Number(btn.getAttribute('data-id') || 0);
          var c = lastRows.find(function (x) {
            return Number(x.id) === id;
          });
          if (c) pick(c);
        });
      });
    }

    function queryText() {
      var q = String(search.value || '').trim();
      if (Number(itemId.value) > 0 && q.indexOf(' — ') >= 0) return '';
      return q;
    }

    function fetchList(q) {
      var seq = ++reqSeq;
      suggest.innerHTML = '<div class="si-suggest-empty">جاري البحث…</div>';
      showSuggest();
      var url =
        apiUrl('/api/lookup/items') +
        '?q=' +
        encodeURIComponent(q || '') +
        '&limit=60';
      fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
        .then(function (r) {
          if (!r.ok) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (d) {
          if (seq !== reqSeq) return;
          var rows = d && d.ok ? d.rows || [] : [];
          render(
            rows.map(function (r) {
              return {
                id: Number(r.id),
                code: r.code || r.barcode || r.sku || '',
                name: r.name_ar || r.name || '',
                name_ar: r.name_ar || '',
                barcode: r.barcode || '',
                sku: r.sku || '',
              };
            })
          );
        })
        .catch(function () {
          if (seq !== reqSeq) return;
          suggest.innerHTML = '<div class="si-suggest-empty">تعذر تحميل المواد</div>';
          showSuggest();
        });
    }

    function openList() {
      fetchList(queryText());
    }

    function moveActive(dir) {
      var btns = Array.prototype.slice.call(suggest.querySelectorAll('button[data-id]'));
      if (!btns.length || suggest.hidden) return false;
      var cur = -1;
      for (var i = 0; i < btns.length; i++) {
        if (btns[i].classList.contains('is-active')) {
          cur = i;
          break;
        }
      }
      if (cur < 0) cur = dir > 0 ? -1 : 0;
      var next = cur + dir;
      if (next < 0) next = btns.length - 1;
      if (next >= btns.length) next = 0;
      btns.forEach(function (b, i) {
        b.classList.toggle('is-active', i === next);
      });
      suggest.dataset.hxUserNav = '1';
      try {
        btns[next].scrollIntoView({ block: 'nearest' });
      } catch (e) {
        /* ignore */
      }
      return true;
    }

    search.addEventListener('input', function () {
      itemId.value = '0';
      clearTimeout(timer);
      timer = setTimeout(function () {
        fetchList(String(search.value || '').trim());
      }, 160);
    });
    search.addEventListener('focus', function () {
      openList();
    });
    search.addEventListener('click', function () {
      openList();
    });
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeList();
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        e.stopPropagation();
        if (suggest.hidden) openList();
        else moveActive(1);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        e.stopPropagation();
        if (suggest.hidden) openList();
        else moveActive(-1);
        return;
      }
      if (e.key === 'Enter') {
        var active = suggest.querySelector('button.is-active');
        var first = suggest.querySelector('button[data-id]');
        if (!suggest.hidden && (active || first)) {
          e.preventDefault();
          e.stopPropagation();
          (active || first).click();
        }
      }
    });

    var openBtn = document.getElementById('imv-item-open');
    if (openBtn) {
      openBtn.addEventListener('click', function (e) {
        e.preventDefault();
        search.focus();
        openList();
      });
    }

    document.addEventListener('mousedown', function (e) {
      if (e.target === search || e.target === openBtn) return;
      if (suggest.contains(e.target)) return;
      if (wrap && wrap.contains(e.target)) return;
      closeList();
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        if (!(Number(itemId.value) > 0)) {
          e.preventDefault();
          openList();
          search.focus();
          if (window.HypexUI && window.HypexUI.alert) {
            window.HypexUI.alert('اختر المادة من القائمة.', 'error');
          } else {
            alert('اختر المادة من القائمة.');
          }
        }
      });
    }
  });
})();
