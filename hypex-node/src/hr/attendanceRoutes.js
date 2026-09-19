'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const svc = require('./attendanceService');

const router = express.Router();
const KICKER = 'Hypex HR · Node';
const HUB = '/hr';
const BASE = '/hr/attendance';
const PERM = 'hr_employee_attendance';

function can(user) {
  return user.is_admin || auth.userCan(user, PERM);
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (p !== BASE && !p.startsWith(BASE + '/')) return next('router');
  return auth.requireAuth(req, res, next);
});

function flashHtml(req) {
  const msg = String(req.query.msg || '');
  const err = String(req.query.err || '');
  return (
    (msg
      ? `<p class="si-pill si-pill--ok" style="display:inline-block;margin:.25rem 0">${esc(msg)}</p>`
      : '') +
    (err
      ? `<p class="si-pill si-pill--lock" style="display:inline-block;margin:.25rem 0">${esc(err)}</p>`
      : '')
  );
}

function backQs(from, to, empId) {
  const q = new URLSearchParams();
  if (from) q.set('from', from);
  if (to) q.set('to', to);
  if (empId) q.set('employee_id', String(empId));
  const s = q.toString();
  return s ? '?' + s : '';
}

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);

  const fromQ = String(req.query.from || req.query.date_from || '');
  const toQ = String(req.query.to || req.query.date_to || '');
  const empId = Number(req.query.employee_id || 0) || 0;

  const [config, totalPunches, employees, punchData, mapped, unmapped, linkEmps] =
    await Promise.all([
      svc.getConfig(),
      svc.countPunches(),
      svc.listEmployeesActive(),
      svc.listPunches({ from: fromQ, to: toQ, employeeId: empId }),
      svc.listMapped(),
      svc.listUnmappedZk(),
      svc.listAvailableEmployeesForLink(),
    ]);

  const { from, to, rows: punches } = punchData;
  const qs = backQs(from, to, empId);

  const empOpts = employees
    .map(
      (e) =>
        `<option value="${e.id}" ${empId === Number(e.id) ? 'selected' : ''}>${esc(
          (e.emp_code ? e.emp_code + ' — ' : '') + (e.name_ar || '')
        )}</option>`
    )
    .join('');

  const mappedHtml =
    mapped.length === 0
      ? '<p class="att-empty">لا موظفون مربوطون بالبصمة بعد.</p>'
      : `<div class="si-table-wrap"><table class="si-table">
        <thead><tr>
          <th>رقم الموظف</th><th>اسم الموظف</th><th>رقم البصمة</th>
          <th>الاسم في الجهاز</th><th>آخر بصمة</th><th>عدد السجلات</th><th></th>
        </tr></thead>
        <tbody>${mapped
          .map((mp) => {
            const match = svc.badgeMatchesCode(mp.badge_number, mp.emp_code);
            return `<tr class="${match ? '' : 'att-mismatch'}">
            <td class="si-num" dir="ltr">${esc(mp.emp_code || '—')}</td>
            <td>${esc(mp.emp_name || '—')}</td>
            <td class="si-num" dir="ltr">${esc(mp.badge_number || '—')}</td>
            <td>${esc(mp.zk_name || '—')}</td>
            <td class="si-num" dir="ltr">${esc(mp.last_punch || '—')}</td>
            <td class="si-num" dir="ltr">${Number(mp.punch_count || 0)}</td>
            <td>
              <form method="post" action="${BASE}/unmap" style="display:inline"
                    onsubmit="return confirm('إلغاء ربط هذا الموظف برقم البصمة؟');">
                <input type="hidden" name="zk_user_id" value="${Number(mp.zk_user_id)}">
                <input type="hidden" name="from" value="${esc(from)}">
                <input type="hidden" name="to" value="${esc(to)}">
                <input type="hidden" name="employee_id" value="${empId}">
                <button class="si-btn" type="submit" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem">إلغاء الربط</button>
              </form>
            </td>
          </tr>`;
          })
          .join('')}</tbody></table></div>`;

  const empLinkOpts = linkEmps
    .map(
      (e) =>
        `<option value="${e.id}">${esc(
          (e.emp_code ? e.emp_code + ' — ' : '') + (e.name_ar || '')
        )}</option>`
    )
    .join('');
  const zkLinkOpts = unmapped
    .map((z) => {
      const badge = z.badge_number || '';
      const name = z.zk_name || '';
      const label =
        badge && name
          ? badge + ' — ' + name
          : badge || 'ZK #' + z.zk_user_id;
      return `<option value="${Number(z.zk_user_id)}">${esc(label)} (${Number(
        z.punch_count || 0
      )})</option>`;
    })
    .join('');

  let linkHtml = '';
  if (!linkEmps.length && !unmapped.length) {
    linkHtml =
      '<p class="att-empty">لا يوجد موظفون متاحون للربط ولا أرقام بصمة غير مربوطة. نفّذ المزامنة أولاً.</p>';
  } else if (!linkEmps.length) {
    linkHtml = '<p class="att-empty">جميع الموظفين مربوطون مسبقاً.</p>';
  } else if (!unmapped.length) {
    linkHtml =
      '<p class="att-empty">لا توجد أرقام بصمة غير مربوطة — نفّذ المزامنة من شاشة السيرفر أو Windows.</p>';
  } else {
    linkHtml = `
      <form method="post" action="${BASE}/map" class="att-link-form">
        <input type="hidden" name="from" value="${esc(from)}">
        <input type="hidden" name="to" value="${esc(to)}">
        <input type="hidden" name="employee_id_filter" value="${empId}">
        <div class="att-link-grid">
          <label>موظف النظام
            <select class="si-field" name="employee_id" required>
              <option value="">— اختر —</option>
              ${empLinkOpts}
            </select>
          </label>
          <label>رقم البصمة (Access)
            <select class="si-field" name="zk_user_id" required>
              <option value="">— اختر —</option>
              ${zkLinkOpts}
            </select>
          </label>
          <button class="si-btn si-btn--primary" type="submit">ربط</button>
        </div>
      </form>`;
  }

  const punchesHtml =
    punches.length === 0
      ? `<p class="att-empty">لا توجد سجلات في الفترة المحددة.
           نفّذ المزامنة من شاشة <a href="/hr/attendance/sync-server">السيرفر (ZKT)</a>
           أو <a href="/hr/attendance/sync-local">Windows (محلي)</a>.</p>`
      : `<div class="si-table-wrap"><table class="si-table">
        <thead><tr>
          <th>التاريخ والوقت</th><th>الموظف</th><th>رقم البصمة</th>
          <th>الاسم (جهاز)</th><th>النوع</th><th>التحقق</th>
        </tr></thead>
        <tbody>${punches
          .map((p) => {
            const linked = Number(p.employee_id) > 0;
            const empCell = linked
              ? `${esc(p.employee_name || '')}${
                  p.emp_code ? ` <span class="att-muted">(${esc(p.emp_code)})</span>` : ''
                }`
              : '<span class="att-unlinked">غير مربوط</span>';
            return `<tr class="${linked ? '' : 'att-row-unlinked'}">
            <td class="si-num" dir="ltr">${esc(p.punch_time || '')}</td>
            <td>${empCell}</td>
            <td class="si-num" dir="ltr">${esc(p.badge_number || '—')}</td>
            <td>${esc(p.zk_name || '—')}</td>
            <td>${esc(svc.punchTypeLabel(p.punch_type))}</td>
            <td>${esc(svc.verifyLabel(p.verify_code))}</td>
          </tr>`;
          })
          .join('')}</tbody></table></div>`;

  const lastSync = config.last_sync_at || '—';

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-attendance.css">
    <div class="si-stage att-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'بصمات الموظفين',
        subtitle: 'عرض وربط سجلات البصمة بعد المزامنة',
        actions: [
          { label: 'مزامنة السيرفر', href: '/hr/attendance/sync-server' },
          { label: 'مزامنة Windows', href: '/hr/attendance/sync-local' },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}

      <p class="att-hint">
        آخر مزامنة: <strong dir="ltr">${esc(String(lastSync))}</strong>
        — إجمالي السجلات: <strong dir="ltr">${totalPunches}</strong>
      </p>

      <div class="att-toolbar no-print">
        <form method="post" action="${BASE}/auto-map" style="display:inline">
          <input type="hidden" name="from" value="${esc(from)}">
          <input type="hidden" name="to" value="${esc(to)}">
          <input type="hidden" name="employee_id" value="${empId}">
          <button class="si-btn" type="submit">ربط تلقائي (رقم الموظف = رقم البصمة)</button>
        </form>
      </div>

      <section class="si-surface att-section">
        <div class="si-surface-head">
          <h2>الموظفون المربوطون بالبصمة</h2>
          <span class="si-count">${mapped.length}</span>
        </div>
        <div style="padding:.65rem 1rem 1rem">${mappedHtml}</div>
      </section>

      <section class="si-surface att-section">
        <div class="si-surface-head"><h2>ربط موظف بالبصمة</h2></div>
        <div style="padding:.65rem 1rem 1rem">${linkHtml}</div>
      </section>

      <section class="si-surface att-section">
        <div class="si-surface-head">
          <h2>سجلات الحضور المُزامَنة</h2>
          <span class="si-count">${punches.length}</span>
        </div>
        <div style="padding:.65rem 1rem 1rem">
          <form method="get" action="${BASE}" class="att-filters no-print">
            <label>من تاريخ
              <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}">
            </label>
            <label>إلى تاريخ
              <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}">
            </label>
            <label>موظف
              <select class="si-field" name="employee_id">
                <option value="0">— الكل —</option>
                ${empOpts}
              </select>
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض</button>
          </form>
          ${punchesHtml}
        </div>
      </section>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'بصمات الموظفين',
      bodyHtml: body,
      css: ['/assets/css/hr-attendance.css'],
      activePath: BASE,
    })
  );
});

