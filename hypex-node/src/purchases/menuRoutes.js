'use strict';

const express = require('express');
const auth = require('../auth');
const q = require('./domainQueries');
const ui = require('../lib/salesUi');
const { purchasesCatalog } = require('./catalog');

const router = express.Router();
const HUB = '/purchases';
const KICKER = 'Hypex Purchases · Node';

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function requireAnyPurchases(req, res, next) {
  const u = req.session.user;
  const any = purchasesCatalog.some((g) => g.items.some((it) => can(u, it.r)));
  if (!any && !u.is_admin) {
    return res.status(403).send(
      ui.salesPage({
        user: u,
        title: 'ممنوع',
        bodyHtml: `<div class="si-stage">${ui.hero({ kicker: KICKER, title: 'لا صلاحية', subtitle: 'ليس لديك شاشات مشتريات' })}</div>`,
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
          bodyHtml: `<div class="si-stage">${ui.hero({ kicker: KICKER, title: 'ممنوع', subtitle: 'لا صلاحية لهذه الشاشة' })}</div>`,
        })
      );
    }
    next();
  };
}

/* فقط مسارات /purchases — لا تعترض بقية التطبيق */
router.use((req, res, next) => {
  if (!req.path.startsWith('/purchases')) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireAnyPurchases(req, res, next);
  });
});

router.get('/purchases', (req, res) => {
  const user = req.session.user;
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Pu',
        kicker: KICKER,
        title: 'المشتريات',
        subtitle: 'كل شاشات وتقارير قائمة المشتريات بنفس تصميم 2027.',
        actions: [
          { label: 'فواتير الشراء', href: '/purchases/invoices', primary: true },
          { label: 'لوحة التحكم', href: '/app', ghost: true },
        ],
      })}
      ${ui.hubTiles(can, user, purchasesCatalog)}
    </div>`;
  res.send(ui.salesPage({ user, title: 'المشتريات', bodyHtml: body }));
});

function listPage(res, user, opts) {
  const {
    title,
    mark,
    subtitle,
    headers,
    rowsHtml,
    count,
    searchPath,
    qVal,
    extraActions = [],
    phpRoute,
  } = opts;
  const actions = [...extraActions, { label: 'لوحة المشتريات', href: HUB }];
  

  const body = `
    <div class="si-stage">
      ${ui.hero({ mark, kicker: KICKER, title, subtitle, actions })}
      ${searchPath ? ui.railSearch(searchPath, qVal) : ''}
      ${ui.tableSurface(title, `${count} صف`, headers, rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user, title, bodyHtml: body }));
}

function reportPage(res, user, opts) {
  const { title, mark, subtitle, headers, rowsHtml, count, path, from, to, phpRoute } = opts;
  const actions = [
    ui.printAction(),
    { label: 'لوحة المشتريات', href: HUB },
    
  ];
  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({ mark, kicker: KICKER, title, subtitle, actions })}
      ${ui.dateFilters(path, from, to)}
      <div class="si-print-area">
        ${ui.tableSurface(title, `${count} صف`, headers, rowsHtml)}
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

function bridge(req, res, conf) {
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: conf.mark,
        kicker: KICKER,
        title: conf.title,
        subtitle: conf.subtitle,
        actions: [{ label: 'لوحة المشتريات', href: HUB }],
      })}
      ${ui.bridgeCard(conf.cardTitle, conf.phpRoute, conf.desc, HUB, 'عودة لوحة المشتريات')}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: conf.title, bodyHtml: body }));
}

/* ── مردود المشتريات: returnsRoutes (/purchases/returns/new) ── */

