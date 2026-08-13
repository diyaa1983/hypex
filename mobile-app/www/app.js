(function () {
  'use strict';

  var STORAGE_KEY = 'namma_mobile_server_base';
  var DEFAULT_BASE = 'http://176.29.176.192/hypex';

  var input = document.getElementById('server-url');
  var statusEl = document.getElementById('status');
  var btnConnect = document.getElementById('btn-connect');
  var btnTest = document.getElementById('btn-test');

  function showStatus(msg, ok) {
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.className = 'status ' + (ok ? 'status--ok' : 'status--err');
  }

  function hideStatus() {
    statusEl.hidden = true;
    statusEl.textContent = '';
  }

  function normalizeBase(raw) {
    var s = String(raw || '').trim();
    if (!s) return '';
    s = s.replace(/\/+$/, '');
    // إذا لصق المستخدم رابط الدخول كاملاً
    s = s.replace(/\/m\/login\.php$/i, '');
    s = s.replace(/\/m$/i, '');
    if (!/^https?:\/\//i.test(s)) {
      s = 'https://' + s;
    }
    return s.replace(/\/+$/, '');
  }

  function loginUrl(base) {
    return normalizeBase(base) + '/m/login.php';
  }

  function pingUrl(base) {
    return normalizeBase(base) + '/m/ping.php';
  }

  function savedBase() {
    try {
      return localStorage.getItem(STORAGE_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function saveBase(base) {
    try {
      localStorage.setItem(STORAGE_KEY, base);
    } catch (e) {
      /* ignore */
    }
  }

  function isReconfigure() {
    try {
      return /(?:\?|&)reconfigure=1(?:&|$)/.test(window.location.search || '');
    } catch (e) {
      return false;
    }
  }

  async function testConnection(base) {
    var url = pingUrl(base) + '?t=' + Date.now();
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () {
      if (ctrl) ctrl.abort();
    }, 10000);
    try {
      var res = await fetch(url, {
        method: 'GET',
        cache: 'no-store',
        signal: ctrl ? ctrl.signal : undefined,
      });
      var data = await res.json().catch(function () {
        return null;
      });
      if (!res.ok || !data || !data.ok) {
        throw new Error('استجابة غير صالحة من السيرفر');
      }
      return true;
    } finally {
      clearTimeout(timer);
    }
  }

  async function connect(doTestOnly) {
    hideStatus();
    var base = normalizeBase(input.value);
    if (!base) {
      showStatus('أدخل عنوان السيرفر.', false);
      input.focus();
      return;
    }
    input.value = base;
    btnConnect.disabled = true;
    btnTest.disabled = true;
    showStatus('جاري فحص الاتصال…', true);
    try {
      await testConnection(base);
      saveBase(base);
      if (doTestOnly) {
        showStatus('الاتصال ناجح. يمكنك الضغط على «اتصال».', true);
        return;
      }
      showStatus('تم الاتصال — جاري الفتح…', true);
      window.location.href = loginUrl(base);
    } catch (e) {
      showStatus(
        'تعذر الاتصال بالسيرفر. تأكد من العنوان والإنترنت وصلاحية الشهادة (HTTPS).',
        false
      );
    } finally {
      btnConnect.disabled = false;
      btnTest.disabled = false;
    }
  }

  // تهيئة الحقل
  var initial = savedBase() || DEFAULT_BASE;
  input.value = initial;

  // فتح تلقائي إن وُجد عنوان محفوظ ولم يُطلب إعادة الإعداد
  if (savedBase() && !isReconfigure()) {
    window.location.replace(loginUrl(savedBase()));
    return;
  }

  btnConnect.addEventListener('click', function () {
    connect(false);
  });
  btnTest.addEventListener('click', function () {
    connect(true);
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      connect(false);
    }
  });
})();