function redirectBack(res, body, queryExtra = {}) {
  const from = String(body?.from || '');
  const to = String(body?.to || '');
  const empId = Number(body?.employee_id || body?.employee_id_filter || 0) || 0;
  const q = new URLSearchParams();
  if (from) q.set('from', from);
  if (to) q.set('to', to);
  if (empId) q.set('employee_id', String(empId));
  for (const [k, v] of Object.entries(queryExtra)) {
    if (v != null && v !== '') q.set(k, String(v));
  }
  const s = q.toString();
  res.redirect(BASE + (s ? '?' + s : ''));
}

router.post(BASE + '/auto-map', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.autoMap();
  redirectBack(res, req.body, result.ok ? { msg: result.message } : { err: result.error });
});

router.post(BASE + '/map', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.saveManualMap(req.body?.zk_user_id, req.body?.employee_id);
  redirectBack(res, req.body, result.ok ? { msg: result.message } : { err: result.error });
});

router.post(BASE + '/unmap', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.unmap(req.body?.zk_user_id);
  redirectBack(res, req.body, result.ok ? { msg: result.message } : { err: result.error });
});

/* ——— مزامنة السيرفر (ZKT) + Windows ——— */

const config = require('../config');

function attNavTabs(active) {
  const tabs = [
    { id: 'main', href: BASE, label: 'بصمات الموظفين' },
    { id: 'server', href: BASE + '/sync-server', label: 'مزامنة السيرفر (ZKT)' },
    { id: 'local', href: BASE + '/sync-local', label: 'مزامنة Windows' },
  ];
  return `<nav class="att-tabs no-print">${tabs
    .map(
      (t) =>
        `<a class="att-tab${t.id === active ? ' is-active' : ''}" href="${t.href}">${esc(t.label)}</a>`
    )
    .join('')}</nav>`;
}

