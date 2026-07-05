(function (global) {
  'use strict';

  /**
   * تصوير الطلبية من الهاتف وحفظها في أرشيف fin_voucher_archive.
   *
   * cfg: {
   *   apiUrl, csrf, kind (default sales_invoice),
   *   getInvoiceId: function(): number,
   *   isLocked: function(): boolean,
   *   onUploaded: function(fileMeta),
   *   onPending: function(hasPending)
   * }
   */
  function InvoicePhotoArchive(cfg) {
    this.cfg = cfg || {};
    this.pendingFile = null;
    this.busy = false;
    this.input = null;
    this.viewerRoot = null;
    this.archiveFiles = [];
    this.archiveReadOnly = false;
    this.fileCount = 0;
  }

  InvoicePhotoArchive.prototype.absUrl = function (url) {
    url = String(url || '').trim();
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    var base = global.AppMobile && AppMobile.baseUrl ? String(AppMobile.baseUrl) : '';
    if (!base) return url;
    return base.replace(/\/$/, '') + (url.charAt(0) === '/' ? url : '/' + url);
  };

  InvoicePhotoArchive.prototype.esc = function (text) {
    var d = document.createElement('div');
    d.textContent = text == null ? '' : String(text);
    return d.innerHTML;
  };

  InvoicePhotoArchive.prototype.formatSize = function (bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(2) + ' MB';
  };

  InvoicePhotoArchive.prototype.getInvoiceLabel = function () {
    if (typeof this.cfg.getInvoiceLabel === 'function') {
      var lbl = String(this.cfg.getInvoiceLabel() || '').trim();
      if (lbl) return lbl;
    }
    var id = this.getInvoiceId();
    return id > 0 ? 'فاتورة #' + id : 'فاتورة';
  };

  InvoicePhotoArchive.prototype.updateArchiveBadge = function () {
    var btn = document.getElementById('m-toolbar-archive');
    if (!btn) return;
    btn.classList.toggle('m-toolbar-btn--has-badge', this.fileCount > 0);
    var lbl = btn.querySelector('.m-toolbar-btn__lbl');
    if (lbl) {
      lbl.textContent = this.fileCount > 0 ? 'أرشيف (' + this.fileCount + ')' : 'أرشيف';
    }
  };

  InvoicePhotoArchive.prototype.refreshMeta = function () {
    var self = this;
    var id = this.getInvoiceId();
    if (id < 1 || !this.cfg.apiUrl) {
      this.fileCount = 0;
      this.updateArchiveBadge();
      return Promise.resolve(0);
    }
    var url =
      this.cfg.apiUrl +
      '?action=meta&kind=' +
      encodeURIComponent(this.cfg.kind || 'sales_invoice') +
      '&voucher_id=' +
      encodeURIComponent(String(id));
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        self.fileCount = data && data.ok ? parseInt(data.file_count, 10) || 0 : 0;
        self.updateArchiveBadge();
        return self.fileCount;
      })
      .catch(function () {
        self.fileCount = 0;
        self.updateArchiveBadge();
        return 0;
      });
  };

  InvoicePhotoArchive.prototype.ensureInput = function () {
    if (this.input) {
      return this.input;
    }
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/jpeg,image/png,image/webp,image/*';
    inp.setAttribute('capture', 'environment');
    inp.hidden = true;
    inp.setAttribute('aria-hidden', 'true');
    var self = this;
    inp.addEventListener('change', function () {
      var file = inp.files && inp.files[0];
      inp.value = '';
      if (file) {
        self.handleSelectedFile(file);
      }
    });
    document.body.appendChild(inp);
    this.input = inp;
    return inp;
  };

  InvoicePhotoArchive.prototype.alert = function (msg, type) {
    if (global.AppDialog && AppDialog.alert) {
      return AppDialog.alert(msg, { type: type || 'info' });
    }
    global.alert(msg);
    return Promise.resolve();
  };

  InvoicePhotoArchive.prototype.notifyPending = function () {
    if (typeof this.cfg.onPending === 'function') {
      this.cfg.onPending(!!this.pendingFile);
    }
  };

  InvoicePhotoArchive.prototype.getInvoiceId = function () {
    if (typeof this.cfg.getInvoiceId === 'function') {
      return parseInt(String(this.cfg.getInvoiceId()), 10) || 0;
    }
    return 0;
  };

  InvoicePhotoArchive.prototype.isLocked = function () {
    if (typeof this.cfg.isLocked === 'function') {
      return !!this.cfg.isLocked();
    }
    return false;
  };

  InvoicePhotoArchive.prototype.openCamera = function () {
    if (this.busy) {
      return;
    }
    if (this.isLocked()) {
      this.alert('لا يمكن إرفاق صور بعد ترحيل الفاتورة.', 'warning');
      return;
    }
    if (!this.cfg.apiUrl) {
      this.alert('رابط الأرشيف غير مضبوط.', 'warning');
      return;
    }
    this.ensureInput().click();
  };

  InvoicePhotoArchive.prototype.handleSelectedFile = function (file) {
    if (!file || this.isLocked()) {
      return;
    }
    var id = this.getInvoiceId();
    if (id > 0) {
      this.upload(id, file);
      return;
    }
    this.pendingFile = file;
    this.notifyPending();
    this.alert(
      'تم التقاط الصورة.\n\nاحفظ الفاتورة الآن — ستُرفع الصورة تلقائياً إلى السيرفر وتُحفظ في أرشيف الفاتورة.',
      'success'
    );
  };

  InvoicePhotoArchive.prototype.prepareUploadFile = function (file) {
    var name = String(file.name || '').trim();
    var ext = name.indexOf('.') >= 0 ? name.split('.').pop().toLowerCase() : '';
    if (!ext || ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) < 0) {
      ext = 'jpg';
      if (file.type === 'image/png') ext = 'png';
      else if (file.type === 'image/webp') ext = 'webp';
      name = 'order-photo-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.' + ext;
    }
    return { file: file, name: name };
  };

  InvoicePhotoArchive.prototype.upload = function (voucherId, file, opts) {
    opts = opts || {};
    var self = this;
    voucherId = parseInt(String(voucherId), 10) || 0;
    if (voucherId < 1 || !file || !this.cfg.apiUrl) {
      return Promise.resolve(false);
    }
    if (this.busy) {
      return Promise.resolve(false);
    }
    this.busy = true;
    var prepared = this.prepareUploadFile(file);
    var fd = new FormData();
    fd.append('_csrf', this.cfg.csrf || '');
    fd.append('action', 'upload');
    fd.append('kind', this.cfg.kind || 'sales_invoice');
    fd.append('voucher_id', String(voucherId));
    fd.append('file', prepared.file, prepared.name);

    return fetch(this.cfg.apiUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r
          .json()
          .catch(function () {
            throw new Error('تعذر قراءة رد الخادم أثناء حفظ الصورة.');
          })
          .then(function (data) {
            if (!r.ok) {
              throw new Error((data && data.message) || 'تعذر حفظ الصورة في الأرشيف.');
            }
            return data;
          });
      })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر حفظ الصورة في الأرشيف.');
        }
        if (typeof self.cfg.onUploaded === 'function') {
          self.cfg.onUploaded(data.file || null);
        }
        self.refreshMeta();
        if (self.viewerRoot && !self.viewerRoot.hidden) {
          self.loadArchiveList();
        }
        if (opts.silent) {
          return true;
        }
        return self
          .alert((data && data.message) || 'تم رفع الصورة إلى السيرفر وحفظها في أرشيف الفاتورة.', 'success')
          .then(function () {
            return true;
          });
      })
      .catch(function (err) {
        if (opts.silent) {
          return false;
        }
        return self.alert(err.message || 'تعذر حفظ الصورة.', 'error').then(function () {
          return false;
        });
      })
      .finally(function () {
        self.busy = false;
      });
  };

  InvoicePhotoArchive.prototype.flushPending = function (voucherId, opts) {
    if (!this.pendingFile) {
      return Promise.resolve(false);
    }
    voucherId = parseInt(String(voucherId), 10) || 0;
    if (voucherId < 1) {
      return Promise.resolve(false);
    }
    var file = this.pendingFile;
    this.pendingFile = null;
    this.notifyPending();
    return this.upload(voucherId, file, opts);
  };

  InvoicePhotoArchive.prototype.hasPending = function () {
    return !!this.pendingFile;
  };

  InvoicePhotoArchive.prototype.takePendingFile = function () {
    if (!this.pendingFile) {
      return null;
    }
    var prepared = this.prepareUploadFile(this.pendingFile);
    this.pendingFile = null;
    this.notifyPending();
    return prepared;
  };

  InvoicePhotoArchive.prototype.ensureViewer = function () {
    if (this.viewerRoot) return this.viewerRoot;
    var self = this;
    var root = document.createElement('div');
    root.className = 'm-inv-archive-root';
    root.hidden = true;
    root.innerHTML =
      '<div class="m-inv-archive-backdrop" data-m-archive-close></div>' +
      '<div class="m-inv-archive-panel" role="dialog" aria-modal="true">' +
      '<header class="m-inv-archive-head">' +
      '<h3 class="m-inv-archive-title"></h3>' +
      '<button type="button" class="m-inv-archive-close" data-m-archive-close aria-label="إغلاق">×</button>' +
      '</header>' +
      '<p class="m-inv-archive-sub muted">نفس أرشيف فواتير المبيعات في النظام — المسار من إعدادات الشركة</p>' +
      '<div class="m-inv-archive-loading" hidden>جاري التحميل...</div>' +
      '<div class="m-inv-archive-empty" hidden>لا توجد صور أو مرفقات في الأرشيف.</div>' +
      '<ul class="m-inv-archive-grid" role="list"></ul>' +
      '</div>' +
      '<div class="m-inv-archive-lightbox" hidden>' +
      '<button type="button" class="m-inv-archive-lightbox-close" data-m-archive-lightbox-close aria-label="إغلاق">×</button>' +
      '<img class="m-inv-archive-lightbox-img" alt="">' +
      '</div>';
    document.body.appendChild(root);
    root.querySelectorAll('[data-m-archive-close]').forEach(function (el) {
      el.addEventListener('click', function () {
        self.closeViewer();
      });
    });
    var lbClose = root.querySelector('[data-m-archive-lightbox-close]');
    if (lbClose) {
      lbClose.addEventListener('click', function () {
        var lb = root.querySelector('.m-inv-archive-lightbox');
        if (lb) lb.hidden = true;
      });
    }
    var lb = root.querySelector('.m-inv-archive-lightbox');
    if (lb) {
      lb.addEventListener('click', function (e) {
        if (e.target === lb) lb.hidden = true;
      });
    }
    this.viewerRoot = root;
    return root;
  };

  InvoicePhotoArchive.prototype.closeViewer = function () {
    if (!this.viewerRoot) return;
    this.viewerRoot.hidden = true;
    document.body.classList.remove('m-inv-archive-open');
    var lb = this.viewerRoot.querySelector('.m-inv-archive-lightbox');
    if (lb) lb.hidden = true;
  };

  InvoicePhotoArchive.prototype.openLightbox = function (viewUrl, name) {
    this.ensureViewer();
    var lb = this.viewerRoot.querySelector('.m-inv-archive-lightbox');
    var img = this.viewerRoot.querySelector('.m-inv-archive-lightbox-img');
    if (!lb || !img) return;
    img.src = this.absUrl(viewUrl);
    img.alt = name || 'مرفق';
    lb.hidden = false;
  };

  InvoicePhotoArchive.prototype.renderArchiveList = function () {
    this.ensureViewer();
    var grid = this.viewerRoot.querySelector('.m-inv-archive-grid');
    var empty = this.viewerRoot.querySelector('.m-inv-archive-empty');
    var self = this;
    if (!grid) return;
    grid.innerHTML = '';
    if (!this.archiveFiles.length) {
      if (empty) {
        empty.hidden = false;
        empty.textContent = this.archiveReadOnly
          ? 'لا توجد صور في أرشيف هذه الفاتورة.'
          : 'لا توجد صور بعد — استخدم زر «تصوير» لإضافة صورة الطلبية.';
      }
      return;
    }
    if (empty) empty.hidden = true;
    this.archiveFiles.forEach(function (file) {
      var li = document.createElement('li');
      li.className = 'm-inv-archive-item';
      var isImage = file.preview_kind === 'image';
      var viewUrl = self.absUrl(file.view_url || file.download_url || '');
      var thumbHtml = isImage
        ? '<img class="m-inv-archive-thumb" src="' + self.esc(viewUrl) + '" alt="" loading="lazy">'
        : '<span class="m-inv-archive-file-ico">📄</span>';
      li.innerHTML =
        '<button type="button" class="m-inv-archive-card" data-view-url="' +
        self.esc(viewUrl) +
        '" data-name="' +
        self.esc(file.name || '') +
        '" data-is-image="' +
        (isImage ? '1' : '0') +
        '">' +
        thumbHtml +
        '<span class="m-inv-archive-card-name">' +
        self.esc(file.name || 'مرفق') +
        '</span>' +
        '<span class="m-inv-archive-card-meta">' +
        self.esc(self.formatSize(file.size)) +
        '</span>' +
        '</button>' +
        (self.archiveReadOnly
          ? ''
          : '<button type="button" class="m-inv-archive-del" data-del-id="' +
            self.esc(String(file.id)) +
            '" title="حذف">×</button>');
      grid.appendChild(li);
    });
    grid.querySelectorAll('.m-inv-archive-card').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-view-url') || '';
        var name = btn.getAttribute('data-name') || '';
        var isImage = btn.getAttribute('data-is-image') === '1';
        if (isImage && url) {
          self.openLightbox(url, name);
        } else if (url) {
          global.open(url, '_blank', 'noopener');
        }
      });
    });
    if (!this.archiveReadOnly) {
      grid.querySelectorAll('.m-inv-archive-del').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var docId = parseInt(btn.getAttribute('data-del-id'), 10) || 0;
          if (docId < 1) return;
          var doDelete = function () {
            var fd = new FormData();
            fd.append('_csrf', self.cfg.csrf || '');
            fd.append('action', 'delete');
            fd.append('kind', self.cfg.kind || 'sales_invoice');
            fd.append('id', String(docId));
            fetch(self.cfg.apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) {
                return r.json();
              })
              .then(function (data) {
                if (!data || !data.ok) {
                  throw new Error((data && data.message) || 'تعذر الحذف.');
                }
                return self.loadArchiveList();
              })
              .catch(function (err) {
                self.alert(err.message || 'تعذر الحذف.', 'error');
              });
          };
          if (global.AppDialog && AppDialog.confirm) {
            AppDialog.confirm('حذف هذا المرفق من الأرشيف؟', { title: 'تأكيد' }).then(function (ok) {
              if (ok) doDelete();
            });
          } else if (global.confirm('حذف المرفق؟')) {
            doDelete();
          }
        });
      });
    }
  };

  InvoicePhotoArchive.prototype.loadArchiveList = function () {
    var self = this;
    var id = this.getInvoiceId();
    if (id < 1 || !this.cfg.apiUrl) {
      return Promise.resolve();
    }
    this.ensureViewer();
    var loading = this.viewerRoot.querySelector('.m-inv-archive-loading');
    var empty = this.viewerRoot.querySelector('.m-inv-archive-empty');
    if (loading) loading.hidden = false;
    if (empty) empty.hidden = true;
    var url =
      this.cfg.apiUrl +
      '?action=list&kind=' +
      encodeURIComponent(this.cfg.kind || 'sales_invoice') +
      '&voucher_id=' +
      encodeURIComponent(String(id));
    return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) || 'تعذر تحميل الأرشيف.');
        }
        self.archiveFiles = data.files || [];
        self.archiveReadOnly = !!data.read_only;
        self.fileCount = self.archiveFiles.length;
        self.updateArchiveBadge();
        if (loading) loading.hidden = true;
        self.renderArchiveList();
      })
      .catch(function (err) {
        if (loading) loading.hidden = true;
        self.alert(err.message || 'تعذر تحميل الأرشيف.', 'error');
      });
  };

  InvoicePhotoArchive.prototype.openViewer = function () {
    var id = this.getInvoiceId();
    if (id < 1) {
      this.alert('احفظ الفاتورة أولاً لعرض الأرشيف.', 'warning');
      return;
    }
    if (!this.cfg.apiUrl) {
      this.alert('رابط الأرشيف غير مضبوط.', 'warning');
      return;
    }
    this.ensureViewer();
    var title = this.viewerRoot.querySelector('.m-inv-archive-title');
    if (title) title.textContent = 'أرشيف — ' + this.getInvoiceLabel();
    this.viewerRoot.hidden = false;
    document.body.classList.add('m-inv-archive-open');
    this.loadArchiveList();
  };

  InvoicePhotoArchive.prototype.bindToolbar = function (toolbar) {
    var self = this;
    var camBtn = toolbar && toolbar.btn ? toolbar.btn('camera') : document.getElementById('m-toolbar-camera');
    if (camBtn && camBtn.dataset.photoArchiveBound !== '1') {
      camBtn.dataset.photoArchiveBound = '1';
      camBtn.addEventListener('click', function (e) {
        if (e && e.preventDefault) e.preventDefault();
        self.openCamera();
      });
    }
    var archBtn = toolbar && toolbar.btn ? toolbar.btn('archive') : document.getElementById('m-toolbar-archive');
    if (archBtn && archBtn.dataset.archiveViewBound !== '1') {
      archBtn.dataset.archiveViewBound = '1';
      archBtn.addEventListener('click', function (e) {
        if (e && e.preventDefault) e.preventDefault();
        self.openViewer();
      });
    }
    this.refreshMeta();
  };

  global.MobileInvoicePhotoArchive = {
    create: function (cfg) {
      return new InvoicePhotoArchive(cfg);
    },
  };
})(typeof window !== 'undefined' ? window : this);
