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

router.get('/sales/reports/by-region', guard('report_sales_by_region'), (req, res) =>
  stdReport(req, res, {
    title: 'تقرير المبيعات حسب المنطقة',
    mark: 'R4',
    subtitle: 'حسب منطقة العميل',
    path: '/sales/reports/by-region',
    phpRoute: 'report_sales_by_region',
    headers: ['المنطقة', 'عدد', 'الإجمالي'],
    colspan: 3,
    fetch: q.reportSalesByRegion,
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
