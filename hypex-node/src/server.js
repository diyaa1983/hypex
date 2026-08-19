'use strict';

const path = require('path');
const express = require('express');
const cookieParser = require('cookie-parser');
const config = require('./config');
const db = require('./db');
const auth = require('./auth');
const nav = require('./nav');
const dashboard = require('./dashboard');
const { renderApp, faviconLinksHtml } = require('./lib/layout');
const { esc, fmtAmt, isoToDmy } = require('./lib/html');
const { resolveNativeEmbedTarget } = require('./lib/nativeEmbed');
const { resolveScreen } = require('./lib/screenMap');
const salesInvoices = require('./sales/invoicesRoutes');
const salesReturns = require('./sales/returnsRoutes');
const customerOrders = require('./sales/customerOrdersRoutes');
const orderReturns = require('./sales/orderReturnsRoutes');
const salesOffers = require('./sales/offersRoutes');
const salesDelivery = require('./sales/deliveryRoutes');
const purchasesDocs = require('./purchases/docsRoutes');
const purchasesReturns = require('./purchases/returnsRoutes');
const salesMenu = require('./sales/menuRoutes');
const purchasesMenu = require('./purchases/menuRoutes');
const customersMenu = require('./customers/menuRoutes');
const salesRepsMenu = require('./sales-reps/menuRoutes');
const accountingMenu = require('./accounting/menuRoutes');
const accountingChart = require('./accounting/chartRoutes');
const accountingPl = require('./accounting/plRoutes');
const accountingReceipt = require('./accounting/receiptRoutes');
const accountingPayment = require('./accounting/paymentRoutes');
const accountingAccountMapping = require('./accounting/accountMappingRoutes');
const accountingAdvances = require('./accounting/advancesRoutes');
const accountingJournalVoucher = require('./accounting/journalVoucherRoutes');
const accountingNativeReports = require('./accounting/nativeReportsRoutes');
const accountingTaxReports = require('./accounting/taxReportsRoutes');
const inventoryMenu = require('./inventory/menuRoutes');
const inventoryMasters = require('./inventory/mastersRoutes');
const inventoryPriceAdjust = require('./inventory/priceAdjustRoutes');
const hrMenu = require('./hr/menuRoutes');
const hrEmployees = require('./hr/employeesRoutes');
const hrAttendance = require('./hr/attendanceRoutes');
const hrSchedule = require('./hr/scheduleRoutes');
const hrShiftSettings = require('./hr/shiftSettingsRoutes');
const hrDepartureTypes = require('./hr/departureTypesRoutes');
const hrDepartures = require('./hr/departuresRoutes');
const systemMenu = require('./system/menuRoutes');
const systemUsers = require('./system/usersRoutes');
const systemAdmin = require('./system/adminRoutes');
const systemGps = require('./system/gpsRoutes');
const suppliersMenu = require('./suppliers/menuRoutes');
const mobileMenu = require('./mobile/menuRoutes');
const mainMenu = require('./main/menuRoutes');
const menuAll = require('./menuAllRoutes');
const hubRoutes = require('./hubRoutes');
const notifications = require('./notifications/routes');
const basePath = require('./lib/basePath');
const fs = require('fs');
const { createSessionMiddleware } = require('./sessionStore');
const { warmPrintBrand, ensurePrintBrand, getPrintBrand } = require('./lib/printBrand');

const app = express();
const phpRoot = path.join(__dirname, '..', '..');
const nodePublic = path.join(__dirname, '..', 'public');

app.set('trust proxy', 1);

// توحيد المسار /hypex قبل كل شيء
app.use(basePath.middleware());

app.use(cookieParser());
app.use(express.urlencoded({ extended: false }));
app.use(express.json({ limit: '2mb' }));
// جلسة في MySQL — لا تُفقد عند إيقاف/تشغيل Node
app.use(createSessionMiddleware());

