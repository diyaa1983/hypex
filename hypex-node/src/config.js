'use strict';

const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

function env(key, fallback = '') {
  const v = process.env[key];
  return v === undefined || v === '' ? fallback : v;
}

module.exports = {
  port: Number(env('PORT', '3000')) || 3000,
  /**
   * المسار العام تحت Apache — ليطابق http://localhost/hypex
   * اتركه فارغاً إن شغّلت Node مباشرة على الجذر.
   */
  basePath: String(env('APP_BASE_PATH', '/hypex')).trim().replace(/\/+$/, '') || '',
  db: {
    host: env('DB_HOST', '127.0.0.1'),
    port: Number(env('DB_PORT', '3306')) || 3306,
    database: env('DB_NAME', 'hypex'),
    user: env('DB_USER', 'root'),
    password: env('DB_PASS', ''),
    charset: 'utf8mb4',
  },
  // شاشات PHP المتبقية (iframe/embed) — نفس المجلد؛ index.php مسموح في .htaccess
  phpBaseUrl: env('PHP_BASE_URL', 'http://127.0.0.1/hypex').replace(/\/$/, ''),
  sessionSecret: env('SESSION_SECRET', 'hypex-node-dev-change-me'),
};
