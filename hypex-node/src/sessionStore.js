'use strict';

/**
 * جلسة ثابتة في MySQL — تبقى بعد إعادة تشغيل Node / pm2 restart
 * حتى لا يُطلب من المستخدم تسجيل الدخول من جديد عند كل restart.
 */
const session = require('express-session');
const MySQLStore = require('express-mysql-session')(session);
const config = require('./config');
const basePath = require('./lib/basePath');

function createSessionMiddleware() {
  const maxAgeMs =
    (Number(process.env.SESSION_MAX_AGE_HOURS) > 0
      ? Number(process.env.SESSION_MAX_AGE_HOURS)
      : 12) *
    60 *
    60 *
    1000;

  const store = new MySQLStore({
    clearExpired: true,
    checkExpirationInterval: 15 * 60 * 1000,
    expiration: maxAgeMs,
    createDatabaseTable: true,
    schema: {
      tableName: 'hypex_node_sessions',
      columnNames: {
        session_id: 'session_id',
        expires: 'expires',
        data: 'data',
      },
    },
    host: config.db.host,
    port: config.db.port,
    user: config.db.user,
    password: config.db.password,
    database: config.db.database,
  });

  store.on('error', (err) => {
    console.error('[session-store]', err.message || err);
  });

  return session({
    name: 'hypex_node_sid',
    secret: config.sessionSecret,
    resave: false,
    saveUninitialized: false,
    store,
    rolling: true, // يمدّد الجلسة مع الاستخدام
    cookie: {
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.SESSION_SECURE === '1',
      maxAge: maxAgeMs,
      path: basePath.hasBase() ? basePath.basePath : '/',
    },
  });
}

module.exports = { createSessionMiddleware };
