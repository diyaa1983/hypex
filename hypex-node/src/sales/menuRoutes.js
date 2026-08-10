'use strict';

const express = require('express');
const auth = require('../auth');
const q = require('./domainQueries');
const ui = require('../lib/salesUi');
const { salesCatalog } = require('./catalog');

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

module.exports = router;
