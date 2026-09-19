'use strict';

const mysql = require('mysql2/promise');
const config = require('./config');

let pool = null;

function getPool() {
  if (!pool) {
    pool = mysql.createPool({
      host: config.db.host,
      port: config.db.port,
      user: config.db.user,
      password: config.db.password,
      database: config.db.database,
      waitForConnections: true,
      connectionLimit: 10,
      charset: config.db.charset,
      dateStrings: true,
    });
  }
  return pool;
}

async function query(sql, params = []) {
  const [rows] = await getPool().execute(sql, params);
  return rows;
}

async function ping() {
  const rows = await query('SELECT 1 AS ok');
  return rows[0] && Number(rows[0].ok) === 1;
}

module.exports = { getPool, query, ping };