function pushApiUrl() {
  return config.phpBaseUrl.replace(/\/$/, '') + '/api/hr_attendance_push.php';
}

function statusMetaHtml(configRow, totalPunches) {
  return `
    <dl class="att-meta">
      <div><dt>آخر مزامنة</dt><dd dir="ltr">${esc(String(configRow.last_sync_at || '—'))}</dd></div>
      <div><dt>آخر بصمة مُزامَنة</dt><dd dir="ltr">${esc(String(configRow.last_punch_time || '—'))}</dd></div>
      <div><dt>إجمالي السجلات</dt><dd dir="ltr">${totalPunches}</dd></div>
    </dl>`;
}

router.get(BASE + '/sync-server', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const [token, cfg, total] = await Promise.all([
    svc.ensureSyncToken(),
    svc.getConfig(),
    svc.countPunches(),
  ]);
  const apiUrl = pushApiUrl();

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-attendance.css">
    <div class="si-stage att-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'مزامنة السيرفر (ZKT)',
        subtitle: 'وكيل Windows يرسل البصمات إلى API النظام',
        actions: [
          { label: 'بصمات الموظفين', href: BASE, primary: true },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      ${attNavTabs('server')}

      <div class="att-callout att-callout--info">
        <strong>وضع السيرفر:</strong>
        ملف <code dir="ltr">att2000.mdb</code> يبقى على جهاز البصمة (Windows).
        ثبّت وكيل المزامنة هناك ليرسل البصمات إلى السيرفر.
      </div>

      <section class="si-surface att-section">
        <div class="si-surface-head"><h2>إعداد وكيل ZKT على جهاز البصمة</h2></div>
        <div class="att-panel-body">
          <ol class="att-steps">
            <li>على <strong>جهاز ZKT (Windows)</strong> أنشئ المجلد
              <code dir="ltr">C:\\zktdata\\tools\\</code> وانسخ:
              <code dir="ltr">zk_sync_agent.ps1</code> و <code dir="ltr">zk_sync_run.bat</code>.</li>
            <li>أنشئ <code dir="ltr">zk_sync.local.php</code> وضع <strong>رابط API</strong> و<strong>رمز المزامنة</strong> أدناه.</li>
            <li>شغّل <code dir="ltr">zk_sync_run.bat</code> أو جدوله كل 5–15 دقيقة.</li>
          </ol>

          <dl class="att-meta att-meta--codes">
            <div>
              <dt>رابط API</dt>
              <dd dir="ltr"><code class="att-code">${esc(apiUrl)}</code></dd>
            </div>
            <div>
              <dt>رمز المزامنة</dt>
              <dd dir="ltr"><code class="att-code att-code--token">${esc(token)}</code></dd>
            </div>
          </dl>

          <form method="post" action="${BASE}/sync-server/regen-token" class="att-toolbar"
                onsubmit="return confirm('إنشاء رمز جديد؟ يجب تحديث zk_sync.local.php على جهاز ZKT.');">
            <button class="si-btn" type="submit">رمز مزامنة جديد</button>
          </form>

          ${statusMetaHtml(cfg, total)}
        </div>
      </section>

      <p class="att-hint">
        لعرض السجلات وربط الموظفين:
        <a href="${BASE}">بصمات الموظفين</a>
        · التقرير:
        <a href="/hr/reports/punches">حركات البصمات (الكل)</a>
      </p>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'مزامنة السيرفر (ZKT)',
      bodyHtml: body,
      css: ['/assets/css/hr-attendance.css'],
      activePath: BASE + '/sync-server',
    })
  );
});

