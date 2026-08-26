'use strict';

const fs = require('fs');
const path = require('path');
const express = require('express');
const auth = require('../auth');
const q = require('./domainQueries');
const ui = require('../lib/salesUi');
const { salesCatalog } = require('./catalog');
const accNative = require('../accounting/nativeService');
const basePath = require('../lib/basePath');
const config = require('../config');
const { esc, fmtAmt, isoToDmy } = require('../lib/html');

const router = express.Router();

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function oracleInvoiceQrFile(year, invoiceNo) {
  const y = Number(year) || 0;
  const n = Number(invoiceNo) || 0;
  const dir = String(config.oracleInvoiceQrDir || '').trim();
  if (y < 1900 || n < 1 || !dir) return '';
  try {
    const files = fs.readdirSync(dir, { withFileTypes: true });
    const rx = new RegExp(`^${y}_[^_]+_${n}\\.(?:jpe?g|png)$`, 'i');
    const found = files.find((entry) => entry.isFile() && rx.test(entry.name));
    return found ? path.join(dir, found.name) : '';
  } catch {
    return '';
  }
}

function requireAnySales(req, res, next) {
  const u = req.session.user;
  const any = salesCatalog.some((g) => g.items.some((it) => can(u, it.r)));
  if (!any && !u.is_admin) {
    return res.status(403).send(
      ui.salesPage({
        user: u,
        title: 'ممنوع',
        bodyHtml: `<div class="si-stage">${ui.hero({ title: 'لا صلاحية', subtitle: 'ليس لديك شاشات مبيعات' })}</div>`,
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
          bodyHtml: `<div class="si-stage">${ui.hero({ title: 'ممنوع', subtitle: 'لا صلاحية لهذه الشاشة' })}</div>`,
        })
      );
    }
    next();
  };
}

/* لا نطبّق auth على كل الطلبات — وإلا تُحجب /purchases وغيرها */
router.use((req, res, next) => {
  if (!req.path.startsWith('/sales')) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireAnySales(req, res, next);
  });
});

/** لوحة المبيعات */
router.get('/sales', (req, res) => {
  const user = req.session.user;
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Sl',
        title: 'المبيعات',
        subtitle: 'كل شاشات وتقارير قائمة المبيعات بنفس تصميم 2027 — Node shell.',
        actions: [
          { label: 'فاتورة جديدة', href: '/sales/invoices/new', primary: true },
          { label: 'لوحة التحكم', href: '/app', ghost: true },
        ],
      })}
      ${ui.hubTiles(can, user)}
    </div>`;
  res.send(ui.salesPage({ user, title: 'المبيعات', bodyHtml: body }));
});

function listPage(res, user, opts) {
  const {
    title,
    tableTitle,
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
  const actions = [
    ...extraActions,
    { label: 'لوحة المبيعات', href: '/sales' },
  ];
  const body = `
    <div class="si-stage">
      ${ui.hero({ mark, title, subtitle, actions })}
      ${searchPath ? ui.railSearch(searchPath, qVal) : ''}
      ${ui.tableSurface(tableTitle || title, `${count} صف`, headers, rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user, title, bodyHtml: body }));
}

function reportPage(res, user, opts) {
  const { title, mark, subtitle, headers, rowsHtml, count, path, from, to, phpRoute } = opts;
  const actions = [
    ui.printAction(),
    { label: 'لوحة المبيعات', href: '/sales' },
    
  ];
  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({ mark, title, subtitle, actions })}
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

/* ── Lists ── */

