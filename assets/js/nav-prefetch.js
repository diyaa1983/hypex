(function () {
  'use strict';

  var prefetched = new Set();
  var prefetchMax = 6;
  var progressEl = null;

  function ensureProgressBar() {
    if (progressEl) {
      return progressEl;
    }
    progressEl = document.createElement('div');
    progressEl.id = 'app-nav-progress';
    progressEl.setAttribute('aria-hidden', 'true');
    document.body.appendChild(progressEl);
    return progressEl;
  }

  function showProgress() {
    var bar = ensureProgressBar();
    bar.classList.add('is-active');
  }

  function hideProgress() {
    if (!progressEl) {
      return;
    }
    progressEl.classList.remove('is-active');
  }

  function isNavigableLink(anchor) {
    if (!anchor || anchor.tagName !== 'A') {
      return false;
    }
    if (anchor.target === '_blank' || anchor.hasAttribute('download')) {
      return false;
    }
    if (anchor.getAttribute('href') === '' || anchor.getAttribute('href') === '#') {
      return false;
    }
    var href = anchor.getAttribute('href');
    if (!href || href.charAt(0) === '#') {
      return false;
    }
    if (anchor.origin !== window.location.origin) {
      return false;
    }
    if (href.indexOf('logout.php') >= 0) {
      return false;
    }
    return href.indexOf('index.php') >= 0 || href.indexOf('?r=') >= 0;
  }

  function prefetchUrl(url) {
    if (!url || prefetched.has(url)) {
      return;
    }
    if (prefetched.size >= prefetchMax) {
      return;
    }
    prefetched.add(url);
    try {
      var link = document.createElement('link');
      link.rel = 'prefetch';
      link.as = 'document';
      link.href = url;
      document.head.appendChild(link);
    } catch (e) {
      // ignore
    }
  }

  function onIntent(anchor) {
    if (!isNavigableLink(anchor)) {
      return;
    }
    prefetchUrl(anchor.href);
  }

  document.addEventListener(
    'mouseover',
    function (e) {
      var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      onIntent(anchor);
    },
    { passive: true }
  );

  document.addEventListener(
    'touchstart',
    function (e) {
      var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      onIntent(anchor);
    },
    { passive: true }
  );

  document.addEventListener(
    'click',
    function (e) {
      var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!isNavigableLink(anchor)) {
        return;
      }
      if (e.defaultPrevented) {
        return;
      }
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
        return;
      }
      showProgress();
    },
    true
  );

  window.addEventListener('pageshow', hideProgress);
  window.addEventListener('load', hideProgress);
})();
