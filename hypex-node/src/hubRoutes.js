'use strict';

const express = require('express');
const auth = require('./auth');
const nav = require('./nav');
const ui = require('./lib/salesUi');
const favSvc = require('./favorites/favoritesService');
const { DOMAIN_CATALOGS } = require('./lib/screenMap');

const router = express.Router();

router.use((req, res, next) => {
  if (!req.path.startsWith('/hub')) return next('router');
  return auth.requireAuth(req, res, next);
});

function renderHub(req, res, hub, opts = {}) {
  if (!hub) {
    return res.status(404).send(
      ui.salesPage({
        user: req.session.user,
        title: 'غير موجود',
        bodyHtml: `<div class="si-stage">${ui.hero({
          title: 'القسم غير موجود',
          subtitle: 'تحقق من الرابط',
        })}</div>`,
        activePath: opts.activePath || '',
      })
    );
  }

  const groupsHtml = hub.groups
    .map((g) => {
      const tiles = g.items
        .map(
          (it) => `
          <a class="si-tile" href="${ui.esc(it.path)}" data-fav-label="${ui.esc(it.label || '')}" data-fav-route="${ui.esc(
            it.r || ''
          )}">
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
          <p style="margin:0;color:#5c6578">${ui.esc(
            hub.emptyHint ||
              'لا توجد شاشات في المفضلة بعد. افتح أي شاشة واضغط نجمة المفضلة في شريط العنوان.'
          )}</p>
        </section>`
      : '';

  const searchHtml =
    hub.id === 'favorites'
      ? `<div class="si-rail no-print" style="margin-top:.65rem">
          <input class="si-field" type="search" id="nav-fav-search-input" placeholder="بحث في المفضلة..." autocomplete="off" style="min-width:14rem;flex:1">
          <button type="button" class="si-btn" id="nav-fav-search-clear">مسح</button>
          <span class="muted" id="nav-fav-search-hint" style="font-size:.82rem"></span>
        </div>`
      : '';

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: hub.id === 'favorites' ? '⭐' : (hub.icon || 'Hx').toString().slice(0, 2),
        kicker: 'Hypex',
        title: hub.title,
        subtitle:
          hub.id === 'favorites'
            ? 'شاشاتك المفضلة — أضف المزيد بالنجمة من شريط عنوان أي شاشة'
            : 'اختر الشاشة',
        actions: [{ label: 'لوحة التحكم', href: '/app', ghost: true }],
      })}
      ${searchHtml}
      <div id="nav-fav-grid">${groupsHtml || empty}</div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: hub.title,
      bodyHtml: body,
      activePath: opts.activePath || hub.hub || '',
      js: hub.id === 'favorites' ? ['/assets/js/nav-favorites-search.js'] : [],
    })
  );
}

router.get('/hub/favorites', async (req, res) => {
  try {
    await favSvc.ensureSchema();
    const hub = await nav.favoritesHubContent(req.session.user);
    return renderHub(req, res, hub, { activePath: '/hub/favorites' });
  } catch (e) {
    console.error('hub/favorites', e);
    return res.status(500).send(String(e.message || e));
  }
});

router.get('/hub/:domainId', (_req, res) => res.redirect('/app'));

// اختصار: إن طلب أحد /sales مباشرة وكان يريد اللوحة
module.exports = router;
module.exports.DOMAIN_CATALOGS = DOMAIN_CATALOGS;