/** تحميل اسم الشركة/الشعار + خانات الأرقام من الإعدادات */
app.use(async (req, res, next) => {
  try {
    if (req.session && req.session.user) {
      await ensurePrintBrand();
      try {
        const companyDecimals = require('./lib/companyDecimals');
        await companyDecimals.load();
      } catch {
        /* */
      }
    }
  } catch {
    /* ignore */
  }
  next();
});

/** بيانات الترويسة للطباعة — دائماً من sys_company_settings */
app.get('/api/print-brand', async (req, res) => {
  if (!req.session || !req.session.user) {
    return res.status(401).json({ ok: false, error: 'auth' });
  }
  try {
    const brand = await ensurePrintBrand(true);
    res.setHeader('Cache-Control', 'no-store');
    res.json({
      ok: true,
      companyName: brand.companyName || 'Hypex',
      logoUrl: brand.logoUrl || '',
      watermarkEnabled: brand.watermarkEnabled !== false,
    });
  } catch (e) {
    const b = getPrintBrand();
    res.json({
      ok: true,
      companyName: b.companyName || 'Hypex',
      logoUrl: b.logoUrl || '',
      watermarkEnabled: b.watermarkEnabled !== false,
    });
  }
});

/** بحث عملاء/مواد — اختصارات F7 / F3 (أي مستخدم مسجّل) */
app.get('/api/lookup/customers', auth.requireAuth, async (req, res) => {
  try {
    const inv = require('./sales/invoicesService');
    const rows = await inv.searchCustomers(String(req.query.q || ''), 50);
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'خطأ' });
  }
});

app.get('/api/lookup/items', auth.requireAuth, async (req, res) => {
  try {
    const inv = require('./sales/invoicesService');
    const rows = await inv.searchItems(String(req.query.q || ''), 50);
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'خطأ' });
  }
});

app.get('/api/lookup/suppliers', auth.requireAuth, async (req, res) => {
  try {
    const docs = require('./purchases/docsService');
    const rows = await docs.searchSuppliers(String(req.query.q || ''), 50);
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'خطأ' });
  }
});

/** أيقونة التبويب — شعار الشركة بدل افتراضي XAMPP */
app.get(['/favicon.ico', '/favicon.png', '/favicon'], async (req, res) => {
  try {
    await ensurePrintBrand(true);
  } catch {
    /* ignore */
  }
  const brand = getPrintBrand();
  let url = brand.logoUrl || basePath.ensurePrefixed('/assets/favicon.svg');
  // لطلبات مباشرة من جذر المنفذ أعد توجيه نسبي
  if (url && url.startsWith('http')) return res.redirect(302, url);
  return res.redirect(302, url || basePath.ensurePrefixed('/assets/favicon.svg'));
});

/** تقديم أصول Node مع إعادة كتابة مسارات JS تحت /hypex */
function sendPublicFile(req, res, next) {
  const rel = decodeURIComponent(req.path || '').replace(/^\/+/, '');
  if (!rel || rel.includes('..')) return next();
  const file = path.join(nodePublic, rel);
  if (!file.startsWith(nodePublic) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
    return next();
  }
  if (/\.js$/i.test(file) && basePath.hasBase()) {
    try {
      const code = fs.readFileSync(file, 'utf8');
      res.type('application/javascript');
      return res.send(basePath.rewriteJs(code));
    } catch (e) {
      return next(e);
    }
  }
  return res.sendFile(file);
}

app.use('/assets', sendPublicFile);
app.use('/assets', express.static(path.join(phpRoot, 'assets')));
app.use('/vendor', express.static(path.join(phpRoot, 'assets', 'vendor')));
app.use('/uploads', express.static(path.join(phpRoot, 'uploads')));
// توافق مع روابط قديمة /hypex/assets بعد strip لا تُستخدم — تُخدَم أعلاه
app.use('/hypex/assets', express.static(path.join(phpRoot, 'assets')));
app.use('/hypex/vendor', express.static(path.join(phpRoot, 'assets', 'vendor')));
app.use('/hypex/uploads', express.static(path.join(phpRoot, 'uploads')));

