'use strict';

/**
 * خريطة route key → كود الصلاحية الفعلي (من config/routes.php + routes_mobile.php).
 * القائمة وواجهة الصلاحيات يجب أن تستخدما كود الصلاحية وليس مفتاح المسار عند الاختلاف.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const APP_ROOT = path.resolve(__dirname, '..', '..', '..');
const ROUTES_PATH = path.join(APP_ROOT, 'config', 'routes.php');
const MOBILE_ROUTES_PATH = path.join(APP_ROOT, 'config', 'routes_mobile.php');

let cache = { stamp: '', map: null };

function phpBin() {
  for (const c of [
    process.env.PHP_BIN,
    'C:\\xampp\\php\\php.exe',
    'C:\\xampp\\php\\php',
    'php',
  ]) {
    if (!c) continue;
    if (c === 'php' || fs.existsSync(c)) return c;
  }
  return 'php';
}

function fileStamp() {
  let s = '';
  for (const p of [ROUTES_PATH, MOBILE_ROUTES_PATH]) {
    try {
      s += String(fs.statSync(p).mtimeMs) + ';';
    } catch {
      s += '0;';
    }
  }
  return s;
}

function loadRoutePermissionMap() {
  const stamp = fileStamp();
  if (cache.map && cache.stamp === stamp) return cache.map;

  const desktop = ROUTES_PATH.replace(/\\/g, '/');
  const mobile = MOBILE_ROUTES_PATH.replace(/\\/g, '/');
  const phpCode = `
    $out = [];
    foreach ([require ${JSON.stringify(desktop)}, require ${JSON.stringify(mobile)}] as $routes) {
      if (!is_array($routes)) continue;
      foreach ($routes as $k => $v) {
        $k = (string) $k;
        $p = is_array($v) && isset($v['permission']) ? (string) $v['permission'] : $k;
        if ($k !== '') $out[$k] = $p !== '' ? $p : $k;
      }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
  `;
  try {
    const out = execFileSync(phpBin(), ['-r', phpCode], {
      encoding: 'utf8',
      maxBuffer: 8 * 1024 * 1024,
      windowsHide: true,
    });
    cache = { stamp, map: JSON.parse(out) || {} };
  } catch (e) {
    console.error('routePermissions.loadRoutePermissionMap', e.message || e);
    cache = { stamp, map: {} };
  }
  return cache.map;
}

/** كود الصلاحية لمفتاح مسار سطح المكتب/الهاتف (أو نفس المفتاح إن لم يوجد). */
function permCodeForRoute(routeKey) {
  const key = String(routeKey || '').trim();
  if (!key) return '';
  const map = loadRoutePermissionMap();
  if (Object.prototype.hasOwnProperty.call(map, key)) {
    return String(map[key] || key);
  }
  return key;
}

/** من عنصر قائمة: code مباشرة أو permission لمسار r. */
function permCodeFromNavItem(it) {
  if (!it || typeof it !== 'object') return '';
  const code = String(it.code || '').trim();
  if (code) return code;
  return permCodeForRoute(it.r);
}

module.exports = {
  loadRoutePermissionMap,
  permCodeForRoute,
  permCodeFromNavItem,
};
