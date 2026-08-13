(function () {
  'use strict';

  /**
   * تنقّل ↑↓ و Enter في قوائم البحث المنسدلة (تقارير، سندات…).
   * @param {object} cfg
   * @param {HTMLInputElement} cfg.input
   * @param {HTMLElement} cfg.list
   * @param {string} [cfg.itemSelector]
   * @param {function(): boolean} [cfg.isOpen]
   * @param {function(): void} [cfg.ensureOpen]
   * @param {function(HTMLElement): void} cfg.onPick
   * @param {function(KeyboardEvent): void} [cfg.onEscape]
   */
  function bindSearchDropdown(cfg) {
    if (!cfg || !cfg.input || !cfg.list || typeof cfg.onPick !== 'function') {
      return { reset: function () {} };
    }

    var activeIndex = -1;
    var itemSelector =
      cfg.itemSelector ||
      '.report-cust-pick-item[data-id], button.report-cust-pick-item[data-id], button.report-cust-pick-opt[data-id]';

    function getButtons() {
      return Array.prototype.slice.call(cfg.list.querySelectorAll(itemSelector));
    }

    function isListOpen() {
      if (typeof cfg.isOpen === 'function') return cfg.isOpen();
      return !cfg.list.hidden;
    }

    function highlight() {
      var buttons = getButtons();
      buttons.forEach(function (btn, i) {
        btn.classList.toggle('is-active', i === activeIndex);
      });
      if (activeIndex >= 0 && buttons[activeIndex]) {
        buttons[activeIndex].scrollIntoView({ block: 'nearest' });
      }
    }

    function reset() {
      activeIndex = -1;
      highlight();
    }

    cfg.input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (typeof cfg.onEscape === 'function') cfg.onEscape(e);
        return;
      }

      var buttons = getButtons();

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!isListOpen() && typeof cfg.ensureOpen === 'function') cfg.ensureOpen();
        buttons = getButtons();
        if (!buttons.length) return;
        activeIndex = activeIndex < buttons.length - 1 ? activeIndex + 1 : 0;
        highlight();
        return;
      }

      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isListOpen() && typeof cfg.ensureOpen === 'function') cfg.ensureOpen();
        buttons = getButtons();
        if (!buttons.length) return;
        activeIndex = activeIndex > 0 ? activeIndex - 1 : buttons.length - 1;
        highlight();
        return;
      }

      if (e.key === 'Enter') {
        if (!isListOpen()) return;
        buttons = getButtons();
        if (!buttons.length) return;
        e.preventDefault();
        var pick =
          activeIndex >= 0 && buttons[activeIndex] ? buttons[activeIndex] : buttons[0];
        if (pick) cfg.onPick(pick);
      }
    });

    return { reset: reset };
  }

  /** تحسين قوائم &lt;select&gt; العادية: أسهم للتنقل و Enter لإغلاق القائمة. */
  function installNativeSelectKeyboard() {
    document.addEventListener('keydown', function (e) {
      var el = e.target;
      if (!el || el.tagName !== 'SELECT' || el.disabled || el.multiple) return;
      if (el.size > 1) return;

      var key = e.key;
      if (key !== 'ArrowDown' && key !== 'ArrowUp' && key !== 'Enter') return;

      var opts = el.options;
      if (!opts || !opts.length) return;

      if (key === 'Enter') {
        e.preventDefault();
        el.blur();
        if (window.HypexFieldNav && typeof window.HypexFieldNav.next === 'function') {
          window.HypexFieldNav.next(el);
        }
        return;
      }

      var dir = key === 'ArrowDown' ? 1 : -1;
      var idx = el.selectedIndex;
      if (idx < 0) idx = 0;

      var next = idx + dir;
      while (next >= 0 && next < opts.length) {
        if (!opts[next].disabled) {
          if (next !== el.selectedIndex) {
            e.preventDefault();
            el.selectedIndex = next;
            try {
              el.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (err) {}
          }
          return;
        }
        next += dir;
      }
    });
  }

  /**
   * منتقي منبثق: حقل بحث + قائمة نتائج (مردودات، إلخ).
   * @param {object} cfg
   * @param {HTMLInputElement} cfg.search
   * @param {HTMLElement} cfg.results
   * @param {function(): boolean} cfg.isOpen
   * @param {function(HTMLElement): void} cfg.onPick
   * @param {string} [cfg.itemSelector]
   */
  function bindModalSearchList(cfg) {
    if (!cfg || !cfg.search || !cfg.results || typeof cfg.onPick !== 'function') {
      return { reset: function () {} };
    }

    var activeIndex = -1;
    var itemSelector = cfg.itemSelector || 'button.sales-inv-pick-item';

    function getRows() {
      return Array.prototype.slice.call(cfg.results.querySelectorAll(itemSelector));
    }

    function highlight() {
      var rows = getRows();
      rows.forEach(function (row, i) {
        row.classList.toggle('is-active', i === activeIndex);
      });
      if (activeIndex >= 0 && rows[activeIndex]) {
        rows[activeIndex].scrollIntoView({ block: 'nearest' });
      }
    }

    function reset() {
      activeIndex = -1;
      highlight();
    }

    cfg.search.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') return;
      if (typeof cfg.isOpen === 'function' && !cfg.isOpen()) return;

      var rows = getRows();
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!rows.length) return;
        activeIndex = activeIndex < rows.length - 1 ? activeIndex + 1 : 0;
        highlight();
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!rows.length) return;
        activeIndex = activeIndex > 0 ? activeIndex - 1 : rows.length - 1;
        highlight();
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (!rows.length) return;
        var pick =
          activeIndex >= 0 && rows[activeIndex] ? rows[activeIndex] : rows[0];
        if (pick) cfg.onPick(pick);
      }
    });

    return { reset: reset };
  }

  window.AppListKeyboard = {
    bindSearchDropdown: bindSearchDropdown,
    bindModalSearchList: bindModalSearchList,
    installNativeSelectKeyboard: installNativeSelectKeyboard,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installNativeSelectKeyboard);
  } else {
    installNativeSelectKeyboard();
  }
})();