router.get('/sales/documents', guard('sales_documents_list'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listInvoices({ q: qv, filter: 'all' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${r.id}</td>
      <td dir="ltr"><a class="si-inv-no" href="/sales/invoices/${r.id}">${ui.esc(r.invoice_no)}</a></td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
      <td>${ui.esc(r.customer_name)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/invoices/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'قائمة فواتير المبيعات',
    mark: 'Doc',
    subtitle: 'جميع الفواتير المؤكدة — تصميم 2027',
    headers: ['ID', 'الرقم', 'التاريخ', 'العميل', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/sales/documents',
    qVal: qv,
    phpRoute: 'sales_documents_list',
    extraActions: [{ label: '＋ فاتورة', href: '/sales/invoices/new', primary: true }],
  });
});

router.get('/sales/unpaid', guard('sales_unpaid_invoices'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listUnpaid({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td dir="ltr"><a class="si-inv-no" href="/sales/invoices/${r.id}">${ui.esc(r.invoice_no)}</a></td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
      <td>${ui.esc(r.customer_name)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.remaining || r.total))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(4);
  listPage(res, req.session.user, {
    title: 'فواتير البيع غير المسددة',
    mark: 'AR',
    subtitle: 'فواتير ذمم مؤكدة — راجع التفاصيل في PHP إن لزم للرصيد الدقيق',
    headers: ['الرقم', 'التاريخ', 'العميل', 'المبلغ'],
    rowsHtml,
    count: rows.length,
    searchPath: '/sales/unpaid',
    qVal: qv,
    phpRoute: 'sales_unpaid_invoices',
  });
});

router.get('/sales/posting', guard('sales_invoices_list'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listInvoices({ q: qv, filter: 'unposted' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td dir="ltr"><a class="si-inv-no" href="/sales/invoices/${r.id}">${ui.esc(r.invoice_no)}</a></td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
      <td>${ui.esc(r.customer_name)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td>${ui.statusPill('wait', 'غير مرحّلة')}</td>
      <td>
        <a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/invoices/${r.id}">فتح</a>
        <button type="button" class="si-btn js-post-one" data-id="${r.id}" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px">ترحيل</button>
      </td>
    </tr>`
      )
      .join('') || ui.emptyRow(6, 'لا فواتير بانتظار الترحيل');
  const extraScript = `
    <script>
    document.querySelectorAll('.js-post-one').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        if(!id||!confirm('ترحيل الفاتورة؟ (قيود + مستودع ثم الفوترة)')) return;
        btn.disabled = true;
        fetch('/api/sales/invoices/'+id+'/post',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})
          .then(function(r){return r.json()})
          .then(function(d){ alert(d.message||d.error||(d.ok?'تم':'فشل')); if(d.ok) location.reload(); else btn.disabled=false; })
          .catch(function(){ btn.disabled=false; alert('تعذر الاتصال'); });
      });
    });
    </script>`;
  listPage(res, req.session.user, {
    title: 'ترحيل فواتير المبيعات',
    mark: 'Post',
    subtitle: 'ترحيل أصلي: قيود محاسبية + خصم مستودع ثم الفوترة الإلكترونية',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الإجمالي', 'الحالة', ''],
    rowsHtml: rowsHtml + extraScript,
    count: rows.length,
    searchPath: '/sales/posting',
    qVal: qv,
  });
});

router.get('/sales/orders', guard('sales_customer_orders'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listOrders({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
      <td>${ui.esc(r.customer_name || '—')}</td>
      <td>${ui.statusPill(r.status === 'approved' ? 'ok' : 'wait', r.status === 'approved' ? 'معتمد' : 'مسودة')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/orders/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'طلبات شراء العملاء',
    tableTitle: 'سجل الطلبات',
    mark: 'PO',
    subtitle: 'قائمة الطلبات — فتح وتعديل',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/sales/orders',
    qVal: qv,
    phpRoute: 'sales_customer_orders',
    extraActions: [{ label: '＋ طلب جديد', href: '/sales/orders/new', primary: true }],
  });
});

router.get('/sales/orders/approve', guard('sales_customer_orders_approve'), async (req, res) => {
  const rows = await q.listOrders({ status: 'draft' });
  // also try pending
  const rows2 = await q.listOrders({ status: 'pending' });
  const all = [...rows, ...rows2];
  const rowsHtml =
    all
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
      <td>${ui.esc(r.customer_name || '—')}</td>
      <td>${ui.statusPill('wait', r.status || 'draft')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/orders/${r.id}">فتح / اعتماد</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6, 'لا طلبات بانتظار الاعتماد');
  listPage(res, req.session.user, {
    title: 'اعتماد طلبات الشراء',
    mark: 'OK',
    subtitle: 'مسودات / قيد الاعتماد',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: all.length,
    phpRoute: 'sales_customer_orders_approve',
  });
});

router.get('/sales/orders/approved', guard('sales_customer_orders_approved'), async (req, res) => {
  const rows = await q.listOrders({ status: 'approved' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
      <td>${ui.esc(r.customer_name || '—')}</td>
      <td>${ui.statusPill('ok', 'معتمد')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/orders/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'الطلبات المعتمدة',
    mark: 'Pk',
    subtitle: 'طلبات شراء بحالة معتمدة',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    phpRoute: 'sales_customer_orders_approved',
  });
});

router.get('/sales/delivery', guard('sales_delivery'), async (req, res) => {
  const qv = String(req.query.q || '');
  const rows = await q.listDeliveries({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.delivery_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.delivery_date))}</td>
      <td>${ui.esc(r.customer_name || '—')}</td>
      <td>${ui.statusPill(r.is_posted ? 'ok' : 'wait', r.is_posted ? 'مرحّل' : r.status || '—')}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/delivery/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(5);
  listPage(res, req.session.user, {
    title: 'سندات التسليم',
    mark: 'DL',
    subtitle: 'قائمة سندات البضاعة — إدخال وحفظ داخل Node',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/sales/delivery',
    qVal: qv,
    phpRoute: 'sales_delivery',
    extraActions: [
      { label: 'سند جديد', href: '/sales/delivery/new', primary: true },
    ],
  });
});

// مرتجعات — مسارات أصلية في returnsRoutes.js (قبل salesMenu)
// /sales/returns, /sales/returns/documents, /sales/returns/posting

/* ── Reports ── */

async function stdReport(req, res, conf) {
  const from = String(req.query.from || '');
  const to = String(req.query.to || '');
  const range = q.dateRange(from, to);
  const rows = await conf.fetch(range.from, range.to);
  const rowsHtml = conf.mapRows(rows) || ui.emptyRow(conf.colspan || 4);
  reportPage(res, req.session.user, {
    title: conf.title,
    mark: conf.mark,
    subtitle: conf.subtitle,
    headers: conf.headers,
    rowsHtml,
    count: rows.length,
    path: conf.path,
    from: range.from,
    to: range.to,
    phpRoute: conf.phpRoute,
  });
}

router.get('/sales/reports/monthly', guard('report_sales'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المبيعات الشهري حسب العميل',
    mark: 'R1',
    subtitle: 'تجميع فواتير مؤكدة حسب العميل',
    path: '/sales/reports/monthly',
    phpRoute: 'report_sales',
    headers: ['العميل', 'الرمز', 'عدد', 'الإجمالي'],
    colspan: 4,
    fetch: q.reportSalesByCustomer,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td>${ui.esc(r.label)}</td>
        <td class="si-num" dir="ltr">${ui.esc(r.code || '')}</td>
        <td class="si-num" dir="ltr">${r.cnt}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/sales/reports/between-dates', guard('report_sales_between_dates'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المبيعات بين تاريخين',
    mark: 'R2',
    subtitle: 'إجمالي يومي للفترة',
    path: '/sales/reports/between-dates',
    phpRoute: 'report_sales_between_dates',
    headers: ['التاريخ', 'عدد الفواتير', 'الإجمالي'],
    colspan: 3,
    fetch: q.reportSalesBetweenDates,
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

router.get('/sales/reports/by-item', guard('report_sales_by_item'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المبيعات حسب المادة',
    mark: 'R3',
    subtitle: 'تجميع بنود الفواتير',
    path: '/sales/reports/by-item',
    phpRoute: 'report_sales_by_item',
    headers: ['المادة', 'الرمز', 'الكمية', 'الإجمالي'],
    colspan: 4,
    fetch: q.reportSalesByItem,
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

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

router.get('/sales/reports/by-region', guard('report_sales_by_region'), async (req, res) => {
  const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
  const rows = await q.reportSalesByRegion(range.from, range.to);
  let sumCnt = 0;
  let sumTot = 0;
  for (const r of rows) {
    sumCnt += Number(r.cnt || 0);
    sumTot += Number(r.total || 0);
  }
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td>${ui.esc(r.label)}</td>
      <td class="si-num" dir="ltr">${Number(r.cnt || 0)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.subtotal))}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(4);

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: 'Hypex Sales · Node',
        title: 'تقرير المبيعات حسب المنطقة',
        subtitle: `من ${range.from} إلى ${range.to} · ${sumCnt} فاتورة · إجمالي ${ui.esc(ui.fmtAmt(sumTot))}`,
        actions: [
          ui.printAction(),
          { label: 'لوحة المبيعات', href: '/sales' },
        ],
      })}
      ${ui.dateFilters('/sales/reports/by-region', range.from, range.to)}
      <div class="si-print-area">
        ${ui.tableSurface(
          'حسب منطقة العميل',
          `${rows.length} منطقة`,
          ['المنطقة', 'فواتير', 'قبل الضريبة', 'الإجمالي'],
          rowsHtml
        )}
      </div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير المبيعات حسب المنطقة',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

router.get('/sales/reports/by-rep', guard('report_sales_by_rep'), async (req, res) => {
  const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const run = String(req.query.run || '') === '1' || salesRepId > 0;
  const reps = await q.listRepsSimple();
  let err = '';
  let detail = [];
  let summary = [];
  let repName = '';

  if (run && salesRepId > 0) {
    const rep = reps.find((r) => Number(r.id) === salesRepId);
    if (!rep) err = 'المندوب غير موجود.';
    else {
      repName = rep.name_ar || '';
      detail = await q.reportSalesByRepDetail(salesRepId, range.from, range.to);
    }
  } else if (!salesRepId) {
    summary = await q.reportSalesByRepSummary(range.from, range.to);
  }

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${ui.esc(r.name_ar)}${
          r.code ? ' (' + ui.esc(r.code) + ')' : ''
        }</option>`
    )
    .join('');

  let tableBlock = '';
  if (err) {
    tableBlock = `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>`;
  } else if (salesRepId > 0) {
    let sumSub = 0;
    let sumTot = 0;
    for (const r of detail) {
      sumSub += Number(r.subtotal || 0);
      sumTot += Number(r.total || 0);
    }
    const rowsHtml =
      detail
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${dash(r.invoice_no)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
        <td>${ui.esc(r.customer_name || '')}</td>
        <td class="si-num" dir="ltr">${dash(r.customer_code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.subtotal))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(6, 'لا فواتير في الفترة');
    tableBlock = ui.tableSurface(
      `فواتير: ${ui.esc(repName)}`,
      `${detail.length} · مجموع ${ui.esc(ui.fmtAmt(sumTot))}`,
      ['فاتورة', 'التاريخ', 'العميل', 'رمز', 'قبل الضريبة', 'الإجمالي'],
      rowsHtml +
        (detail.length
          ? `<tr><td colspan="4" style="font-weight:800">المجموع</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${ui.esc(ui.fmtAmt(sumSub))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${ui.esc(ui.fmtAmt(sumTot))}</td></tr>`
          : '')
    );
  } else {
    const rowsHtml =
      summary
        .map(
          (r) => `<tr>
        <td>${ui.esc(r.label)}</td>
        <td class="si-num" dir="ltr">${dash(r.code)}</td>
        <td class="si-num" dir="ltr">${Number(r.cnt || 0)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.subtotal))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
        <td>${
          Number(r.rep_id) > 0
            ? `<a class="si-btn" href="/sales/reports/by-rep?run=1&sales_rep_id=${r.rep_id}&from=${encodeURIComponent(
                range.from
              )}&to=${encodeURIComponent(range.to)}">تفصيل</a>`
            : '—'
        }</td>
      </tr>`
        )
        .join('') || ui.emptyRow(6);
    tableBlock = ui.tableSurface(
      'ملخص حسب المندوب',
      `${summary.length} مندوب`,
      ['المندوب', 'الرمز', 'فواتير', 'قبل الضريبة', 'الإجمالي', ''],
      rowsHtml
    );
  }

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '📊',
        kicker: 'Hypex Sales · Node',
        title: 'تقرير المبيعات حسب المندوب',
        subtitle: `من ${range.from} إلى ${range.to}`,
        actions: [
          ui.printAction(),
          { label: 'لوحة المبيعات', href: '/sales' },
        ],
      })}
      <div class="si-rail no-print">
        <form method="get" action="/sales/reports/by-rep" class="si-search" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
          <input type="hidden" name="run" value="1">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">المندوب
            <select name="sales_rep_id" class="si-field" style="min-width:12rem">
              <option value="0">— ملخص الكل —</option>
              ${repOpts}
            </select>
          </label>
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">من
            <input class="si-field" type="date" name="from" value="${ui.esc(range.from)}">
          </label>
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى
            <input class="si-field" type="date" name="to" value="${ui.esc(range.to)}">
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      <div class="si-print-area">${tableBlock}</div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير المبيعات حسب المندوب',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

router.get('/sales/reports/detailed', guard('report_sales_detailed'), async (req, res) => {
  const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
  const tab = String(req.query.tab || 'summary') === 'detail' ? 'detail' : 'summary';
  const run = String(req.query.run || '') === '1';
  const source = String(req.query.source || 'both').toLowerCase();
  const customerId = Number(req.query.customer_id || 0) || 0;
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const regionId = Number(req.query.region_id || 0) || 0;
  const categoryId = Number(req.query.category_id || 0) || 0;
  const itemId = Number(req.query.item_id || 0) || 0;
  const warehouseId = Number(req.query.warehouse_id || 0) || 0;
  const paymentType = String(req.query.payment_type || '').toLowerCase();
  const postedOnly = String(req.query.posted_only || '') === '1';
  const groupBy = String(req.query.group_by || 'customer');

  const [reps, regions, categories, warehouses, customers] = await Promise.all([
    q.listRepsSimple(),
    q.listRegionsSimple(),
    q.listCategoriesSimple(),
    q.listWarehousesSimple(),
    q.listCustomersSimple(),
  ]);

  let data = {
    summary: [],
    details: [],
    totals: {
      qty: 0,
      line_total: 0,
      line_gross: 0,
      tax_amount: 0,
      line_count: 0,
      invoice_count: 0,
      order_count: 0,
      doc_count: 0,
    },
    group_by: groupBy,
    source: source === 'orders' ? 'orders' : source === 'both' ? 'both' : 'sales',
  };
  let err = '';
  if (run) {
    try {
      data = await q.reportCombinedDetailed({
        from: range.from,
        to: range.to,
        source,
        customer_id: customerId,
        sales_rep_id: salesRepId,
        region_id: regionId,
        category_id: categoryId,
        item_id: itemId,
        warehouse_id: warehouseId,
        payment_type: paymentType,
        posted_only: postedOnly,
        group_by: groupBy,
      });
    } catch (e) {
      err = e.message || 'تعذر تحميل التقرير';
      console.error('report detailed', e);
    }
  }

  const sourceLabels = { sales: 'المبيعات', orders: 'طلبات الشراء', both: 'المبيعات + الطلبات' };
  const sourceLabel = sourceLabels[data.source] || sourceLabels.sales;

  const groupLabels = {
    customer: 'العميل',
    sales_rep: 'المندوب',
    region: 'المنطقة',
    category: 'فئة المادة',
    item: 'المادة',
    invoice_date: 'التاريخ',
    warehouse: 'المستودع',
    payment_type: data.source === 'orders' ? 'حالة الطلب' : 'نوع الدفع',
  };
  const groupLabel = groupLabels[data.group_by] || groupLabels.customer;

  function payLbl(v) {
    const pt = String(v || '');
    if (pt === 'cash') return 'نقد';
    if (pt === 'credit') return 'آجل';
    return pt || '—';
  }

  function filterQs(extraTab, extraSource) {
    const p = new URLSearchParams();
    p.set('run', '1');
    p.set('tab', extraTab || tab);
    p.set('source', extraSource || data.source || 'sales');
    p.set('from', range.from);
    p.set('to', range.to);
    p.set('customer_id', String(customerId));
    p.set('sales_rep_id', String(salesRepId));
    p.set('region_id', String(regionId));
    p.set('category_id', String(categoryId));
    p.set('item_id', String(itemId));
    p.set('warehouse_id', String(warehouseId));
    p.set('group_by', data.group_by);
    if (paymentType) p.set('payment_type', paymentType);
    if (postedOnly) p.set('posted_only', '1');
    return p.toString();
  }

  const custOpts = customers
    .map(
      (c) =>
        `<option value="${c.id}" ${customerId === Number(c.id) ? 'selected' : ''}>${ui.esc(c.name_ar || '')}${
          c.code ? ' (' + ui.esc(c.code) + ')' : ''
        }</option>`
    )
    .join('');
  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${ui.esc(r.name_ar)}${
          r.code ? ' (' + ui.esc(r.code) + ')' : ''
        }</option>`
    )
    .join('');
  const regionOpts = regions
    .map(
      (rg) =>
        `<option value="${rg.id}" ${regionId === Number(rg.id) ? 'selected' : ''}>${ui.esc(rg.name_ar || '')}</option>`
    )
    .join('');
  const catOpts = categories
    .map(
      (c) =>
        `<option value="${c.id}" ${categoryId === Number(c.id) ? 'selected' : ''}>${ui.esc(c.name_ar || '')}</option>`
    )
    .join('');
  const whOpts = warehouses
    .map(
      (w) =>
        `<option value="${w.id}" ${warehouseId === Number(w.id) ? 'selected' : ''}>${ui.esc(w.name_ar || '')}</option>`
    )
    .join('');
  const groupOpts = Object.entries(groupLabels)
    .map(
      ([k, lab]) =>
        `<option value="${k}" ${data.group_by === k ? 'selected' : ''}>${ui.esc(lab)}</option>`
    )
    .join('');

  const showDocCol = data.source === 'both';
  const docCountCol =
    data.source === 'orders'
      ? 'طلبات'
      : data.source === 'both'
        ? 'مستندات'
        : 'فواتير';

  let tableBlock = '';
  if (run && tab === 'summary') {
    const rowsHtml =
      data.summary
        .map(
          (r, i) => `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        <td>${ui.esc(r.label || '')}</td>
        <td class="si-num" dir="ltr">${dash(r.code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_total))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.tax_amount))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_gross))}</td>
        <td class="si-num" dir="ltr">${Number(r.line_count || 0)}</td>
        <td class="si-num" dir="ltr">${Number(r.invoice_count || 0)}</td>
      </tr>`
        )
        .join('') ||
      ui.emptyRow(9, `لا توجد ${sourceLabel} في الفترة المحددة`);
    tableBlock = ui.tableSurface(
      `ملخص حسب ${groupLabel}`,
      `${data.summary.length} صف · ${data.totals.doc_count || data.totals.invoice_count} ${docCountCol}`,
      ['#', groupLabel, 'الرمز', 'الكمية', 'بدون ض.', 'الضريبة', 'شامل', 'بنود', docCountCol],
      rowsHtml +
        (data.summary.length
          ? `<tr><td colspan="3" style="font-weight:800">الإجمالي</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${ui.esc(ui.fmtAmt(data.totals.qty))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${ui.esc(ui.fmtAmt(data.totals.line_total))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${ui.esc(ui.fmtAmt(data.totals.tax_amount))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${ui.esc(ui.fmtAmt(data.totals.line_gross))}</td>
          <td colspan="2"></td></tr>`
          : '')
    );
  } else if (run) {
    const rowsHtml =
      data.details
        .map(
          (r, i) => `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        ${showDocCol ? `<td>${r.doc_type === 'order' ? '<span class="sd-pill-order">طلب</span>' : '<span class="sd-pill-sales">فاتورة</span>'}</td>` : ''}
        <td class="si-num" dir="ltr">${dash(r.invoice_no)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.invoice_date))}</td>
        <td>${ui.esc(r.customer_name || '—')}</td>
        <td>${ui.esc(r.sales_rep_name || '—')}</td>
        <td>${ui.esc(r.region_name || '—')}</td>
        <td class="si-num" dir="ltr">${dash(r.item_sku)}</td>
        <td>${ui.esc(r.item_name || '')}</td>
        <td>${ui.esc(r.category_name || '—')}</td>
        <td>${ui.esc(r.warehouse_name || '—')}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.unit_price))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_total))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_gross))}</td>
        <td>${ui.esc(data.source === 'orders' ? r.payment_type : payLbl(r.payment_type))}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(showDocCol ? 16 : 15, 'لا توجد بنود في الفترة المحددة');
    const headers = [
      '#',
      ...(showDocCol ? ['النوع'] : []),
      data.source === 'orders' ? 'طلب' : 'فاتورة',
      'التاريخ',
      'العميل',
      'المندوب',
      'المنطقة',
      'المادة',
      'الاسم',
      'الفئة',
      'المستودع',
      'الكمية',
      'السعر',
      'بدون ض.',
      'شامل',
      data.source === 'orders' ? 'الحالة' : 'دفع',
    ];
    tableBlock = ui.tableSurface(
      data.source === 'orders' ? 'تفصيل بنود طلبات الشراء' : data.source === 'both' ? 'تفصيل البنود (مبيعات + طلبات)' : 'تفصيل بنود الفواتير',
      `${data.details.length} سطر · ${data.totals.doc_count || 0} ${docCountCol}`,
      headers,
      rowsHtml
    );
  } else {
    tableBlock = `<p class="muted" style="padding:0.5rem 0">حدّد المصدر والفلاتر ثم اضغط «عرض التقرير».</p>`;
  }

  const kpiBlock = run
    ? `<div class="sd-kpi-row no-print">
        <div class="sd-kpi sd-kpi--accent"><span>الإجمالي شامل</span><strong dir="ltr">${ui.esc(ui.fmtAmt(data.totals.line_gross))}</strong></div>
        <div class="sd-kpi"><span>بدون ضريبة</span><strong dir="ltr">${ui.esc(ui.fmtAmt(data.totals.line_total))}</strong></div>
        <div class="sd-kpi"><span>الكمية</span><strong dir="ltr">${ui.esc(ui.fmtAmt(data.totals.qty))}</strong></div>
        <div class="sd-kpi"><span>البنود</span><strong dir="ltr">${Number(data.totals.line_count || 0)}</strong></div>
        ${
          data.source !== 'orders'
            ? `<div class="sd-kpi"><span>فواتير</span><strong dir="ltr">${Number(data.totals.invoice_count || 0)}</strong></div>`
            : ''
        }
        ${
          data.source !== 'sales'
            ? `<div class="sd-kpi"><span>طلبات</span><strong dir="ltr">${Number(data.totals.order_count || 0)}</strong></div>`
            : ''
        }
      </div>`
    : '';

  const body = `
    <div class="si-stage si-report-page si-report-detailed" data-hx-print-landscape="1">
      ${ui.hero({
        mark: '📋',
        kicker: 'Hypex Sales · Node',
        title: 'تقرير المبيعات وطلبات الشراء',
        subtitle: run
          ? `${sourceLabel} · ${ui.esc(ui.isoToDmy(range.from))} → ${ui.esc(ui.isoToDmy(range.to))} · ${data.totals.doc_count || 0} مستند`
          : 'فلاتر شاملة — مبيعات و/أو طلبات شراء العملاء — ملخص أو تفصيل',
        actions: [
          ui.printAction(),
          { label: 'لوحة المبيعات', href: '/sales' },
        ],
      })}
      <section class="si-surface sd-filters no-print">
        <div class="sd-source-bar">
          <a class="si-btn${data.source === 'sales' ? ' si-btn--primary' : ''}" href="/sales/reports/detailed?${filterQs(tab, 'sales')}">المبيعات</a>
          <a class="si-btn${data.source === 'orders' ? ' si-btn--primary' : ''}" href="/sales/reports/detailed?${filterQs(tab, 'orders')}">طلبات الشراء</a>
          <a class="si-btn${data.source === 'both' ? ' si-btn--primary' : ''}" href="/sales/reports/detailed?${filterQs(tab, 'both')}">الكل معاً</a>
        </div>
        <form method="get" action="/sales/reports/detailed">
          <input type="hidden" name="run" value="1">
          <input type="hidden" name="tab" value="${ui.esc(tab)}">
          <input type="hidden" name="source" value="${ui.esc(data.source || 'both')}">
          <div class="sd-filter-grid">
            <label>من تاريخ
              <input class="si-field" type="date" name="from" value="${ui.esc(range.from)}" required dir="ltr">
            </label>
            <label>إلى تاريخ
              <input class="si-field" type="date" name="to" value="${ui.esc(range.to)}" required dir="ltr">
            </label>
            <label>العميل
              <select name="customer_id" class="si-field">
                <option value="0">— جميع العملاء —</option>
                ${custOpts}
              </select>
            </label>
            <label>المندوب
              <select name="sales_rep_id" class="si-field">
                <option value="0">— الكل —</option>
                ${repOpts}
              </select>
            </label>
            <label>المنطقة
              <select name="region_id" class="si-field">
                <option value="0">— الكل —</option>
                ${regionOpts}
              </select>
            </label>
            <label>فئة المادة
              <select name="category_id" class="si-field">
                <option value="0">— الكل —</option>
                ${catOpts}
              </select>
            </label>
            <label>المادة #
              <input class="si-field" type="number" name="item_id" min="0" value="${itemId || ''}" placeholder="الكل" dir="ltr">
            </label>
            <label>المستودع
              <select name="warehouse_id" class="si-field">
                <option value="0">— الكل —</option>
                ${whOpts}
              </select>
            </label>
            ${
              data.source !== 'orders'
                ? `<label>الدفع
              <select name="payment_type" class="si-field">
                <option value="" ${paymentType === '' ? 'selected' : ''}>الكل</option>
                <option value="cash" ${paymentType === 'cash' ? 'selected' : ''}>نقد</option>
                <option value="credit" ${paymentType === 'credit' ? 'selected' : ''}>آجل</option>
              </select>
            </label>`
                : ''
            }
            <label>تجميع الملخص
              <select name="group_by" class="si-field">${groupOpts}</select>
            </label>
          </div>
          <div class="sd-filter-actions">
            <label class="sd-check">
              <input type="checkbox" name="posted_only" value="1" ${postedOnly ? 'checked' : ''}>
              ${data.source === 'orders' ? 'معتمد فقط' : data.source === 'both' ? 'مرحّل/معتمد فقط' : 'مرحّل فقط'}
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
            ${
              run
                ? `<div class="sd-tabs" style="margin:0">
              <a class="si-btn${tab === 'summary' ? ' si-btn--primary' : ''}" href="/sales/reports/detailed?${filterQs('summary')}">ملخص</a>
              <a class="si-btn${tab === 'detail' ? ' si-btn--primary' : ''}" href="/sales/reports/detailed?${filterQs('detail')}">تفصيل البنود</a>
            </div>`
                : ''
            }
          </div>
        </form>
      </section>
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
      ${kpiBlock}
      <div class="si-print-area sd-table">${tableBlock}</div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير المبيعات وطلبات الشراء',
      bodyHtml: body,
      css: ['/assets/css/report-sales-detailed.css'],
      printTitle: 'تقرير المبيعات وطلبات الشراء',
    })
  );
});

