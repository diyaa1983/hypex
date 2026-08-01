/**
 * ترجمة الواجهة الأمامية — يعتمد على window.AppI18n من القوالب.
 */
(function (global) {
  'use strict';

  var i18n = global.AppI18n || { lang: 'ar', dir: 'rtl', catalog: {} };

  function translate(text, replace) {
    var out = String(text == null ? '' : text);
    if (i18n.lang && i18n.lang !== 'ar' && i18n.catalog && Object.prototype.hasOwnProperty.call(i18n.catalog, out)) {
      out = String(i18n.catalog[out]);
    }
    if (replace && typeof replace === 'object') {
      Object.keys(replace).forEach(function (key) {
        out = out.split(':' + key).join(String(replace[key]));
      });
    }
    return out;
  }

  function htmlLangAttrs() {
    var lang = (i18n.lang || 'ar');
    var dir = (i18n.dir || (lang === 'en' ? 'ltr' : 'rtl'));
    return 'lang="' + lang + '" dir="' + dir + '"';
  }

  global.AppI18n = i18n;
  global.__ = translate;
  global.appPrintHtmlLangAttrs = htmlLangAttrs;
})(typeof window !== 'undefined' ? window : this);
