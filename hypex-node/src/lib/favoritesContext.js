'use strict';

const crypto = require('crypto');
const fav = require('../favorites/favoritesService');

/** سياق الطلب الحالي لرسم زر المفضلة في layout المتزامن */
let current = {
  codes: new Set(),
  csrf: '',
  screenCode: '',
  allowed: false,
};

function clearFavoritesCache(session) {
  if (!session) return;
  delete session._favCodes;
  delete session._favAt;
}

async function loadFavoritesForRequest(req) {
  current = { codes: new Set(), csrf: '', screenCode: '', allowed: false };
  const user = req.session && req.session.user;
  if (!user) return;

  if (!req.session.csrfToken) {
    req.session.csrfToken = crypto.randomBytes(16).toString('hex');
  }
  current.csrf = String(req.session.csrfToken);

  const now = Date.now();
  if (
    !Array.isArray(req.session._favCodes) ||
    !req.session._favAt ||
    now - Number(req.session._favAt) > 60000
  ) {
    try {
      req.session._favCodes = await fav.codesForUser(user.id);
      req.session._favAt = now;
    } catch {
      req.session._favCodes = [];
      req.session._favAt = now;
    }
  }
  current.codes = new Set((req.session._favCodes || []).map(String));
}

function setFavoritesScreen(screenCode, user) {
  const code = String(screenCode || '').trim();
  current.screenCode = code;
  current.allowed = code !== '' && fav.canFavorite(user, code);
}

function getFavoritesContext() {
  return current;
}

function isCurrentFavorite(screenCode) {
  const code = String(screenCode || current.screenCode || '').trim();
  return code !== '' && current.codes.has(code);
}

module.exports = {
  loadFavoritesForRequest,
  setFavoritesScreen,
  getFavoritesContext,
  isCurrentFavorite,
  clearFavoritesCache,
};
