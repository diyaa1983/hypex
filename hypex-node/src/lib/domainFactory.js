'use strict';

/**
 * مصنع مسارات نطاق كامل (hub + tiles + list/report/bridge)
 * كل بند في الكتالوج يصير صفحة 2027؛ القوائم/التقارير تُنفَّذ إن وُجد handler.
 */
const express = require('express');
const auth = require('../auth');
const ui = require('./salesUi');

/**
 * @param {object} conf
 * @param {string} conf.basePath e.g. '/hr'
 * @param {string} conf.kicker
 * @param {string} conf.hubTitle
 * @param {string} conf.hubSubtitle
 * @param {string} [conf.mark]
 * @param {Array} conf.catalog groups with items {r,label,icon,path,kind}
 * @param {Record<string, function>} [conf.listHandlers] path → async (req) => listOpts
 * @param {Record<string, function>} [conf.reportHandlers] path → async (req) => reportOpts
 * @param {string} [conf.defaultBridgeDesc]
 */
function createDomainRouter(conf) {
  const {
    basePath,
    kicker,
    hubTitle,
    hubSubtitle,
    mark = 'Hx',
    catalog,
    listHandlers = {},
    reportHandlers = {},
    defaultBridgeDesc = 'الشاشة التفصيلية والإدخال/الترحيل عبر PHP حالياً. هذه الصفحة بنفس تصميم Node 2027.',
  } = conf;

  const router = express.Router();
  const flat = catalog.flatMap((g) => g.items.map((it) => ({ ...it, groupTitle: g.title })));

  function can(user, code) {
    return auth.userCan(user, code) || user.is_admin;
  }

  function requireAny(req, res, next) {
    const u = req.session.user;
    const any = flat.some((it) => can(u, it.r));
    if (!any && !u.is_admin) {
      return res.status(403).send(
        ui.salesPage({
          user: u,
          title: 'ممنوع',
          bodyHtml: `<div class="si-stage">${ui.hero({
            kicker,
            title: 'لا صلاحية',
            subtitle: 'ليس لديك صلاحيات في هذا القسم',
          })}</div>`,
        })
      );
    }
    next();
  }

  function guard(code) {
    return (req, res, next) => {
      if (!can(req.session.user, code)) {
        return res.status(403).send(
          ui.salesPage({
            user: req.session.user,
            title: 'ممنوع',
            bodyHtml: `<div class="si-stage">${ui.hero({
              kicker,
              title: 'ممنوع',
              subtitle: 'لا صلاحية لهذه الشاشة',
            })}</div>`,
          })
        );
      }
      next();
    };
  }

  router.use((req, res, next) => {
    if (!req.path.startsWith(basePath)) return next('router');
    return auth.requireAuth(req, res, (err) => {
      if (err) return next(err);
      return requireAny(req, res, next);
    });
  });

  router.get(basePath, (req, res) => {
    const user = req.session.user;
    const primary = flat.find((it) => it.kind === 'list') || flat[0];
    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark,
          kicker,
          title: hubTitle,
          subtitle: hubSubtitle,
          actions: [
            ...(primary
              ? [{ label: primary.label, href: primary.path, primary: true }]
              : []),
            { label: 'لوحة التحكم', href: '/app', ghost: true },
          ],
        })}
        ${ui.hubTiles(can, user, catalog)}
      </div>`;
    res.send(ui.salesPage({ user, title: hubTitle, bodyHtml: body }));
  });

  function renderList(res, user, opts) {
    const {
      title,
      mark: m = mark,
      subtitle = '',
      headers,
      rowsHtml,
      count,
      searchPath,
      qVal = '',
      phpRoute,
      filtersHtml = '',
      extraActions = [],
    } = opts;
    const actions = [...extraActions, { label: `لوحة ${hubTitle}`, href: basePath }];
    // لا نفتح تبويب PHP خارجي — الشاشات داخل Node
    const body = `
      <div class="si-stage">
        ${ui.hero({ mark: m, kicker, title, subtitle, actions })}
        ${filtersHtml || (searchPath ? ui.railSearch(searchPath, qVal) : '')}
        ${ui.tableSurface(title, `${count} صف`, headers, rowsHtml)}
      </div>`;
    res.send(ui.salesPage({ user, title, bodyHtml: body }));
  }

  function renderReport(res, user, opts) {
    const {
      title,
      mark: m = mark,
      subtitle = '',
      headers,
      rowsHtml,
      count,
      path,
      from,
      to,
      phpRoute,
      filtersHtml = '',
      useDateFilters = true,
      extraHtml = '',
    } = opts;
    const actions = [
      { label: '🖨 طباعة', primary: true, print: true },
      { label: `لوحة ${hubTitle}`, href: basePath },
    ];
    const body = `
      <div class="si-stage si-report-page">
        ${ui.hero({ mark: m, kicker, title, subtitle, actions })}
        ${filtersHtml || (useDateFilters && path ? ui.dateFilters(path, from, to) : '')}
        <div class="si-print-area">
          ${extraHtml}
          ${
            Array.isArray(headers) && headers.length
              ? ui.tableSurface(title, `${count} صف`, headers, rowsHtml)
              : ''
          }
        </div>
      </div>`;
    res.send(
      ui.salesPage({
        user,
        title,
        bodyHtml: body,
        js: ['/assets/js/sales-print.js'],
      })
    );
  }

  function renderBridge(req, res, it) {
    // شاشة داخل غلاف Node مباشرة
    return res.redirect(`/embed/${encodeURIComponent(it.r)}`);
  }

  for (const it of flat) {
    if (!it.path || it.path === basePath) continue;
    router.get(it.path, guard(it.r), async (req, res) => {
      try {
        if (listHandlers[it.path]) {
          const opts = await listHandlers[it.path](req, { ui, can });
          if (opts && opts.__raw) {
            return res.send(opts.__raw);
          }
          return renderList(res, req.session.user, {
            title: it.label,
            phpRoute: it.r,
            ...opts,
          });
        }
        if (reportHandlers[it.path]) {
          const opts = await reportHandlers[it.path](req, { ui, can });
          if (opts && opts.__raw) {
            return res.send(opts.__raw);
          }
          return renderReport(res, req.session.user, {
            title: it.label,
            phpRoute: it.r,
            path: it.path,
            ...opts,
          });
        }
        return renderBridge(req, res, it);
      } catch (e) {
        console.error(basePath, it.path, e);
        res.status(500).send(
          ui.salesPage({
            user: req.session.user,
            title: 'خطأ',
            bodyHtml: `<div class="si-stage">${ui.hero({
              kicker,
              title: 'خطأ في الشاشة',
              subtitle: String(e.message || e),
            })}</div>`,
          })
        );
      }
    });
  }

  return router;
}

module.exports = { createDomainRouter };
