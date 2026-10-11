'use strict';

/**
 * إخفاء مسارات الشاشات من شريط العنوان:
 *   /hr/departure-types  →  /n/<رمز>
 *
 * يُفعَّل عبر APP_OBSCURE_ROUTES=1
 * لا يغني عن HTTPS والصلاححات — يخفي أسماء الشاشات عن المشاهدة العابرة فقط.
 */

const crypto = require('crypto');
const config = require('../config');

const PREFIX = '/n/';

function isEnabled() {
  return !!config.obscureRoutes;
}

function cryptoKey() {
  return crypto
    .createHash('sha256')
    .update(String(config.sessionSecret || 'hypex') + '|obscure-v1')
    .digest();
}

const encodeCache = new Map();
const DECODE_CACHE_MAX = 4000;

function pathOnly(urlPath) {
  const s = String(urlPath || '/');
  const q = s.indexOf('?');
  return q === -1 ? s : s.slice(0, q);
}

function queryOf(urlPath) {
  const s = String(urlPath || '');
  const q = s.indexOf('?');
  return q === -1 ? '' : s.slice(q);
}

/** مسارات لا تُشفَّر (API، أصول، دخول…) */
function shouldObscure(urlPath) {
  if (!isEnabled()) return false;
  let p = pathOnly(urlPath);
  if (!p.startsWith('/')) p = '/' + p;
  if (p === '/' || p === '/app') return false;
  if (p.startsWith(PREFIX)) return false;
  if (
    p.startsWith('/api/') ||
    p === '/api' ||
    p.startsWith('/assets/') ||
    p.startsWith('/static/') ||
    p === '/health' ||
    p === '/login' ||
    p === '/logout' ||
    p.startsWith('/favicon') ||
    p === '/manifest.php' ||
    p.startsWith('/index.php')
  ) {
    return false;
  }
  // ملفات بامتداد
  if (/\.[a-z0-9]{1,8}$/i.test(p)) return false;
  return true;
}

function b64url(buf) {
  return Buffer.from(buf)
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');
}

function fromB64url(s) {
  const pad = s.length % 4 === 0 ? '' : '='.repeat(4 - (s.length % 4));
  const b64 = s.replace(/-/g, '+').replace(/_/g, '/') + pad;
  return Buffer.from(b64, 'base64');
}

