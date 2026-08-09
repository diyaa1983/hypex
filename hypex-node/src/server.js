'use strict';

const path = require('path');
const express = require('express');
const cookieParser = require('cookie-parser');
const config = require('./config');
const db = require('./db');
const auth = require('./auth');
const nav = require('./nav');
const dashboard = require('./dashboard');
const { renderApp, phpUrl } = require('./lib/layout');
const { esc, fmtAmt, isoToDmy } = require('./lib/html');
const salesInvoices = require('./sales/invoicesRoutes');
const salesReturns = require('./sales/returnsRoutes');
const customerOrders = require('./sales/customerOrdersRoutes');
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
const { resolveScreen } = require('./lib/screenMap');
const { phpEmbedPage } = require('./lib/layout');
const basePath = require('./lib/basePath');
const fs = require('fs');
const { createSessionMiddleware } = require('./sessionStore');
const { warmPrintBrand } = require('./lib/printBrand');

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
      .map(
        (k) => `<article class="kpi kpi--${esc(k.tone || 'primary')}">
        <span class="kpi-label">${esc(k.label)}</span>
        <span class="kpi-value" dir="ltr">${esc(k.value)}</span>
        ${k.hint ? `<span class="kpi-hint">${esc(k.hint)}</span>` : ''}
      </article>`
      )
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

    const bodyHtml = `
      <header class="topbar">
        <div>
          <h1>لوحة التحكم</h1>
          <p class="muted">نظام Hypex على Node.js · كل الشاشات والتقارير من القائمة الجانبية</p>
        </div>
        <div class="topbar-actions">
          <a class="btn btn-primary" href="/sales/invoices/new">＋ فاتورة مبيعات</a>
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
      <section class="panel panel--hint">
        <h2>مسار الهجرة</h2>
        <ol class="steps">
          <li class="done">قائمة مجالات مطابقة للنظام القديم</li>
          <li class="done">كل الشاشات تُفتح داخل Node (قوائم محلية أو تضمين)</li>
          <li class="done">فاتورة مبيعات (حفظ Node)</li>
          <li>استبدال التضمين بواجهات Node أصلية تدريجياً</li>
        </ol>
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

/** فتح أي شاشة PHP داخل الغلاف — لا يغادر المنفذ 3000 */
const NATIVE_EMBED_REDIRECT = new Set([
  'user_gps_tracker',
  'm_user_gps_tracker',
  'user_gps_locations',
  'm_user_gps_locations',
  'sales_invoice_gps',
  'gps_tracking_settings',
  'einvoice_settings',
  'users',
  'groups',
  'permissions',
  'open_sessions',
  'settings',
  'tax_rates_settings',
  'dashboard_accounts_settings',
  'system_backup',
  // HR — شاشات Node الأصلية
  'hr_employees',
  'hr_employee_attendance',
  'hr_attendance_sync_server',
  'hr_attendance_sync_local',
  'hr_employee_schedule',
  'hr_attendance_settings',
  'hr_departure_types',
  'hr_employee_departures',
  'report_hr_employee_departures',
  'report_hr_employees_by_department',
  'report_hr_employees_by_nationality',
  // محاسبة
  'chart_of_accounts',
  'report_income_statement_comprehensive',
  'cash_receipt',
  'cash_receipts_list',
  'cash_payment',
  'cash_payments_list',
  'account_mapping',
  'fin_employee_advances',
  'journal_voucher',
  'acc_opening_balance',
  'acc_period_close',
  'acc_year_close',
  'report_general_ledger',
  'report_receivables',
  'report_receivables_aging',
  'report_incoming_checks',
  'report_outgoing_checks',
  'report_supplier_payables',
  'report_party_statement',
  'report_oracle_customer_statement',
  'report_account_statement',
  'report_trial_balance',
  'report_trial_balance_detailed',
  'report_income_statement',
  'report_balance_sheet',
  'report_tax_declaration',
  'report_tax_ar3',
  'report_vat_net_payable',
  'report_invoice_tax',
  // مبيعات — فواتير ومرتجعات Node
  'sales_invoices',
  'sales_invoices_list',
  'sales_returns',
  'sales_returns_list',
  'sales_returns_documents_list',
  'report_invoice_tax_purchase',
  'report_vat_return_tax',
  'report_vat_return_tax_purchase',
]);

function embedQueryString(query) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query || {})) {
    if (v == null) continue;
    if (Array.isArray(v)) v.forEach((x) => qs.append(k, String(x)));
    else qs.set(k, String(v));
  }
  const s = qs.toString();
  return s ? `?${s}` : '';
}

app.get('/embed/:code', auth.requireAuth, (req, res) => {
  const code = String(req.params.code || '').trim();
  if (!code) return res.redirect('/app');
  const sc = resolveScreen(code);
  // شاشات محوّلة لـ Node: لا تُفتح كـ PHP iframe
  if (sc && sc.path && NATIVE_EMBED_REDIRECT.has(code)) {
    return res.redirect(sc.path + embedQueryString(req.query));
  }
  if (sc && sc.path && !Object.keys(req.query || {}).length && sc.kind === 'doc') {
    return res.redirect(sc.path);
  }
  let extra = '';
  for (const [k, v] of Object.entries(req.query || {})) {
    extra += `&${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`;
  }
  const back =
    sc && sc.domain
      ? sc.domain === 'main'
        ? '/app'
        : `/hub/${sc.domain}`
      : '/app';
  res.send(
    phpEmbedPage({
      user: req.session.user,
      title: sc?.label || code,
      phpRoute: code,
      extra,
      backHref: back,
    })
  );
});

app.use(salesReturns);
app.use(customerOrders);
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
  <body style="font-family:Segoe UI,Tahoma,sans-serif;padding:2rem;background:#f0f0f0">
  <h1>خطأ</h1><pre>${esc(msg)}</pre>
  <a href="/login">العودة لتسجيل الدخول</a></body></html>`;
}

app.listen(config.port, () => {
  warmPrintBrand().catch(() => {});
  const base = basePath.hasBase() ? basePath.basePath : '';
  const publicUrl = base
    ? `http://localhost${base}`
    : `http://127.0.0.1:${config.port}`;
  console.log(`Hypex Node UI      → ${publicUrl}`);
  console.log(`  (داخلي)          → http://127.0.0.1:${config.port}${base || ''}`);
  console.log(`  APP_BASE_PATH    → ${base || '(root)'}`);
  console.log(`  PHP embeds       → ${config.phpBaseUrl}`);
});