router.get('/sales/reports/qty-extra', guard('report_sales_qty_extra'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير الكميات الإضافية',
    mark: 'R5',
    subtitle: 'بنود بكمية إضافية > 0',
    path: '/sales/reports/qty-extra',
    phpRoute: 'report_sales_qty_extra',
    headers: ['فاتورة', 'التاريخ', 'العميل', 'المادة', 'كمية إض.'],
    colspan: 5,
    fetch: q.reportQtyExtra,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${ui.esc(r.code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.d))}</td>
        <td>${ui.esc(r.label)}</td>
        <td>${ui.esc(r.item)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.qty))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/sales/reports/discount', guard('report_sales_invoice_discount'), (req, res) =>
  stdReport(req, res, {
    title: 'الخصم على الفواتير',
    mark: 'R6',
    subtitle: 'فواتير فيها خصم مستوى فاتورة',
    path: '/sales/reports/discount',
    phpRoute: 'report_sales_invoice_discount',
    headers: ['فاتورة', 'التاريخ', 'العميل', 'خصم', 'صافي', 'إجمالي'],
    colspan: 6,
    fetch: q.reportInvoiceDiscount,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${ui.esc(r.code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.d))}</td>
        <td>${ui.esc(r.label)}</td>
        <td class="si-num" dir="ltr">${ui.esc(r.disc)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.subtotal))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/sales/reports/customer-orders', guard('report_customer_orders'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير طلبات الشراء',
    mark: 'R7',
    subtitle: 'طلبات العملاء في الفترة',
    path: '/sales/reports/customer-orders',
    phpRoute: 'report_customer_orders',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', 'الإجمالي'],
    colspan: 5,
    fetch: q.reportCustomerOrders,
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

