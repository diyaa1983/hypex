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
  }

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
      'تم التقاط الصورة.\n\nاحفظ الفاتورة الآن لإرفاقها تلقائياً بأرشيف الفاتورة.',
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
        if (opts.silent) {
          return true;
        }
        return self
          .alert((data && data.message) || 'تم حفظ صورة الطلبية في أرشيف الفاتورة.', 'success')
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

  InvoicePhotoArchive.prototype.bindToolbar = function (toolbar) {
    var self = this;
    var btn = toolbar && toolbar.btn ? toolbar.btn('camera') : document.getElementById('m-toolbar-camera');
    if (!btn || btn.dataset.photoArchiveBound === '1') {
      return;
    }
    btn.dataset.photoArchiveBound = '1';
    btn.addEventListener('click', function (e) {
      if (e && e.preventDefault) e.preventDefault();
      self.openCamera();
    });
  };

  global.MobileInvoicePhotoArchive = {
    create: function (cfg) {
      return new InvoicePhotoArchive(cfg);
    },
  };
})(typeof window !== 'undefined' ? window : this);
