'use strict';

const express = require('express');
const auth = require('../auth');
const basePath = require('../lib/basePath');
const { collectInbox, userCanSeeBell, emptyPayload } = require('./inboxService');
const { renderBellShell, renderPanelBody, badgeText } = require('./bellHtml');
const userInbox = require('./userInbox');

const router = express.Router();

async function mobileInboxUserId(req) {
  const device =
    String(req.get('x-device-id') || req.query.device_id || req.body?.device_id || '').trim();
  const uid = await userInbox.userIdFromDevice(device);
  if (uid > 0) return uid;
  const sessionUid = Number(req.session && req.session.user && req.session.user.id) || 0;
  return sessionUid;
}

async function sendMobileInbox(req, res) {
  try {
    const uid = await mobileInboxUserId(req);
    if (uid < 1) {
      return res.status(401).json({
        ok: false,
        error: 'unauthorized',
        message: 'الجلسة منتهية.',
      });
    }
    const items = await userInbox.listForUser(uid, 50);
    const unread = await userInbox.unreadCount(uid);
    return res.json({ ok: true, unread_count: unread, items });
  } catch (e) {
    console.error('mobile-inbox', e.message);
    return res.status(500).json({ ok: false, error: 'server', message: 'تعذر تحميل الإشعارات.' });
  }
}

router.get('/api/mobile-inbox', sendMobileInbox);
router.post('/api/mobile-inbox', async (req, res) => {
  try {
    const uid = await mobileInboxUserId(req);
    if (uid < 1) {
      return res.status(401).json({
        ok: false,
        error: 'unauthorized',
        message: 'الجلسة منتهية.',
      });
    }
    const action = String((req.body && req.body.action) || '').toLowerCase();
    if (action === 'mark_all_read') {
      await userInbox.markAllRead(uid);
    }
    const items = await userInbox.listForUser(uid, 50);
    const unread = await userInbox.unreadCount(uid);
    return res.json({ ok: true, unread_count: unread, items });
  } catch (e) {
    console.error('mobile-inbox post', e.message);
    return res.status(500).json({ ok: false, error: 'server', message: 'تعذر تحديث الإشعارات.' });
  }
});

router.get('/api/notifications', auth.requireAuth, async (req, res) => {
  try {
    const user = req.session.user;
    if (!userCanSeeBell(user)) {
      return res.json({ ok: true, enabled: false, alert_count: 0, data: emptyPayload() });
    }
    const data = await collectInbox(user);
    const alertCount = Number((data.summary && data.summary.alert_count) || 0);
    // إعادة كتابة المسارات داخل HTML لأن JSON لا يمرّ عبر rewriteHtml للصفحة
    const panelHtml = basePath.rewriteHtml(renderPanelBody(data));
    res.json({
      ok: true,
      enabled: true,
      alert_count: alertCount,
      badge: badgeText(alertCount),
      panel_html: panelHtml,
      data,
    });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'تعذر تحميل التنبيهات' });
  }
});

module.exports = {
  router,
  renderBellForUser: async (user) => {
    if (!user || !userCanSeeBell(user)) return '';
    try {
      const shell = emptyPayload();
      shell.enabled = true;
      return renderBellShell(shell);
    } catch {
      return '';
    }
  },
};
