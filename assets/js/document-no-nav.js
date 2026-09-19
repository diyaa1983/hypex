/**
 * تنقل أرقام المستندات في النموذج الفارغ:
 * ‹ (prev) = آخر مستند محفوظ، › (next) = الذي قبل الأخير (أو الأقدم إن وُجد).
 * يدعم البحث الجزئي بالرقم والتنقل بين النتائج بالأسهم.
 * يُستخدم مع .sales-inv-no-nav { direction: ltr; } في sales-invoice.css
 */
(function (global) {
  'use strict';

  var DOC_NO_KEYS = [
    'invoice_no',
    'voucher_no',
    'return_no',
    'delivery_no',
    'move_no',
    'order_no',
    'take_no',
    'adj_no',
    'note_no',
    'stocktake_no',
    'entry_no',
  ];

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

  function payloadDocNo(payload) {
    if (!payload) return '';
    var i;
    for (i = 0; i < DOC_NO_KEYS.length; i++) {
      var val = payload[DOC_NO_KEYS[i]];
      if (val != null && String(val).trim() !== '') {
        return String(val).trim();
      }
    }
    return '';
  }

  function createSearchState() {
    return {
      matchIds: [],
      matchIndex: -1,
      query: '',
      currentDocNo: '',
    };
  }

  function clearSearch(state) {
    if (!state) return;
    state.matchIds = [];
    state.matchIndex = -1;
    state.query = '';
    state.currentDocNo = '';
  }

  function isSearchActive(state) {
    return !!(state && state.matchIds && state.matchIds.length > 1);
  }

  function searchNavIds(state) {
    var prevId = 0;
    var nextId = 0;
    if (!isSearchActive(state)) {
      return { prevId: prevId, nextId: nextId };
    }
    if (state.matchIndex > 0) {
      prevId = state.matchIds[state.matchIndex - 1] || 0;
    }
    if (state.matchIndex >= 0 && state.matchIndex < state.matchIds.length - 1) {
      nextId = state.matchIds[state.matchIndex + 1] || 0;
    }
    return { prevId: prevId, nextId: nextId };
  }

  function updateSearchHint(state, ui) {
    ui = ui || {};
    var noInput = ui.noInputId ? document.getElementById(ui.noInputId) : null;
    var prevBtn = ui.prevBtnId ? document.getElementById(ui.prevBtnId) : null;
    var nextBtn = ui.nextBtnId ? document.getElementById(ui.nextBtnId) : null;
    if (!noInput) return;

    if (isSearchActive(state)) {
      var pos = state.matchIndex + 1;
      noInput.title =
        'نتيجة ' +
        pos +
        ' من ' +
        state.matchIds.length +
        ' (بحث: «' +
        state.query +
        '») — استخدم الأسهم للتنقل بين النتائج';
      if (prevBtn) {
        prevBtn.title = state.matchIndex > 0 ? 'نتيجة البحث السابقة' : 'أول نتيجة';
      }
      if (nextBtn) {
        nextBtn.title =
          state.matchIndex < state.matchIds.length - 1
            ? 'نتيجة البحث التالية'
            : 'آخر نتيجة';
      }
      return;
    }

    noInput.title = ui.defaultNoTitle || 'اكتب جزءاً من رقم المستند واضغط Enter للبحث';
  }

  /**
   * @param {*} state
   * @param {*} payload
   * @param {function(number, number): void} setBrowseNavFn
   * @param {{ noInputId?: string, prevBtnId?: string, nextBtnId?: string, defaultNoTitle?: string }} ui
   */
  function applyBrowseNav(state, payload, setBrowseNavFn, ui) {
    ui = ui || {};
    var docId = parseInt(payload && payload.id, 10) || 0;
    state.currentDocNo = payloadDocNo(payload);

    if (
      payload &&
      payload.search_match_count > 1 &&
      payload.search_match_ids &&
      payload.search_match_ids.length > 1
    ) {
      state.matchIds = payload.search_match_ids
        .map(function (id) {
          return parseInt(id, 10) || 0;
        })
        .filter(function (id) {
          return id > 0;
        });
      state.matchIndex = parseInt(payload.search_match_index, 10);
      if (isNaN(state.matchIndex) || state.matchIndex < 0) {
        state.matchIndex = 0;
      }
      state.query = String(payload.search_query || '').trim();
      var nav = searchNavIds(state);
      setBrowseNavFn(nav.prevId, nav.nextId);
      updateSearchHint(state, ui);
      return;
    }

    if (isSearchActive(state) && state.query && state.matchIds.indexOf(docId) >= 0) {
      state.matchIndex = state.matchIds.indexOf(docId);
      nav = searchNavIds(state);
      setBrowseNavFn(nav.prevId, nav.nextId);
      updateSearchHint(state, ui);
      return;
    }

    clearSearch(state);
    setBrowseNavFn((payload && payload.prev_id) || 0, (payload && payload.next_id) || 0);
    updateSearchHint(state, ui);
  }

  function shouldSkipBlurSearch(state, currentId, fieldValue) {
    var no = String(fieldValue || '').trim();
    if (!no) return true;
    if (isSearchActive(state)) return true;
    if (currentId > 0 && no === String(state.currentDocNo || '').trim()) return true;
    return false;
  }

  /**
   * @param {'prev'|'next'} dir
   * @param {*} state
   * @param {{
   *   fetchById: function(number): Promise,
   *   apply: function(*): void,
   *   isOk?: function(*): boolean,
   *   getPayload?: function(*): *,
   *   loadError?: string,
   *   edgePrevMessage?: string,
   *   edgeNextMessage?: string
   * }} opts
   */
  function navigateSearchMatch(dir, state, opts) {
    if (!isSearchActive(state)) return Promise.resolve(false);

    var newIndex = dir === 'prev' ? state.matchIndex - 1 : state.matchIndex + 1;
    if (newIndex < 0 || newIndex >= state.matchIds.length) {
      showMessage(
        dir === 'prev'
          ? opts.edgePrevMessage || 'أول نتيجة في البحث.'
          : opts.edgeNextMessage || 'آخر نتيجة في البحث.',
        'info'
      );
      return Promise.resolve(true);
    }

    var targetId = state.matchIds[newIndex];
    var isOk =
      opts.isOk ||
      function (data) {
        return !!data;
      };
    var getPayload =
      opts.getPayload ||
      function (data) {
        return data;
      };

    return opts.fetchById(targetId).then(function (data) {
      if (!isOk(data)) {
        showMessage((data && data.message) || opts.loadError || 'تعذر التحميل.', 'error');
        return true;
      }
      opts.apply(getPayload(data));
      return true;
    });
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
      var prevEnabled = onEmpty ? nextId > 0 : prevId > 0;
      prevBtn.disabled = !prevEnabled;
      prevBtn.title =
        onEmpty && nextId > 0
          ? titles.latestTitle || 'آخر سند'
          : titles.prevTitle || 'السابق';
    }
    if (nextBtn) {
      var nextEnabled = onEmpty ? prevId > 0 : nextId > 0;
      nextBtn.disabled = !nextEnabled;
      nextBtn.title =
        onEmpty && prevId > 0
          ? titles.prevBeforeLatestTitle || 'السابق قبل الأخير'
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

    // في النموذج الفارغ: ‹ (prev) = آخر مستند (browseNext)، › (next) = السابق/الأقدم (browsePrev)
    if (dir === 'prev') {
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
    createSearchState: createSearchState,
    clearSearch: clearSearch,
    isSearchActive: isSearchActive,
    applyBrowseNav: applyBrowseNav,
    shouldSkipBlurSearch: shouldSkipBlurSearch,
    navigateSearchMatch: navigateSearchMatch,
    updateButtons: updateButtons,
    navigateEmpty: navigateEmpty,
    payloadDocNo: payloadDocNo,
  };
})(typeof window !== 'undefined' ? window : this);
