(function (global) {
  'use strict';

  var cfg = null;
  var getVoucherId = null;
  var getVoucherLabel = null;
  var isArchiveAllowed = null;
  var modal = null;
  var viewerEl = null;
  var sidebarEl = null;
  var viewerBodyEl = null;
  var stageEl = null;
  var warnEl = null;
  var fileInput = null;
  var uploadBtn = null;
  var sizeToggleBtn = null;
  var titleEl = null;
  var readOnlyMode = false;
  var viewerExpanded = false;
  var archiveFileCount = 0;
  var metaVoucherId = 0;
  var metaPromise = null;
  var currentFiles = [];
  var activeFileIndex = -1;

  function esc(text) {
    var d = document.createElement('div');
    d.textContent = text == null ? '' : String(text);
    return d.innerHTML;
  }

  function formatSize(bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(2) + ' MB';
  }

  function alertMsg(message, type) {
    if (global.AppDialog && AppDialog.alert) {
      return AppDialog.alert(message, { type: type || 'info', theme: 'oracle' });
    }
    global.alert(message);
    return Promise.resolve();
  }

  function confirmMsg(message) {
    if (global.AppDialog && AppDialog.confirm) {
      return AppDialog.confirm(message, {
        title: 'تأكيد',
        okText: 'نعم',
        cancelText: 'إلغاء',
        theme: 'oracle',
      });
    }
    return Promise.resolve(global.confirm(message));
  }

  function ensureModal() {
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'fin-voucher-archive-modal fin-voucher-archive-modal--oracle12 no-print';
    modal.hidden = true;
    modal.innerHTML =
      '<div class="fin-voucher-archive-backdrop" data-archive-close></div>' +
      '<div class="fin-voucher-archive-viewer fin-voucher-archive-viewer--oracle12" role="dialog" aria-modal="true" aria-labelledby="fin-voucher-archive-title">' +
      '<header class="fin-voucher-archive-viewer-head">' +
      '<h2 id="fin-voucher-archive-title" class="fin-voucher-archive-viewer-title"></h2>' +
      '<div class="fin-voucher-archive-head-actions">' +
      '<button type="button" class="fin-voucher-archive-size-toggle" aria-label="تكبير" title="تكبير">□</button>' +
      '<button type="button" class="fin-voucher-archive-close" data-archive-close aria-label="إغلاق">×</button>' +
      '</div>' +
      '</header>' +
      '<div class="fin-voucher-archive-viewer-toolbar">' +
      '<button type="button" class="fin-voucher-archive-ora-btn fin-voucher-archive-tool-print" title="طباعة">طباعة</button>' +
      '<a class="fin-voucher-archive-ora-btn fin-voucher-archive-tool-download" href="#" download title="تحميل">تحميل</a>' +
      '</div>' +
      '<div class="fin-voucher-archive-viewer-body">' +
      '<aside class="fin-voucher-archive-sidebar">' +
      '<div class="fin-voucher-archive-warn" hidden></div>' +
      '<div class="fin-voucher-archive-upload">' +
      '<p class="fin-voucher-archive-upload-label">رفع مرفق جديد</p>' +
      '<input type="file" class="fin-voucher-archive-file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp">' +
      '<button type="button" class="fin-voucher-archive-ora-btn fin-voucher-archive-upload-btn">رفع ملف</button>' +
      '</div>' +
      '<div class="fin-voucher-archive-sidebar-head">المرفقات</div>' +
      '<ul class="fin-voucher-archive-file-list"></ul>' +
      '</aside>' +
      '<div class="fin-voucher-archive-stage"></div>' +
      '</div>' +
      '<footer class="fin-voucher-archive-viewer-foot">' +
      '<button type="button" class="fin-voucher-archive-ora-btn fin-voucher-archive-foot-close" data-archive-close>إغلاق</button>' +
      '</footer>' +
      '</div>';
    document.body.appendChild(modal);

    viewerEl = modal.querySelector('.fin-voucher-archive-viewer');
    sidebarEl = modal.querySelector('.fin-voucher-archive-file-list');
    viewerBodyEl = modal.querySelector('.fin-voucher-archive-viewer-body');
    stageEl = modal.querySelector('.fin-voucher-archive-stage');
    warnEl = modal.querySelector('.fin-voucher-archive-warn');
    fileInput = modal.querySelector('.fin-voucher-archive-file');
    uploadBtn = modal.querySelector('.fin-voucher-archive-upload-btn');
    sizeToggleBtn = modal.querySelector('.fin-voucher-archive-size-toggle');
    titleEl = modal.querySelector('#fin-voucher-archive-title');

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-archive-close]')) {
        close();
      }
    });

    sizeToggleBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleViewerSize();
    });

    uploadBtn.addEventListener('click', function () {
      if (readOnlyMode || uploadBtn.disabled) return;
      if (fileInput) fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      if (!fileInput.files || !fileInput.files[0]) return;
      uploadSelected();
    });

    modal.querySelector('.fin-voucher-archive-tool-print').addEventListener('click', function () {
      printCurrent();
    });
  }

  function currentId() {
    if (typeof getVoucherId === 'function') {
      return parseInt(String(getVoucherId()), 10) || 0;
    }
    return 0;
  }

  function buildViewerTitle(readOnly) {
    var label = typeof getVoucherLabel === 'function' ? getVoucherLabel() || {} : {};
    var no = String(label.no || '').trim();
    var date = String(label.date || '').trim();
    var company = String(cfg.companyName || '').trim();
    var parts = ['مشاهدة الأرشيف —'];
    if (cfg.title) parts.push(cfg.title);
    if (no) parts.push(no);
    if (company) parts.push(company);
    var head = parts.join(' ').replace(/\s+/g, ' ').trim();
    if (date) head += ' | ' + date;
    if (readOnly) head += ' (عرض فقط)';
    return head;
  }

  function updateBodyLock() {
    var lock = modal && !modal.hidden;
    document.body.classList.toggle('fin-voucher-archive-open', lock);
  }

  function archiveState() {
    var id = currentId();
    if (typeof isArchiveAllowed === 'function') {
      return isArchiveAllowed(id) || { allowed: false, reason: 'archive_blocked' };
    }
    if (id < 1) {
      return { allowed: false, reason: 'not_saved' };
    }
    return { allowed: true, readOnly: false, reason: '' };
  }

  function resolveArchiveAccess(state) {
    if (!state || !state.allowed) {
      return state || { allowed: false, reason: 'archive_blocked' };
    }
    if (state.readOnly) {
      if (archiveFileCount < 1) {
        return {
          allowed: false,
          readOnly: true,
          reason: 'posted_no_files',
          message: 'لا توجد مرفقات — الرفع متاح قبل الترحيل فقط',
        };
      }
      return { allowed: true, readOnly: true, reason: '' };
    }
    return state;
  }

  function effectiveArchiveAccess() {
    return resolveArchiveAccess(archiveState());
  }

  function archiveBlockMessage(state) {
    if (state && state.message) return state.message;
    switch (state && state.reason) {
      case 'not_saved':
        return 'احفظ السند أولاً ثم افتح الأرشيف';
      case 'posted_no_files':
        return 'لا توجد مرفقات — الرفع متاح قبل الترحيل فقط';
      case 'cancelled':
        return 'لا يمكن استخدام الأرشيف على سند ملغى';
      default:
        return 'لا يمكن استخدام الأرشيف في هذه الحالة';
    }
  }

  function archiveToolbarTitle(state) {
    if (!state || !state.allowed) return archiveBlockMessage(state);
    if (state.readOnly) {
      return 'عرض المرفقات (' + archiveFileCount + ') — قراءة فقط';
    }
    if (archiveFileCount > 0) {
      return 'مشاهدة وإدارة المرفقات (' + archiveFileCount + ')';
    }
    return 'أرشيف المرفقات — رفع قبل الترحيل';
  }

  function setArchiveFileCount(count) {
    archiveFileCount = Math.max(0, parseInt(String(count), 10) || 0);
  }

  function updateViewerLayout() {
    ensureModal();
    if (!viewerBodyEl) return;
    viewerBodyEl.classList.toggle(
      'is-single-file',
      readOnlyMode && currentFiles.length === 1
    );
  }

  function setViewerExpanded(expanded) {
    ensureModal();
    viewerExpanded = !!expanded;
    modal.classList.toggle('is-viewer-expanded', viewerExpanded);
    if (viewerEl) viewerEl.classList.toggle('is-expanded', viewerExpanded);
    if (sizeToggleBtn) {
      sizeToggleBtn.textContent = viewerExpanded ? '❐' : '□';
      sizeToggleBtn.setAttribute('aria-label', viewerExpanded ? 'تصغير' : 'تكبير');
      sizeToggleBtn.title = viewerExpanded ? 'تصغير' : 'تكبير';
    }
  }

  function toggleViewerSize() {
    setViewerExpanded(!viewerExpanded);
  }

  function applyReadOnlyMode(readOnly) {
    readOnlyMode = !!readOnly;
    ensureModal();
    var uploadWrap = modal.querySelector('.fin-voucher-archive-upload');
    if (uploadWrap) {
      uploadWrap.hidden = readOnlyMode;
      uploadWrap.classList.toggle('fin-voucher-archive-upload--hidden', readOnlyMode);
    }
    if (titleEl) {
      titleEl.textContent = buildViewerTitle(readOnlyMode);
    }
    updateViewerLayout();
  }

  function applyToolbarArchiveUi() {
    if (!cfg || !cfg.canArchive) return;
    var btn = document.querySelector('#master-toolbar [data-master-action="archive"]');
    if (!btn) return;
    var state = effectiveArchiveAccess();
    btn.disabled = !state.allowed;
    btn.title = archiveToolbarTitle(state);
    btn.classList.toggle('fin-voucher-archive-has-files', archiveFileCount > 0 && state.allowed);
    btn.classList.toggle('fin-voucher-archive-read-only', !!state.readOnly && state.allowed);
  }

  function refreshArchiveMeta(force) {
    var id = currentId();
    if (!cfg || !cfg.canArchive || id < 1) {
      metaVoucherId = 0;
      metaPromise = null;
      setArchiveFileCount(0);
      applyToolbarArchiveUi();
      return Promise.resolve(0);
    }
    var baseState = archiveState();
    if (!baseState.allowed) {
      metaVoucherId = 0;
      metaPromise = null;
      setArchiveFileCount(0);
      applyToolbarArchiveUi();
      return Promise.resolve(0);
    }
    if (!force && metaVoucherId === id && metaPromise) {
      return metaPromise;
    }
    metaVoucherId = id;
    var url =
      cfg.apiUrl +
      '?action=meta&kind=' +
      encodeURIComponent(cfg.kind) +
      '&voucher_id=' +
      encodeURIComponent(String(id));
    metaPromise = fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        setArchiveFileCount(data && data.ok ? data.file_count : 0);
        applyToolbarArchiveUi();
        return archiveFileCount;
      })
      .catch(function () {
        setArchiveFileCount(0);
        applyToolbarArchiveUi();
        return 0;
      });
    return metaPromise;
  }

  function syncToolbar() {
    refreshArchiveMeta(true);
  }

  function getActiveFile() {
    if (activeFileIndex < 0 || activeFileIndex >= currentFiles.length) return null;
    return currentFiles[activeFileIndex];
  }

  function updateToolLinks() {
    ensureModal();
    var file = getActiveFile();
    var dl = modal.querySelector('.fin-voucher-archive-tool-download');
    var printBtn = modal.querySelector('.fin-voucher-archive-tool-print');
    if (dl) {
      if (file && file.download_url) {
        dl.href = file.download_url;
        dl.setAttribute('download', file.name || '');
        dl.classList.remove('is-disabled');
      } else {
        dl.href = '#';
        dl.removeAttribute('download');
        dl.classList.add('is-disabled');
      }
    }
    if (printBtn) {
      var canPrint = !!(file && (file.preview_kind === 'pdf' || file.preview_kind === 'image'));
      printBtn.disabled = !canPrint;
      printBtn.classList.toggle('is-disabled', !canPrint);
    }
  }

  function renderStage(file) {
    ensureModal();
    stageEl.innerHTML = '';
    if (!file) {
      stageEl.innerHTML =
        '<div class="fin-voucher-archive-stage-empty">' +
        '<p>اختر مرفقاً من القائمة أو ارفع ملفاً جديداً.</p>' +
        '</div>';
      updateToolLinks();
      return;
    }

    if (file.preview_kind === 'pdf') {
      var frame = document.createElement('iframe');
      frame.className = 'fin-voucher-archive-frame';
      frame.title = file.name || 'مرفق';
      frame.src = file.view_url || file.download_url;
      stageEl.appendChild(frame);
    } else if (file.preview_kind === 'image') {
      var imgWrap = document.createElement('div');
      imgWrap.className = 'fin-voucher-archive-image-wrap';
      var img = document.createElement('img');
      img.className = 'fin-voucher-archive-image';
      img.alt = file.name || 'مرفق';
      img.src = file.view_url || file.download_url;
      imgWrap.appendChild(img);
      stageEl.appendChild(imgWrap);
    } else {
      stageEl.innerHTML =
        '<div class="fin-voucher-archive-stage-fallback">' +
        '<p class="fin-voucher-archive-stage-fallback-name">' +
        esc(file.name) +
        '</p>' +
        '<p class="fin-voucher-archive-stage-fallback-note">لا يمكن معاينة هذا النوع داخل المتصفح.</p>' +
        '<a class="fin-voucher-archive-ora-btn" href="' +
        esc(file.download_url) +
        '" download="' +
        esc(file.name) +
        '">تحميل الملف</a>' +
        '</div>';
    }
    updateToolLinks();
  }

  function selectFile(index) {
    if (!currentFiles.length) {
      activeFileIndex = -1;
      renderStage(null);
      renderSidebar();
      return;
    }
    if (index < 0) index = 0;
    if (index >= currentFiles.length) index = currentFiles.length - 1;
    activeFileIndex = index;
    renderSidebar();
    renderStage(getActiveFile());
  }

  function renderSidebar() {
    ensureModal();
    sidebarEl.innerHTML = '';
    if (!currentFiles.length) {
      var empty = document.createElement('li');
      empty.className = 'fin-voucher-archive-file-empty';
      empty.textContent = readOnlyMode ? 'لا توجد مرفقات.' : 'لا توجد مرفقات بعد — ارفع ملفاً.';
      sidebarEl.appendChild(empty);
      return;
    }
    currentFiles.forEach(function (file, idx) {
      var li = document.createElement('li');
      li.className = 'fin-voucher-archive-file-item';
      if (idx === activeFileIndex) li.classList.add('is-active');
      li.innerHTML =
        '<button type="button" class="fin-voucher-archive-file-btn" data-file-index="' +
        idx +
        '">' +
        '<span class="fin-voucher-archive-file-btn-name">' +
        esc(file.name) +
        '</span>' +
        '<span class="fin-voucher-archive-file-btn-meta">' +
        esc(formatSize(file.size)) +
        '</span>' +
        '</button>' +
        (readOnlyMode
          ? ''
          : '<button type="button" class="fin-voucher-archive-file-del" data-archive-delete="' +
            esc(String(file.id)) +
            '" title="حذف">×</button>');
      sidebarEl.appendChild(li);
    });

    sidebarEl.querySelectorAll('.fin-voucher-archive-file-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        selectFile(parseInt(btn.getAttribute('data-file-index'), 10) || 0);
      });
    });

    if (!readOnlyMode) {
      sidebarEl.querySelectorAll('[data-archive-delete]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          deleteFile(parseInt(btn.getAttribute('data-archive-delete'), 10) || 0);
        });
      });
    }
    updateViewerLayout();
  }

  function loadList(selectIndex) {
    var id = currentId();
    if (!cfg || id < 1) return Promise.resolve();
    var state = archiveState();
    var url =
      cfg.apiUrl +
      '?action=list&kind=' +
      encodeURIComponent(cfg.kind) +
      '&voucher_id=' +
      encodeURIComponent(String(id));
    return fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر تحميل الأرشيف.');
        }
        var ro = data.read_only === true || state.readOnly === true;
        currentFiles = data.files || [];
        setArchiveFileCount(
          typeof data.file_count === 'number' ? data.file_count : currentFiles.length
        );
        applyReadOnlyMode(ro);
        applyToolbarArchiveUi();

        if (data.path_issue && !ro) {
          warnEl.hidden = false;
          warnEl.textContent = data.path_issue + ' — راجع الإعدادات.';
          uploadBtn.disabled = true;
          if (fileInput) fileInput.disabled = true;
        } else {
          warnEl.hidden = true;
          warnEl.textContent = '';
          if (!ro) {
            uploadBtn.disabled = false;
            if (fileInput) fileInput.disabled = false;
          }
        }

        if (typeof selectIndex === 'number') {
          selectFile(selectIndex);
        } else if (currentFiles.length) {
          selectFile(activeFileIndex >= 0 ? activeFileIndex : 0);
        } else {
          selectFile(-1);
        }
      });
  }

  function printCurrent() {
    var file = getActiveFile();
    if (!file) return;
    var frame = stageEl.querySelector('.fin-voucher-archive-frame');
    if (frame && frame.contentWindow) {
      try {
        frame.contentWindow.focus();
        frame.contentWindow.print();
        return;
      } catch (e) {
        /* fallback below */
      }
    }
    if (file.view_url) {
      global.open(file.view_url, '_blank');
    }
  }

  function open() {
    if (!cfg || !cfg.canArchive) {
      alertMsg('لا تملك صلاحية الأرشيف.', 'error');
      return;
    }
    refreshArchiveMeta(true).then(function () {
      var state = effectiveArchiveAccess();
      if (!state.allowed) {
        alertMsg(archiveBlockMessage(state), 'warning');
        return;
      }
      var id = currentId();
      if (id < 1) {
        alertMsg('احفظ السند أولاً ثم افتح الأرشيف.', 'warning');
        return;
      }
      ensureModal();
      if (!modal.hidden) {
        return;
      }
      setViewerExpanded(false);
      activeFileIndex = -1;
      currentFiles = [];
      applyReadOnlyMode(state.readOnly);
      modal.hidden = false;
      if (fileInput) fileInput.value = '';
      updateBodyLock();
      loadList(0).catch(function (err) {
        alertMsg(err.message || 'تعذر فتح الأرشيف.', 'error');
      });
    });
  }

  function close() {
    if (modal) {
      modal.hidden = true;
      if (stageEl) stageEl.innerHTML = '';
      setViewerExpanded(false);
    }
    updateBodyLock();
  }

  function uploadSelected() {
    if (readOnlyMode) {
      alertMsg('لا يمكن رفع ملفات بعد ترحيل السند.', 'warning');
      return;
    }
    if (!cfg || !fileInput || !fileInput.files || !fileInput.files[0]) {
      return;
    }
    var id = currentId();
    if (id < 1) return;
    var selectedFile = fileInput.files[0];
    var body = new FormData();
    body.append('_csrf', cfg.csrf);
    body.append('action', 'upload');
    body.append('kind', cfg.kind);
    body.append('voucher_id', String(id));
    body.append('file', selectedFile);
    uploadBtn.disabled = true;
    if (fileInput) fileInput.disabled = true;
    fetch(cfg.apiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر رفع الملف.');
        }
        fileInput.value = '';
        return loadList(0);
      })
      .then(function () {
        alertMsg('تم حفظ الملف في الأرشيف.', 'success');
      })
      .catch(function (err) {
        alertMsg(err.message || 'تعذر رفع الملف.', 'error');
      })
      .finally(function () {
        uploadBtn.disabled = false;
        if (fileInput) fileInput.disabled = false;
      });
  }

  function deleteFile(docId) {
    if (readOnlyMode) {
      alertMsg('لا يمكن حذف الملفات بعد ترحيل السند.', 'warning');
      return;
    }
    if (docId < 1) return;
    confirmMsg('حذف هذا الملف من الأرشيف؟').then(function (ok) {
      if (!ok) return;
      var body = new FormData();
      body.append('_csrf', cfg.csrf);
      body.append('action', 'delete');
      body.append('kind', cfg.kind);
      body.append('id', String(docId));
      fetch(cfg.apiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            throw new Error((data && data.message) || 'تعذر حذف الملف.');
          }
          activeFileIndex = 0;
          return loadList(0);
        })
        .catch(function (err) {
          alertMsg(err.message || 'تعذر حذف الملف.', 'error');
        });
    });
  }

  function init(config) {
    cfg = config || {};
    getVoucherId = cfg.getVoucherId || null;
    getVoucherLabel = cfg.getVoucherLabel || null;
    isArchiveAllowed = cfg.isArchiveAllowed || null;
    cfg.companyName = cfg.companyName || '';
    metaVoucherId = 0;
    metaPromise = null;
    setArchiveFileCount(0);
    ensureModal();
    syncToolbar();
  }

  function handleToolbarEvent(e) {
    if (!e.detail || e.detail.action !== 'archive') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    open();
  }

  document.addEventListener('master-toolbar', handleToolbarEvent);

  global.FinVoucherArchive = {
    init: init,
    open: open,
    close: close,
    syncToolbar: syncToolbar,
    refreshMeta: refreshArchiveMeta,
  };
})(window);