/* ── Lists ── */
router.get('/purchases/orders', guard('purchase_orders_documents_list'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listOrders({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
      <td>${ui.esc(r.supplier_name || '—')}</td>
      <td>${ui.statusPill(r.status === 'approved' ? 'ok' : 'wait', r.status || '—')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/purchases/orders/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'قائمة طلبات الشراء',
    mark: 'OL',
    subtitle: 'جميع طلبات الشراء — فتح وتعديل داخل Node',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/purchases/orders',
    qVal: qv,
    phpRoute: 'purchase_orders_documents_list',
    extraActions: [{ label: 'طلب جديد', href: '/purchases/orders/new', primary: true }],
  });
});

router.get('/purchases/orders/approve', guard('purchase_orders_list'), async (req, res) => {
  const draft = await q.listOrders({ status: 'draft' });
  const pending = await q.listOrders({ status: 'pending' });
  const all = [...draft, ...pending];
  const rowsHtml =
    all
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
      <td>${ui.esc(r.supplier_name || '—')}</td>
      <td>${ui.statusPill('wait', r.status || 'draft')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/purchases/orders/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6, 'لا طلبات بانتظار الاعتماد');
  listPage(res, req.session.user, {
    title: 'اعتماد طلبات الشراء',
    mark: 'OK',
    subtitle: 'مسودات / قيد الاعتماد',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: all.length,
    phpRoute: 'purchase_orders_list',
  });
});

router.get('/purchases/invoices', guard('purchase_documents_list'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listInvoices({ q: qv, filter: 'all' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.invoice_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
      <td>${ui.esc(r.supplier_name)}</td>
      <td class="si-num" dir="ltr">${ui.esc(r.supplier_invoice_no || '—')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/purchases/invoices/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'قائمة فواتير الشراء',
    mark: 'Doc',
    subtitle: 'فواتير شراء — فتح وتعديل داخل Node',
    headers: ['الرقم', 'التاريخ', 'المورد', 'مرجع المورد', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/purchases/invoices',
    qVal: qv,
    phpRoute: 'purchase_documents_list',
    extraActions: [{ label: 'فاتورة جديدة', href: '/purchases/invoices/new', primary: true }],
  });
});

router.get('/purchases/unpaid', guard('purchase_unpaid_invoices'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listUnpaid({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.invoice_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
      <td>${ui.esc(r.supplier_name)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.remaining || r.total))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(4);
  listPage(res, req.session.user, {
    title: 'فواتير الشراء غير المدفوعة',
    mark: 'AP',
    subtitle: 'فواتير ذمم مؤكدة — الرصيد الدقيق قد يحتاج PHP',
    headers: ['الرقم', 'التاريخ', 'المورد', 'المبلغ'],
    rowsHtml,
    count: rows.length,
    searchPath: '/purchases/unpaid',
    qVal: qv,
    phpRoute: 'purchase_unpaid_invoices',
  });
});

router.get('/purchases/posting', guard('purchase_invoices_list'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listInvoices({ q: qv, filter: 'unposted' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.invoice_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
      <td>${ui.esc(r.supplier_name)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td>${ui.statusPill('wait', 'بانتظار الترحيل')}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/purchases/invoices/${r.id}">ترحيل</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6, 'لا فواتير بانتظار الترحيل');
  listPage(res, req.session.user, {
    title: 'ترحيل فواتير الشراء',
    mark: 'Post',
    subtitle: 'ترحيل عبر PHP حالياً',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الإجمالي', 'الحالة', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/purchases/posting',
    qVal: qv,
    phpRoute: 'purchase_invoices_list',
  });
});

router.get('/purchases/returns', guard('purchase_returns_documents_list'), async (req, res) => {
  const qv = String(req.query.q || '');
  const flash = String(req.query.msg || '');
  const rows = await q.listReturns({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.return_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.return_date))}</td>
      <td>${ui.esc(r.supplier_name || '—')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td>${ui.statusPill(r.status === 'confirmed' ? 'ok' : 'wait', r.status || '—')}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/purchases/returns/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'قائمة مردودات المشتريات',
    mark: 'RT',
    subtitle: flash || 'مردودات مسجّلة — أصلية على Node',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الإجمالي', 'الحالة', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/purchases/returns',
    qVal: qv,
    phpRoute: 'purchase_returns_documents_list',
    extraActions: [{ label: '＋ مردود جديد', href: '/purchases/returns/new', primary: true }],
  });
});

router.get('/purchases/returns/posting', guard('purchase_returns_list'), async (req, res) => {
  const rows = await q.listReturns({});
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.return_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.return_date))}</td>
      <td>${ui.esc(r.supplier_name || '—')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/purchases/returns/${r.id}">فتح / ترحيل</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(5);
  listPage(res, req.session.user, {
    title: 'ترحيل مردودات المشتريات',
    mark: 'RP',
    subtitle: 'افتح المردود وترحّله من Node (مخزون + ذمة المورد)',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    phpRoute: 'purchase_returns_list',
    extraActions: [{ label: 'مردود جديد', href: '/purchases/returns/new', primary: true }],
  });
});

/* ── Reports ── */
async function stdReport(req, res, conf) {
  const from = String(req.query.from || '');
  const to = String(req.query.to || '');
  const range = q.dateRange(from, to);
  const rows = await conf.fetch(range.from, range.to);
  reportPage(res, req.session.user, {
    title: conf.title,
    mark: conf.mark,
    subtitle: conf.subtitle,
    headers: conf.headers,
    rowsHtml: conf.mapRows(rows) || ui.emptyRow(conf.colspan || 4),
    count: rows.length,
    path: conf.path,
    from: range.from,
    to: range.to,
    phpRoute: conf.phpRoute,
  });
}

router.get('/purchases/reports/orders', guard('report_purchase_orders'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير طلبات الشراء',
    mark: 'R1',
    subtitle: 'طلبات في الفترة',
    path: '/purchases/reports/orders',
    phpRoute: 'report_purchase_orders',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الحالة', 'الإجمالي'],
    colspan: 5,
    fetch: q.reportOrders,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${ui.esc(r.code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.d))}</td>
        <td>${ui.esc(r.label)}</td>
        <td>${ui.esc(r.status)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/purchases/reports/orders-by-item', guard('report_purchase_orders_by_item'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير طلبات الشراء حسب المادة',
    mark: 'R2',
    subtitle: 'تجميع بنود الطلبات',
    path: '/purchases/reports/orders-by-item',
    phpRoute: 'report_purchase_orders_by_item',
    headers: ['المادة', 'الرمز', 'الكمية', 'الإجمالي'],
    colspan: 4,
    fetch: q.reportOrdersByItem,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td>${ui.esc(r.label)}</td>
        <td class="si-num" dir="ltr">${ui.esc(r.code || '')}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/purchases/reports/orders-open', guard('report_purchase_orders_open'), async (req, res) => {
  const rows = await q.listOpenOrders();
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
      <td>${ui.esc(r.supplier_name || '—')}</td>
      <td>${ui.statusPill('wait', r.status || '—')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(5, 'لا توجد طلبات مفتوحة');
  listPage(res, req.session.user, {
    title: 'تقرير الطلبات المفتوحة',
    mark: 'R3',
    subtitle: 'طلبات غير مغلقة / غير مفوترة',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الحالة', 'الإجمالي'],
    rowsHtml,
    count: rows.length,
    phpRoute: 'report_purchase_orders_open',
  });
});

router.get('/purchases/reports/between-dates', guard('report_purchases'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المشتريات بين تاريخين',
    mark: 'R4',
    subtitle: 'إجمالي يومي',
    path: '/purchases/reports/between-dates',
    phpRoute: 'report_purchases',
    headers: ['التاريخ', 'عدد الفواتير', 'الإجمالي'],
    colspan: 3,
    fetch: q.reportPurchasesBetween,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.label))}</td>
        <td class="si-num" dir="ltr">${r.cnt}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/purchases/reports/by-item', guard('report_purchases_by_item'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المشتريات حسب المادة',
    mark: 'R5',
    subtitle: 'تجميع بنود فواتير الشراء',
    path: '/purchases/reports/by-item',
    phpRoute: 'report_purchases_by_item',
    headers: ['المادة', 'الرمز', 'الكمية', 'الإجمالي'],
    colspan: 4,
    fetch: q.reportPurchasesByItem,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td>${ui.esc(r.label)}</td>
        <td class="si-num" dir="ltr">${ui.esc(r.code || '')}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/purchases/reports/returns', guard('report_purchase_returns'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير مرتجعات المشتريات',
    mark: 'R6',
    subtitle: 'مردودات الفترة',
    path: '/purchases/reports/returns',
    phpRoute: 'report_purchase_returns',
    headers: ['الرقم', 'التاريخ', 'المورد', 'الحالة', 'الإجمالي'],
    colspan: 5,
    fetch: q.reportPurchaseReturns,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${ui.esc(r.code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.d))}</td>
        <td>${ui.esc(r.label)}</td>
        <td>${ui.esc(r.status)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

module.exports = router;