app.get('/health', async (_req, res) => {
  try {
    const ok = await db.ping();
    res.json({ ok, service: 'hypex-node', db: ok ? 'up' : 'down' });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

app.get('/', (req, res) => {
  if (req.session.user) return res.redirect('/app');
  return res.redirect('/login');
});

app.get('/login', async (req, res) => {
  if (req.session.user) return res.redirect('/app');
  const brand = await getLoginBrand();
  res.send(renderLogin({ error: '', username: '', brand }));
});

app.post('/login', async (req, res) => {
  const username = String(req.body.username || '');
  const password = String(req.body.password || '');
  const brand = await getLoginBrand();
  try {
    const result = await auth.attemptLogin(username, password);
    if (!result.ok) {
      return res.status(401).send(renderLogin({ error: result.error, username, brand }));
    }
    req.session.regenerate((err) => {
      if (err) console.error('session regenerate', err);
      req.session.user = result.user;
      res.redirect('/app');
    });
  } catch (e) {
    console.error('login error', e);
    res.status(500).send(
      renderLogin({
        error: 'تعذر الاتصال بقاعدة البيانات. تحقق من .env و MySQL.',
        username,
        brand,
      })
    );
  }
});

app.post('/logout', (req, res) => {
  req.session.destroy(() => {
    res.redirect('/login');
  });
});

app.get('/app', auth.requireAuth, async (req, res) => {
  try {
    const user = req.session.user;
    const dash = await dashboard.collectDashboard();
    const kpis = (dash.kpis || [])
      .map((k) => {
        const inner = `
        <span class="kpi-label">${esc(k.label)}</span>
        <span class="kpi-value" dir="ltr">${esc(k.value)}</span>
        ${k.hint ? `<span class="kpi-hint">${esc(k.hint)}</span>` : ''}`;
        if (k.href) {
          return `<a class="kpi kpi--${esc(k.tone || 'primary')}" href="${esc(k.href)}" style="text-decoration:none;color:inherit">${inner}</a>`;
        }
        return `<article class="kpi kpi--${esc(k.tone || 'primary')}">${inner}</article>`;
      })
      .join('');
    const rows = (dash.recent_sales || [])
      .map(
        (r) => `<tr>
        <td dir="ltr"><a href="/sales/invoices/${r.id}">${esc(r.invoice_no)}</a></td>
        <td dir="ltr">${esc(isoToDmy(r.invoice_date))}</td>
        <td>${esc(r.customer_name)}</td>
        <td dir="ltr">${esc(r.total)}</td>
      </tr>`
      )
      .join('');

    const orderRow = (r) => `<tr>
        <td dir="ltr"><a href="/sales/orders/${r.id}">${esc(r.order_no)}</a></td>
        <td dir="ltr">${esc(isoToDmy(r.order_date))}</td>
        <td>${esc(r.customer_name)}</td>
        <td>${esc(r.status_label || '')}</td>
        <td dir="ltr">${esc(r.total)}</td>
      </tr>`;
    const openOrderRows = (dash.open_orders || []).map(orderRow).join('');
    const approvedOrderRows = (dash.approved_orders || []).map(orderRow).join('');
    const recentOrderRows = (dash.recent_orders || []).map(orderRow).join('');

    const bodyHtml = `
      <header class="topbar">
        <div>
          <h1>لوحة التحكم</h1>
          <p class="muted">نظام Hypex — كل الشاشات والتقارير من القائمة الجانبية</p>
        </div>
        <div class="topbar-actions">
          <a class="btn btn-primary" href="/sales/invoices/new">＋ فاتورة مبيعات</a>
          <a class="btn" href="/sales/orders/new">＋ طلب شراء عميل</a>
          <a class="btn" href="/hub/sales">المبيعات</a>
          <a class="btn" href="/hub/purchases">المشتريات</a>
          <a class="btn" href="/hub/customers">العملاء</a>
          <a class="btn" href="/hub/accounting">المحاسبة</a>
        </div>
      </header>
      <section class="kpi-grid">${kpis}</section>
      <section class="panel">
        <div class="panel-head">
          <h2>آخر فواتير المبيعات</h2>
          <a href="/sales/invoices">عرض الكل</a>
        </div>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr><th>رقم</th><th>التاريخ</th><th>العميل</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
              ${rows || '<tr><td colspan="4" class="empty">لا توجد فواتير بعد</td></tr>'}
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head">
          <h2>طلبات شراء العملاء</h2>
          <a href="/sales/orders">عرض الكل</a>
        </div>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr><th>رقم</th><th>التاريخ</th><th>العميل</th><th>الحالة</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
              ${
                recentOrderRows ||
                '<tr><td colspan="5" class="empty">لا توجد طلبات بعد</td></tr>'
              }
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head">
          <h2>الطلبات المفتوحة</h2>
          <a href="/sales/orders/approve">عرض الكل</a>
        </div>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr><th>رقم</th><th>التاريخ</th><th>العميل</th><th>الحالة</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
              ${
                openOrderRows ||
                '<tr><td colspan="5" class="empty">لا توجد طلبات مفتوحة</td></tr>'
              }
            </tbody>
          </table>
        </div>
      </section>
      <section class="panel">
        <div class="panel-head">
          <h2>الطلبات المعتمدة</h2>
          <a href="/sales/orders/approved">عرض الكل</a>
        </div>
        <div class="table-wrap">
          <table class="grid">
            <thead>
              <tr><th>رقم</th><th>التاريخ</th><th>العميل</th><th>الحالة</th><th>الإجمالي</th></tr>
            </thead>
            <tbody>
              ${
                approvedOrderRows ||
                '<tr><td colspan="5" class="empty">لا توجد طلبات معتمدة</td></tr>'
              }
            </tbody>
          </table>
        </div>
      </section>
    `;

    res.send(renderApp({ user, title: 'لوحة التحكم', bodyHtml }));
  } catch (e) {
    console.error('app error', e);
    res.status(500).send(renderError(e.message));
  }
});

app.get('/api/me', auth.requireAuth, (req, res) => {
  res.json({ ok: true, user: req.session.user });
});

app.use(notifications.router);

app.get('/api/dashboard', auth.requireAuth, async (req, res) => {
  try {
    const data = await dashboard.collectDashboard();
    res.json({ ok: true, data });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

app.get('/api/nav', auth.requireAuth, (req, res) => {
  const menu = nav.filterNav(req.session.user, auth.userCan);
  res.json({ ok: true, menu, php_base: config.phpBaseUrl });
});

app.use(hubRoutes);

/** فتح أي شاشة عبر رمزها — Node فقط (بدون iframe PHP) */
app.get('/embed/:code', auth.requireAuth, (req, res) => {
  const code = String(req.params.code || '').trim();
  if (!code) return res.redirect('/app');

  const target = resolveNativeEmbedTarget(code, req.query || {});
  if (target.ok) {
    return res.redirect(target.url);
  }

  const sc = resolveScreen(code);
  const back =
    sc && sc.domain
      ? sc.domain === 'main'
        ? '/app'
        : `/hub/${sc.domain}`
      : '/app';
  const title = sc?.label || code;
  const bodyHtml = `
    <div class="si-stage">
      <header class="si-hero">
        <div class="si-brand-lockup">
          <div class="si-brand-text">
            <h1>${esc(title)}</h1>
          </div>
        </div>
        <div class="si-hero-actions">
          <a class="si-btn" href="${esc(back)}">القسم</a>
          <a class="si-btn si-btn--primary" href="/app">لوحة التحكم</a>
        </div>
      </header>
      <section class="si-surface" style="padding:1.25rem 1.35rem">
        <h2 style="margin:0 0 .5rem">هذه الشاشة غير متاحة بعد</h2>
        <p class="muted" style="margin:0;line-height:1.55;font-size:.92rem">
          تم إيقاف فتح شاشات PHP القديمة من النظام.
          الشاشة <code dir="ltr">${esc(code)}</code> لم تُربط بعد —
          استخدم القوائم من لوحة التحكم، أو راجع مدير النظام لإكمال التحويل.
        </p>
      </section>
    </div>`;
  res.send(
    renderApp({
      user: req.session.user,
      title,
      bodyHtml,
      bodyClass: 'si-2027',
      mainClass: 'main si-main',
      css: ['/assets/css/sales-2027.css'],
      printChrome: false,
    })
  );
});

app.use(salesReturns);
app.use(customerOrders);
app.use(orderReturns);
app.use(salesOffers);
app.use(salesDelivery);
app.use(purchasesDocs);
app.use(purchasesReturns);
app.use(salesMenu);
app.use(salesInvoices);
app.use(purchasesMenu);
app.use(customersMenu);
app.use(salesRepsMenu);
app.use(accountingChart);
app.use(accountingPl);
app.use(accountingReceipt);
app.use(accountingPayment);
app.use(accountingAccountMapping);
app.use(accountingAdvances);
app.use(accountingJournalVoucher);
app.use(accountingNativeReports);
app.use(accountingTaxReports);
app.use(accountingMenu);
app.use(inventoryMasters);
app.use(inventoryPriceAdjust);
app.use(inventoryMenu);
app.use(hrEmployees);
app.use(hrAttendance);
app.use(hrSchedule);
app.use(hrShiftSettings);
app.use(hrDepartureTypes);
app.use(hrDepartures);
app.use(hrMenu);
app.use(systemUsers);
app.use(systemAdmin);
app.use(systemGps);
app.use(systemMenu);
app.use(suppliersMenu);
app.use(mobileMenu);
app.use(mainMenu);

// لم تعد «جميع الشاشات» موجودة
app.get('/menu', auth.requireAuth, (_req, res) => res.redirect('/app'));
// app.use(menuAll);

async function getLoginBrand() {
  try {
    const rows = await db.query(
      `SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1`
    );
    const r = rows[0] || {};
    let companyName = String(r.company_name_ar || '').trim();
    if (!companyName || companyName === 'اسم الشركة') companyName = 'Hypex';
    let logoUrl = '';
    const lp = String(r.logo_path || '').trim().replace(/\\/g, '/').replace(/^\/+/, '');
    if (lp) {
      if (/^https?:\/\//i.test(lp)) logoUrl = lp;
      else if (lp.startsWith('uploads/')) logoUrl = '/uploads/' + lp.slice('uploads/'.length);
      else if (lp.startsWith('hypex/')) logoUrl = '/' + lp.replace(/^hypex\//, '');
      else logoUrl = '/uploads/' + lp;
    }
    return { companyName, logoUrl };
  } catch {
    return { companyName: 'Hypex', logoUrl: '' };
  }
}

function renderLogin({ error, username, brand }) {
  const companyName = (brand && brand.companyName) || 'Hypex';
  const logoUrl = (brand && brand.logoUrl) || '';
  const forgotUrl = config.phpBaseUrl + '/forgot_password.php';
  const brandMark = logoUrl
    ? `<img class="login-hero__logo" src="${esc(logoUrl)}" alt="">`
    : `<span class="login-hero__mark" aria-hidden="true">ERP</span>`;
  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>تسجيل الدخول · ${esc(companyName)}</title>
  ${(() => {
    const h = logoUrl || '';
    if (h) {
      return (
        `<link rel="icon" href="${esc(h)}">\n` +
        `<link rel="shortcut icon" href="${esc(h)}">\n` +
        `<link rel="apple-touch-icon" href="${esc(h)}">`
      );
    }
    try {
      return faviconLinksHtml();
    } catch {
      return '<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">';
    }
  })()}
  <script>window.__HYPEX_BASE__=${JSON.stringify(basePath.basePath || '')};</script>
  <script src="/assets/js/base-path.js"></script>
  <link rel="stylesheet" href="/assets/css/app-font.css">
  <link rel="stylesheet" href="/assets/css/login-pro.css">
  <link rel="stylesheet" href="/assets/css/app-pwa-install.css">
  <style>
    .login-panel__pwa .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.35rem;
      padding: 0.4rem 0.75rem;
      border-radius: 8px;
      border: 1px solid #cbd5e1;
      background: #fff;
      color: #1e293b;
      font: inherit;
      font-size: 0.78rem;
      font-weight: 650;
      cursor: pointer;
      white-space: nowrap;
    }
    .login-panel__pwa .btn:hover { background: #f8fafc; border-color: #94a3b8; }
  </style>
</head>
<body class="login-body login-body--pro">
<div class="login-shell">
  <aside class="login-hero" aria-hidden="false">
    <div class="login-hero__glow" aria-hidden="true"></div>
    <div class="login-hero__content">
      <div class="login-hero__brand">
        ${brandMark}
        <h1 class="login-hero__title">${esc(companyName)}</h1>
      </div>
      <p class="login-hero__tagline">منصة أعمال متكاملة لإدارة العمليات والمالية والمخزون والموارد البشرية.</p>
      <ul class="login-hero__points">
        <li>لوحة مؤشرات فورية</li>
        <li>محاسبة ومبيعات ومشتريات</li>
        <li>صلاحيات آمنة ومتعددة المستخدمين</li>
      </ul>
    </div>
  </aside>

  <main class="login-panel">
    <div class="login-panel__card">
      <header class="login-panel__header">
        <p class="login-panel__eyebrow">مرحباً بعودتك</p>
        <h2 class="login-panel__title">تسجيل الدخول</h2>
        <p class="login-panel__sub">أدخل بياناتك للوصول إلى النظام</p>
      </header>

      ${error ? `<div class="login-alert" role="alert">${esc(error)}</div>` : ''}

      <form method="post" action="/login" class="login-form" autocomplete="on">
        <label class="login-field">
          <span class="login-field__label">اسم المستخدم</span>
          <input class="login-field__input" name="username" value="${esc(username || '')}" autocomplete="username" required autofocus>
        </label>
        <label class="login-field">
          <span class="login-field__label">كلمة المرور</span>
          <input class="login-field__input" name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="login-submit" type="submit">دخول</button>
      </form>

      <p class="login-panel__forgot">
        <a href="${esc(forgotUrl)}">نسيت كلمة المرور؟</a>
      </p>

      <div class="login-panel__pwa">
        <div id="app-pwa-install" class="app-pwa-install">
          <p class="app-pwa-install__text">ثبّت النظام كتطبيق على سطح المكتب — أيقونة مستقلة بدون متصفح.</p>
          <button type="button" class="btn btn-secondary btn-sm" id="app-pwa-install-btn">تثبيت التطبيق</button>
          <button type="button" class="app-pwa-install__dismiss" id="app-pwa-install-dismiss" aria-label="إخفاء">×</button>
        </div>
      </div>
    </div>
    <p class="login-panel__foot">${esc(companyName)}</p>
  </main>
</div>
<script src="/assets/js/app-pwa-install.js"></script>
</body>
</html>`;
}

function renderError(msg) {
  return `<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8">
  <body style="font-family:Arial, Helvetica, sans-serif;padding:2rem;background:#f0f0f0">
  <h1>خطأ</h1><pre>${esc(msg)}</pre>
  <a href="/login">العودة لتسجيل الدخول</a></body></html>`;
}

app.listen(config.port, () => {
  warmPrintBrand().catch(() => {});
  try {
    const companyDecimals = require('./lib/companyDecimals');
    companyDecimals.load(true).catch(() => {});
  } catch {
    /* */
  }
  const base = basePath.hasBase() ? basePath.basePath : '';
  const publicUrl = base
    ? `http://localhost${base}`
    : `http://127.0.0.1:${config.port}`;
  console.log(`Hypex Node UI      → ${publicUrl}`);
  console.log(`  (داخلي)          → http://127.0.0.1:${config.port}${base || ''}`);
  console.log(`  APP_BASE_PATH    → ${base || '(root)'}`);
  console.log(`  PHP embeds       → ${config.phpBaseUrl}`);
});