router.get('/sales/reports/customer-orders-by-item', guard('report_customer_orders_by_item'), async (req, res) => {
  try {
    const itemId = Number(req.query.item_id || 0) || 0;
    const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
    const run = String(req.query.run || '') === '1' || itemId > 0;
    let err = '';
    let data = { item: null, rows: [], totals: { qty: 0, line_gross: 0 } };

    if (run) {
      if (itemId < 1) err = 'اختر المادة.';
      else {
        data = await q.reportCustomerOrdersByItem({ itemId, from: range.from, to: range.to });
        if (!data.item) err = 'المادة غير موجودة.';
      }
    }

    const itemLabel = data.item
      ? ((data.item.barcode || data.item.sku || '')
          ? `${data.item.barcode || data.item.sku} — `
          : '') + (data.item.name_ar || '')
      : '';

    const statusLabel = (s) => {
      const v = String(s || '');
      if (v === 'approved') return 'معتمد';
      if (v === 'draft') return 'مسودة';
      return v || '—';
    };

    const rowsHtml =
      data.rows
        .map(
          (r, i) => {
            const qty = Number(r.qty || 0) + Number(r.qty_extra || 0);
            return `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        <td class="si-num" dir="ltr">${ui.esc(r.order_no || '')}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
        <td>${ui.esc(r.customer_name || '—')}</td>
        <td>${ui.esc(r.sales_rep_name || '—')}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(qty))}</td>
        <td>${ui.esc(r.unit_name || '—')}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.unit_price))}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_gross))}</td>
        <td>${ui.esc(statusLabel(r.status))}</td>
      </tr>`;
          }
        )
        .join('') ||
      ui.emptyRow(
        10,
        run && !err ? 'لا توجد طلبات شراء لهذه المادة في الفترة' : 'اختر المادة والتواريخ ثم اعرض التقرير'
      );

    const body = `
      <div class="si-stage si-report-page">
        ${ui.hero({
          mark: '📦',
          kicker: 'Hypex Sales · Node',
          title: 'طلبات الشراء للعميل حسب مادة معينة',
          subtitle:
            run && !err && data.item
              ? `${ui.esc(itemLabel)} · من ${ui.esc(ui.isoToDmy(range.from))} إلى ${ui.esc(
                  ui.isoToDmy(range.to)
                )} · كمية ${ui.esc(ui.fmtAmt(data.totals.qty))} · إجمالي ${ui.esc(
                  ui.fmtAmt(data.totals.line_gross)
                )}`
              : 'اختر مادة وفترة لعرض طلبات شراء العملاء التي تتضمنها',
          actions: [
            ui.printAction(),
            { label: 'تقرير طلبات الشراء', href: '/sales/reports/customer-orders' },
            { label: 'لوحة المبيعات', href: '/sales' },
          ],
        })}
        ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
        <div class="si-rail no-print">
          <form method="get" action="/sales/reports/customer-orders-by-item" class="si-search"
                id="co-by-item-form" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;max-width:100%"
                autocomplete="off">
            <input type="hidden" name="run" value="1">
            <input type="hidden" name="item_id" id="co-item-id" value="${itemId || ''}">
            <label style="font-size:.8rem;font-weight:700;color:#5c6578;flex:1 1 16rem">المادة *
              <div class="si-cust-wrap" style="position:relative">
                <input type="search" class="si-field" id="co-item-search"
                       value="${ui.esc(itemLabel)}"
                       placeholder="ابحث بالباركود أو اسم المادة…"
                       autocomplete="off" spellcheck="false"
                       aria-autocomplete="list" aria-controls="co-item-suggest">
                <div id="co-item-suggest" class="si-suggest si-suggest--name" hidden></div>
              </div>
            </label>
            <label style="font-size:.8rem;font-weight:700;color:#5c6578">من تاريخ
              <input class="si-field" type="date" name="from" value="${ui.esc(range.from)}" required>
            </label>
            <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى تاريخ
              <input class="si-field" type="date" name="to" value="${ui.esc(range.to)}" required>
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
          </form>
        </div>
        <div class="si-print-area">
          ${ui.tableSurface(
            'بنود طلبات الشراء',
            `${data.rows.length} سطر`,
            ['#', 'رقم الطلب', 'التاريخ', 'العميل', 'المندوب', 'الكمية', 'الوحدة', 'السعر', 'الإجمالي', 'الحالة'],
            rowsHtml
          )}
        </div>
      </div>
      <script>
(function(){
  var hid = document.getElementById('co-item-id');
  var inp = document.getElementById('co-item-search');
  var box = document.getElementById('co-item-suggest');
  var form = document.getElementById('co-by-item-form');
  if (!hid || !inp || !box) return;
  var t = null;
  function hide(){ box.hidden = true; box.innerHTML = ''; }
  function pick(id, label){
    hid.value = String(id || '');
    inp.value = label || '';
    hide();
  }
  function escHtml(s){
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
  }
  inp.addEventListener('input', function(){
    hid.value = '';
    var q = (inp.value || '').trim();
    clearTimeout(t);
    if (q.length < 1) { hide(); return; }
    t = setTimeout(function(){
      fetch('/api/lookup/items?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
          var rows = (j && j.rows) || [];
          if (!rows.length) { hide(); return; }
          box.innerHTML = rows.map(function(r){
            var code = r.barcode || r.sku || r.item_code || '';
            var lab = (code ? code + ' — ' : '') + (r.name_ar || '');
            return '<button type="button" class="si-suggest__item" data-id="' + r.id + '" data-label="' +
              escHtml(lab) + '"><strong>' + escHtml(lab) + '</strong></button>';
          }).join('');
          box.hidden = false;
        }).catch(function(){ hide(); });
    }, 220);
  });
  box.addEventListener('click', function(e){
    var btn = e.target.closest('[data-id]');
    if (!btn) return;
    pick(btn.getAttribute('data-id'), btn.getAttribute('data-label'));
  });
  document.addEventListener('click', function(e){
    if (!box.contains(e.target) && e.target !== inp) hide();
  });
  if (form) form.addEventListener('submit', function(e){
    if (!hid.value || Number(hid.value) < 1) {
      e.preventDefault();
      inp.focus();
      alert('اختر المادة من نتائج البحث.');
    }
  });
})();
      </script>`;

    res.send(
      ui.salesPage({
        user: req.session.user,
        title: 'طلبات الشراء حسب المادة',
        bodyHtml: body,
        js: ['/assets/js/sales-print.js'],
      })
    );
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

router.get('/sales/reports/delivery', guard('report_sales_delivery'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير سندات البضاعة',
    mark: 'R8',
    subtitle: 'سندات التسليم',
    path: '/sales/reports/delivery',
    phpRoute: 'report_sales_delivery',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة'],
    colspan: 4,
    fetch: q.reportDelivery,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td class="si-num" dir="ltr">${ui.esc(r.code)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.d))}</td>
        <td>${ui.esc(r.label)}</td>
        <td>${ui.esc(r.status)} ${r.is_posted ? '· مرحّل' : ''}</td>
      </tr>`
        )
        .join(''),
  })
);

router.get('/sales/reports/returns', guard('report_sales_returns'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المرتجعات',
    mark: 'R9',
    subtitle: 'تفاصيل مرتجعات الفترة',
    path: '/sales/reports/returns',
    phpRoute: 'report_sales_returns',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', 'الإجمالي'],
    colspan: 5,
    fetch: q.reportReturns,
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

router.get('/sales/reports/returns-totals', guard('report_sales_returns_totals'), (req, res) =>
  stdReport(req, res, {
    title: 'إجمالي المرتجعات',
    mark: 'R0',
    subtitle: 'تجميع حسب العميل',
    path: '/sales/reports/returns-totals',
    phpRoute: 'report_sales_returns_totals',
    headers: ['العميل', 'عدد', 'الإجمالي'],
    colspan: 3,
    fetch: q.reportReturnsTotals,
    mapRows: (rows) =>
      rows
        .map(
          (r) => `<tr>
        <td>${ui.esc(r.label)}</td>
        <td class="si-num" dir="ltr">${r.cnt}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      </tr>`
        )
        .join(''),
  })
);

/* صورة QR المحفوظة من Oracle Forms — لا نكشف مسار القرص للمتصفح */
router.get(
  '/sales/reports/oracle-sales-invoice/qr',
  guard('report_oracle_sales_invoice'),
  (req, res) => {
    const file = oracleInvoiceQrFile(req.query.year, req.query.invoice_no);
    if (!file) return res.status(404).send('QR not found');
    res.setHeader('Cache-Control', 'private, max-age=300');
    return res.sendFile(file);
  }
);

/* ═══════════════ فاتورة بيع Oracle — شاشة تقليب بالرقم ═══════════════ */
router.get('/sales/reports/oracle-sales-invoice', guard('report_oracle_sales_invoice'), async (req, res) => {
  const BASE = '/sales/reports/oracle-sales-invoice';
  let invoiceNo = Number(req.query.invoice_no || req.query.v_num || 0) || 0;
  let year = Number(req.query.year || req.query.vyear || 0) || 0;
  const navAct = String(req.query.nav || '').toLowerCase().trim();
  const hasNav = ['first', 'last', 'prev', 'next'].includes(navAct);
  const uid = Number(req.session.user?.id || 0) || 0;

  let err = '';
  let data = { ok: true, message: '', matches: [], header: null, lines: [], nav: null };
  const payload = { invoice_no: invoiceNo, year };
  if (hasNav) payload.nav = navAct;

  data = await accNative.run('oracle_sales_invoice', uid, payload);
  if (!data || data.ok === false) {
    err = String(data?.error || data?.message || 'تعذر الاتصال بـ Oracle.');
    data = { ok: false, matches: [], header: null, lines: [], nav: null };
  }

  const matches = Array.isArray(data.matches) ? data.matches : [];
  const header = data.header && typeof data.header === 'object' ? data.header : null;
  const lines = Array.isArray(data.lines) ? data.lines : [];
  const nav = data.nav && typeof data.nav === 'object' ? data.nav : {};

  if (header) {
    invoiceNo = Number(header.v_num || invoiceNo) || 0;
    year = Number(header.vyear || year) || 0;
  }
  const qrFile = header ? oracleInvoiceQrFile(year, invoiceNo) : '';
  const qrUrl = `${basePath.ensurePrefixed(BASE)}/qr?year=${year || 0}&invoice_no=${
    invoiceNo || 0
  }`;

  const hrefKey = (k) =>
    k && k.v_num
      ? `${BASE}?run=1&invoice_no=${Number(k.v_num)}&year=${Number(k.vyear || 0)}`
      : '';
  const hrefNav = (act) =>
    `${BASE}?run=1&nav=${act}&invoice_no=${invoiceNo || 0}&year=${year || 0}`;
  const btn = (href, label, title, disabled) =>
    disabled || !href
      ? `<span class="si-btn si-docno-btn" style="opacity:.35;pointer-events:none" title="${esc(
          title
        )}">${label}</span>`
      : `<a class="si-btn si-docno-btn" href="${esc(href)}" title="${esc(title)}">${label}</a>`;

  let result = '';
  if (err) {
    result = `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>`;
  } else if (matches.length && !header) {
    const rows =
      matches
        .map((m) => {
          const href = hrefKey({ v_num: m.v_num, vyear: m.vyear });
          return `<tr>
            <td class="si-num" dir="ltr">${esc(m.v_num)}</td>
            <td class="si-num" dir="ltr">${esc(m.vyear)}</td>
            <td class="si-num" dir="ltr">${esc(isoToDmy(m.vdate))}</td>
            <td class="si-num" dir="ltr">${esc(m.cust_acc || '—')}</td>
            <td class="si-num" dir="ltr">${esc(m.store)}</td>
            <td class="si-num" dir="ltr">${esc(fmtAmt(m.gross))}</td>
            <td class="no-print"><a class="si-btn" href="${esc(href)}">فتح</a></td>
          </tr>`;
        })
        .join('') || ui.emptyRow(7);
    result = `
      <p class="muted" style="margin-bottom:.6rem">${esc(
        data.message || 'وُجدت عدة فواتير — اختر السنة'
      )}</p>
      ${ui.tableSurface(
        'فواتير بنفس الرقم',
        `${matches.length} فاتورة`,
        ['الرقم', 'السنة', 'التاريخ', 'العميل', 'المستودع', 'الإجمالي', ''],
        rows
      )}`;
  } else if (header) {
    const lineRows =
      lines
        .map(
          (ln, i) => `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        <td class="si-num" dir="ltr">${esc(ln.item || '')}</td>
        <td>${esc(ln.item_name || '—')}</td>
        <td class="si-num" dir="ltr">${esc(ln.cat || '—')}</td>
        <td class="si-num" dir="ltr">${esc(ln.batch || '—')}</td>
        <td class="si-num" dir="ltr">${esc(ln.unit_label || (ln.tr_unit ? '1*' + ln.tr_unit : '—'))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.qty))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.bonus))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.sell))}</td>
        <td class="si-num" dir="ltr">${esc(Number(ln.tax_pct || 0))}%</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.line_gross))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.vou_tax))}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(12, 'لا بنود');

    const qtySum = lines.reduce((s, ln) => s + Number(ln.qty || 0), 0);
    const bonusSum = lines.reduce((s, ln) => s + Number(ln.bonus || 0), 0);
    const discPct = Number(header.per_disc || 0);
    const discLabel = discPct > 0 ? `الخصم (${(discPct * 100).toFixed(2).replace(/\.?0+$/, '')}%)` : 'الخصم';

    const salesmanTxt =
      header.salesman_no || header.salesman_name
        ? `${esc(header.salesman_no || '')}${
            header.salesman_name ? ' — ' + esc(header.salesman_name) : ''
          }`
        : '—';

    result = `
      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بيانات المستند</h2>
          <span class="si-count">${qrFile ? 'مرسلة للفوترة · QR متوفر' : 'Oracle · MAS.DAILY'}</span>
        </div>
        <div class="ora-doc-head" style="display:flex;gap:1rem;align-items:flex-start;padding:.9rem 1rem">
          <div class="si-meta si-meta--invoice ora-doc-meta" style="flex:1 1 auto;min-width:0;padding:0">
          <label class="si-f si-f--docno">
            <span class="si-f-head">رقم الفاتورة</span>
            <input class="si-field si-field--mono" dir="ltr" readonly
                   value="${esc(header.v_num)} / ${esc(header.vyear)}">
          </label>
          <label class="si-f si-f--date">
            <span class="si-f-head">التاريخ</span>
            <input class="si-field si-field--mono" dir="ltr" readonly
                   value="${esc(isoToDmy(header.vdate))}">
          </label>
          <label class="si-f">
            <span class="si-f-head">المستودع</span>
            <input class="si-field si-field--mono" dir="ltr" readonly value="${esc(header.store)}">
          </label>
          <label class="si-f">
            <span class="si-f-head">رقم العميل</span>
            <input class="si-field si-field--mono" dir="ltr" readonly
                   value="${esc(header.cust_acc || '')}">
          </label>
          <label class="si-f si-f--cust">
            <span class="si-f-head">اسم العميل</span>
            <input class="si-field" readonly value="${esc(header.customer_name || '—')}">
          </label>
          <label class="si-f si-f--cust">
            <span class="si-f-head">البائع</span>
            <input class="si-field" readonly value="${salesmanTxt}">
          </label>
          </div>
          ${
            qrFile
              ? `<figure class="ora-doc-qr" style="flex:0 0 auto;width:126px;margin:0;padding:6px;
                    border:1px solid #dfe4ec;border-radius:10px;background:#fff;text-align:center;
                    box-sizing:border-box">
                  <img src="${esc(qrUrl)}" alt="QR الفاتورة ${esc(invoiceNo)}"
                       width="112" height="112"
                       style="display:block;width:112px;height:112px;margin:0 auto;object-fit:contain">
                  <figcaption style="margin-top:4px;font-size:9px;font-weight:700;color:#64748b;
                      line-height:1.3">رمز الفوترة الإلكتروني</figcaption>
                </figure>`
              : ''
          }
        </div>
      </section>

      <section class="si-surface">
        <div class="si-surface-head">
          <h2>بنود الفاتورة</h2>
          <span class="si-count">${lines.length} بند</span>
        </div>
        <div class="si-table-wrap">
          <table class="si-table ora-doc-lines">
            <thead>
              <tr>
                <th>#</th><th>المادة</th><th>البيان</th><th>الفئة</th><th>التشغيلة</th><th>الوحدة</th>
                <th>الكمية</th><th>بونص</th><th>السعر</th><th>ض%</th><th>الإجمالي</th><th>الضريبة</th>
              </tr>
            </thead>
            <tbody>${lineRows}</tbody>
            ${
              lines.length
                ? `<tfoot>
                    <tr class="ora-doc-lines__sum">
                      <td colspan="6">مجموع البنود</td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(qtySum))}</td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(bonusSum))}</td>
                      <td></td><td></td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(header.gross))}</td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(header.tax_sum))}</td>
                    </tr>
                  </tfoot>`
                : ''
            }
          </table>
        </div>
        <div class="ora-doc-foot">
          <div class="ora-doc-note">
            <span class="ora-doc-note__head">بيانات النظام القديم</span>
            <p>فاتورة بيع من Oracle Forms 6i · جدول MAS.DAILY · النوع 9 · للعرض والطباعة فقط.</p>
          </div>
          <table class="ora-doc-totals">
            <tr><th>مجموع الفاتورة</th><td dir="ltr">${esc(fmtAmt(header.gross))}</td></tr>
            <tr><th>${esc(discLabel)}</th><td dir="ltr">${esc(fmtAmt(header.vou_disc))}</td></tr>
            <tr><th>الصافي قبل الضريبة</th><td dir="ltr">${esc(fmtAmt(header.net))}</td></tr>
            <tr><th>قيمة الضريبة</th><td dir="ltr">${esc(fmtAmt(header.tax_sum))}</td></tr>
            <tr class="ora-doc-totals__grand"><th>الإجمالي النهائي</th><td dir="ltr">${esc(
              fmtAmt(header.total)
            )}</td></tr>
          </table>
        </div>
      </section>`;
  } else {
    result = `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(
      data.message || 'لا توجد فواتير بيع في Oracle'
    )}</p>`;
  }

  const toolbar = `
    <form class="ora-nav no-print" method="get" action="${BASE}" id="ora-inv-nav-form">
      <input type="hidden" name="run" value="1">
      <div class="ora-nav__group ora-nav__group--no">
        <span class="ora-nav__lab">رقم الفاتورة</span>
        <div class="ora-nav__row" dir="ltr">
          ${btn(hrefNav('first'), '«', 'أول فاتورة', false)}
          ${btn(nav.prev ? hrefKey(nav.prev) : hrefNav('prev'), '‹', 'السابق', !nav.prev && !!header)}
          <input class="ora-nav__no" type="text" name="invoice_no"
                 id="ora_inv_no" value="${invoiceNo || ''}"
                 inputmode="numeric" dir="ltr" placeholder="رقم" autocomplete="off"
                 title="اكتب الرقم ثم Enter · الأسهم للتقليب">
          ${btn(nav.next ? hrefKey(nav.next) : hrefNav('next'), '›', 'التالي', !nav.next && !!header)}
          ${btn(hrefNav('last'), '»', 'آخر فاتورة', false)}
        </div>
      </div>
      <div class="ora-nav__group">
        <span class="ora-nav__lab">السنة</span>
        <input class="ora-nav__year" type="text" name="year" id="ora_inv_year"
               value="${year || ''}"
               inputmode="numeric" dir="ltr" placeholder="كل السنوات" autocomplete="off">
      </div>
      <div class="ora-nav__group">
        <span class="ora-nav__lab" aria-hidden="true">&nbsp;</span>
        <button class="ora-nav__go" type="submit">عرض الفاتورة</button>
      </div>
      <p class="ora-nav__hint">
        <kbd>Enter</kbd> عرض · <kbd>←</kbd> سابق · <kbd>→</kbd> تالي · <kbd>Home</kbd> أول · <kbd>End</kbd> آخر
      </p>
    </form>
    <style>
      .ora-nav{display:flex;flex-wrap:wrap;gap:.7rem 1.1rem;align-items:flex-end;
        margin-bottom:1rem;padding:.85rem 1rem;border:1px solid #e2e8f0;border-radius:14px;
        background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.05)}
      .ora-nav__group{display:flex;flex-direction:column;gap:.32rem}
      .ora-nav__lab{font-size:.7rem;font-weight:800;letter-spacing:.05em;color:#64748b;
        text-transform:uppercase}
      .ora-nav__row{display:flex;align-items:center;gap:.3rem;padding:.2rem;border-radius:11px;
        background:#f1f5f9;border:1px solid #e2e8f0}
      .ora-nav .si-docno-btn{display:inline-flex;align-items:center;justify-content:center;
        min-width:2.1rem;height:2.1rem;padding:0;font-weight:800;font-size:1rem;line-height:1;
        border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#0369a1;
        text-decoration:none;transition:.15s}
      .ora-nav .si-docno-btn:hover{background:#0369a1;color:#fff;border-color:#0369a1}
      .ora-nav__no,.ora-nav__year{height:2.1rem;border:1px solid #cbd5e1;border-radius:8px;
        padding:.2rem .5rem;font-family:var(--si-mono,ui-monospace,Consolas,monospace);
        background:#fff;box-sizing:border-box}
      .ora-nav__no{width:8rem;text-align:center;font-weight:800;font-size:1.05rem;color:#0f172a}
      .ora-nav__no:focus,.ora-nav__year:focus{outline:none;border-color:#0369a1;
        box-shadow:0 0 0 3px rgba(3,105,161,.15)}
      .ora-nav__year{width:7.5rem;text-align:center;font-weight:700}
      .ora-nav__go{height:2.1rem;padding:0 1.3rem;border:0;border-radius:9px;cursor:pointer;
        font-weight:800;font-size:.9rem;color:#fff;
        background:linear-gradient(180deg,#0ea5e9,#0369a1);box-shadow:0 1px 3px rgba(3,105,161,.35)}
      .ora-nav__go:hover{filter:brightness(1.06)}
      .ora-nav__hint{flex:1 1 100%;margin:0;font-size:.76rem;color:#94a3b8;
        display:flex;flex-wrap:wrap;gap:.35rem;align-items:center}
      .ora-nav__hint kbd{font-family:inherit;font-size:.72rem;font-weight:700;color:#475569;
        background:#f1f5f9;border:1px solid #e2e8f0;border-radius:5px;padding:.05rem .35rem}
      .ora-doc-meta .si-field[readonly]{background:rgba(15,23,42,.03);cursor:default}
      .ora-doc-lines tfoot .ora-doc-lines__sum td{background:rgba(15,23,42,.05);font-weight:800}
      .ora-doc-foot{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;
        gap:1rem;padding:1rem 1.1rem 1.15rem}
      .ora-doc-note{flex:1 1 16rem;min-width:14rem}
      .ora-doc-note__head{font-size:.72rem;font-weight:800;letter-spacing:.04em;color:#5c6578;
        text-transform:uppercase}
      .ora-doc-note p{margin:.35rem 0 0;font-size:.82rem;color:#7b8494;line-height:1.5}
      .ora-doc-totals{border-collapse:collapse;font-size:.9rem;min-width:19rem;
        border:1px solid #cfd7e3 !important}
      .ora-doc-totals th,.ora-doc-totals td{border:1px solid #dfe4ec !important;padding:.34rem .8rem}
      .ora-doc-totals th{background:#eef1f6 !important;text-align:right;font-weight:600;
        color:#43506b;white-space:nowrap}
      .ora-doc-totals td{text-align:left;font-weight:700;min-width:8rem;
        font-variant-numeric:tabular-nums}
      .ora-doc-totals__grand th,.ora-doc-totals__grand td{background:#e3e9fb !important;
        font-weight:800;font-size:.98rem;color:#1f2a44}
      @media print{
        .ora-doc-lines tfoot{display:table-footer-group}
        .ora-doc-head,.ora-doc-qr,.ora-doc-foot,.ora-doc-totals{page-break-inside:avoid}
      }
      @media(max-width:720px){
        .ora-doc-head{flex-wrap:wrap}
      }
    </style>
    <script>
    (function(){
      var form=document.getElementById('ora-inv-nav-form');
      var noEl=document.getElementById('ora_inv_no');
      var yearEl=document.getElementById('ora_inv_year');
      if(!form||!noEl) return;
      var base=${JSON.stringify(basePath.ensurePrefixed(BASE) + '?run=1')};
      var curNo=${invoiceNo || 0};
      var curYear=${year || 0};
      var yearTouched=false;
      if(yearEl){ yearEl.addEventListener('input',function(){ yearTouched=true; }); }
      function digits(v){ return String(v||'').replace(/[^0-9]/g,''); }
      function goNav(act){
        location.href=base+'&nav='+act+'&invoice_no='+encodeURIComponent(curNo||0)
          +'&year='+encodeURIComponent(curYear||0);
      }
      function show(){
        var n=digits(noEl.value);
        if(!n){ goNav('last'); return; }
        var y=digits(yearEl?yearEl.value:'');
        if(Number(n)!==curNo && !yearTouched){ y=''; }
        location.href=base+'&invoice_no='+encodeURIComponent(n)+(y?'&year='+encodeURIComponent(y):'');
      }
      form.addEventListener('submit',function(e){ e.preventDefault(); show(); });
      noEl.addEventListener('keydown',function(e){
        if(e.key==='Enter'){ e.preventDefault(); show(); }
        else if(e.key==='ArrowLeft'||e.key==='ArrowUp'){ e.preventDefault(); goNav('prev'); }
        else if(e.key==='ArrowRight'||e.key==='ArrowDown'){ e.preventDefault(); goNav('next'); }
        else if(e.key==='Home'){ e.preventDefault(); goNav('first'); }
        else if(e.key==='End'){ e.preventDefault(); goNav('last'); }
      });
      if(yearEl){
        yearEl.addEventListener('keydown',function(e){
          if(e.key==='Enter'){ e.preventDefault(); show(); }
        });
      }
      try{ noEl.focus(); noEl.select(); }catch(err){}
    })();
    </script>`;

  const titleLine = header ? `فاتورة ${invoiceNo} / ${year}` : 'فاتورة بيع Oracle';

  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: header ? `فاتورة Oracle ${invoiceNo}` : 'فاتورة بيع Oracle',
      activePath: BASE,
      css: ['/assets/css/customer-order-doc.css'],
      bodyHtml: `
        <div class="si-stage">
          <header class="si-hero">
            <div class="si-brand-lockup">
              <div class="si-brand-text">
                <p class="si-kicker">Hypex Sales · Oracle</p>
                <h1>${esc(titleLine)}</h1>
                <div class="si-hero-badge">
                  <span class="si-pill si-pill--lock">النظام القديم — قراءة فقط</span>
                </div>
              </div>
            </div>
            <div class="si-hero-actions">
              ${ui.siPrintBtnHtml('طباعة')}
              <a class="si-btn no-print" href="/sales/invoices">فاتورة مبيعات</a>
              <a class="si-btn no-print" href="/sales">لوحة المبيعات</a>
            </div>
          </header>

          ${toolbar}
          ${result}
        </div>`,
    })
  );
});

module.exports = router;
