/**
 * تنقل أرقام السندات: السهم الأيمن (›) = أحدث/آخر سند عند فتح نموذج جديد فارغ.
 * يُستخدم مع .sales-inv-no-nav { direction: ltr; } في sales-invoice.css
 */
(function (global) {
  'use strict';

  function showMessage(msg, type) {
    if (global.AppDialog) {
      if (type === 'error') {
        AppDialog.error(msg);
      } else {
        AppDialog.alert(msg, { type: type || 'info' });
      }
    } else {
      global.alert(msg);
    }
  }

  /**
   * @param {string} prevBtnId
   * @param {string} nextBtnId
   * @param {number} prevId
   * @param {number} nextId
   * @param {{ onEmpty?: boolean, prevTitle?: string, nextTitle?: string, prevBeforeLatestTitle?: string, latestTitle?: string }} titles
   */
  function updateButtons(prevBtnId, nextBtnId, prevId, nextId, titles) {
    titles = titles || {};
    var onEmpty = titles.onEmpty === true;
    var prevBtn = document.getElementById(prevBtnId);
    var nextBtn = document.getElementById(nextBtnId);
    if (prevBtn) {
      prevBtn.disabled = !(prevId > 0);
      prevBtn.title = onEmpty && prevId > 0
        ? titles.prevBeforeLatestTitle || 'السابق قبل الأخير'
        : titles.prevTitle || 'السابق';
    }
    if (nextBtn) {
      nextBtn.disabled = !(nextId > 0);
      nextBtn.title = onEmpty && nextId > 0
        ? titles.latestTitle || 'آخر سند'
        : titles.nextTitle || 'التالي';
    }
  }

  /**
   * @param {'prev'|'next'} dir
   * @param {{
   *   browseNavPrevId?: number,
   *   browseNavNextId?: number,
   *   fetchById: function(number): Promise,
   *   fetchLatest: function(): Promise,
   *   isOk: function(*): boolean,
   *   getPayload: function(*): *,
   *   apply: function(*): void,
   *   emptyMessage?: string,
   *   loadLatestError?: string,
   *   loadError?: string
   * }} opts
   */
  function navigateEmpty(dir, opts) {
    var browsePrev = opts.browseNavPrevId || 0;
    var browseNext = opts.browseNavNextId || 0;

    function tryApply(data) {
      if (opts.isOk(data)) {
        opts.apply(opts.getPayload(data));
        return true;
      }
      return false;
    }

    if (dir === 'next') {
      var latestId = browseNext;
      if (latestId > 0) {
        return opts.fetchById(latestId).then(function (data) {
          if (tryApply(data)) return;
          return opts.fetchLatest().then(function (fallback) {
            if (tryApply(fallback)) return;
            showMessage((data && data.message) || opts.loadLatestError || 'تعذر التحميل.', 'error');
          });
        });
      }
      return opts.fetchLatest().then(function (data) {
        if (tryApply(data)) return;
        showMessage(opts.emptyMessage || 'لا توجد سندات محفوظة بعد.', 'info');
      });
    }

    if (browsePrev > 0) {
      return opts.fetchById(browsePrev).then(function (data) {
        if (tryApply(data)) return;
        showMessage((data && data.message) || opts.loadError || 'تعذر التحميل.', 'error');
      });
    }
    return opts.fetchLatest().then(function (data) {
      if (tryApply(data)) return;
      showMessage(opts.emptyMessage || 'لا توجد سندات محفوظة بعد.', 'info');
    });
  }

  global.DocumentNoNav = {
    updateButtons: updateButtons,
    navigateEmpty: navigateEmpty,
  };
})(typeof window !== 'undefined' ? window : this);
