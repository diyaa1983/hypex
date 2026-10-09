'use strict';

const express = require('express');
const auth = require('../auth');
const fav = require('./favoritesService');
const { clearFavoritesCache } = require('../lib/favoritesContext');

const router = express.Router();

router.post('/api/favorites/toggle', auth.requireAuth, async (req, res) => {
  const screen = String(req.body?.screen || req.body?.screen_code || '').trim();
  const csrf = String(req.body?._csrf || req.get('x-csrf-token') || '');
  const sessionCsrf = String(req.session?.csrfToken || '');
  if (sessionCsrf && csrf && csrf !== sessionCsrf) {
    return res.status(403).json({ ok: false, favorited: false, message: 'انتهت صلاحية الجلسة.' });
  }
  const result = await fav.toggle(req.session.user, screen);
  if (result.ok) {
    clearFavoritesCache(req.session);
  }
  res.json(result);
});

module.exports = router;