function encode(urlPath) {
  if (!isEnabled()) return urlPath;
  const raw = String(urlPath || '/');
  if (!shouldObscure(raw)) return raw;
  const cached = encodeCache.get(raw);
  if (cached) return cached;

  const key = cryptoKey();
  // IV ثابت من المسار — نفس الشاشة = نفس الرمز (أسهل للتخزين المؤقت)
  const iv = crypto.createHash('sha256').update('iv|' + raw).digest().subarray(0, 12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const enc = Buffer.concat([cipher.update(raw, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  const token = b64url(Buffer.concat([iv, tag, enc]));
  const out = PREFIX + token;
  if (encodeCache.size > DECODE_CACHE_MAX) encodeCache.clear();
  encodeCache.set(raw, out);
  return out;
}

function decodeToken(token) {
  if (!token || typeof token !== 'string') return null;
  try {
    const buf = fromB64url(token);
    if (buf.length < 12 + 16 + 1) return null;
    const iv = buf.subarray(0, 12);
    const tag = buf.subarray(12, 28);
    const data = buf.subarray(28);
    const decipher = crypto.createDecipheriv('aes-256-gcm', cryptoKey(), iv);
    decipher.setAuthTag(tag);
    const plain = Buffer.concat([decipher.update(data), decipher.final()]).toString(
      'utf8'
    );
    if (!plain.startsWith('/')) return null;
    return plain;
  } catch {
    return null;
  }
}

function decodeRequestUrl(reqUrl) {
  const raw = String(reqUrl || '/');
  const q = raw.indexOf('?');
  const pathPart = q === -1 ? raw : raw.slice(0, q);
  const query = q === -1 ? '' : raw.slice(q);
  if (!pathPart.startsWith(PREFIX)) return null;
  const token = pathPart.slice(PREFIX.length);
  if (!token || token.includes('/')) return null;
  const decoded = decodeToken(token);
  if (!decoded) return null;
  if (!query) return decoded;
  if (decoded.includes('?')) {
    return decoded + '&' + query.slice(1);
  }
  return decoded + query;
}

function obscureHref(href) {
  if (!isEnabled() || typeof href !== 'string') return href;
  if (!href.startsWith('/') || href.startsWith('//')) return href;
  if (href.startsWith(PREFIX)) return href;
  return encode(href);
}

function rewriteHtml(html) {
  if (!isEnabled() || typeof html !== 'string') return html;
  return html.replace(
    /\b(href|action|formaction)=("|')(\/[^"']*)\2/gi,
    (full, attr, q, path) => {
      if (path.startsWith('//')) return full;
      const next = obscureHref(path);
      if (next === path) return full;
      return `${attr}=${q}${next}${q}`;
    }
  );
}

function rewriteJs(code) {
  if (!isEnabled() || typeof code !== 'string') return code;
  // مسارات صفحات شائعة داخل نصوص JS — لا نلمس /api و /assets
  return code.replace(
    /(['"`])(\/(?!\/)(?:hub|sales|purchases|customers|sales-reps|suppliers|accounting|inventory|hr|system|mobile|main|menu|app|embed)(?:\/[^'"`?]*)?(?:\?[^'"`]*)?)\1/g,
    (full, q, path) => {
      if (!shouldObscure(path)) return full;
      return q + encode(path) + q;
    }
  );
}

function clientBootstrap(realPath) {
  if (!isEnabled()) return '';
  const p = pathOnly(realPath || '/');
  return (
    `<script>` +
    `window.__HYPEX_OBSCURE__=1;` +
    `window.__HYPEX_PATH__=${JSON.stringify(p)};` +
    `</script>`
  );
}

/**
 * وسيط Express — بعد إزالة basePath:
 * 1) فك /n/token → المسار الحقيقي
 * 2) إعادة توجيه المسارات الواضحة إلى المشفّرة (GET)
 * 3) تشفير Location في redirect
 * 4) إعادة كتابة HTML الصادر
 */
function middleware() {
  return function obscureRoutesMiddleware(req, res, next) {
    if (!isEnabled()) return next();

    const decoded = decodeRequestUrl(req.url || '/');
    if (decoded) {
      req.url = decoded.startsWith('/') ? decoded : '/' + decoded;
      req.__hypexObscured = true;
    } else if (
      (req.method === 'GET' || req.method === 'HEAD') &&
      shouldObscure(req.url || '/')
    ) {
      // حوّل الرابط الواضح إلى مشفّر حتى لا يبقى اسم الشاشة في الشريط
      const target = encode(req.url || '/');
      return res.redirect(302, target);
    }

    const origRedirect = res.redirect.bind(res);
    res.redirect = function obscureRedirect(a, b) {
      if (typeof b === 'undefined') {
        return origRedirect(obscureHref(String(a)));
      }
      return origRedirect(a, obscureHref(String(b)));
    };

    const origSend = res.send.bind(res);
    res.send = function obscureSend(body) {
      if (typeof body === 'string' && body.indexOf('<') !== -1) {
        const type = String(res.getHeader('Content-Type') || '');
        if (!type || type.includes('html') || /^\s*<(!doctype|html)/i.test(body)) {
          body = rewriteHtml(body);
        }
      }
      return origSend(body);
    };

    next();
  };
}

module.exports = {
  PREFIX,
  isEnabled,
  shouldObscure,
  encode,
  decodeToken,
  decodeRequestUrl,
  obscureHref,
  rewriteHtml,
  rewriteJs,
  clientBootstrap,
  middleware,
  pathOnly,
  queryOf,
};
