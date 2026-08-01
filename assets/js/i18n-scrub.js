/**
 * ينظّف أي نص عربي متبقٍ في الصفحة بعد التحميل وعند تغيّر الـ DOM (لغة الإنجليزية فقط).
 */
(function () {
  function isEn() {
    return window.APP_I18N && window.APP_I18N.lang === 'en';
  }

  function dict() {
    return window.APP_I18N_DICT || (window.APP_I18N && window.APP_I18N.dict) || {};
  }

  function hasArabic(s) {
    return /[\u0600-\u06FF]/.test(String(s || ''));
  }

  function translateText(text) {
    var d = dict();
    var out = String(text || '');
    if (!out || !hasArabic(out)) return out;

    // longest keys first (cached)
    if (!window.__I18N_KEYS) {
      window.__I18N_KEYS = Object.keys(d).filter(function (k) {
        return k && k.length >= 2 && k.indexOf('<') === -1;
      }).sort(function (a, b) { return b.length - a.length; });
    }
    var keys = window.__I18N_KEYS;
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      if (out.indexOf(k) !== -1) {
        out = out.split(k).join(d[k]);
      }
    }

    if (hasArabic(out)) {
      out = out.replace(/[\u0600-\u06FF](?:[\u0600-\u06FF\u064B-\u065F]|\s+)*/g, function (chunk) {
        var c = chunk.replace(/\s+/g, ' ').trim();
        if (!c) return '';
        if (d[c]) return d[c];
        var words = c.split(/\s+/);
        var parts = [];
        for (var w = 0; w < words.length; w++) {
          if (d[words[w]]) parts.push(d[words[w]]);
        }
        return parts.join(' ');
      });
      out = out.replace(/[ \t]{2,}/g, ' ');
    }
    return out;
  }

  function scrubNode(node) {
    if (!node || node.nodeType !== 3) return;
    var parent = node.parentNode;
    if (!parent) return;
    var tag = (parent.nodeName || '').toLowerCase();
    if (tag === 'script' || tag === 'style' || tag === 'textarea' || tag === 'code' || tag === 'pre') return;
    var val = node.nodeValue;
    if (!val || !hasArabic(val)) return;
    var tr = translateText(val);
    if (tr !== val) node.nodeValue = tr;
  }

  function scrubAttrs(el) {
    if (!el || el.nodeType !== 1) return;
    ['title', 'aria-label', 'placeholder', 'alt'].forEach(function (a) {
      if (!el.hasAttribute(a)) return;
      var v = el.getAttribute(a);
      if (!v || !hasArabic(v)) return;
      el.setAttribute(a, translateText(v));
    });
    // لا تلمس قيم حقول الإدخال النصية (اسم الشركة، العنوان، …)
    // فقط أزرار submit/button/reset
    var tag = (el.tagName || '').toUpperCase();
    if (tag === 'BUTTON') {
      if (el.value && hasArabic(el.value)) {
        el.value = translateText(el.value);
      }
      return;
    }
    if (tag === 'INPUT') {
      var type = (el.getAttribute('type') || 'text').toLowerCase();
      if ((type === 'button' || type === 'submit' || type === 'reset') && el.value && hasArabic(el.value)) {
        el.value = translateText(el.value);
      }
    }
  }

  function walk(root) {
    if (!root) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var n;
    while ((n = walker.nextNode())) scrubNode(n);
    if (root.querySelectorAll) {
      root.querySelectorAll('[title],[aria-label],[placeholder],[alt],input,button').forEach(scrubAttrs);
    }
  }

  function run() {
    if (!isEn()) return;
    walk(document.body);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }

  // راقب الإضافات الديناميكية (نوافذ، تنبيهات، جداول)
  try {
    var obs = new MutationObserver(function (mutations) {
      if (!isEn()) return;
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (m.type === 'characterData') {
          scrubNode(m.target);
        } else if (m.addedNodes && m.addedNodes.length) {
          for (var j = 0; j < m.addedNodes.length; j++) {
            var node = m.addedNodes[j];
            if (node.nodeType === 3) scrubNode(node);
            else if (node.nodeType === 1) {
              scrubAttrs(node);
              walk(node);
            }
          }
        }
      }
    });
    obs.observe(document.documentElement, {
      childList: true,
      subtree: true,
      characterData: true
    });
  } catch (e) {}

  // واجهة عامة لإعادة التنظيف بعد عمليات AJAX
  window.i18nScrub = run;
})();