router.post(BASE + '/sync-server/regen-token', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  try {
    await svc.regenerateSyncToken();
    res.redirect(BASE + '/sync-server?msg=' + encodeURIComponent('تم إنشاء رمز مزامنة جديد.'));
  } catch (e) {
    res.redirect(BASE + '/sync-server?err=' + encodeURIComponent(e.message || 'تعذر التجديد.'));
  }
});

router.get(BASE + '/sync-local', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const [cfg, total] = await Promise.all([svc.getConfig(), svc.countPunches()]);
  const mdb = String(cfg.mdb_path || 'C:\\zktdata\\att2000.mdb');
  const isWin = process.platform === 'win32';

  const body = `
    <link rel="stylesheet" href="/assets/css/hr-attendance.css">
    <div class="si-stage att-page">
      ${ui.hero({
        mark: 'Hr',
        kicker: KICKER,
        title: 'مزامنة Windows (محلي)',
        subtitle: 'مسار att2000.mdb على نفس جهاز Windows',
        actions: [
          { label: 'مزامنة السيرفر', href: BASE + '/sync-server' },
          { label: 'بصمات الموظفين', href: BASE },
          { label: 'لوحة الموارد', href: HUB },
        ],
      })}
      ${flashHtml(req)}
      ${attNavTabs('local')}

      <div class="att-callout ${isWin ? 'att-callout--ok' : 'att-callout--warn'}">
        ${
          isWin
            ? 'هذه الشاشة تُستخدم على Windows حيث يوجد ملف <code dir="ltr">att2000.mdb</code>. احفظ المسار؛ قراءة الملف مباشرة من Node غير مفعّلة — نفّذ المزامنة عبر وكيل PHP/XAMPP أو عبر شاشة السيرفر.'
            : 'الخادم ليس Windows. احفظ المسار إن لزم، والمزامنة الفعلية تتم عبر <a href="' +
              BASE +
              '/sync-server">وكيل ZKT (السيرفر)</a>.'
        }
      </div>

      <section class="si-surface att-section">
        <div class="si-surface-head"><h2>مسار قاعدة البصمة</h2></div>
        <div class="att-panel-body">
          <form method="post" action="${BASE}/sync-local/save" class="att-link-grid" style="margin-bottom:1rem">
            <label style="flex:1 1 100%">مسار att2000.mdb
              <input class="si-field si-field--mono" name="mdb_path" dir="ltr" required value="${esc(mdb)}"
                     placeholder="C:\\zktdata\\att2000.mdb">
            </label>
            <button class="si-btn si-btn--primary" type="submit">حفظ المسار</button>
          </form>
          ${statusMetaHtml(cfg, total)}
        </div>
      </section>

      <p class="att-hint">
        بعد وصول البيانات: <a href="${BASE}">بصمات الموظفين</a>
      </p>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'مزامنة Windows',
      bodyHtml: body,
      css: ['/assets/css/hr-attendance.css'],
      activePath: BASE + '/sync-local',
    })
  );
});

router.post(BASE + '/sync-local/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const result = await svc.saveMdbPath(req.body?.mdb_path);
  res.redirect(
    BASE +
      '/sync-local?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

module.exports = router;
