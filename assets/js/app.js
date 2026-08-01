document.documentElement.lang = (window.AppI18n && AppI18n.lang) ? AppI18n.lang : (document.documentElement.lang || 'ar');
if (window.AppI18n && AppI18n.dir) {
  document.documentElement.dir = AppI18n.dir;
}
