'use strict';

const db = require('../db');

function clientIp(req) {
  const forwarded = String(req.headers['x-forwarded-for'] || '')
    .split(',')[0]
    .trim();
  return String(forwarded || req.ip || '').slice(0, 45);
}

function tokenFor(sessionId) {
  return ('w:' + String(sessionId || '')).slice(0, 128);
}

/** يسجّل جلسة سطح المكتب كـ Windows، بمعزل عن قفل تطبيق الموبايل. */
async function touchWindows(req) {
  const user = req.session && req.session.user;
  if (!user || !user.id || !req.sessionID) return;
  const now = Date.now();
  if (req.session.winSessionToken && now - Number(req.session.winSessionTouch || 0) < 60000) {
    return;
  }
  const token = tokenFor(req.sessionID);
  req.session.winSessionToken = token;
  req.session.winSessionTouch = now;
  const ip = clientIp(req);
  const ua = String(req.headers['user-agent'] || '').slice(0, 255);
  await db.query(
    `INSERT INTO sys_user_open_session
      (user_id, session_token, client_type, client_label, ip_address, user_agent, login_at, last_seen_at, revoked_at, revoked_by)
     VALUES (?, ?, 'windows', 'Windows', ?, ?, NOW(), NOW(), NULL, NULL)
     ON DUPLICATE KEY UPDATE
       user_id = VALUES(user_id),
       client_type = 'windows',
       client_label = 'Windows',
       ip_address = VALUES(ip_address),
       user_agent = VALUES(user_agent),
       last_seen_at = NOW(),
       login_at = IF(revoked_at IS NULL, login_at, NOW()),
       revoked_at = NULL,
       revoked_by = NULL`,
    [Number(user.id), token, ip || null, ua || null]
  );
}

async function revokeWindows(sessionId) {
  const token = tokenFor(sessionId);
  if (token === 'w:') return;
  await db.query(
    `UPDATE sys_user_open_session
     SET revoked_at = NOW(), last_seen_at = NOW()
     WHERE session_token = ? AND revoked_at IS NULL`,
    [token]
  );
}

module.exports = { touchWindows, revokeWindows };
