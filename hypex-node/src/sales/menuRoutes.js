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
const excelCats = require('./excelItemCategories');

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
    oraSkin = true,
  } = opts;
  const actions = [
    ...extraActions,
    { label: 'لوحة المبيعات', href: '/sales' },
  ];
  const body = `
    <div class="si-stage co-ora-skin">
      ${ui.hero({ mark, title, subtitle, actions })}
      ${searchPath ? ui.railSearch(searchPath, qVal) : ''}
      ${ui.tableSurface(tableTitle || title, `${count} صف`, headers, rowsHtml)}
    </div>`;
  res.send(
    ui.salesPage({
      user,
      title,
      bodyHtml: body,
      bodyClass: 'si-2027 co-ora-body',
    })
  );
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

router.get('/sales/orders', async (req, res) => {
  const u = req.session.user;
  if (!can(u, 'sales_customer_orders') && !u.is_admin) {
    return res.status(403).send(
      ui.salesPage({
        user: u,
        title: 'ممنوع',
        bodyHtml: `<div class="si-stage">${ui.hero({ title: 'ممنوع', subtitle: 'لا صلاحية لهذه الشاشة' })}</div>`,
      })
    );
  }
  const qv = String(req.query.q || '');
  const rows = await q.listOrders({ q: qv });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmyHm(r.created_at || r.order_date))}</td>
      <td>${ui.esc(r.customer_name || '—')}</td>
      <td>${ui.statusPill(r.status === 'approved' ? 'ok' : 'wait', r.status === 'approved' ? 'معتمد' : 'مسودة')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.total))}</td>
      <td><a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/orders/${r.id}">فتح</a></td>
    </tr>`
      )
      .join('') || ui.emptyRow(6);
  listPage(res, req.session.user, {
    title: 'قائمة طلبات الشراء',
    tableTitle: 'سجل الطلبات',
    mark: 'PO',
    subtitle: 'قائمة الطلبات — فتح وتعديل',
    headers: ['الرقم', 'التاريخ والوقت', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/sales/orders',
    qVal: qv,
    phpRoute: 'sales_customer_order_entry',
    extraActions: [{ label: '＋ طلب جديد', href: '/sales/orders/new', primary: true }],
    oraSkin: true,
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
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmyHm(r.created_at || r.order_date))}</td>
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
    headers: ['الرقم', 'التاريخ والوقت', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: all.length,
    phpRoute: 'sales_customer_orders_approve',
    oraSkin: true,
  });
});

router.get('/sales/orders/approved', guard('sales_customer_orders_approve'), async (req, res) => {
  const rows = await q.listOrders({ status: 'approved' });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${ui.esc(r.order_no)}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmyHm(r.created_at || r.order_date))}</td>
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
    headers: ['الرقم', 'التاريخ والوقت', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    phpRoute: 'sales_customer_orders_approved',
    oraSkin: true,
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

router.get('/sales/reports/customer-orders-detailed/options', async (req, res) => {
  const u = req.session && req.session.user;
  if (!u || (!can(u, 'report_customer_orders_detailed') && !u.is_admin)) {
    return res.status(403).json({ ok: false, rows: [] });
  }
  try {
    const kind = String(req.query.kind || '');
    if (kind === 'customers') {
      const rows = await q.listCustomersForReps(req.query.sales_rep_id);
      return res.json({ ok: true, rows });
    }
    if (kind === 'items') {
      const rows = await q.listItemsForCategory(req.query.category_id);
      return res.json({ ok: true, rows });
    }
    return res.json({ ok: false, rows: [] });
  } catch (e) {
    console.error('customer-orders-detailed options', e);
    return res.status(500).json({ ok: false, rows: [] });
  }
});

router.get('/sales/reports/customer-orders-detailed', async (req, res) => {
  const u = req.session.user;
  if (!can(u, 'report_customer_orders_detailed') && !u.is_admin) {
    return res.status(403).send(
      ui.salesPage({
        user: u,
        title: 'ممنوع',
        bodyHtml: `<div class="si-stage">${ui.hero({
          title: 'ممنوع',
          subtitle: 'لا صلاحية لهذه الشاشة',
        })}</div>`,
      })
    );
  }

  try {
    const MONTHS = [
      '',
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ];
    const now = new Date();
    const run = String(req.query.run || '') === '1';
    const numList = (value, min, max) => {
      const raw = Array.isArray(value) ? value : value == null || value === '' ? [] : [value];
      const out = [];
      for (const v of raw) {
        const n = Number(v) || 0;
        if (n >= min && n <= max && !out.includes(n)) out.push(n);
      }
      out.sort((a, b) => a - b);
      return out;
    };
    const onRaw = String(req.query.on_date || '').trim();
    let onDate = /^\d{4}-\d{2}-\d{2}$/.test(onRaw) ? onRaw : '';
    let years = numList(req.query.year, 1900, 2100);
    let months = numList(req.query.month, 1, 12);
    let days = numList(req.query.day, 1, 31);
    if (onDate) {
      years = [Number(onDate.slice(0, 4))];
      months = [Number(onDate.slice(5, 7))];
      days = [Number(onDate.slice(8, 10))];
    } else if (!run) {
      if (!years.length) years = [now.getFullYear()];
      if (!months.length) months = [now.getMonth() + 1];
    }
    const salesRepIds = (Array.isArray(req.query.sales_rep_id)
      ? req.query.sales_rep_id
      : req.query.sales_rep_id
        ? [req.query.sales_rep_id]
        : []
    )
      .map((v) => Number(v) || 0)
      .filter((n, i, arr) => n > 0 && arr.indexOf(n) === i);
    const pickedIds = (value) =>
      (Array.isArray(value) ? value : value ? [value] : [])
        .map((v) => Number(v) || 0)
        .filter((n, i, arr) => n > 0 && arr.indexOf(n) === i);
    const categoryIds = pickedIds(req.query.category_id);
    const customerIds = pickedIds(req.query.customer_id);
    const itemIds = pickedIds(req.query.item_id);
    const onAll = (key) => String(req.query[key] || '') === '1';
    const yearAll = onAll('year_all');
    const monthAll = onAll('month_all');
    const dayAll = onAll('day_all');
    const repAll = onAll('sales_rep_id_all');
    const catAll = onAll('category_id_all');
    const custAll = onAll('customer_id_all');
    const itemAll = onAll('item_id_all');
    const status = String(req.query.status || 'all').toLowerCase();
    let err = '';
    let data = {
      groups: [],
      details: [],
      totals: { qty: 0, line_gross: 0, line_count: 0, order_count: 0 },
      period: { years, months, days, on_date: onDate },
    };

    const [reps, categories, customers] = await Promise.all([
      q.listRepsSimple(),
      q.listCategoriesSimple(),
      q.listCustomersForReps(repAll ? [] : salesRepIds),
    ]);
    const categoryFilter = catAll
      ? []
      : categoryIds.filter((id) => categories.some((c) => Number(c.id) === id));
    const itemSourceIds = catAll ? categories.map((c) => Number(c.id)) : categoryFilter;
    const items = await q.listItemsForCategory(itemSourceIds);
    const customerFilter = custAll
      ? []
      : customerIds.filter((id) => customers.some((c) => Number(c.id) === id));
    const itemFilter = itemAll ? [] : itemIds.filter((id) => items.some((it) => Number(it.id) === id));

    if (yearAll) {
      years = [];
      for (let y = now.getFullYear() + 1; y >= now.getFullYear() - 6; y -= 1) years.push(y);
    }
    if (monthAll) months = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
    if (dayAll) {
      days = [];
      for (let d = 1; d <= 31; d += 1) days.push(d);
    }

    if (run) {
      try {
        data = await q.reportCustomerOrdersByRepCategory({
          years,
          months,
          days,
          on_date: onDate,
          sales_rep_ids: repAll ? [] : salesRepIds,
          category_ids: categoryFilter,
          customer_ids: customerFilter,
          item_ids: itemFilter,
          status,
        });
      } catch (e) {
        err = e.message || 'تعذر تحميل التقرير';
        console.error('customer-orders-detailed', e);
      }
    }

    const yearNow = now.getFullYear();
    const yearValues = [];
    for (let y = yearNow + 1; y >= yearNow - 6; y -= 1) yearValues.push(y);
    for (const y of years) {
      if (!yearValues.includes(y)) yearValues.push(y);
    }
    yearValues.sort((a, b) => b - a);
    const check = (name, value, label, selected) =>
      `<label><input type="checkbox" name="${name}" value="${value}" ${
        selected ? 'checked' : ''
      }> ${label}</label>`;
    const pickedText = (labels, total) => {
      if (!labels.length || (total > 0 && labels.length >= total)) return 'الكل';
      if (labels.length <= 2) return labels.join('، ');
      return labels.length + ' محدد';
    };
    const menu = (id, title, panelClass, checks, valueText) => `<div class="co-ms-wrap">
        <details class="co-ms" id="${id}">
          <summary class="co-ms__btn">
            <span class="co-ms__label">${title}</span>
            <span class="co-ms__value">${ui.esc(valueText || 'الكل')}</span>
          </summary>
          <div class="co-ms__panel${panelClass ? ' ' + panelClass : ''}">
            <label class="co-ms__all"><input type="checkbox" data-all> اختيار الكل</label>
            <div class="co-ms__list">${checks}</div>
          </div>
        </details>
      </div>`;
    const yearChecks = yearValues
      .map((y) => check('year', y, String(y), yearAll || years.includes(y)))
      .join('');
    const monthChecks = MONTHS.slice(1)
      .map((name, i) => check('month', i + 1, name, monthAll || months.includes(i + 1)))
      .join('');
    let dayChecks = '';
    for (let d = 1; d <= 31; d += 1) {
      dayChecks += check('day', d, String(d), dayAll || days.includes(d));
    }
    const repChecks = reps
      .map((r) => {
        const id = Number(r.id);
        const label =
          ui.esc(r.name_ar || '') + (r.code ? ' (' + ui.esc(r.code) + ')' : '');
        return check('sales_rep_id', id, label, repAll || salesRepIds.includes(id));
      })
      .join('');
    const catChecks = categories
      .map((c) =>
        check('category_id', c.id, ui.esc(c.name_ar || ''), catAll || categoryIds.includes(Number(c.id)))
      )
      .join('');
    const custChecks = customers
      .map((c) => {
        const label = ui.esc(c.name_ar || '') + (c.code ? ' (' + ui.esc(c.code) + ')' : '');
        return check('customer_id', c.id, label, custAll || customerFilter.includes(Number(c.id)));
      })
      .join('');
    const itemChecks = items
      .map((it) => {
        const code = it.barcode || it.sku || '';
        const lab = ui.esc((code ? code + ' — ' : '') + (it.name_ar || ''));
        return check('item_id', it.id, lab, itemAll || itemIds.includes(Number(it.id)));
      })
      .join('');
    const yearMenu = menu(
      'co-det-years',
      'السنة',
      '',
      yearChecks,
      pickedText(
        yearValues.filter((y) => years.includes(y)).map(String),
        yearValues.length
      )
    );
    const monthMenu = menu(
      'co-det-months',
      'الشهر',
      '',
      monthChecks,
      pickedText(
        MONTHS.slice(1).filter((_, i) => months.includes(i + 1)),
        12
      )
    );
    const dayMenu = menu(
      'co-det-days',
      'أيام الشهر',
      'co-ms__panel--days',
      dayChecks,
      pickedText(days.map(String), 31)
    );
    const repMenu = menu(
      'co-det-reps',
      'المندوب',
      '',
      repChecks,
      pickedText(
        reps.filter((r) => salesRepIds.includes(Number(r.id))).map((r) => r.name_ar || ''),
        reps.length
      )
    );
    const custMenu = menu(
      'co-det-customer',
      'العميل',
      '',
      custChecks,
      pickedText(
        customers.filter((c) => customerFilter.includes(Number(c.id))).map((c) => c.name_ar || ''),
        customers.length
      )
    );
    const catMenu = menu(
      'co-det-category',
      'الفئة',
      '',
      catChecks,
      pickedText(
        categories.filter((c) => categoryFilter.includes(Number(c.id))).map((c) => c.name_ar || ''),
        categories.length
      )
    );
    const itemMenu = menu(
      'co-det-item',
      'المادة',
      '',
      itemChecks,
      categoryIds.length || catAll
        ? pickedText(
            items
              .filter((it) => itemAll || itemIds.includes(Number(it.id)))
              .map((it) => it.name_ar || ''),
            items.length
          )
        : 'اختر الفئة'
    );

    const colCount = 7;
    function sumGross(rows) {
      let n = 0;
      for (const r of rows || []) n += Number(r.line_gross || 0);
      return n;
    }
    function totalRow(label, amount, cls, foldKey) {
      const fold = foldKey ? ` co-det-fold" data-fold="${foldKey}` : '';
      return `<tr class="${cls}${fold}"><td colspan="6"><strong>${ui.esc(label)}</strong></td>
        <td class="si-num" dir="ltr"><strong>${ui.esc(ui.fmtAmt(amount || 0))}</strong></td></tr>`;
    }
    function itemTotalRow(name, amount, foldKey) {
      return `<tr class="co-det-item-tot co-det-fold" data-fold="${foldKey}">
        <td></td><td></td><td></td><td></td><td></td>
        <td><strong>مجموع المادة · ${ui.esc(name || '')}</strong></td>
        <td class="si-num" dir="ltr"><strong>${ui.esc(ui.fmtAmt(amount || 0))}</strong></td>
      </tr>`;
    }
    function detailRowHtml(r, foldKey) {
      const repTitle =
        (r.sales_rep_code ? r.sales_rep_code + ' — ' : '') + (r.sales_rep_name || '—');
      return `<tr class="co-det-fold" data-fold="${foldKey}">
        <td>${ui.esc(r.customer_name || '—')}</td>
        <td>${ui.esc(repTitle)}</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.order_date))}</td>
        <td class="si-num" dir="ltr">${ui.esc(r.order_no || '')}</td>
        <td>${ui.esc(r.category_name || '—')}</td>
        <td>${ui.esc(r.item_name || '')}${
          r.item_sku ? ` <span class="muted" dir="ltr">(${ui.esc(r.item_sku)})</span>` : ''
        }</td>
        <td class="si-num" dir="ltr">${ui.esc(ui.fmtAmt(r.line_gross))}</td>
      </tr>`;
    }

    let rowsHtml = '';
    if (!run) {
      rowsHtml = ui.emptyRow(colCount, 'حدّد الفلاتر ثم اعرض التقرير');
    } else if (err) {
      rowsHtml = ui.emptyRow(colCount, err);
    } else if (!(data.details || []).length) {
      rowsHtml = ui.emptyRow(colCount, 'لا توجد بنود طلبات شراء في الفترة المحددة');
    } else {
      const repMap = new Map();
      const repBlocks = [];
      for (const row of data.details) {
        const rk = String(row.sales_rep_id);
        let rep = repMap.get(rk);
        if (!rep) {
          rep = {
            sales_rep_name: row.sales_rep_name,
            sales_rep_code: row.sales_rep_code,
            categories: [],
            catMap: new Map(),
            rows: [],
          };
          repMap.set(rk, rep);
          repBlocks.push(rep);
        }
        rep.rows.push(row);
        const ck = String(row.category_id);
        let cat = rep.catMap.get(ck);
        if (!cat) {
          cat = {
            category_name: row.category_name,
            items: [],
            itemMap: new Map(),
            rows: [],
          };
          rep.catMap.set(ck, cat);
          rep.categories.push(cat);
        }
        cat.rows.push(row);
        const ik = String(row.item_id);
        let item = cat.itemMap.get(ik);
        if (!item) {
          item = { item_name: row.item_name, rows: [] };
          cat.itemMap.set(ik, item);
          cat.items.push(item);
        }
        item.rows.push(row);
      }
      let foldSeq = 0;
      for (const rep of repBlocks) {
        const repTitle =
          (rep.sales_rep_code ? rep.sales_rep_code + ' — ' : '') + (rep.sales_rep_name || 'مندوب');
        rowsHtml += `<tr class="co-det-rep"><td colspan="${colCount}"><strong>${ui.esc(
          repTitle
        )}</strong></td></tr>`;
        for (const cat of rep.categories) {
          const foldKey = 'f' + ++foldSeq;
          const catName = cat.category_name || '—';
          rowsHtml += `<tr class="co-det-cat">
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td>
              <button type="button" class="co-det-twist" data-fold="${foldKey}" aria-expanded="false">
                <span class="co-det-twist__icon" aria-hidden="true"></span>
                <span>${ui.esc(catName)}</span>
              </button>
            </td>
            <td class="co-det-cat__count">${cat.items.length} ${cat.items.length === 1 ? 'مادة' : 'مواد'}</td>
            <td class="si-num" dir="ltr"><strong>${ui.esc(ui.fmtAmt(sumGross(cat.rows)))}</strong></td>
          </tr>`;
          for (const item of cat.items) {
            for (const r of item.rows) rowsHtml += detailRowHtml(r, foldKey);
            rowsHtml += itemTotalRow(item.item_name || '', sumGross(item.rows), foldKey);
          }
        }
        rowsHtml += totalRow(
          'مجموع المندوب · ' + (rep.sales_rep_name || ''),
          sumGross(rep.rows),
          'co-det-rep-tot'
        );
      }
      rowsHtml += totalRow('الإجمالي', data.totals.line_gross, 'co-det-grand');
    }

    const body = `
      <div class="si-stage si-report-page co-det-page" data-hx-print-landscape="1">
        ${ui.hero({
          mark: '📑',
          kicker: 'Hypex Sales · Node',
          title: 'تقرير تفصيلي لطلبات الشراء',
          subtitle: '',
          actions: [
            ui.printAction(),
            { label: 'تقرير طلبات الشراء', href: '/sales/reports/customer-orders' },
            { label: 'لوحة المبيعات', href: '/sales' },
          ],
        })}
        ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${ui.esc(err)}</p>` : ''}
        <div class="si-rail no-print">
          <form method="get" action="/sales/reports/customer-orders-detailed" id="co-det-form" class="co-det-filters">
            <input type="hidden" name="run" value="1">
            ${yearMenu}
            ${monthMenu}
            ${dayMenu}
            ${repMenu}
            ${custMenu}
            ${catMenu}
            ${itemMenu}
            <label>الحالة
              <select class="si-field" name="status">
                <option value="all" ${status === 'all' ? 'selected' : ''}>الكل</option>
                <option value="draft" ${status === 'draft' ? 'selected' : ''}>مسودة</option>
                <option value="approved" ${status === 'approved' ? 'selected' : ''}>معتمد</option>
              </select>
            </label>
            <button class="si-btn si-btn--primary co-det-run-btn" type="submit" title="التحديث يتم تلقائياً عند الاختيار">تحديث</button>
          </form>
          <p class="co-det-slicer-hint no-print">اختر من القوائم كما في Power BI — القائمة تبقى مفتوحة أثناء التحديد، والجدول يتحدّث تلقائياً.</p>
        </div>
        <div id="co-det-results">
        ${
          run && !err
            ? `<div class="sd-kpi-row no-print" style="display:flex;flex-wrap:wrap;gap:.65rem;margin:.75rem 0">
          <div class="sd-kpi"><span>البنود</span><strong dir="ltr">${Number(
            data.totals.line_count || 0
          )}</strong></div>
          <div class="sd-kpi"><span>الطلبات</span><strong dir="ltr">${Number(
            data.totals.order_count || 0
          )}</strong></div>
          <div class="sd-kpi sd-kpi--accent"><span>المبيعات</span><strong dir="ltr">${ui.esc(
            ui.fmtAmt(data.totals.line_gross || 0)
          )}</strong></div>
        </div>`
            : ''
        }
        <div class="si-print-area">
          <style>
            .co-det-rep td{background:#1e3a5f;color:#fff;font-weight:800;padding:.55rem .65rem !important}
            .co-det-cat td{background:#e8eef7;color:#1e3a5f;font-weight:700;padding:.4rem .65rem !important}
            .co-det-cat__count{font-weight:600;color:#64748b}
            .co-det-item-tot td{background:#f8fafc;font-weight:700}
            .co-det-cat-tot td{background:#eef6ff;font-weight:800;border-top:1px dashed #cbd5e1}
            .co-det-rep-tot td{background:#dbeafe;font-weight:800}
            .co-det-grand td{background:#1e3a5f;color:#fff;font-weight:800}
            .co-det-grand td strong{color:#fff}
          </style>
          ${ui.tableSurface(
            'تفصيل طلبات الشراء حسب المندوب والفئة والمادة',
            run ? `${data.details.length} سطر` : '—',
            ['العميل', 'المندوب', 'تاريخ الطلبية', 'رقم الطلبية', 'الفئة', 'المادة', 'المبيعات'],
            rowsHtml
          )}
        </div>
        </div>
      </div>
      `;

    res.send(
      ui.salesPage({
        user: req.session.user,
        title: 'تقرير تفصيلي لطلبات الشراء',
        bodyHtml: body,
        css: ['/assets/css/report-sales-detailed.css'],
        js: ['/assets/js/customer-orders-detailed-filters.js', '/assets/js/sales-print.js'],
        printTitle: 'تقرير تفصيلي لطلبات الشراء',
        activePath: '/sales/reports/customer-orders-detailed',
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

/* ═══════════════ صافي مبيعات المندوب Oracle ═══════════════ */
router.get('/sales/reports/oracle-rep-net-sales', guard('report_oracle_rep_net_sales'), async (req, res) => {
  const BASE = '/sales/reports/oracle-rep-net-sales';
  const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
  const store = Number(req.query.store || 4) || 4;
  // sales_rep_id = رقم مندوب Oracle (MAN_NUM) = رمز بطاقة هايبكس — ليس id الداخلي
  const oracleRepNo = Number(req.query.sales_rep_id || 0) || 0;
  const run = String(req.query.run || '') === '1' || oracleRepNo > 0;
  const uid = Number(req.session.user?.id || 0) || 0;

  const parseManNo = (code) => {
    const c = String(code || '').trim();
    if (/^[1-9]\d*$/.test(c)) return Number(c);
    const m = c.match(/(\d+)/);
    if (m) {
      const n = Number(m[1]);
      if (n > 0 && n < 100000) return n;
    }
    return 0;
  };

  // أسماء المناديب المعتمدة عندكم: بطاقة هايبكس (الرمز = MAN_NUM Forms)
  // EMP_INFO غالباً موظفون وليسوا مناديب الفواتير → لا نعتمد اسمه للعرض
  const hypexReps = await q.listRepsSimple();
  const hypexNameByMan = new Map();
  for (const r of hypexReps || []) {
    const manNo = parseManNo(r.code);
    if (manNo < 1 || hypexNameByMan.has(manNo)) continue;
    const nm = String(r.name_ar || '').trim();
    if (nm) hypexNameByMan.set(manNo, nm);
  }

  const repsPayload = await accNative.run('oracle_list_rep_net_sales_reps', uid, {});
  const oraRows = Array.isArray(repsPayload?.rows) ? repsPayload.rows : [];
  const seen = new Set();
  let reps = [];

  // أرقام لها حركات في Oracle + اسم هايبكس إن وُجد
  for (const r of oraRows) {
    const manNo = Number(r.id) || parseManNo(r.code);
    if (manNo < 1 || seen.has(manNo)) continue;
    seen.add(manNo);
    reps.push({
      id: manNo,
      code: String(manNo),
      name_ar: hypexNameByMan.get(manNo) || String(r.name_ar || '').trim() || `مندوب ${manNo}`,
    });
  }
  // أضف بطاقات هايبكس حتى لو لم تظهر بعد في حركات الفترة
  for (const [manNo, nm] of hypexNameByMan.entries()) {
    if (seen.has(manNo)) continue;
    seen.add(manNo);
    reps.push({ id: manNo, code: String(manNo), name_ar: nm });
  }
  reps.sort((a, b) => String(a.name_ar).localeCompare(String(b.name_ar), 'ar'));

  let err = '';
  let rows = [];
  let totals = { inv_cnt: 0, net: 0, cost: 0, profit: 0, profit_pct: 0 };
  let repName = '';
  let oraFilters = null;

  if (run) {
    let repFrom = null;
    let repTo = null;
    if (oracleRepNo > 0) {
      repName = hypexNameByMan.get(oracleRepNo) ||
        (reps.find((r) => Number(r.id) === oracleRepNo)?.name_ar) ||
        `مندوب ${oracleRepNo}`;
      repFrom = oracleRepNo;
      repTo = oracleRepNo;
    }

    const data = await accNative.run('oracle_rep_net_sales', uid, {
      from: range.from,
      to: range.to,
      store,
      rep_from: repFrom,
      rep_to: repTo,
    });
    if (!data || data.ok === false) {
      err = String(data?.error || data?.message || 'تعذر الاتصال بـ Oracle.');
    } else {
      rows = (Array.isArray(data.rows) ? data.rows : []).map((r) => {
        const no = Number(r.rep_no) || 0;
        const hx = no > 0 ? hypexNameByMan.get(no) : '';
        return hx ? { ...r, rep_name: hx } : r;
      });
      totals = data.totals && typeof data.totals === 'object' ? data.totals : totals;
      oraFilters = data.filters && typeof data.filters === 'object' ? data.filters : null;
      if (!rows.length && data.message) err = String(data.message);
      if (oracleRepNo > 0) {
        const hx = hypexNameByMan.get(oracleRepNo);
        if (hx) repName = hx;
        else if (rows.length === 1 && rows[0].rep_name) repName = String(rows[0].rep_name);
      } else if (rows.length === 1 && rows[0].rep_name) {
        repName = String(rows[0].rep_name);
      }
    }
  }

  const repsJson = JSON.stringify(reps).replace(/</g, '\\u003c');
  const mapWarn = `<p class="muted no-print" style="margin:0 1rem .35rem;font-size:.8rem">الفلتر برقم المندوب (MAN_NUM / رمز البطاقة) — الاسم من بطاقة المناديب في هايبكس (مثل Forms). المبالغ من Oracle.</p>`;
  const mismatchWarn = '';

  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${esc(r.rep_no)}</td>
      <td>${esc(r.rep_name || '')}</td>
      <td class="si-num" dir="ltr">${esc(r.inv_cnt)}</td>
      <td class="si-num" dir="ltr">${esc(fmtAmt(r.net))}</td>
      <td class="si-num" dir="ltr">${esc(fmtAmt(r.cost))}</td>
      <td class="si-num" dir="ltr">${esc(fmtAmt(r.profit))}</td>
      <td class="si-num" dir="ltr">${esc(fmtAmt(r.profit_pct))}</td>
    </tr>`
      )
      .join('') || (run && !err ? ui.emptyRow(7, 'لا توجد بيانات') : '');

  const foot =
    rows.length > 0
      ? `<tr>
          <td colspan="2" style="font-weight:800">الإجمالي</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${esc(totals.inv_cnt)}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${esc(fmtAmt(totals.net))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${esc(fmtAmt(totals.cost))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${esc(fmtAmt(totals.profit))}</td>
          <td class="si-num" dir="ltr" style="font-weight:800">${esc(fmtAmt(totals.profit_pct))}</td>
        </tr>`
      : '';

  let tableBlock = '';
  if (err) {
    tableBlock = `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>`;
  } else if (run) {
    const f = oraFilters || {};
    const cand = f.candidates && typeof f.candidates === 'object' ? f.candidates : {};
    const candRows = Object.keys(cand)
      .filter((k) => k !== 'base')
      .map((k) => ({ key: k, ...(cand[k] || {}) }))
      .sort((a, b) => (Number(a.d_sum) || 999) - (Number(b.d_sum) || 999));
    const candTable =
      candRows.length > 0
        ? `<div class="no-print" style="margin:0 0 .75rem;overflow:auto;max-width:100%">
            <table class="si-table" style="font-size:.75rem;min-width:36rem">
              <thead><tr>
                <th>المعادلة</th><th>الصافي</th><th>التكلفة</th>
                <th>Δ صافي عن Forms</th><th>Δ تكلفة عن Forms</th><th>المجموع</th>
              </tr></thead>
              <tbody>
                ${candRows
                  .slice(0, 12)
                  .map(
                    (c) => `<tr${c.key === f.formula ? ' style="font-weight:800;background:#eef6ff"' : ''}>
                  <td><code>${esc(c.key)}</code>${c.key === f.formula ? ' ← المعتمد' : ''}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(c.net || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(c.cost || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(c.d_net || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(c.d_cost || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(c.d_sum || 0))}</td>
                </tr>`
                  )
                  .join('')}
              </tbody>
            </table>
            <p class="muted" style="margin:.35rem 0 0;font-size:.72rem">هدف Forms: صافي <span dir="ltr">${esc(
              fmtAmt(f.forms_target_net || 10213.988)
            )}</span> / تكلفة <span dir="ltr">${esc(fmtAmt(f.forms_target_cost || 4801.379))}</span>
            — إن ظهر صف بـ Δ≈0 فهو المطابق. صَوّر هذا الجدول إن بقي فرق.</p>
          </div>`
        : '';
    const alt20 = f.alt_to_20 && typeof f.alt_to_20 === 'object' ? f.alt_to_20 : null;
    const altTot = alt20 && alt20.totals ? alt20.totals : null;
    const formsHint = String(f.forms_period_hint || '');
    const altBanner =
      altTot
        ? `<p class="si-pill no-print" style="display:inline-block;margin:0 0 .5rem;background:#fff8e6;border:1px solid #f0d78c">
            مقارنة Forms: لو «إلى تاريخ» = <strong>20-09-2026</strong> ← صافي
            <span dir="ltr">${esc(fmtAmt(altTot.net || 0))}</span>
            / تكلفة <span dir="ltr">${esc(fmtAmt(altTot.cost || 0))}</span>
            · Δ <span dir="ltr">${esc(fmtAmt(alt20.formula_delta || 0))}</span>
            ${formsHint
              ? ' — <strong style="color:#0a7a2f">يطابق Forms — غيّر تاريخ النهاية إلى 20-09-2026</strong>'
              : Number(alt20.formula_delta || 99) < Number(f.formula_delta || 99)
                ? ' — أقرب من الفترة حتى 30'
                : ''}
          </p>`
        : '';
    const invs = Array.isArray(f.invoices) ? f.invoices : [];
    const invTable =
      invs.length > 0
        ? `<div class="no-print" style="margin:0 0 .75rem;overflow:auto;max-width:100%">
            <p class="muted" style="margin:0 0 .25rem;font-size:.78rem">تفصيل الفواتير (${invs.length}) — لمطابقة صفوف Forms</p>
            <table class="si-table" style="font-size:.75rem;min-width:32rem">
              <thead><tr>
                <th>الرقم</th><th>التاريخ</th><th>الصافي</th><th>التكلفة</th><th>الربح</th><th>خصم فاتورة</th><th>ضريبة</th>
              </tr></thead>
              <tbody>
                ${invs
                  .map(
                    (iv) => `<tr>
                  <td class="si-num" dir="ltr">${esc(iv.v_num)}/${esc(iv.vyear)}</td>
                  <td class="si-num" dir="ltr">${esc(iv.vdate || '')}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(iv.net || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(iv.cost || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(iv.profit || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(iv.vou_disc || 0))}</td>
                  <td class="si-num" dir="ltr">${esc(fmtAmt(iv.tax_max || 0))}</td>
                </tr>`
                  )
                  .join('')}
              </tbody>
            </table>
          </div>`
        : '';
    const diag =
      f.formula
        ? `${altBanner}<p class="muted no-print" style="margin:0 0 .35rem;font-size:.8rem;line-height:1.6">
            معادلة: <code>${esc(String(f.formula))}</code>
            · Δ عن Forms: <span dir="ltr">${esc(fmtAmt(f.formula_delta || 0))}</span>
            · SELL: <span dir="ltr">${esc(fmtAmt(f.gross_raw || 0))}</span>
            · بعد خصم%: <span dir="ltr">${esc(fmtAmt(f.gross_disc_pct || 0))}</span>
            · خصم فاتورة: <span dir="ltr">${esc(fmtAmt(f.vou_disc || 0))}</span>
            · مبلغ خصم البنود: <span dir="ltr">${esc(fmtAmt(f.disc_amt || 0))}</span>
            · ضريبة MAX: <span dir="ltr">${esc(fmtAmt(f.tax_max || 0))}</span>
            · تكلفة بونص: <span dir="ltr">${esc(fmtAmt(f.bonus_cost || 0))}</span>
          </p>${candTable}${invTable}`
        : '';
    tableBlock =
      diag +
      ui.tableSurface(
        repName
          ? `مندوب: ${esc(repName)}${oracleRepNo > 0 ? ` (#${oracleRepNo})` : ''}`
          : 'ملخص المندوبين — Oracle',
        `${rows.length} مندوب`,
        ['رقم المندوب', 'اسم المندوب', 'فواتير', 'الصافي', 'التكلفة', 'الربح', 'الربح %'],
        rowsHtml + foot
      );
  }

  const body = `
    <div class="si-stage si-report-page report-sales-page" data-report-route="report_oracle_rep_net_sales">
      ${ui.hero({
        mark: '📊',
        kicker: 'تقارير المبيعات',
        title: 'صافي فواتير مبيعات المندوب (Oracle)',
        subtitle: '',
        actions: [ui.printAction(), { label: 'لوحة المبيعات', href: '/sales' }],
      })}
      ${mapWarn}
      ${mismatchWarn}
      <div class="si-rail no-print">
        <form method="get" action="${esc(BASE)}" class="si-search report-sales-filters" data-app-busy-msg="جاري تحميل التقرير…" style="display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:flex-end;max-width:100%;margin:0">
          <input type="hidden" name="run" value="1" />
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;flex:1 1 16rem;min-width:14rem">المندوب (Oracle)
            <div class="report-cust-pick" id="report-sales-rep-pick" style="margin-top:.25rem">
              <input type="hidden" name="sales_rep_id" data-rep-id value="${oracleRepNo > 0 ? oracleRepNo : ''}">
              <input type="text" class="si-field report-cust-pick-inp" data-rep-search
                     placeholder="ابحث بالاسم أو رقم المندوب… (فارغ = الكل)" autocomplete="off" spellcheck="false"
                     aria-label="بحث عن مندوب" style="min-width:100%;width:100%">
              <div class="report-cust-pick-list" data-rep-list hidden></div>
            </div>
          </label>
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">من تاريخ
            <input class="si-field js-date-dmy" type="text" name="from" value="${esc(isoToDmy(range.from))}"
                   placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required
                   style="margin-top:.25rem;min-width:9.5rem">
          </label>
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى تاريخ
            <input class="si-field js-date-dmy" type="text" name="to" value="${esc(isoToDmy(range.to))}"
                   placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required
                   style="margin-top:.25rem;min-width:9.5rem">
          </label>
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">المستودع
            <input class="si-field" type="number" name="store" value="${store}" min="1" dir="ltr"
                   style="margin-top:.25rem;width:5.5rem">
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
        </form>
      </div>
      <div class="si-print-area report-sales-result">${tableBlock}</div>
      <script type="application/json" id="report-sales-reps-json">${repsJson}</script>
      <script>
      document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('report-sales-reps-json');
        var root = document.getElementById('report-sales-rep-pick');
        if (!el || !root || !window.ReportRepPicker) return;
        var reps = [];
        try { reps = JSON.parse(el.textContent || '[]'); } catch (e) {}
        var hidden = root.querySelector('[data-rep-id]');
        var initialId = hidden && hidden.value ? parseInt(hidden.value, 10) : 0;
        window.ReportRepPicker.init(root, reps, { initialId: initialId });
      });
      </script>
    </div>`;

  return res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'صافي مبيعات المندوب Oracle',
      bodyHtml: body,
      css: ['/assets/css/report-sales.css'],
      js: ['/assets/js/report-rep-picker.js', '/assets/js/sales-print.js'],
    })
  );
});

/* ═══════════════ مبيعات المندوب حسب المادة والفئة Oracle ═══════════════ */
const oracleRepItemsHandler = async (req, res) => {
  const BASE = '/sales/reports/oracle-rep-items';
  try {
  // POST لتجنب 414 عند اختيار كثير من العملاء/المندوبين؛ GET لفتح الصفحة فقط
  const params = req.method === 'POST'
    ? { ...(req.query || {}), ...(req.body || {}) }
    : (req.query || {});
  const store = Number(params.store || 4) || 4;
  const asList = (value) => {
    const raw = Array.isArray(value) ? value : value != null && String(value) !== '' ? [value] : [];
    return [...new Set(raw.map((v) => String(v).trim()).filter(Boolean))];
  };
  const oracleRepNos = asList(params.sales_rep_id).map((id) => Number(id)).filter((id) => id > 0);
  const customerIds = asList(params.customer_id).map((id) => Number(id)).filter((id) => id > 0);
  const categoryIds = asList(params.category_id);
  const reportYears = asList(params.report_year).map((y) => Number(y)).filter((y) => y >= 2000 && y <= 2100);
  const reportMonths = asList(params.report_month).map((m) => Number(m)).filter((m) => m >= 1 && m <= 12);
  // أي POST من النموذج = تشغيل؛ وrun=1 للتوافق مع GET
  const run =
    req.method === 'POST' ||
    String(params.run || '') === '1' ||
    oracleRepNos.length > 0 ||
    customerIds.length > 0 ||
    categoryIds.length > 0 ||
    reportYears.length > 0 ||
    reportMonths.length > 0;
  const uid = Number(req.session.user?.id || 0) || 0;
  const yearNow = new Date().getFullYear();
  const todayIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };
  const range = {
    from: `${yearNow}-01-01`,
    to: todayIso(),
  };

  const parseManNo = (code) => {
    const c = String(code || '').trim();
    if (/^[1-9]\d*$/.test(c)) return Number(c);
    const m = c.match(/(\d+)/);
    if (m) {
      const n = Number(m[1]);
      if (n > 0 && n < 100000) return n;
    }
    return 0;
  };
  const addRepCodeKeys = (set, code) => {
    const raw = String(code || '').trim();
    if (!raw) return;
    set.add(raw);
    const man = parseManNo(raw);
    if (man > 0) set.add(String(man));
  };

  // قائمة المندوبين من هايبكس فقط (crm_sales_rep) — لا نعرض مندوبي Oracle غير الموجودين بالنظام
  const hypexReps = await q.listRepsSimple();
  const hypexNameByMan = new Map();
  const seen = new Set();
  let reps = [];
  for (const r of hypexReps || []) {
    const manNo = parseManNo(r.code);
    if (manNo < 1 || seen.has(manNo)) continue;
    const nm = String(r.name_ar || '').trim();
    if (!nm) continue;
    seen.add(manNo);
    hypexNameByMan.set(manNo, nm);
    reps.push({ id: manNo, code: String(manNo), name_ar: nm });
  }
  reps.sort((a, b) => String(a.name_ar).localeCompare(String(b.name_ar), 'ar'));

  const db = require('../db');
  let customers = [];
  try {
    customers = await db.query(
      `SELECT c.id, c.name_ar, c.code, IFNULL(c.oracle_key, '') AS oracle_key,
              IFNULL(r.code, '') AS primary_rep_code
       FROM crm_customer c
       LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
       WHERE c.is_active = 1
       ORDER BY c.name_ar
       LIMIT 4000`
    );
  } catch (e) {
    customers = [];
  }
  const custRepCodes = new Map();
  try {
    const linkRows = await db.query(
      `SELECT csr.customer_id, r.code
       FROM crm_customer_sales_rep csr
       INNER JOIN crm_sales_rep r ON r.id = csr.sales_rep_id`
    );
    for (const row of linkRows || []) {
      const cid = String(Number(row.customer_id) || 0);
      const code = String(row.code || '').trim();
      if (!cid || cid === '0' || !code) continue;
      if (!custRepCodes.has(cid)) custRepCodes.set(cid, new Set());
      addRepCodeKeys(custRepCodes.get(cid), code);
    }
  } catch (e) {
    /* optional table */
  }
  for (const c of customers || []) {
    const cid = String(Number(c.id) || 0);
    if (!custRepCodes.has(cid)) custRepCodes.set(cid, new Set());
    addRepCodeKeys(custRepCodes.get(cid), c.primary_rep_code);
  }
  // فئات النظام الحالي فقط — استبعاد الفئات المزامَنة من Oracle (oracle_key)
  let categories = [];
  try {
    categories = await db.query(
      `SELECT id, name_ar FROM inv_item_category
       WHERE is_active = 1
         AND (oracle_key IS NULL OR TRIM(oracle_key) = '')
       ORDER BY name_ar`
    );
  } catch (e) {
    try {
      categories = await db.query(
        `SELECT id, name_ar FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar`
      );
    } catch (e2) {
      try {
        categories = await db.query(
          `SELECT id, name_ar FROM inv_item_category ORDER BY name_ar`
        );
      } catch (e3) {
        categories = [];
      }
    }
  }
  const custAccOf = (picked) => {
    const raw = String(picked?.oracle_key || '').trim() || String(picked?.code || '').trim();
    return String(raw).replace(/\D+/g, '').replace(/^0+/, '');
  };
  let scopeCustomerIds = customerIds.slice();
  let filterOracleRep = true;
  const selectedRepCodes = new Set(oracleRepNos.map(String));
  // خريطة حساب Oracle → رقم مندوب هايبكس الحالي
  const accToRep = new Map();
  if (oracleRepNos.length) {
    // مندوبو هايبكس الحاليون: كل مبيعات عملائهم (حتى ما بيع تحت مندوب قديم في Oracle)
    filterOracleRep = false;
    const linkedIds = (customers || [])
      .filter((c) => {
        const codes = custRepCodes.get(String(Number(c.id) || 0)) || new Set();
        return [...selectedRepCodes].some((code) => codes.has(code));
      })
      .map((c) => Number(c.id) || 0)
      .filter((id) => id > 0);
    if (scopeCustomerIds.length) {
      const allow = new Set(scopeCustomerIds);
      scopeCustomerIds = linkedIds.filter((id) => allow.has(id));
    } else {
      scopeCustomerIds = linkedIds;
    }
  }
  const custAccs = [];
  const seenAcc = new Set();
  const pushAcc = (picked) => {
    const acc = custAccOf(picked);
    if (!acc || seenAcc.has(acc)) return;
    seenAcc.add(acc);
    custAccs.push(acc);
    const cid = String(Number(picked.id) || 0);
    const codes = [...(custRepCodes.get(cid) || [])];
    const primary = String(picked.primary_rep_code || '').trim();
    let prefer = '';
    if (selectedRepCodes.size) {
      if (primary && selectedRepCodes.has(primary)) prefer = primary;
      else prefer = codes.find((c) => selectedRepCodes.has(c)) || '';
    } else {
      prefer = primary || codes[0] || '';
    }
    if (prefer) accToRep.set(acc, Number(prefer) || 0);
  };
  if (scopeCustomerIds.length) {
    scopeCustomerIds.forEach((id) => {
      const picked = (customers || []).find((c) => Number(c.id) === id);
      if (picked) pushAcc(picked);
    });
  } else if (!oracleRepNos.length) {
    customerIds.forEach((id) => {
      const picked = (customers || []).find((c) => Number(c.id) === id);
      if (picked) pushAcc(picked);
    });
  }
  const catNames = [];
  categoryIds.forEach((id) => {
    if (id === 'none') {
      catNames.push('بدون فئة');
      return;
    }
    const row = (categories || []).find((c) => String(c.id) === id);
    const name = String(row?.name_ar || '').trim();
    if (name) catNames.push(name);
  });

  const period = (() => {
    let years = [...new Set(reportYears)].sort((a, b) => a - b);
    let months = [...new Set(reportMonths)].sort((a, b) => a - b);
    if (!years.length && !months.length) {
      return { from: range.from, to: range.to, yearMonths: [] };
    }
    if (!years.length) {
      const y1 = Number(String(range.from).slice(0, 4)) || new Date().getFullYear();
      const y2 = Number(String(range.to).slice(0, 4)) || y1;
      for (let y = Math.min(y1, y2); y <= Math.max(y1, y2); y += 1) years.push(y);
    }
    if (!months.length) months = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
    const yearMonths = [];
    let from = '';
    let to = '';
    years.forEach((y) => {
      months.forEach((m) => {
        const ym = `${y}-${String(m).padStart(2, '0')}`;
        yearMonths.push(ym);
        const start = `${ym}-01`;
        const endDate = new Date(y, m, 0);
        const end = `${endDate.getFullYear()}-${String(endDate.getMonth() + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')}`;
        if (!from || start < from) from = start;
        if (!to || end > to) to = end;
      });
    });
    return { from: from || range.from, to: to || range.to, yearMonths };
  })();
  range.from = period.from;
  range.to = period.to;

  const fmtQty = (n) => {
    const x = Number(n);
    if (!Number.isFinite(x)) return '0';
    return x.toLocaleString('en-US', { maximumFractionDigits: 3 });
  };

  let err = '';
  let rows = [];
  let returnRows = [];
  let netRows = [];
  let totals = { qty: 0, bonus: 0, amount: 0, reps: 0, items: 0 };
  let netTotals = { qty: 0, bonus: 0, amount: 0, reps: 0, items: 0 };
  let oraDebug = null;
  let repName = '';

  const mapOraRow = (r) => {
    const accKey = String(r.customer_acc || '').replace(/\D+/g, '').replace(/^0+/, '');
    let no = Number(r.rep_no) || 0;
    if (filterOracleRep === false) {
      const mapped = accToRep.get(accKey) || accToRep.get(String(r.customer_acc || '').trim()) || 0;
      if (mapped > 0) no = mapped;
    }
    const hx = no > 0 ? hypexNameByMan.get(no) : '';
    const current = String(r.rep_name || '').trim();
    const fromList = no > 0 ? String(reps.find((x) => Number(x.id) === no)?.name_ar || '').trim() : '';
    const named = current && !/^مندوب\s+\d+$/.test(current) ? current : '';
    const rep_name = hx || named || fromList || current || (no > 0 ? `مندوب ${no}` : '');
    return { ...r, rep_no: no, rep_name };
  };

  /** تعزيز الفئة من بطاقة المادة في MySQL (باركود / SKU / oracle_key) */
  const enrichRowsLocalCats = async (list) => {
    if (!Array.isArray(list) || !list.length) return list;
    let items = [];
    try {
      items = await db.query(
        `SELECT i.sku, i.barcode, IFNULL(i.oracle_key, '') AS oracle_key,
                COALESCE(
                  (
                    SELECT c2.name_ar FROM inv_item_category c2
                    WHERE c.id IS NOT NULL
                      AND LOWER(TRIM(c2.name_ar)) = LOWER(TRIM(c.name_ar))
                      AND (c2.oracle_key IS NULL OR TRIM(IFNULL(c2.oracle_key, '')) = '')
                      AND IFNULL(c2.is_active, 1) = 1
                    ORDER BY CASE WHEN c2.code LIKE 'XL-%' THEN 0 ELSE 1 END, c2.id
                    LIMIT 1
                  ),
                  NULLIF(TRIM(c.name_ar), '')
                ) AS cat_name
         FROM inv_item i
         LEFT JOIN inv_item_category c ON c.id = i.category_id`
      );
    } catch (e) {
      try {
        items = await db.query(
          `SELECT i.sku, i.barcode, IFNULL(i.oracle_key, '') AS oracle_key, c.name_ar AS cat_name
           FROM inv_item i
           LEFT JOIN inv_item_category c ON c.id = i.category_id`
        );
      } catch (e2) {
        return list;
      }
    }
    const put = (map, key, pack) => {
      const k = String(key || '').trim();
      if (!k || !pack || !pack.cat) return;
      if (!map.has(k)) map.set(k, pack);
      const digits = k.replace(/\D+/g, '');
      const norm = digits.replace(/^0+/, '') || digits;
      if (norm && !map.has(norm)) map.set(norm, pack);
    };
    const byKey = new Map();
    for (const it of items || []) {
      const barcode = String(it.barcode || '').trim();
      const sku = String(it.sku || '').trim();
      let cat = String(it.cat_name || '').trim();
      // إن الفئة غير معتمدة من الاكسل: خذها من كشف الاكسل بالباركود/sku
      if (!excelCats.isExcelCategory(cat)) {
        const fromXl = excelCats.lookupExcelCategory(barcode, sku, it.oracle_key);
        if (fromXl) cat = fromXl;
      }
      if (!cat) {
        cat = excelCats.lookupExcelCategory(barcode, sku, it.oracle_key);
      }
      if (!cat) continue;
      const pack = { cat, barcode, sku };
      put(byKey, it.oracle_key, pack);
      put(byKey, sku, pack);
      put(byKey, barcode, pack);
    }
    const lookup = (row) => {
      const keys = [
        row.item_code,
        row.item_barcode,
        row.item_sku,
        String(row.item_code || '').replace(/\D+/g, ''),
        String(row.item_barcode || '').replace(/\D+/g, ''),
      ];
      for (const raw of keys) {
        const k = String(raw || '').trim();
        if (!k) continue;
        if (byKey.has(k)) return byKey.get(k);
        const norm = k.replace(/\D+/g, '').replace(/^0+/, '') || k.replace(/\D+/g, '');
        if (norm && byKey.has(norm)) return byKey.get(norm);
      }
      const xl = excelCats.lookupExcelCategory(...keys);
      if (xl) return { cat: xl, barcode: '', sku: '' };
      return null;
    };
    return list.map((row) => {
      const cur = String(row.cat_name || '').trim();
      const hit = lookup(row);
      const code = String(row.item_code || '').trim();
      if (!hit) {
        if ((!row.item_barcode || !String(row.item_barcode).trim()) && /^\d{8,}$/.test(code)) {
          return { ...row, item_barcode: code };
        }
        return row;
      }
      const needCat =
        !cur || cur === 'بدون فئة' || (excelCats.isExcelCategory(hit.cat) && !excelCats.isExcelCategory(cur));
      return {
        ...row,
        cat_name: needCat ? hit.cat : cur,
        item_barcode: String(row.item_barcode || '').trim() || hit.barcode || (/^\d{8,}$/.test(code) ? code : ''),
        item_sku: String(row.item_sku || '').trim() || hit.sku || '',
      };
    });
  };

  if (run) {
    if (oracleRepNos.length === 1) {
      const one = oracleRepNos[0];
      repName =
        hypexNameByMan.get(one) ||
        reps.find((r) => Number(r.id) === one)?.name_ar ||
        `مندوب ${one}`;
    } else if (oracleRepNos.length > 1) {
      repName = `${oracleRepNos.length} مندوبين`;
    }
    if (oracleRepNos.length && !scopeCustomerIds.length) {
      err = 'لا يوجد عملاء مربوطون بالمندوبين المحددين في النظام.';
    } else if (oracleRepNos.length && !custAccs.length) {
      err = 'عملاء المندوبين المحددين غير مربوطين بحساب في Oracle.';
    } else if (customerIds.length && !custAccs.length) {
      err = 'العملاء المحددون غير مربوطين بحساب في Oracle.';
    } else if (custAccs.length > 1500) {
      err = 'عدد العملاء المحددين كبير جداً. اختر مندوباً أو قلّل العملاء، أو اترك «كل العملاء».';
    } else {
    const data = await accNative.run('oracle_rep_item_sales', uid, {
      from: range.from,
      to: range.to,
      store,
      rep_no: oracleRepNos.length === 1 ? oracleRepNos[0] : null,
      cust_accs: custAccs,
      cat_names: catNames,
      year_months: period.yearMonths,
      filter_oracle_rep: filterOracleRep,
    });
    if (!data || data.ok === false) {
      let rawErr = String(data?.error || data?.message || 'تعذر الاتصال بـ Oracle.');
      if (/<!doctype html|<html[\s>]|cPanel|تعذر إكمال الطلب|حدث خطأ داخلي/i.test(rawErr)) {
        rawErr =
          'تعذر إكمال التقرير على الخادم. اختر مندوباً أو سنة/شهراً محدداً ثم أعد المحاولة.';
      } else if (/Allowed memory size/i.test(rawErr)) {
        rawErr = 'نفدت الذاكرة أثناء التقرير. قلّل الفلاتر (مندوب/سنة/شهر) ثم أعد المحاولة.';
      } else if (rawErr.length > 320) {
        rawErr = rawErr.slice(0, 320) + '…';
      }
      err = rawErr;
    } else {
      rows = await enrichRowsLocalCats((Array.isArray(data.rows) ? data.rows : []).map(mapOraRow));
      returnRows = await enrichRowsLocalCats(
        (Array.isArray(data.return_rows) ? data.return_rows : []).map(mapOraRow)
      );
      oraDebug = data.debug && typeof data.debug === 'object' ? data.debug : null;
      netRows = rows.concat(
        returnRows.map((r) => ({
          ...r,
          doc_kind: 'R',
          qty: -(Number(r.qty) || 0),
          bonus: -(Number(r.bonus) || 0),
          amount: -(Number(r.amount) || 0),
        }))
      );
      // فرض فئات الاكسل على كل الصفوف قبل البناء
      const applyExcelCat = (r) => {
        const code = String(r.item_code || '').trim();
        const barcode =
          String(r.item_barcode || '').trim() || (/^\d{8,}$/.test(code) ? code : '');
        const cur = String(r.cat_name || '').trim();
        const xl = excelCats.lookupExcelCategory(barcode, code, r.item_sku);
        if (xl && (!cur || cur === 'بدون فئة' || !excelCats.isExcelCategory(cur))) {
          return { ...r, cat_name: xl, item_barcode: barcode || String(r.item_barcode || '') };
        }
        if (!String(r.item_barcode || '').trim() && barcode) {
          return { ...r, item_barcode: barcode };
        }
        return r;
      };
      rows = rows.map(applyExcelCat);
      returnRows = returnRows.map(applyExcelCat);
      netRows = netRows.map(applyExcelCat);
      netRows.sort((a, b) => {
        const c = (Number(a.rep_no) || 0) - (Number(b.rep_no) || 0);
        if (c) return c;
        const c2 = String(a.customer_name || '').localeCompare(String(b.customer_name || ''), 'ar');
        if (c2) return c2;
        const c3 = String(a.sale_date || '').localeCompare(String(b.sale_date || ''));
        if (c3) return c3;
        return (Number(a.invoice_no) || 0) - (Number(b.invoice_no) || 0);
      });
      totals = data.totals && typeof data.totals === 'object' ? data.totals : totals;
      netTotals =
        data.net_totals && typeof data.net_totals === 'object'
          ? data.net_totals
          : {
              qty: (Number(totals.qty) || 0) - returnRows.reduce((s, r) => s + (Number(r.qty) || 0), 0),
              bonus: (Number(totals.bonus) || 0) - returnRows.reduce((s, r) => s + (Number(r.bonus) || 0), 0),
              amount: (Number(totals.amount) || 0) - returnRows.reduce((s, r) => s + (Number(r.amount) || 0), 0),
              reps: totals.reps,
              items: rows.length + returnRows.length,
            };
      if (!rows.length && !returnRows.length && data.message) err = String(data.message);
    }
    }
  }

  const itemCodesOf = (r) => {
    const id = String(r.item_code || '').trim();
    const barcode = String(r.item_barcode || '').trim();
    const sku = String(r.item_sku || '').trim();
    const code = barcode || (sku && sku !== id ? sku : '');
    return { id, code };
  };
  const itemRow = (r, groupId) => {
    const qty = Number(r.qty) || 0;
    const bonus = Number(r.bonus) || 0;
    const amount = Number(r.amount) || 0;
    const codes = itemCodesOf(r);
    return `<tr class="ora-item" data-ora-lines="${groupId}" hidden>
      <td>${esc(r.cat_name || 'بدون فئة')}</td>
      <td class="si-num ora-code" dir="ltr">${esc(codes.id)}</td>
      <td class="si-num ora-code" dir="ltr">${esc(codes.code)}</td>
      <td class="ora-name">${esc(r.item_name || '')}</td>
      <td class="si-num" dir="ltr">${esc(fmtQty(qty))}</td>
      <td class="si-num ora-qty-extra" dir="ltr">${bonus ? esc(fmtQty(bonus)) : ''}</td>
      <td class="si-num" dir="ltr">${esc(fmtAmt(amount))}</td>
    </tr>`;
  };
  let rowsHtml = '';
  let groupSeq = 0;
  // by-item = التصميم السابق (مواد العميل والفئات القابلة للفتح). by-customer = الفئات أعمدة وأسماء العملاء صفوف.
  const oraLayout = 'by-customer';
  if (oraLayout === 'by-item' && rows.length) {
    let i = 0;
    while (i < rows.length) {
      const repStart = i;
      const no = Number(rows[i].rep_no) || 0;
      while (i < rows.length && (Number(rows[i].rep_no) || 0) === no) i += 1;
      const repRows = rows.slice(repStart, i);
      const repLabel = String(repRows[0].rep_name || '').trim() || (no > 0 ? `مندوب ${no}` : '');
      const customers = [];
      let j = 0;
      while (j < repRows.length) {
        const acc = String(repRows[j].customer_acc || '');
        const slice = [];
        while (j < repRows.length && String(repRows[j].customer_acc || '') === acc) {
          slice.push(repRows[j]);
          j += 1;
        }
        customers.push(slice);
      }
      let repQty = 0;
      let repAmt = 0;
      customers.forEach((slice) => {
        const custLabel = String(slice[0].customer_name || slice[0].customer_acc || '—');
        const cats = [];
        let c = 0;
        while (c < slice.length) {
          const cat = String(slice[c].cat_name || 'بدون فئة');
          const lines = [];
          let catQty = 0;
          let catAmt = 0;
          let catBonus = 0;
          while (c < slice.length && String(slice[c].cat_name || 'بدون فئة') === cat) {
            const qty = Number(slice[c].qty) || 0;
            const bonus = Number(slice[c].bonus) || 0;
            const amount = Number(slice[c].amount) || 0;
            catQty += qty;
            catBonus += bonus;
            catAmt += amount;
            lines.push(slice[c]);
            c += 1;
          }
          cats.push({ cat, lines, catQty, catBonus, catAmt });
        }
        const custQty = cats.reduce((s, x) => s + x.catQty, 0);
        const custBonus = cats.reduce((s, x) => s + (x.catBonus || 0), 0);
        const custAmt = cats.reduce((s, x) => s + x.catAmt, 0);
        repQty += custQty;
        repAmt += custAmt;
        let body = cats
          .map((g) => {
            groupSeq += 1;
            const id = `ora-g-${groupSeq}`;
            const head = `<tr class="ora-cat-head" data-ora-toggle="${id}" data-open="0">
              <td colspan="4"><span class="ora-arrow" aria-hidden="true"></span>${esc(g.cat)}</td>
              <td class="si-num" dir="ltr">${esc(fmtQty(g.catQty))}</td>
              <td class="si-num ora-qty-extra" dir="ltr">${g.catBonus ? esc(fmtQty(g.catBonus)) : ''}</td>
              <td class="si-num" dir="ltr">${esc(fmtAmt(g.catAmt))}</td>
            </tr>`;
            return head + g.lines.map((line) => itemRow(line, id)).join('');
          })
          .join('');
        body += `<tr class="ora-cust-final">
          <td colspan="4">المجموع النهائي لمبيعات العميل</td>
          <td class="si-num" dir="ltr">${esc(fmtQty(custQty))}</td>
          <td class="si-num ora-qty-extra" dir="ltr">${custBonus ? esc(fmtQty(custBonus)) : ''}</td>
          <td class="si-num" dir="ltr">${esc(fmtAmt(custAmt))}</td>
        </tr>`;
        rowsHtml += `<section class="ora-cust-block">
          <header class="ora-cust-head">
            <div class="ora-rep">المندوب: ${esc(repLabel)}</div>
            <div class="ora-who">العميل: ${esc(custLabel)}</div>
          </header>
          <div class="si-table-wrap ora-portrait-wrap">
            <table class="si-table ora-portrait-table">
              <thead><tr>
                <th class="ora-vhead">فئة</th>
                <th class="ora-vhead">ايدي</th>
                <th class="ora-vhead">كود</th>
                <th class="ora-vhead">اسم المادة</th>
                <th class="ora-vhead">الكمية</th>
                <th class="ora-vhead">إضافي</th>
                <th class="ora-vhead">المبلغ</th>
              </tr></thead>
              <tbody>${body}</tbody>
            </table>
          </div>
        </section>`;
      });
      rowsHtml += `<p class="ora-rep-total">إجمالي المندوب ${esc(repLabel)} — الكمية ${esc(fmtQty(repQty))} — المبلغ ${esc(fmtAmt(repAmt))}</p>`;
    }
    rowsHtml += `<p class="ora-grand">الإجمالي — الكمية ${esc(fmtQty(totals.qty))} — إضافي ${esc(fmtQty(totals.bonus || 0))} — المبلغ ${esc(fmtAmt(totals.amount))}</p>`;
  } else if (rows.length || netRows.length) {
    const invBase = basePath.ensurePrefixed('/sales/reports/oracle-sales-invoice');
    const compareYears =
      reportYears.length > 1 ? [...new Set(reportYears.map((y) => Number(y) || 0).filter((y) => y >= 2000))].sort((a, b) => a - b) : [];
    const yearCompare = compareYears.length > 1;
    const buildOraViews = (sourceRows) => {
    let summaryHtml = '';
    let detailHtml = '';
    let invoiceHtml = '';
    let invoiceSumHtml = '';
    let i = 0;
    while (i < sourceRows.length) {
      const repStart = i;
      const no = Number(sourceRows[i].rep_no) || 0;
      while (i < sourceRows.length && (Number(sourceRows[i].rep_no) || 0) === no) i += 1;
      const repRows = sourceRows.slice(repStart, i);
      const repLabel = String(repRows[0].rep_name || '').trim() || (no > 0 ? `مندوب ${no}` : '');
      const catSeen = new Set();
      const catOrder = [];
      const custMap = new Map();
      const invMap = new Map();
      const yearStackHtml = (byYear, withDiff) => {
        const lines = compareYears
          .map((y) => {
            const a = Number(byYear?.get?.(y) || 0) || 0;
            return `<div class="ora-yline"><span class="ora-ylbl">${y}</span><span class="ora-yval" dir="ltr">${
              a ? esc(fmtAmt(a)) : '—'
            }</span></div>`;
          })
          .join('');
        let diffHtml = '';
        if (withDiff && compareYears.length > 1) {
          const first = Number(byYear?.get?.(compareYears[0]) || 0) || 0;
          const last = Number(byYear?.get?.(compareYears[compareYears.length - 1]) || 0) || 0;
          const diff = last - first;
          const sign = diff > 0 ? '+' : '';
          diffHtml = `<div class="ora-yline ora-ydiff"><span class="ora-ylbl">الفرق</span><span class="ora-yval" dir="ltr">${esc(
            sign + fmtAmt(diff)
          )}</span></div>`;
        }
        return `<div class="ora-ystack">${lines}${diffHtml}</div>`;
      };
      repRows.forEach((r) => {
        const name = String(r.item_name || r.item_code || '').trim();
        const code = String(r.item_code || '');
        const fromName = (name.match(/\d{8,}/) || [])[0] || '';
        let barcode = String(r.item_barcode || '').trim() || fromName;
        if (!barcode && /^\d{8,}$/.test(code)) barcode = code;
        // فرض فئة الاكسل هنا (آخر خط دفاع قبل الرسم)
        let cat = String(r.cat_name || '').trim() || 'بدون فئة';
        const xlCat = excelCats.lookupExcelCategory(barcode, code, r.item_sku, fromName);
        if (xlCat && (!excelCats.isExcelCategory(cat) || cat === 'بدون فئة')) {
          cat = xlCat;
        }
        if (!cat) cat = 'بدون فئة';
        if (!catSeen.has(cat)) {
          catSeen.add(cat);
          catOrder.push(cat);
        }
        const acc = String(r.customer_acc || '');
        if (!custMap.has(acc)) {
          custMap.set(acc, {
            label: String(r.customer_name || acc || '—'),
            cats: new Map(),
          });
        }
        const slot = custMap.get(acc);
        let bag = slot.cats.get(cat);
        if (!bag) {
          bag = { amt: 0, byYear: new Map(), items: [] };
          slot.cats.set(cat, bag);
        }
        const amount = Number(r.amount) || 0;
        const qty = Number(r.qty) || 0;
        const bonus = Number(r.bonus) || 0;
        bag.amt += amount;
        const saleDate = String(r.sale_date || '').trim();
        const invNo = Number(r.invoice_no) || 0;
        const invYear = Number(r.invoice_year) || 0;
        const saleYear = Number((saleDate.match(/^(\d{4})/) || [])[1]) || invYear || 0;
        if (saleYear > 0) {
          bag.byYear.set(saleYear, (bag.byYear.get(saleYear) || 0) + amount);
        }
        const docKind = String(r.doc_kind || '').toUpperCase() === 'R' || amount < 0 || qty < 0 ? 'R' : 'S';
        const found = bag.items.find(
          (it) => it.code === code && it.name === name && it.saleDate === saleDate && it.invNo === invNo && it.invYear === invYear
        );
        if (found) {
          found.amount += amount;
          found.qty += qty;
          found.bonus += bonus;
          if (!found.barcode && barcode) found.barcode = barcode;
        } else {
          bag.items.push({ name, code, barcode, saleDate, qty, bonus, amount, invNo, invYear });
        }
        const invKey = `${docKind}|${invYear}|${invNo}|${acc}`;
        if (!invMap.has(invKey)) {
          invMap.set(invKey, {
            docKind,
            invNo,
            invYear,
            saleDate,
            customer: String(r.customer_name || acc || '—'),
            lines: [],
            qty: 0,
            bonus: 0,
            amount: 0,
          });
        }
        const inv = invMap.get(invKey);
        if (saleDate && (!inv.saleDate || saleDate < inv.saleDate)) inv.saleDate = saleDate;
        inv.qty += qty;
        inv.bonus += bonus;
        inv.amount += amount;
        inv.lines.push({ cat, name, barcode, qty, bonus, amount });
      });
      catOrder.sort((a, b) => {
        if (a === 'بدون فئة') return 1;
        if (b === 'بدون فئة') return -1;
        return a.localeCompare(b, 'ar');
      });
      const colTotals = catOrder.map(() => 0);
      const colYearTotals = catOrder.map(() => new Map(compareYears.map((y) => [y, 0])));
      const repYearTotals = new Map(compareYears.map((y) => [y, 0]));
      let summaryBody = '';
      let detailBody = '';
      let invoiceBody = '';
      let invoiceSumBody = '';
      let repAmt = 0;
      let invRepQty = 0;
      let invRepBonus = 0;
      let invRepAmt = 0;
      const cleanName = (name) => String(name || '').replace(/\d{8,}/g, ' ').replace(/\s+/g, ' ').trim();
      const fmtSaleDate = (iso) => {
        const s = String(iso || '');
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return m ? `${m[3]}-${m[2]}-${m[1]}` : s;
      };
      const qtyExtraCell = (qty, bonus) => {
        const b = Number(bonus) || 0;
        return `<td class="si-num" dir="ltr">${esc(fmtQty(qty))}</td><td class="si-num ora-qty-extra" dir="ltr">${
          b ? esc(fmtQty(b)) : ''
        }</td>`;
      };
      custMap.forEach((slot, acc) => {
        let rowAmt = 0;
        const catAmts = [];
        const catYearParts = [];
        const rowByYear = new Map(compareYears.map((y) => [y, 0]));
        const sumCells = catOrder
          .map((cat, idx) => {
            const bag = slot.cats.get(cat);
            const amt = bag?.amt || 0;
            colTotals[idx] += amt;
            rowAmt += amt;
            catAmts.push(String(amt));
            if (yearCompare) {
              const yParts = compareYears.map((y) => {
                const ya = Number(bag?.byYear?.get(y) || 0) || 0;
                colYearTotals[idx].set(y, (colYearTotals[idx].get(y) || 0) + ya);
                rowByYear.set(y, (rowByYear.get(y) || 0) + ya);
                return String(ya);
              });
              catYearParts.push(yParts.join(','));
              return `<td class="si-num ora-cat-years">${yearStackHtml(bag?.byYear || new Map(), false)}</td>`;
            }
            return `<td class="si-num" dir="ltr">${amt ? esc(fmtAmt(amt)) : ''}</td>`;
          })
          .join('');
        repAmt += rowAmt;
        if (yearCompare) {
          compareYears.forEach((y) => {
            repYearTotals.set(y, (repYearTotals.get(y) || 0) + (rowByYear.get(y) || 0));
          });
        }
        const catSortAttrs = catOrder
          .map((cat, idx) => {
            const amt = slot.cats.get(cat)?.amt || 0;
            return ` data-sort-cat${idx}="${amt}"`;
          })
          .join('');
        const accSafe = esc(String(acc || slot.label || ''));
        const yearAttr = yearCompare
          ? ` data-ora-years="${esc(compareYears.map((y) => rowByYear.get(y) || 0).join('|'))}" data-ora-cat-years="${esc(
              catYearParts.join('|')
            )}"`
          : '';
        const totalCell = yearCompare
          ? `<td class="si-num ora-total-years">${yearStackHtml(rowByYear, true)}</td>`
          : `<td class="si-num" dir="ltr">${esc(fmtAmt(rowAmt))}</td>`;
        summaryBody += `<tr class="ora-sum-row" data-sort-row data-sort-customer="${esc(slot.label)}" data-sort-total="${rowAmt}" data-ora-total="${rowAmt}" data-ora-cats="${esc(catAmts.join('|'))}" data-ora-acc="${accSafe}"${yearAttr}${catSortAttrs}><td class="ora-cust-name"><label class="ora-cust-pick-lab no-print" title="تحديد للتجميع"><input type="checkbox" class="ora-cust-pick" value="${accSafe}"></label><span class="ora-cust-label">${esc(slot.label)}</span></td>${sumCells}${totalCell}</tr>`;
        let custQty = 0;
        let custBonus = 0;
        let custAmt = 0;
        let lines = '';
        catOrder.forEach((cat) => {
          const bag = slot.cats.get(cat);
          if (!bag || !bag.items.length) return;
          let catQty = 0;
          let catBonus = 0;
          bag.items
            .slice()
            .sort((a, b) => {
              const d = String(a.saleDate || '').localeCompare(String(b.saleDate || ''));
              if (d) return d;
              return cleanName(a.name).localeCompare(cleanName(b.name), 'ar');
            })
            .forEach((it) => {
              catQty += it.qty;
              catBonus += Number(it.bonus) || 0;
              const label = cleanName(it.name) || it.name;
              lines += `<tr data-sort-row data-sort-date="${esc(it.saleDate || '')}" data-sort-cat="${esc(cat)}" data-sort-item="${esc(label)}" data-sort-barcode="${esc(it.barcode || '')}" data-sort-qty="${it.qty}" data-sort-bonus="${Number(it.bonus) || 0}" data-sort-amount="${it.amount}">
                <td class="si-num" dir="ltr">${esc(fmtSaleDate(it.saleDate) || '—')}</td>
                <td>${esc(cat)}</td>
                <td class="ora-name">${esc(label)}</td>
                <td class="ora-code" dir="ltr">${esc(it.barcode || '')}</td>
                ${qtyExtraCell(it.qty, it.bonus)}
                <td class="si-num" dir="ltr">${esc(fmtAmt(it.amount))}</td>
              </tr>`;
            });
          lines += `<tr class="ora-cat-sum"><td colspan="4">مجموع ${esc(cat)}</td>${qtyExtraCell(catQty, catBonus)}<td class="si-num" dir="ltr">${esc(fmtAmt(bag.amt))}</td></tr>`;
          custQty += catQty;
          custBonus += catBonus;
          custAmt += bag.amt;
        });
        if (!lines) return;
        detailBody += `<tr class="ora-cust-band"><td colspan="7">${esc(slot.label)}</td></tr>${lines}`;
        detailBody += `<tr class="ora-cust-final"><td colspan="4">المجموع النهائي لمبيعات العميل</td>${qtyExtraCell(custQty, custBonus)}<td class="si-num" dir="ltr">${esc(fmtAmt(custAmt))}</td></tr>`;
      });
      const invList = [...invMap.values()].sort((a, b) => {
        const d = String(a.saleDate || '').localeCompare(String(b.saleDate || ''));
        if (d) return d;
        const k = String(a.docKind || 'S').localeCompare(String(b.docKind || 'S'));
        if (k) return k;
        return (a.invNo || 0) - (b.invNo || 0);
      });
      invList.forEach((inv) => {
        const isRet = String(inv.docKind || 'S') === 'R';
        const kindLabel = isRet ? 'مرتجع' : 'بيع';
        const invLabel =
          inv.invNo > 0
            ? `${isRet ? 'مرتجع' : 'فاتورة'} ${inv.invNo}${inv.invYear > 0 ? ` / ${inv.invYear}` : ''}`
            : isRet
              ? 'مرتجع بدون رقم'
              : 'بدون رقم فاتورة';
        const invHref =
          inv.invNo > 0
            ? `${invBase}?run=1&invoice_no=${inv.invNo}&year=${inv.invYear || 0}`
            : '';
        const invTitle = invHref
          ? `<a class="ora-inv-link" href="${esc(invHref)}">${esc(invLabel)}</a>`
          : esc(invLabel);
        invoiceBody += `<tr class="ora-cust-band"><td colspan="6">${invTitle}
          <span class="ora-inv-meta"> — ${esc(kindLabel)} — ${esc(fmtSaleDate(inv.saleDate) || '—')} — ${esc(inv.customer)}</span></td></tr>`;
        inv.lines
          .slice()
          .sort((a, b) => {
            const c = String(a.cat || '').localeCompare(String(b.cat || ''), 'ar');
            if (c) return c;
            return cleanName(a.name).localeCompare(cleanName(b.name), 'ar');
          })
          .forEach((ln) => {
            const lnName = cleanName(ln.name) || ln.name;
            invoiceBody += `<tr data-sort-row data-sort-cat="${esc(ln.cat)}" data-sort-item="${esc(lnName)}" data-sort-barcode="${esc(ln.barcode || '')}" data-sort-qty="${ln.qty}" data-sort-bonus="${Number(ln.bonus) || 0}" data-sort-amount="${ln.amount}">
              <td>${esc(ln.cat)}</td>
              <td class="ora-name">${esc(lnName)}</td>
              <td class="ora-code" dir="ltr">${esc(ln.barcode || '')}</td>
              ${qtyExtraCell(ln.qty, ln.bonus)}
              <td class="si-num" dir="ltr">${esc(fmtAmt(ln.amount))}</td>
            </tr>`;
          });
        invoiceBody += `<tr class="ora-cust-final"><td colspan="3">مجموع ${esc(kindLabel)}</td>
          ${qtyExtraCell(inv.qty, inv.bonus)}
          <td class="si-num" dir="ltr">${esc(fmtAmt(inv.amount))}</td></tr>`;
        invRepQty += inv.qty;
        invRepBonus += Number(inv.bonus) || 0;
        invRepAmt += inv.amount;
        invoiceSumBody += `<tr class="${isRet ? 'ora-ret-row' : ''}" data-sort-row data-sort-kind="${esc(kindLabel)}" data-sort-invoice="${esc(String(inv.invNo || 0))}" data-sort-date="${esc(inv.saleDate || '')}" data-sort-customer="${esc(inv.customer)}" data-sort-qty="${inv.qty}" data-sort-bonus="${Number(inv.bonus) || 0}" data-sort-amount="${inv.amount}">
          <td>${esc(kindLabel)}</td>
          <td>${invTitle}</td>
          <td class="si-num" dir="ltr">${esc(fmtSaleDate(inv.saleDate) || '—')}</td>
          <td class="ora-cust-name">${esc(inv.customer)}</td>
          ${qtyExtraCell(inv.qty, inv.bonus)}
          <td class="si-num" dir="ltr">${esc(fmtAmt(inv.amount))}</td>
        </tr>`;
      });
      if (invoiceSumBody) {
        invoiceSumBody += `<tr class="ora-cust-final">
          <td colspan="4">مجموع المندوب</td>
          ${qtyExtraCell(invRepQty, invRepBonus)}
          <td class="si-num" dir="ltr">${esc(fmtAmt(invRepAmt))}</td>
        </tr>`;
      }
      const foot = yearCompare
        ? colYearTotals
            .map((ym) => `<td class="si-num ora-cat-years">${yearStackHtml(ym, false)}</td>`)
            .join('')
        : colTotals.map((amt) => `<td class="si-num" dir="ltr">${esc(fmtAmt(amt))}</td>`).join('');
      const footTotal = yearCompare
        ? `<td class="si-num ora-total-years">${yearStackHtml(repYearTotals, true)}</td>`
        : `<td class="si-num" dir="ltr">${esc(fmtAmt(repAmt))}</td>`;
      const footRow = `<tr class="ora-cust-final ora-sum-all"><td>المجموع</td>${foot}${footTotal}</tr>`;
      const pickCats = catOrder
        .map((_, idx) => `<td class="si-num ora-pick-cat" data-cat-idx="${idx}" dir="ltr"></td>`)
        .join('');
      const pickRow = `<tr class="ora-pick-sum is-empty"><td>مجموع المحددين (<span class="ora-pick-n">0</span>)</td>${pickCats}<td class="si-num ora-pick-total" dir="ltr"></td></tr>`;
      const heads = catOrder
        .map(
          (cat, idx) =>
            `<th class="ora-vhead ora-cat-col js-sort-th" data-sort="cat${idx}" data-sort-type="number" title="ترتيب حسب ${esc(cat)}">${esc(cat)}${
              yearCompare ? `<span class="ora-yhint">${esc(compareYears.join(' / '))}</span>` : ''
            }</th>`
        )
        .join('');
      const yearNote = yearCompare
        ? `<div class="ora-year-note muted">مقارنة السنوات: ${esc(compareYears.join(' و '))} — تحت كل فئة مبيعات كل سنة، وفي المجموع يظهر مجموع كل سنة والفرق (${esc(
            String(compareYears[compareYears.length - 1])
          )} − ${esc(String(compareYears[0]))})</div>`
        : '';
      const sectionOf = (body) => `<section class="ora-cust-block ora-summary-block">
        <header class="ora-cust-head">
          <div class="ora-rep">المندوب: ${esc(repLabel)}</div>
          ${yearNote}
          <div class="ora-pick-bar no-print is-empty">
            <span>مجموع العملاء المحددين (<span class="ora-pick-count">0</span>):</span>
            <strong class="ora-pick-amt" dir="ltr">0</strong>
            <button type="button" class="si-btn ora-pick-clear">مسح التحديد</button>
          </div>
        </header>
        <div class="si-table-wrap ora-portrait-wrap">
          <table class="si-table ora-portrait-table report-sales-table js-sortable-report ora-summary-pick${
            yearCompare ? ' ora-year-compare' : ''
          }" data-no-default-sort="1" data-ora-cat-count="${catOrder.length}" data-ora-compare-years="${esc(
            compareYears.join(',')
          )}">
            <thead><tr>
              <th class="ora-vhead js-sort-th" data-sort="customer" data-sort-type="text">العميل</th>
              ${heads}
              <th class="ora-vhead js-sort-th" data-sort="total" data-sort-type="number">المجموع${
                yearCompare ? '<span class="ora-yhint">سنوات + فرق</span>' : ''
              }</th>
            </tr></thead>
            <tbody>${body}${footRow}${pickRow}</tbody>
          </table>
        </div>
        <div class="ora-pick-foot no-print is-empty">
          <span>مجموع التحديد أسفل الجدول (<span class="ora-pick-count">0</span>):</span>
          <strong class="ora-pick-amt" dir="ltr">0</strong>
          <span class="ora-pick-foot-cats"></span>
        </div>
      </section>`;
      summaryHtml += sectionOf(summaryBody);
      detailHtml += `<section class="ora-cust-block">
        <header class="ora-cust-head">
          <div class="ora-rep">المندوب: ${esc(repLabel)}</div>
        </header>
        <div class="si-table-wrap ora-portrait-wrap">
          <table class="si-table ora-portrait-table ora-detail-table report-sales-table js-sortable-report" data-no-default-sort="1">
            <thead><tr>
              <th class="ora-vhead js-sort-th" data-sort="date" data-sort-type="date">التاريخ</th>
              <th class="ora-vhead js-sort-th" data-sort="cat" data-sort-type="text">الفئة</th>
              <th class="ora-vhead js-sort-th" data-sort="item" data-sort-type="text">المادة</th>
              <th class="ora-vhead js-sort-th" data-sort="barcode" data-sort-type="text">الباركود</th>
              <th class="ora-vhead js-sort-th" data-sort="qty" data-sort-type="number">الكمية</th>
              <th class="ora-vhead js-sort-th" data-sort="bonus" data-sort-type="number">إضافي</th>
              <th class="ora-vhead js-sort-th" data-sort="amount" data-sort-type="number">المبلغ</th>
            </tr></thead>
            <tbody>${detailBody}</tbody>
          </table>
        </div>
      </section>`;
      invoiceHtml += `<section class="ora-cust-block">
        <header class="ora-cust-head">
          <div class="ora-rep">المندوب: ${esc(repLabel)}</div>
        </header>
        <div class="si-table-wrap ora-portrait-wrap">
          <table class="si-table ora-portrait-table ora-detail-table report-sales-table js-sortable-report" data-no-default-sort="1">
            <thead><tr>
              <th class="ora-vhead js-sort-th" data-sort="cat" data-sort-type="text">الفئة</th>
              <th class="ora-vhead js-sort-th" data-sort="item" data-sort-type="text">المادة</th>
              <th class="ora-vhead js-sort-th" data-sort="barcode" data-sort-type="text">الباركود</th>
              <th class="ora-vhead js-sort-th" data-sort="qty" data-sort-type="number">الكمية</th>
              <th class="ora-vhead js-sort-th" data-sort="bonus" data-sort-type="number">إضافي</th>
              <th class="ora-vhead js-sort-th" data-sort="amount" data-sort-type="number">المبلغ</th>
            </tr></thead>
            <tbody>${invoiceBody}</tbody>
          </table>
        </div>
      </section>`;
      invoiceSumHtml += `<section class="ora-cust-block">
        <header class="ora-cust-head">
          <div class="ora-rep">المندوب: ${esc(repLabel)}</div>
        </header>
        <div class="si-table-wrap ora-portrait-wrap">
          <table class="si-table ora-portrait-table ora-detail-table report-sales-table js-sortable-report" data-no-default-sort="1">
            <thead><tr>
              <th class="ora-vhead js-sort-th" data-sort="kind" data-sort-type="text">النوع</th>
              <th class="ora-vhead js-sort-th" data-sort="invoice" data-sort-type="number">الفاتورة</th>
              <th class="ora-vhead js-sort-th" data-sort="date" data-sort-type="date">التاريخ</th>
              <th class="ora-vhead js-sort-th" data-sort="customer" data-sort-type="text">العميل</th>
              <th class="ora-vhead js-sort-th" data-sort="qty" data-sort-type="number">الكمية</th>
              <th class="ora-vhead js-sort-th" data-sort="bonus" data-sort-type="number">إضافي</th>
              <th class="ora-vhead js-sort-th" data-sort="amount" data-sort-type="number">المبلغ</th>
            </tr></thead>
            <tbody>${invoiceSumBody}</tbody>
          </table>
        </div>
      </section>`;
    }
    return { summaryHtml, detailHtml, invoiceHtml, invoiceSumHtml };
    };
    // كل العروض بعد خصم المرتجعات
    const netV = netRows.length
      ? buildOraViews(netRows)
      : { summaryHtml: '', detailHtml: '', invoiceHtml: '', invoiceSumHtml: '' };
    const retAmtShow = returnRows.reduce((s, r) => s + (Number(r.amount) || 0), 0);
    const netGrand = `<p class="ora-grand">الصافي (مبيعات − مرتجعات) — الكمية ${esc(fmtQty(netTotals.qty))} — إضافي ${esc(fmtQty(netTotals.bonus || 0))} — المبلغ ${esc(fmtAmt(netTotals.amount))} <span class="muted">(مبيعات ${esc(fmtAmt(totals.amount))} − مرتجعات ${esc(String(returnRows.length))} بمبلغ ${esc(fmtAmt(retAmtShow))})</span></p>`;
    const emptyNet = '<p class="muted">لا توجد حركة في الفترة المحددة.</p>';
    const toolsHtml = `<div class="ora-tools no-print">
        <button type="button" class="si-btn" id="ora-excel-btn">Excel</button>
        <button type="button" class="si-btn si-btn--primary" data-ora-view="summary">ملخص</button>
        <button type="button" class="si-btn" data-ora-view="detail">تفصيلي</button>
        <button type="button" class="si-btn" data-ora-view="invoice">فواتير تفصيلي</button>
        <button type="button" class="si-btn" data-ora-view="invoice-sum">فواتير إجمالي</button>
      </div>`;
    rowsHtml = `${toolsHtml}
      <div class="ora-view" id="ora-view-net-summary" data-ora-mode-panel="net" data-ora-view-panel="summary">${netV.summaryHtml || emptyNet}${netGrand}</div>
      <div class="ora-view" id="ora-view-net-detail" data-ora-mode-panel="net" data-ora-view-panel="detail" hidden>${netV.detailHtml || emptyNet}${netGrand}</div>
      <div class="ora-view" id="ora-view-net-invoice" data-ora-mode-panel="net" data-ora-view-panel="invoice" hidden>${netV.invoiceHtml || emptyNet}${netGrand}</div>
      <div class="ora-view" id="ora-view-net-invoice-sum" data-ora-mode-panel="net" data-ora-view-panel="invoice-sum" hidden>${netV.invoiceSumHtml || emptyNet}${netGrand}</div>`;
  } else if (run && !err) {
    rowsHtml = '<p class="muted">لا توجد مبيعات في الفترة المحددة.</p>';
  }

  let tableBlock = '';
  let stickyToolsHtml = '';
  if (err) {
    tableBlock = `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>`;
  } else if (run) {
    // افصل أزرار العرض عن البيانات حتى تبقى مع ترويسة التقرير عند التمرير
    const toolsMatch = String(rowsHtml || '').match(/^(\s*<div class="ora-tools[\s\S]*?<\/div>)/);
    if (toolsMatch) {
      stickyToolsHtml = toolsMatch[1];
      tableBlock = String(rowsHtml).slice(toolsMatch[0].length);
    } else {
      tableBlock = rowsHtml;
    }
  } else {
    tableBlock = `<p class="muted" style="padding:.5rem 0">حدّد الفلاتر ثم اضغط «عرض».</p>`;
  }

  const repsJson = JSON.stringify(reps).replace(/</g, '\\u003c');
  const customersJson = JSON.stringify(
    (customers || []).map((c) => {
      const cid = String(Number(c.id) || 0);
      return {
        id: cid,
        label: String(c.name_ar || ''),
        code: String(c.code || ''),
        rep_codes: [...(custRepCodes.get(cid) || [])],
      };
    })
  ).replace(/</g, '\\u003c');
  const categoriesJson = JSON.stringify(
    [{ id: 'none', label: 'بدون فئة', code: '' }].concat(
      (categories || []).map((c) => ({
        id: String(Number(c.id) || 0),
        label: String(c.name_ar || ''),
        code: '',
      }))
    )
  ).replace(/</g, '\\u003c');
  const monthLabels = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
  const yearsJson = JSON.stringify(
    Array.from({ length: 9 }, (_, i) => {
      const y = String(yearNow - i);
      return { id: y, label: y, code: '' };
    })
  ).replace(/</g, '\\u003c');
  const monthsJson = JSON.stringify(
    monthLabels.map((label, i) => ({ id: String(i + 1), label, code: '' }))
  ).replace(/</g, '\\u003c');
  const selectedJson = JSON.stringify({
    reps: oracleRepNos.map(String),
    customers: customerIds.map(String),
    categories: categoryIds,
    years: reportYears.map(String),
    months: reportMonths.map(String),
  }).replace(/</g, '\\u003c');
  const repsPickerJson = JSON.stringify(
    (reps || []).map((r) => ({
      id: String(Number(r.id) || 0),
      label: String(r.name_ar || ''),
      code: String(r.code || ''),
    }))
  ).replace(/</g, '\\u003c');
  const body = `
    <div class="si-stage si-report-page report-sales-page" data-report-route="report_oracle_rep_item_sales">
      <div class="ora-report-sticky-chrome">
      ${ui.hero({
        mark: '📦',
        kicker: 'تقارير المبيعات',
        title: 'مبيعات المندوب حسب المادة والفئة (Oracle)',
        subtitle: 'كل مندوب، العميل، المواد التي باعها، وفئة كل مادة',
        actions: [
          ui.printAction(),
          { label: 'عرض التقرير', submit: true, form: 'ora-rep-filter', primary: true },
          { label: 'لوحة المبيعات', href: '/sales' },
        ],
      })}
      <p class="muted no-print ora-report-sticky-note">قوائم المندوب والفئة من النظام الحالي. المبيعات من Oracle حسب السنة والشهر. عند اختيار مندوب تُعرض كل مبيعات عملائه الحاليين (بما فيها ما بيع سابقاً تحت مندوب آخر).</p>
      <div class="si-rail no-print">
        <form id="ora-rep-filter" method="post" action="${esc(BASE)}" class="si-search report-sales-filters ora-filter-row" data-app-busy-msg="جاري تحميل التقرير…">
          <input type="hidden" name="run" value="1" />
          <label>المندوب
            <div class="ora-multi" id="ora-rep-multi" data-ora-name="sales_rep_id" data-ora-empty="كل المندوبين">
              <button type="button" class="si-field ora-multi-btn" data-ora-multi-btn>كل المندوبين</button>
              <div class="ora-multi-panel" data-ora-multi-panel hidden>
                <div class="ora-multi-actions">
                  <button type="button" data-ora-all>تحديد الكل</button>
                  <button type="button" data-ora-none>كل المندوبين</button>
                </div>
                <input type="text" class="si-field ora-multi-q" data-ora-multi-q placeholder="بحث بالاسم أو الرقم…" autocomplete="off">
                <div class="ora-multi-list" data-ora-multi-list></div>
              </div>
            </div>
          </label>
          <label>العميل
            <div class="ora-multi" id="ora-cust-multi" data-ora-name="customer_id" data-ora-empty="كل العملاء" data-ora-collapse-all="1">
              <button type="button" class="si-field ora-multi-btn" data-ora-multi-btn>كل العملاء</button>
              <div class="ora-multi-panel" data-ora-multi-panel hidden>
                <div class="ora-multi-actions">
                  <button type="button" data-ora-all>تحديد الكل</button>
                  <button type="button" data-ora-none>كل العملاء</button>
                </div>
                <input type="text" class="si-field ora-multi-q" data-ora-multi-q placeholder="بحث بالاسم أو الرمز…" autocomplete="off">
                <div class="ora-multi-list" data-ora-multi-list></div>
              </div>
            </div>
          </label>
          <label>الفئة
            <div class="ora-multi" id="ora-cat-multi" data-ora-name="category_id" data-ora-empty="كل الفئات">
              <button type="button" class="si-field ora-multi-btn" data-ora-multi-btn>كل الفئات</button>
              <div class="ora-multi-panel" data-ora-multi-panel hidden>
                <input type="text" class="si-field ora-multi-q" data-ora-multi-q placeholder="بحث عن فئة…" autocomplete="off">
                <div class="ora-multi-list" data-ora-multi-list></div>
              </div>
            </div>
          </label>
          <label>السنة
            <div class="ora-multi" id="ora-year-multi" data-ora-name="report_year" data-ora-empty="كل السنوات">
              <button type="button" class="si-field ora-multi-btn" data-ora-multi-btn>كل السنوات</button>
              <div class="ora-multi-panel" data-ora-multi-panel hidden>
                <div class="ora-multi-actions">
                  <button type="button" data-ora-all>تحديد الكل</button>
                  <button type="button" data-ora-none>كل السنوات</button>
                </div>
                <input type="text" class="si-field ora-multi-q" data-ora-multi-q placeholder="بحث عن سنة…" autocomplete="off">
                <div class="ora-multi-list" data-ora-multi-list></div>
              </div>
            </div>
          </label>
          <label>الشهر
            <div class="ora-multi" id="ora-month-multi" data-ora-name="report_month" data-ora-empty="كل الأشهر">
              <button type="button" class="si-field ora-multi-btn" data-ora-multi-btn>كل الأشهر</button>
              <div class="ora-multi-panel" data-ora-multi-panel hidden>
                <div class="ora-multi-actions">
                  <button type="button" data-ora-all>تحديد الكل</button>
                  <button type="button" data-ora-none>كل الأشهر</button>
                </div>
                <input type="text" class="si-field ora-multi-q" data-ora-multi-q placeholder="بحث عن شهر…" autocomplete="off">
                <div class="ora-multi-list" data-ora-multi-list></div>
              </div>
            </div>
          </label>
          <label>المستودع
            <input class="si-field" type="number" name="store" value="${store}" min="1" dir="ltr">
          </label>
          <div class="ora-filter-actions">
            <button type="submit" class="si-btn si-btn--primary">عرض</button>
          </div>
        </form>
      </div>
      ${stickyToolsHtml}
      </div>
      <style>
        .si-report-page[data-report-route="report_oracle_rep_item_sales"] .ora-report-sticky-chrome {
          position: sticky;
          top: 0;
          z-index: 12;
          margin: 0 0 .55rem;
          padding: 0 0 .45rem;
          background: #d4d0c8;
          box-shadow: 0 1px 0 #808080;
        }
        .si-report-page[data-report-route="report_oracle_rep_item_sales"] .ora-report-sticky-note {
          margin: 0 0 .35rem;
          font-size: .8rem;
        }
        .si-report-page[data-report-route="report_oracle_rep_item_sales"] .ora-report-sticky-chrome .si-hero {
          margin-bottom: .45rem;
        }
        .si-report-page[data-report-route="report_oracle_rep_item_sales"] .ora-report-sticky-chrome .ora-tools {
          margin: .45rem 0 0;
        }
        .si-report-page[data-report-route="report_oracle_rep_item_sales"] .si-rail {
          display: block;
          border-radius: 12px;
          padding: .7rem .8rem .75rem;
          background: #f8fafc;
        }
        #ora-rep-filter.ora-filter-row {
          display: grid !important;
          grid-template-columns:
            minmax(0, 1.35fr)
            minmax(0, 1.35fr)
            minmax(0, 1.05fr)
            minmax(7.2rem, .85fr)
            minmax(7.2rem, .85fr)
            4.5rem
            auto;
          gap: .35rem .5rem;
          align-items: end;
          width: 100% !important;
          max-width: none !important;
          margin: 0 !important;
          padding: 0 !important;
          border: 0 !important;
          border-radius: 0 !important;
          background: transparent !important;
          box-shadow: none !important;
          overflow: visible;
        }
        #ora-rep-filter .ora-filter-actions {
          display: flex;
          align-items: end;
          padding-bottom: 1px;
        }
        .ora-multi-actions {
          display: flex;
          gap: .35rem;
          flex-wrap: wrap;
        }
        .ora-multi-actions button {
          flex: 1 1 auto;
          border: 1px solid #cbd5e1;
          background: #f8fafc;
          border-radius: 6px;
          padding: .35rem .45rem;
          font-family: Arial, Helvetica, sans-serif;
          font-size: 12px;
          font-weight: 700;
          color: #1e3a5f;
          cursor: pointer;
        }
        #ora-rep-filter.ora-filter-row > label {
          display: flex;
          flex-direction: column;
          gap: .22rem;
          min-width: 0;
          font-family: Arial, Helvetica, sans-serif;
          font-size: 13px;
          font-weight: 700;
          color: #334155;
          white-space: nowrap;
        }
        #ora-rep-filter .si-field,
        #ora-rep-filter .ora-multi-btn,
        #ora-rep-filter .report-cust-pick-inp,
        #ora-rep-filter .date-dmy-field {
          width: 100% !important;
          min-width: 0 !important;
          max-width: none !important;
          height: 2.35rem;
          min-height: 2.35rem;
          box-sizing: border-box;
        }
        #ora-rep-filter .ora-multi-btn,
        #ora-rep-filter .report-cust-pick-inp,
        #ora-rep-filter .si-field {
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
          border: 1px solid #cbd5e1 !important;
          background: #fff !important;
        }
        /* overflow/max-height على الحاوية وborder-collapse:collapse يكسران sticky */
        .si-report-page .si-table-wrap.ora-portrait-wrap,
        .ora-portrait-wrap {
          max-width: none;
          max-height: none !important;
          height: auto !important;
          overflow-x: clip !important;
          overflow-y: visible !important;
        }
        .ora-portrait-table {
          width: 100%;
          table-layout: auto;
          border-collapse: separate !important;
          border-spacing: 0;
        }
        .ora-portrait-table thead th,
        .ora-portrait-table th.ora-vhead {
          writing-mode: horizontal-tb;
          white-space: nowrap;
          height: auto;
          vertical-align: middle;
          text-align: center;
          font-size: .82rem;
          padding: .55rem .45rem;
          position: sticky !important;
          top: var(--ora-report-sticky-h, 0px);
          background: #e8eef5 !important;
          box-shadow: 0 1px 0 #94a3b8;
          z-index: 6;
        }
        .ora-portrait-table th.ora-vhead.js-sort-th {
          cursor: pointer;
          user-select: none;
        }
        .ora-portrait-table th.ora-vhead.js-sort-th:hover {
          background: #dbe4ef !important;
        }
        .ora-portrait-table th.ora-vhead.js-sort-th.is-sort-asc::after {
          content: ' ▲';
          font-size: .7em;
          opacity: .75;
        }
        .ora-portrait-table th.ora-vhead.js-sort-th.is-sort-desc::after {
          content: ' ▼';
          font-size: .7em;
          opacity: .75;
        }
        .ora-portrait-table td { vertical-align: middle; padding: .4rem .45rem; }
        .ora-portrait-table td.si-num,
        .ora-portrait-table td.ora-code {
          white-space: nowrap;
          word-break: keep-all;
          font-variant-numeric: tabular-nums;
        }
        .ora-portrait-table td.ora-name { min-width: 14rem; white-space: normal; }
        .ora-portrait-table th.ora-cat-col { white-space: normal; min-width: 6.5rem; text-align: center; }
        .ora-portrait-table td.ora-cust-name { font-weight: 700; min-width: 12rem; white-space: normal; }
        .ora-tools { display: flex; gap: .5rem; margin: 0 1rem .75rem; }
        .ora-multi-btn,
        .si-report-page .report-cust-pick-inp {
          width: 100%;
          height: 2.4rem;
          text-align: right;
          cursor: pointer;
          background: #fff !important;
          color: #0f172a;
          font-family: Arial, Helvetica, sans-serif;
          font-size: 15px;
          font-weight: 700;
          line-height: 1.4;
          border: 1px solid #cbd5e1;
          border-radius: 8px;
          padding: 0 .7rem;
          box-sizing: border-box;
        }
        .si-report-page .report-sales-filters > label {
          font-family: Arial, Helvetica, sans-serif;
          font-size: 13px;
          font-weight: 700;
          color: #334155;
        }
        .ora-multi-panel,
        .report-cust-pick-list.is-floating {
          font-family: Arial, Helvetica, sans-serif;
          font-size: 15px;
          line-height: 1.45;
          color: #0f172a;
          background: #fff;
          border: 1px solid #cbd5e1;
          border-radius: 10px;
          box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
          padding: .35rem;
        }
        .ora-multi-panel {
          position: fixed; z-index: 100600; display: flex; flex-direction: column; gap: .35rem;
          max-height: min(22rem, 70vh); min-width: 18rem; box-sizing: border-box;
        }
        .ora-multi-panel[hidden] { display: none !important; }
        .ora-multi-q {
          width: 100%; box-sizing: border-box; background: #fff; border: 1px solid #cbd5e1;
          border-radius: 6px; padding: .45rem .6rem; color: #0f172a;
          font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600;
        }
        .ora-multi-q:focus { outline: none; border-color: #1e3a5f; background: #fff; }
        .ora-multi-list { overflow: auto; max-height: 16rem; background: #fff; }
        .ora-multi-item,
        .report-cust-pick-list.is-floating .report-cust-pick-item,
        .report-cust-pick-list.is-floating .report-cust-pick-empty {
          display: flex !important;
          flex-direction: row;
          justify-content: flex-start !important;
          align-items: center;
          gap: .6rem;
          width: 100%;
          margin: 0;
          padding: .5rem .7rem;
          direction: rtl;
          text-align: right;
          cursor: pointer;
          box-sizing: border-box;
          font-family: Arial, Helvetica, sans-serif;
          font-size: 15px;
          font-weight: 600;
          line-height: 1.45;
          color: #0f172a;
          background: #fff;
          border: 0;
          border-bottom: 1px solid #f1f5f9;
          border-radius: 0;
        }
        .ora-multi-item:hover,
        .report-cust-pick-list.is-floating .report-cust-pick-item:hover {
          background: #eef2ff;
        }
        .ora-multi-item input[type="checkbox"] { width: 1rem; height: 1rem; margin: 0; flex: 0 0 auto; }
        .ora-multi-name { flex: 1 1 auto; min-width: 0; text-align: right; }
        .ora-multi-code {
          flex: 0 0 auto; font-size: 12px; font-weight: 700; color: #64748b; direction: ltr;
        }
        .report-cust-pick-list.is-floating .report-cust-pick-empty {
          cursor: default; color: #64748b; font-weight: 600;
        }
        .ora-view[hidden] { display: none !important; }
        .ora-detail-table td.ora-name { min-width: 18rem; white-space: normal; }
        .ora-cust-band td { background: #f4f7fb; font-weight: 800; font-size: 1rem; }
        .ora-cat-sum td { background: #e8eef8; font-weight: 800; }
        .ora-cust-block { margin: 0 1rem 1.35rem; }
        .ora-cust-head {
          background: #f4f7fb;
          border: 1px solid #d5deea;
          border-bottom: 0;
          padding: .7rem .9rem;
        }
        .ora-cust-head .ora-rep { font-weight: 800; color: #1e3a5f; }
        .ora-cust-head .ora-who { font-weight: 800; font-size: 1.02rem; margin-top: .25rem; }
        .ora-cat-head { cursor: pointer; }
        .ora-cat-head td { background: #e8eef8; font-weight: 800; }
        .ora-arrow { display: inline-block; width: 1.1rem; }
        .ora-arrow::before { content: "◂"; }
        .ora-cat-head[data-open="1"] .ora-arrow::before { content: "▾"; }
        .ora-cust-final td { background: #e7f4ea; font-weight: 800; }
        .ora-cust-name {
          display: flex;
          align-items: center;
          gap: .45rem;
          white-space: nowrap;
        }
        .ora-cust-pick-lab {
          display: inline-flex;
          align-items: center;
          margin: 0;
          cursor: pointer;
          flex: 0 0 auto;
        }
        .ora-cust-pick {
          width: 1.05rem;
          height: 1.05rem;
          accent-color: #1e3a5f;
          cursor: pointer;
        }
        .ora-cust-label { min-width: 0; }
        tr.ora-sum-row.is-picked td { background: #eef6ff; }
        tr.ora-sum-row.is-picked .ora-cust-label { font-weight: 800; color: #1e3a5f; }
        tr.ora-pick-sum td {
          background: #dbeafe;
          font-weight: 800;
          color: #1e3a5f;
          border-top: 2px solid #93c5fd;
        }
        tr.ora-pick-sum.is-empty { display: none; }
        .ora-pick-bar,
        .ora-pick-foot {
          margin-top: .55rem;
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          gap: .45rem .7rem;
          padding: .45rem .65rem;
          background: #dbeafe;
          border: 1px solid #93c5fd;
          border-radius: .35rem;
          font-weight: 700;
          color: #1e3a5f;
        }
        .ora-pick-bar.is-empty,
        .ora-pick-foot.is-empty { display: none !important; }
        .ora-pick-foot {
          margin: 0 1rem .85rem;
          border-radius: 0 0 .35rem .35rem;
          border-top: 0;
        }
        .ora-pick-bar .ora-pick-amt,
        .ora-pick-foot .ora-pick-amt { font-size: 1.05rem; letter-spacing: .01em; }
        .ora-pick-bar .ora-pick-clear { min-height: 2rem; padding: .2rem .65rem; }
        .ora-pick-foot-cats {
          display: inline-flex;
          flex-wrap: wrap;
          gap: .35rem .65rem;
          font-weight: 700;
          color: #334155;
        }
        .ora-pick-foot-cats span { white-space: nowrap; }
        .ora-ystack {
          display: grid;
          gap: .12rem;
          min-width: 5.5rem;
          justify-items: stretch;
        }
        .ora-yline {
          display: flex;
          align-items: baseline;
          justify-content: space-between;
          gap: .35rem;
          font-size: .78rem;
          line-height: 1.25;
          white-space: nowrap;
        }
        .ora-ylbl {
          font-weight: 800;
          color: #475569;
          font-variant-numeric: tabular-nums;
        }
        .ora-yval {
          font-weight: 700;
          color: #0f172a;
          font-variant-numeric: tabular-nums;
        }
        .ora-yline.ora-ydiff {
          margin-top: .12rem;
          padding-top: .18rem;
          border-top: 1px dashed #94a3b8;
        }
        .ora-yline.ora-ydiff .ora-ylbl,
        .ora-yline.ora-ydiff .ora-yval { color: #1e3a5f; font-weight: 800; }
        .ora-yhint {
          display: block;
          margin-top: .15rem;
          font-size: .68rem;
          font-weight: 700;
          color: #64748b;
          line-height: 1.2;
        }
        .ora-year-note {
          margin-top: .35rem;
          font-size: .78rem;
          font-weight: 600;
        }
        table.ora-year-compare td.ora-cat-years,
        table.ora-year-compare td.ora-total-years {
          vertical-align: top;
        }
        .ora-inv-link { color: #1e3a5f; font-weight: 800; text-decoration: underline; }
        .ora-inv-meta { font-weight: 600; color: #475569; }
        .ora-mode-group { display: inline-flex; gap: .25rem; margin: 0 .35rem; }
        tr.ora-ret-row td { color: #9f1239; background: #fff1f2; }
        .ora-rep-total, .ora-grand {
          margin: .35rem 1rem 1rem;
          font-weight: 800;
          padding: .45rem .7rem;
          background: #f8fafc;
          border: 1px solid #e2e8f0;
        }
        .ora-grand { background: #eef2f7; }
        @media print {
          @page { size: A4 portrait; margin: 8mm 6mm 10mm 6mm; }
          .ora-report-sticky-chrome {
            position: static !important;
            box-shadow: none !important;
            background: transparent !important;
          }
          .ora-portrait-table thead th,
          .ora-portrait-table th.ora-vhead { position: static; box-shadow: none; }
          .ora-portrait-table { font-size: 9pt; }
          tr.ora-item[hidden] { display: table-row !important; }
          .ora-arrow { display: none; }
          .ora-cust-pick-lab, .ora-pick-bar, .ora-pick-foot, tr.ora-pick-sum { display: none !important; }
          tr.ora-sum-row.is-picked td { background: transparent; }
        }
      </style>
      <div class="si-print-area report-sales-result">${tableBlock}</div>
      <script>
      (function syncOraReportSticky() {
        var page = document.querySelector('.si-report-page[data-report-route="report_oracle_rep_item_sales"]');
        if (!page) return;
        var title = document.querySelector('.hx-ora-screen-title');
        var chrome = page.querySelector('.ora-report-sticky-chrome');
        var main = document.querySelector('main.si-main, main.app-embed-main, main.main');
        function sync() {
          var titleH = 0;
          if (title) {
            title.style.position = 'sticky';
            title.style.top = '0px';
            title.style.zIndex = '20';
            titleH = Math.ceil(title.getBoundingClientRect().height || title.offsetHeight || 0);
          }
          if (chrome) {
            chrome.style.position = 'sticky';
            chrome.style.top = titleH + 'px';
            chrome.style.zIndex = '12';
            var chromeH = Math.ceil(chrome.getBoundingClientRect().height || chrome.offsetHeight || 0);
            var total = titleH + chromeH;
            page.style.setProperty('--ora-report-sticky-h', total + 'px');
            if (main) main.style.setProperty('--ora-report-sticky-h', total + 'px');
          } else {
            page.style.setProperty('--ora-report-sticky-h', titleH + 'px');
            if (main) main.style.setProperty('--ora-report-sticky-h', titleH + 'px');
          }
        }
        sync();
        requestAnimationFrame(sync);
        if (typeof ResizeObserver !== 'undefined') {
          var ro = new ResizeObserver(sync);
          if (title) ro.observe(title);
          if (chrome) ro.observe(chrome);
          if (main) ro.observe(main);
        }
        window.addEventListener('resize', sync);
        if (main) main.addEventListener('scroll', function () { /* keep sticky vars fresh after fonts */ }, { passive: true });
      })();
      </script>
      <script type="application/json" id="report-sales-reps-json">${repsPickerJson}</script>
      <script type="application/json" id="report-sales-customers-json">${customersJson}</script>
      <script type="application/json" id="report-sales-categories-json">${categoriesJson}</script>
      <script type="application/json" id="report-sales-years-json">${yearsJson}</script>
      <script type="application/json" id="report-sales-months-json">${monthsJson}</script>
      <script type="application/json" id="ora-filter-selected">${selectedJson}</script>
      <script>
      function initOraMulti(root, items, selected) {
        if (!root) return null;
        var btn = root.querySelector('[data-ora-multi-btn]');
        var panel = root.querySelector('[data-ora-multi-panel]');
        var q = root.querySelector('[data-ora-multi-q]');
        var list = root.querySelector('[data-ora-multi-list]');
        var name = root.getAttribute('data-ora-name');
        var emptyLabel = root.getAttribute('data-ora-empty') || 'الكل';
        var collapseAll = root.getAttribute('data-ora-collapse-all') === '1';
        var allItems = Array.isArray(items) ? items.slice() : [];
        var visible = allItems.slice();
        var sel = {};
        (selected || []).forEach(function (id) { sel[String(id)] = true; });
        function selectedIds() { return Object.keys(sel).filter(function (k) { return sel[k]; }); }
        function allVisibleSelected() {
          if (!visible.length) return false;
          for (var i = 0; i < visible.length; i += 1) {
            if (!sel[String(visible[i].id)]) return false;
          }
          return true;
        }
        function emitIds() {
          var ids = selectedIds();
          // عند تحديد كل الظاهر: لا نرسل المعرّفات (يعني «الكل») لتفادي 414/500
          if (collapseAll && allVisibleSelected()) return [];
          return ids;
        }
        function labelOf() {
          var ids = selectedIds();
          if (!ids.length || (collapseAll && allVisibleSelected())) return emptyLabel;
          if (ids.length === 1) {
            var one = allItems.find(function (it) { return String(it.id) === ids[0]; })
              || visible.find(function (it) { return String(it.id) === ids[0]; });
            return one ? one.label : ids[0];
          }
          return ids.length + ' محدد';
        }
        function syncHidden() {
          root.querySelectorAll('input[data-ora-hidden]').forEach(function (n) { n.remove(); });
          emitIds().forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = id;
            input.setAttribute('data-ora-hidden', '1');
            root.appendChild(input);
          });
          btn.textContent = labelOf();
        }
        function render() {
          var needle = String(q.value || '').trim().toLowerCase();
          list.innerHTML = '';
          visible.forEach(function (it) {
            var text = String(it.label || '');
            var code = String(it.code || '');
            var hay = (text + ' ' + code).toLowerCase();
            if (needle && hay.indexOf(needle) < 0) return;
            var row = document.createElement('div');
            row.className = 'ora-multi-item';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !!sel[String(it.id)];
            cb.addEventListener('change', function () {
              if (cb.checked) sel[String(it.id)] = true;
              else delete sel[String(it.id)];
              syncHidden();
            });
            var span = document.createElement('span');
            span.className = 'ora-multi-name';
            span.textContent = text;
            row.appendChild(cb);
            row.appendChild(span);
            if (code) {
              var codeEl = document.createElement('span');
              codeEl.className = 'ora-multi-code';
              codeEl.textContent = code;
              row.appendChild(codeEl);
            }
            row.addEventListener('click', function (ev) {
              if (ev.target === cb) return;
              cb.checked = !cb.checked;
              cb.dispatchEvent(new Event('change'));
            });
            list.appendChild(row);
          });
        }
        function place() {
          var rect = btn.getBoundingClientRect();
          if (panel.parentElement !== document.body) document.body.appendChild(panel);
          var width = Math.max(rect.width, 280);
          var left = rect.left;
          if (left + width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - width - 8);
          var top = rect.bottom + 4;
          panel.style.position = 'fixed';
          panel.style.top = Math.round(top) + 'px';
          panel.style.left = Math.round(left) + 'px';
          panel.style.width = Math.round(width) + 'px';
          panel.style.right = 'auto';
          panel.style.zIndex = '100600';
        }
        function setVisible(next) {
          visible = Array.isArray(next) ? next.slice() : [];
          var allowed = {};
          visible.forEach(function (it) { allowed[String(it.id)] = true; });
          Object.keys(sel).forEach(function (id) {
            if (!allowed[id]) delete sel[id];
          });
          syncHidden();
          if (!panel.hidden) render();
        }
        function selectedNow() { return selectedIds(); }
        var allBtn = panel.querySelector('[data-ora-all]');
        var noneBtn = panel.querySelector('[data-ora-none]');
        if (allBtn) {
          allBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            sel = {};
            visible.forEach(function (it) { sel[String(it.id)] = true; });
            syncHidden();
            render();
          });
        }
        if (noneBtn) {
          noneBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            sel = {};
            syncHidden();
            render();
          });
        }
        btn.addEventListener('click', function (ev) {
          ev.preventDefault();
          ev.stopPropagation();
          var open = panel.hidden;
          document.querySelectorAll('[data-ora-multi-panel]').forEach(function (p) { p.hidden = true; });
          panel.hidden = !open;
          if (!panel.hidden) { place(); render(); q.focus(); }
        });
        q.addEventListener('input', render);
        q.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
        document.addEventListener('click', function (ev) {
          if (!root.contains(ev.target) && !panel.contains(ev.target)) panel.hidden = true;
        });
        window.addEventListener('resize', function () { if (!panel.hidden) place(); });
        syncHidden();
        return { setVisible: setVisible, selectedIds: selectedNow, allItems: function () { return allItems; } };
      }
      document.addEventListener('DOMContentLoaded', function () {
        var selected = { reps: [], customers: [], categories: [], years: [], months: [] };
        var selEl = document.getElementById('ora-filter-selected');
        try { selected = JSON.parse(selEl && selEl.textContent || '{}'); } catch (e3) {}
        var repItems = [];
        var custItems = [];
        var catItems = [];
        var yearItems = [];
        var monthItems = [];
        try { repItems = JSON.parse(document.getElementById('report-sales-reps-json').textContent || '[]'); } catch (eR) {}
        try { custItems = JSON.parse(document.getElementById('report-sales-customers-json').textContent || '[]'); } catch (e4) {}
        try { catItems = JSON.parse(document.getElementById('report-sales-categories-json').textContent || '[]'); } catch (e5) {}
        try { yearItems = JSON.parse(document.getElementById('report-sales-years-json').textContent || '[]'); } catch (e6) {}
        try { monthItems = JSON.parse(document.getElementById('report-sales-months-json').textContent || '[]'); } catch (e7) {}
        var repMulti = initOraMulti(document.getElementById('ora-rep-multi'), repItems, selected.reps || []);
        var custMulti = initOraMulti(document.getElementById('ora-cust-multi'), custItems, selected.customers || []);
        initOraMulti(document.getElementById('ora-cat-multi'), catItems, selected.categories || []);
        initOraMulti(document.getElementById('ora-year-multi'), yearItems, selected.years || []);
        initOraMulti(document.getElementById('ora-month-multi'), monthItems, selected.months || []);
        function parseManNoJs(code) {
          var c = String(code || '').trim();
          if (/^[1-9]\\d*$/.test(c)) return Number(c);
          var m = c.match(/(\\d+)/);
          if (m) {
            var n = Number(m[1]);
            if (n > 0 && n < 100000) return n;
          }
          return 0;
        }
        function filterCustomersByReps() {
          if (!custMulti) return;
          var codes = repMulti && repMulti.selectedIds ? repMulti.selectedIds() : [];
          var want = {};
          codes.forEach(function (c) {
            want[String(c)] = true;
            var man = parseManNoJs(c);
            if (man > 0) want[String(man)] = true;
          });
          var next = !codes.length
            ? custItems
            : custItems.filter(function (it) {
                var rc = Array.isArray(it.rep_codes) ? it.rep_codes : [];
                return rc.some(function (x) {
                  if (want[String(x)]) return true;
                  var man = parseManNoJs(x);
                  return man > 0 && want[String(man)];
                });
              });
          custMulti.setVisible(next);
        }
        var repRoot = document.getElementById('ora-rep-multi');
        if (repRoot) {
          repRoot.addEventListener('change', filterCustomersByReps);
          repRoot.addEventListener('click', function () { setTimeout(filterCustomersByReps, 0); });
        }
        filterCustomersByReps();
      });
      function fmtOraPickAmt(n) {
        var x = Number(n);
        if (!isFinite(x)) x = 0;
        return x.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
      }
      function oraYearStackHtml(years, values, withDiff) {
        var lines = (years || []).map(function (y, idx) {
          var a = Number(values[idx] || 0) || 0;
          return '<div class="ora-yline"><span class="ora-ylbl">' + y + '</span><span class="ora-yval" dir="ltr">' +
            (a ? fmtOraPickAmt(a) : '—') + '</span></div>';
        }).join('');
        var diffHtml = '';
        if (withDiff && years && years.length > 1) {
          var first = Number(values[0] || 0) || 0;
          var last = Number(values[years.length - 1] || 0) || 0;
          var diff = last - first;
          var sign = diff > 0 ? '+' : '';
          diffHtml = '<div class="ora-yline ora-ydiff"><span class="ora-ylbl">الفرق</span><span class="ora-yval" dir="ltr">' +
            sign + fmtOraPickAmt(diff) + '</span></div>';
        }
        return '<div class="ora-ystack">' + lines + diffHtml + '</div>';
      }
      function refreshOraCustPick(block) {
        if (!block) return;
        var table = block.querySelector('table.ora-summary-pick');
        var bar = block.querySelector('.ora-pick-bar');
        var foot = block.querySelector('.ora-pick-foot');
        var pickRow = table && table.querySelector('tr.ora-pick-sum');
        if (!table || !pickRow) return;
        var catCount = Number(table.getAttribute('data-ora-cat-count') || 0) || 0;
        var years = String(table.getAttribute('data-ora-compare-years') || '')
          .split(',')
          .map(function (y) { return String(y || '').trim(); })
          .filter(Boolean);
        var yearMode = years.length > 1;
        var heads = table.querySelectorAll('thead th.ora-cat-col');
        var sums = [];
        var catYearSums = [];
        var yearSums = years.map(function () { return 0; });
        var i;
        var j;
        for (i = 0; i < catCount; i += 1) {
          sums[i] = 0;
          catYearSums[i] = years.map(function () { return 0; });
        }
        var total = 0;
        var count = 0;
        table.querySelectorAll('tr.ora-sum-row').forEach(function (tr) {
          var cb = tr.querySelector('.ora-cust-pick');
          var on = !!(cb && cb.checked);
          tr.classList.toggle('is-picked', on);
          if (!on) return;
          count += 1;
          total += Number(tr.getAttribute('data-ora-total') || 0) || 0;
          var parts = String(tr.getAttribute('data-ora-cats') || '').split('|');
          for (i = 0; i < catCount; i += 1) {
            sums[i] += Number(parts[i] || 0) || 0;
          }
          if (yearMode) {
            var yParts = String(tr.getAttribute('data-ora-years') || '').split('|');
            for (j = 0; j < years.length; j += 1) {
              yearSums[j] += Number(yParts[j] || 0) || 0;
            }
            var cy = String(tr.getAttribute('data-ora-cat-years') || '').split('|');
            for (i = 0; i < catCount; i += 1) {
              var yp = String(cy[i] || '').split(',');
              for (j = 0; j < years.length; j += 1) {
                catYearSums[i][j] += Number(yp[j] || 0) || 0;
              }
            }
          }
        });
        pickRow.querySelectorAll('.ora-pick-cat').forEach(function (td) {
          var idx = Number(td.getAttribute('data-cat-idx') || 0) || 0;
          if (yearMode) {
            td.innerHTML = count ? oraYearStackHtml(years, catYearSums[idx] || [], false) : '';
          } else {
            td.textContent = count ? fmtOraPickAmt(sums[idx] || 0) : '';
          }
        });
        var totalCell = pickRow.querySelector('.ora-pick-total');
        if (totalCell) {
          if (yearMode) totalCell.innerHTML = count ? oraYearStackHtml(years, yearSums, true) : '';
          else totalCell.textContent = count ? fmtOraPickAmt(total) : '';
        }
        var nEl = pickRow.querySelector('.ora-pick-n');
        if (nEl) nEl.textContent = String(count);
        pickRow.classList.toggle('is-empty', count < 1);
        function fillPickUi(el) {
          if (!el) return;
          var cEl = el.querySelector('.ora-pick-count');
          var aEl = el.querySelector('.ora-pick-amt');
          if (cEl) cEl.textContent = String(count);
          if (aEl) {
            if (yearMode && count) {
              aEl.innerHTML = oraYearStackHtml(years, yearSums, true);
            } else {
              aEl.textContent = fmtOraPickAmt(total);
            }
          }
          el.classList.toggle('is-empty', count < 1);
        }
        fillPickUi(bar);
        fillPickUi(foot);
        if (foot) {
          var catsEl = foot.querySelector('.ora-pick-foot-cats');
          if (catsEl) {
            var bits = [];
            for (i = 0; i < catCount; i += 1) {
              var rawLabel = heads[i] ? String(heads[i].childNodes[0] && heads[i].childNodes[0].textContent || heads[i].textContent || '').trim() : '';
              var label = rawLabel.split(/\s+/)[0] || ('فئة ' + (i + 1));
              if (!label) continue;
              if (yearMode) {
                bits.push('<span>' + label + ': ' + oraYearStackHtml(years, catYearSums[i] || [], false) + '</span>');
              } else {
                bits.push('<span>' + label + ': <b dir="ltr">' + fmtOraPickAmt(sums[i] || 0) + '</b></span>');
              }
            }
            catsEl.innerHTML = count ? bits.join('') : '';
          }
        }
      }
      document.addEventListener('change', function (ev) {
        var cb = ev.target && ev.target.classList && ev.target.classList.contains('ora-cust-pick')
          ? ev.target
          : null;
        if (!cb) return;
        var block = cb.closest('.ora-summary-block');
        refreshOraCustPick(block);
      });
      document.addEventListener('click', function (ev) {
        var clearBtn = ev.target.closest && ev.target.closest('.ora-pick-clear');
        if (clearBtn) {
          var block = clearBtn.closest('.ora-summary-block');
          if (block) {
            block.querySelectorAll('.ora-cust-pick').forEach(function (cb) { cb.checked = false; });
            refreshOraCustPick(block);
          }
          return;
        }
        var excelBtn = ev.target.closest && ev.target.closest('#ora-excel-btn');
        if (excelBtn) {
          var view = document.querySelector('.ora-view:not([hidden])');
          if (!view) return;
          var clone = view.cloneNode(true);
          clone.querySelectorAll('.ora-cust-pick-lab, .ora-pick-bar, .ora-pick-foot, tr.ora-pick-sum').forEach(function (el) {
            el.remove();
          });
          var html = '<html><head><meta charset="UTF-8"></head><body dir="rtl">' + clone.innerHTML + '</body></html>';
          var blob = new Blob(['\\uFEFF' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = 'مبيعات-المندوب-حسب-الفئة.xls';
          document.body.appendChild(a);
          a.click();
          setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 200);
          return;
        }
        var viewBtn = ev.target.closest && ev.target.closest('[data-ora-view]');
        if (viewBtn) {
          var which = viewBtn.getAttribute('data-ora-view') || 'summary';
          if (which === 'invoice-net') which = 'invoice-sum';
          document.querySelectorAll('[data-ora-view]').forEach(function (btn) {
            if (btn.getAttribute('data-ora-view') === which) btn.classList.add('si-btn--primary');
            else btn.classList.remove('si-btn--primary');
          });
          document.querySelectorAll('.ora-view[data-ora-view-panel]').forEach(function (el) {
            var ok = el.getAttribute('data-ora-view-panel') === which;
            if (ok) el.removeAttribute('hidden');
            else el.setAttribute('hidden', '');
          });
          return;
        }
        var head = ev.target.closest && ev.target.closest('[data-ora-toggle]');
        if (!head) return;
        var id = head.getAttribute('data-ora-toggle');
        var open = head.getAttribute('data-open') === '1';
        head.setAttribute('data-open', open ? '0' : '1');
        document.querySelectorAll('[data-ora-lines="' + id + '"]').forEach(function (tr) {
          tr.hidden = open;
        });
      });
      </script>
    </div>`;

  return res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'مبيعات المندوب حسب المادة والفئة Oracle',
      bodyHtml: body,
      css: ['/assets/css/report-sales.css'],
      js: ['/assets/js/sales-print.js', '/assets/js/report-sales-table-sort.js'],
    })
  );
  } catch (e) {
    console.error('oracle-rep-items', e);
    return res.status(500).send(
      ui.salesPage({
        user: req.session && req.session.user,
        title: 'مبيعات المندوب حسب المادة والفئة Oracle',
        bodyHtml: `<div class="si-stage"><div class="alert alert-error">${esc(
          String((e && e.message) || e || 'خطأ داخلي')
        )}</div><p class="muted"><a href="${esc(BASE)}">العودة للتقرير</a></p></div>`,
        css: ['/assets/css/report-sales.css'],
      })
    );
  }
};
router.get('/sales/reports/oracle-rep-items', guard('report_oracle_rep_item_sales'), oracleRepItemsHandler);
router.post('/sales/reports/oracle-rep-items', guard('report_oracle_rep_item_sales'), oracleRepItemsHandler);

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
        <td class="si-num" dir="ltr">${esc(ln.unit_label || (ln.tr_unit ? '1*' + ln.tr_unit : '—'))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.qty))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.bonus))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.sell))}</td>
        <td class="si-num" dir="ltr">${esc(Number(ln.tax_pct || 0))}%</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.line_gross))}</td>
        <td class="si-num" dir="ltr">${esc(fmtAmt(ln.vou_tax))}</td>
        <td class="si-num no-print" dir="ltr">${esc(ln.batch || '—')}</td>
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
                <th>#</th><th>المادة</th><th>البيان</th><th>الفئة</th><th>الوحدة</th>
                <th>الكمية</th><th>بونص</th><th>السعر</th><th>ض%</th><th>الإجمالي</th><th>الضريبة</th>
                <th class="no-print">التشغيلة</th>
              </tr>
            </thead>
            <tbody>${lineRows}</tbody>
            ${
              lines.length
                ? `<tfoot>
                    <tr class="ora-doc-lines__sum">
                      <td colspan="5">مجموع البنود</td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(qtySum))}</td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(bonusSum))}</td>
                      <td></td><td></td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(header.gross))}</td>
                      <td class="si-num" dir="ltr">${esc(fmtAmt(header.tax_sum))}</td>
                      <td class="no-print"></td>
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
              <a class="si-btn no-print" href="/sales/invoices/new">فاتورة مبيعات</a>
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
