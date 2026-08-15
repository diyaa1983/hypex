'use strict';

const express = require('express');
const auth = require('../auth');
const q = require('./domainQueries');
const ui = require('../lib/salesUi');
const { salesCatalog } = require('./catalog');
const accNative = require('../accounting/nativeService');
const { esc, fmtAmt, isoToDmy } = require('../lib/html');

const router = express.Router();

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
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
      ${ui.tableSurface(title, `${count} صف`, headers, rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user, title, bodyHtml: body }));
}

function reportPage(res, user, opts) {
  const { title, mark, subtitle, headers, rowsHtml, count, path, from, to, phpRoute } = opts;
  const actions = [
    { label: '🖨 طباعة', primary: true, print: true },
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
    mark: 'PO',
    subtitle: 'قائمة الطلبات — فتح وتعديل داخل Node',
    headers: ['الرقم', 'التاريخ', 'العميل', 'الحالة', 'الإجمالي', ''],
    rowsHtml,
    count: rows.length,
    searchPath: '/sales/orders',
    qVal: qv,
    phpRoute: 'sales_customer_orders',
    extraActions: [{ label: 'طلب جديد', href: '/sales/orders/new', primary: true }],
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
          { label: '🖨 طباعة', primary: true, print: true },
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
          { label: '🖨 طباعة', primary: true, print: true },
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
            { label: '🖨 طباعة', primary: true, print: true },
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
    const discLabel = discPct > 0 ? ` (${(discPct * 100).toFixed(2).replace(/\.?0+$/, '')}%)` : '';

    const stMeta =
      'width:100%;border-collapse:collapse;table-layout:auto;margin:0 0 .7rem;' +
      'font-size:.86rem;line-height:1.4;border:1px solid #cfd7e3';
    const stLab =
      'border:1px solid #dfe4ec;background:#eef1f6;padding:.3rem .6rem;text-align:right;' +
      'white-space:nowrap;color:#43506b;font-weight:600;width:1px';
    const stVal =
      'border:1px solid #dfe4ec;padding:.3rem .6rem;text-align:right;font-weight:700;' +
      'white-space:nowrap;width:1px';
    const stValWide = 'border:1px solid #dfe4ec;padding:.3rem .6rem;text-align:right;font-weight:700';
    const stTot =
      'border-collapse:collapse;font-size:.88rem;margin-top:.7rem;border:1px solid #cfd7e3';
    const stTLab =
      'border:1px solid #dfe4ec;background:#eef1f6;padding:.3rem .8rem;text-align:right;' +
      'white-space:nowrap;font-weight:600;color:#43506b';
    const stTVal =
      'border:1px solid #dfe4ec;padding:.3rem .8rem;text-align:left;font-weight:700;' +
      'min-width:8rem;font-variant-numeric:tabular-nums';
    const stTLabG = stTLab + ';background:#e3e9fb;font-weight:800;font-size:.95rem;color:#1f2a44';
    const stTValG = stTVal + ';background:#e3e9fb;font-weight:800;font-size:.95rem';

    const salesmanTxt =
      header.salesman_no || header.salesman_name
        ? `${esc(header.salesman_no || '')}${
            header.salesman_name ? ' — ' + esc(header.salesman_name) : ''
          }`
        : '—';

    const totalsRows = [
      ['مجموع الفاتورة', fmtAmt(header.gross), false],
      [`الخصم${discLabel}`, fmtAmt(header.vou_disc), false],
      ['الصافي قبل الضريبة', fmtAmt(header.net), false],
      ['قيمة الضريبة', fmtAmt(header.tax_sum), false],
      ['الإجمالي النهائي', fmtAmt(header.total), true],
    ]
      .map(
        ([lab, val, grand]) => `<tr>
          <td style="${grand ? stTLabG : stTLab}">${esc(lab)}</td>
          <td style="${grand ? stTValG : stTVal}" dir="ltr">${esc(val)}</td></tr>`
      )
      .join('');

    result = `
      <div class="si-print-area">
        <table style="${stMeta}">
          <tr>
            <td style="${stLab}">رقم الفاتورة</td>
            <td style="${stVal}" dir="ltr">${esc(header.v_num)} / ${esc(header.vyear)}</td>
            <td style="${stLab}">التاريخ</td>
            <td style="${stVal}">${esc(isoToDmy(header.vdate))}</td>
            <td style="${stLab}">المستودع</td>
            <td style="${stValWide}" dir="ltr">${esc(header.store)}</td>
          </tr>
          <tr>
            <td style="${stLab}">رقم العميل</td>
            <td style="${stVal}" dir="ltr">${esc(header.cust_acc || '—')}</td>
            <td style="${stLab}">اسم العميل</td>
            <td style="${stValWide}" colspan="3">${esc(header.customer_name || '—')}</td>
          </tr>
          <tr>
            <td style="${stLab}">البائع</td>
            <td style="${stValWide}" colspan="5">${salesmanTxt}</td>
          </tr>
        </table>
        ${ui.tableSurface(
          'بنود الفاتورة',
          `${lines.length} بند`,
          ['#', 'المادة', 'البيان', 'الفئة', 'التشغيلة', 'الوحدة', 'الكمية', 'بونص', 'السعر', 'ض%', 'الإجمالي', 'الضريبة'],
          lineRows +
            (lines.length
              ? `<tr>
                  <td colspan="6" style="background:#f4f6fa;font-weight:800">مجموع البنود</td>
                  <td class="si-num" dir="ltr" style="background:#f4f6fa;font-weight:800">${esc(
                    fmtAmt(qtySum)
                  )}</td>
                  <td class="si-num" dir="ltr" style="background:#f4f6fa;font-weight:800">${esc(
                    fmtAmt(bonusSum)
                  )}</td>
                  <td style="background:#f4f6fa"></td><td style="background:#f4f6fa"></td>
                  <td class="si-num" dir="ltr" style="background:#f4f6fa;font-weight:800">${esc(
                    fmtAmt(header.gross)
                  )}</td>
                  <td class="si-num" dir="ltr" style="background:#f4f6fa;font-weight:800">${esc(
                    fmtAmt(header.tax_sum)
                  )}</td>
                </tr>`
              : '')
        )}
        <table style="${stTot}">${totalsRows}</table>
      </div>`;
  } else {
    result = `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(
      data.message || 'لا توجد فواتير بيع في Oracle'
    )}</p>`;
  }

  const filters = `
    <form class="si-search no-print ora-nav" method="get" action="${BASE}" id="ora-inv-nav-form">
      <input type="hidden" name="run" value="1">
      <div class="ora-nav__group">
        <span class="ora-nav__lab">رقم الفاتورة</span>
        <div class="ora-nav__row" dir="ltr">
          ${btn(hrefNav('first'), '«', 'أول فاتورة', false)}
          ${btn(nav.prev ? hrefKey(nav.prev) : hrefNav('prev'), '‹', 'السابق', !nav.prev && !!header)}
          <input class="si-field si-field--mono ora-nav__no" type="text" name="invoice_no"
                 id="ora_inv_no" value="${invoiceNo || ''}"
                 inputmode="numeric" dir="ltr" placeholder="رقم" autocomplete="off"
                 title="اكتب الرقم ثم Enter · الأسهم للتقليب">
          ${btn(nav.next ? hrefKey(nav.next) : hrefNav('next'), '›', 'التالي', !nav.next && !!header)}
          ${btn(hrefNav('last'), '»', 'آخر فاتورة', false)}
        </div>
      </div>
      <div class="ora-nav__group">
        <span class="ora-nav__lab">السنة</span>
        <input class="si-field si-field--mono ora-nav__year" type="text" name="year" id="ora_inv_year"
               value="${year || ''}"
               inputmode="numeric" dir="ltr" placeholder="كل السنوات" autocomplete="off">
      </div>
      <div class="ora-nav__group">
        <span class="ora-nav__lab">&nbsp;</span>
        <button class="si-btn si-btn--primary" type="submit">عرض الفاتورة</button>
      </div>
      <p class="ora-nav__hint">Enter عرض · ← سابق · → تالي · Home أول · End آخر</p>
      <style>
        .ora-nav{display:flex;flex-wrap:wrap;gap:.6rem .9rem;align-items:flex-end}
        .ora-nav__group{display:flex;flex-direction:column;gap:.25rem}
        .ora-nav__lab{font-size:.78rem;font-weight:700;color:#5c6578}
        .ora-nav__row{display:flex;align-items:center;gap:.2rem}
        .ora-nav__no{width:8rem;text-align:center;font-weight:800;font-size:1rem}
        .ora-nav__year{width:7rem;text-align:center}
        .ora-nav .si-docno-btn{min-width:2rem;padding:.3rem .45rem;font-weight:800;line-height:1}
        .ora-nav__hint{flex:1 1 100%;margin:0;font-size:.76rem;color:#7b8494}
      </style>
    </form>
    <script>
    (function(){
      var form=document.getElementById('ora-inv-nav-form');
      var noEl=document.getElementById('ora_inv_no');
      var yearEl=document.getElementById('ora_inv_year');
      if(!form||!noEl) return;
      var base='${BASE}?run=1';
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

  const subtitle = header
    ? `فاتورة ${invoiceNo} / ${year} · تقليب فواتير النظام القديم`
    : 'شاشة تقليب فواتير النظام القديم · MAS.DAILY · TYPE=9';

  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'فاتورة بيع Oracle',
      activePath: BASE,
      css: ['/assets/css/acc-reports-node.css'],
      js: ['/assets/js/sales-print.js'],
      bodyHtml: `
        <div class="si-stage si-report-page">
          ${ui.hero({
            mark: '🧾',
            kicker: 'Hypex Sales · Node',
            title: 'فاتورة بيع Oracle',
            subtitle,
            actions: [
              { label: '🖨 طباعة', primary: true, print: true },
              { label: 'فاتورة مبيعات', href: '/sales/invoices' },
              { label: 'لوحة المبيعات', href: '/sales' },
            ],
          })}
          <div class="si-rail no-print">${filters}</div>
          ${result}
        </div>`,
    })
  );
});

module.exports = router;
