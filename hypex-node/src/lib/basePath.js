'use strict';

const config = require('../config');

/** Base path e.g. '/hypex' or '' when Node is at domain root */
const basePath = String(config.basePath || '')
  .trim()
  .replace(/\/+$/, '');

function hasBase() {
  return basePath.length > 0;
}

/** Absolute app path: '/sales' → '/hypex/sales' */
function url(p = '/') {
  let path = p == null || p === '' ? '/' : String(p);
  if (/^https?:\/\//i.test(path) || path.startsWith('//')) return path;
  if (!path.startsWith('/')) path = '/' + path;
  if (!hasBase()) return path;
  if (path === basePath || path.startsWith(basePath + '/')) return path;
  return basePath + path;
}

function stripBaseFromRequest(req) {
  if (!hasBase()) return false;
  const raw = req.url || '/';
  const q = raw.indexOf('?');
  const pathOnly = q === -1 ? raw : raw.slice(0, q);
  const query = q === -1 ? '' : raw.slice(q);
  if (pathOnly === basePath || pathOnly.startsWith(basePath + '/')) {
    let next = pathOnly.slice(basePath.length) || '/';
    if (!next.startsWith('/')) next = '/' + next;
    req.url = next + query;
    return true;
  }
  return false;
}

function ensurePrefixed(href) {
  if (!hasBase() || typeof href !== 'string') return href;
  if (!href.startsWith('/') || href.startsWith('//')) return href;
  if (href === basePath || href.startsWith(basePath + '/')) return href;
  return basePath + href;
}

/** Rewrite absolute root paths in HTML so links work under /hypex */
function rewriteHtml(html) {
  if (!hasBase() || typeof html !== 'string') return html;
  return html.replace(
    /\b(href|src|action|formaction)=("|')(\/[^"']*)\2/gi,
    (full, attr, q, path) => {
      if (path.startsWith('//')) return full;
      return `${attr}=${q}${ensurePrefixed(path)}${q}`;
    }
  );
}

/** Rewrite absolute paths inside served front-end JS */
function rewriteJs(code) {
  if (!hasBase() || typeof code !== 'string') return code;
  // '/api/...', "/sales/...", `/assets/...` — not protocol-relative //
  return code.replace(
    /(['"`])(\/(?!\/)(?:api|assets|static|sales|purchases|customers|sales-reps|suppliers|accounting|inventory|hr|system|mobile|main|hub|menu|app|embed|login|logout|health|n)(?:\/[^'"`]*)?)\1/g,
    (_, q, path) => q + ensurePrefixed(path) + q
  );
}

/**
 * Express middleware:
 * 1) strip base path from incoming URL
 * 2) prefix redirects
 * 3) rewrite HTML responses
 */
function middleware() {
  return function basePathMiddleware(req, res, next) {
    const stripped = stripBaseFromRequest(req);

    // عند تفعيل /hypex: حوّل المسارات المباشرة على المنفذ 3000 إلى المسار الموحّد
    if (hasBase() && !stripped) {
      const raw = req.url || '/';
      const pathOnly = raw.split('?')[0] || '/';
      if (pathOnly === '/health') return next();
      if (req.method === 'GET' || req.method === 'HEAD') {
        return res.redirect(302, basePath + (raw.startsWith('/') ? raw : '/' + raw));
      }
    }

    if (hasBase()) {
      const origRedirect = res.redirect.bind(res);
      res.redirect = function redirectWithBase(a, b) {
        if (typeof b === 'undefined') {
          return origRedirect(ensurePrefixed(a));
        }
        return origRedirect(a, ensurePrefixed(b));
      };

      const origSend = res.send.bind(res);
      res.send = function sendWithBase(body) {
        if (typeof body === 'string' && body.indexOf('<') !== -1) {
          const type = String(res.getHeader('Content-Type') || '');
          if (!type || type.includes('html') || /^\s*<(!doctype|html|script)/i.test(body)) {
            body = rewriteHtml(body);
          }
        }
        return origSend(body);
      };
    }

    next();
  };
}

module.exports = {
  basePath,
  hasBase,
  url,
  ensurePrefixed,
  rewriteHtml,
  rewriteJs,
  middleware,
  stripBaseFromRequest,
};
