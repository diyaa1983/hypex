'use strict';

const express = require('express');
const auth = require('../auth');
const basePath = require('../lib/basePath');
const { collectInbox, userCanSeeBell, emptyPayload } = require('./inboxService');
const { renderBellShell, renderPanelBody, badgeText } = require('./bellHtml');

const router = express.Router();

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
