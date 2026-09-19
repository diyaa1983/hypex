'use strict';

/**
 * فهرس كل الشاشات على Node — بديل menu_hub من PHP
 */
const express = require('express');
const auth = require('./auth');
const ui = require('./lib/salesUi');
const { salesCatalog } = require('./sales/catalog');
const { purchasesCatalog } = require('./purchases/catalog');
const { customersCatalog } = require('./customers/catalog');
const { salesRepsCatalog } = require('./sales-reps/catalog');
const { suppliersCatalog } = require('./suppliers/catalog');
const { accountingCatalog } = require('./accounting/catalog');
const { inventoryCatalog } = require('./inventory/catalog');
const { hrCatalog } = require('./hr/catalog');
const { systemCatalog } = require('./system/catalog');
const { mobileCatalog } = require('./mobile/catalog');
const { mainCatalog } = require('./main/catalog');

const router = express.Router();

const ALL = [
  { title: 'رئيسي', catalog: mainCatalog, hub: '/main' },
  { title: 'المبيعات', catalog: salesCatalog, hub: '/sales' },
  { title: 'المشتريات', catalog: purchasesCatalog, hub: '/purchases' },
  { title: 'العملاء', catalog: customersCatalog, hub: '/customers' },
  { title: 'الموردين', catalog: suppliersCatalog, hub: '/suppliers' },
  { title: 'المندوبين', catalog: salesRepsCatalog, hub: '/sales-reps' },
  { title: 'المستودعات', catalog: inventoryCatalog, hub: '/inventory' },
  { title: 'المحاسبة', catalog: accountingCatalog, hub: '/accounting' },
  { title: 'شؤون الموظفين', catalog: hrCatalog, hub: '/hr' },
  { title: 'النظام', catalog: systemCatalog, hub: '/system' },
  { title: 'تطبيق الهاتف', catalog: mobileCatalog, hub: '/mobile' },
];

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin || code === 'dashboard';
}

router.use((req, res, next) => {
  if (req.path !== '/menu' && req.path !== '/menu/') return next('router');
  return auth.requireAuth(req, res, next);
});

router.get('/menu', (req, res) => {
  const user = req.session.user;
  let total = 0;
  const sections = ALL.map((block) => {
    const groupsHtml = block.catalog
      .map((g) => {
        const tiles = g.items
          .filter((it) => can(user, it.r))
          .map((it) => {
            total += 1;
            return `
            <a class="si-tile" href="${ui.esc(it.path)}">
              <span class="si-tile-ico">${ui.esc(it.icon || '·')}</span>
              <span class="si-tile-label">${ui.esc(it.label)}</span>
              <span class="si-tile-kind">${ui.esc(it.kind || 'screen')}</span>
            </a>`;
          })
          .join('');
        if (!tiles) return '';
        return `
          <div style="margin-top:.55rem">
            <h3 style="margin:0 0 .4rem;font-size:.85rem;color:#5c6578">${ui.esc(g.title)}</h3>
            <div class="si-tiles">${tiles}</div>
          </div>`;
      })
      .join('');
    if (!groupsHtml) return '';
    return `
      <section class="si-surface" style="margin-top:.85rem">
        <div class="si-surface-head">
          <h2>${ui.esc(block.title)}</h2>
          <a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="${ui.esc(block.hub)}">اللوحة</a>
        </div>
        <div style="padding:.5rem 1rem 1rem">${groupsHtml}</div>
      </section>`;
  }).join('');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'All',
        kicker: 'Hypex Full Menu · Node',
        title: 'جميع الشاشات والتقارير',
        subtitle: `دليل كامل لواجهات النظام على Node.js — ${total} شاشة متاحة لحسابك.`,
        actions: [
          { label: 'لوحة التحكم', href: '/app', primary: true },
          { label: 'فتح الشاشة', href: '/app' },
        ],
      })}
      ${sections}
    </div>`;
  res.send(ui.salesPage({ user, title: 'جميع الشاشات', bodyHtml: body }));
});

module.exports = router;
