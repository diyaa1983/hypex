'use strict';

const express = require('express');
const auth = require('./auth');
const nav = require('./nav');
const ui = require('./lib/salesUi');
const { DOMAIN_CATALOGS } = require('./lib/screenMap');

const router = express.Router();

router.use((req, res, next) => {
  if (!req.path.startsWith('/hub')) return next('router');
  return auth.requireAuth(req, res, next);
});

function renderHub(req, res, hub) {
  if (!hub) {
    return res.status(404).send(
      ui.salesPage({
        user: req.session.user,
        title: 'غير موجود',
        bodyHtml: `<div class="si-stage">${ui.hero({
          title: 'القسم غير موجود',
          subtitle: 'تحقق من الرابط',
        })}</div>`,
      })
    );
  }

  const groupsHtml = hub.groups
    .map((g) => {
      const tiles = g.items
        .map(
          (it) => `
          <a class="si-tile" href="${ui.esc(it.path)}">
            <span class="si-tile-ico">${ui.esc(it.icon || '·')}</span>
            <span class="si-tile-label">${ui.esc(it.label)}</span>
            <span class="si-tile-kind">${ui.esc(it.kind || 'screen')}</span>
          </a>`
        )
        .join('');
      if (!tiles) return '';
      return `
        <section class="si-surface" style="margin-top:.85rem">
          <div class="si-surface-head"><h2>${ui.esc(g.title)}</h2></div>
          <div class="si-tiles" style="padding:0 1rem 1rem">${tiles}</div>
        </section>`;
    })
    .join('');

  const empty =
    !hub.groups.length || hub.groups.every((g) => !g.items.length)
      ? `<section class="si-surface" style="margin-top:.85rem;padding:1.25rem">
          <p style="margin:0;color:#5c6578">${ui.esc(hub.emptyHint || 'لا شاشات متاحة لصلاحياتك في هذا القسم.')}</p>
        </section>`
      : '';

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: (hub.icon || 'Hx').toString().slice(0, 2),
        kicker: 'Hypex · Node',
        title: hub.title,
        subtitle: 'اختر الشاشة — كلها داخل واجهة Node.',
        actions: [{ label: 'لوحة التحكم', href: '/app', ghost: true }],
      })}
      ${groupsHtml || empty}
    </div>`;

  res.send(ui.salesPage({ user: req.session.user, title: hub.title, bodyHtml: body }));
}

router.get('/hub/favorites', async (req, res) => {
  const hub = await nav.favoritesHubContent(req.session.user);
  renderHub(req, res, hub);
});

router.get('/hub/:domainId', (req, res) => {
  const domainId = String(req.params.domainId || '');
  if (domainId === 'favorites') {
    return res.redirect('/hub/favorites');
  }
  if (domainId === 'main') {
    return res.redirect('/app');
  }
  // إعادة توجيه اللوحات القديمة /sales → /hub/sales متوافق عبر server redirects
  const hub = nav.domainHubContent(req.session.user, domainId);
  renderHub(req, res, hub);
});

// اختصار: إن طلب أحد /sales مباشرة وكان يريد اللوحة
module.exports = router;
module.exports.DOMAIN_CATALOGS = DOMAIN_CATALOGS;
