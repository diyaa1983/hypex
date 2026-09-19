'use strict';

const express = require('express');
const auth = require('../auth');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const svc = require('./accountMappingService');

const router = express.Router();
const KICKER = 'Hypex Accounting · Node';
const HUB = '/accounting';
const BASE = '/accounting/account-mapping';
const PERM = 'account_mapping';

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

function formatCode(code) {
  const d = String(code || '').replace(/\D/g, '');
  if (!d) return '';
  const t = d.replace(/^0+/, '');
  return t === '' ? '0' : t;
}

router.get(BASE, async (req, res) => {
  if (!can(req.session.user)) return forbid(res);

  if (!(await svc.tableReady())) {
    return res.status(500).send(
      ui.salesPage({
        user: req.session.user,
        title: 'ربط الحسابات',
        bodyHtml: `<div class="si-stage">${ui.hero({
          mark: 'Ac',
          kicker: KICKER,
          title: 'ربط الحسابات',
          subtitle: 'جدول acc_posting_setting غير موجود — نفّذ ترحيل قاعدة البيانات 032.',
          actions: [{ label: 'لوحة المحاسبة', href: HUB }],
        })}</div>`,
      })
    );
  }

  const [settings, leaves] = await Promise.all([svc.listSettings(), svc.listLeafAccounts()]);
  const required = svc.requiredCodes(settings);
  const invOn = !!settings.find((s) => s.rule_code === 'inventory' && s.account_id);

  const rowsHtml =
    settings
      .map((s) => {
        const reqMark = required.has(s.rule_code)
          ? `<span class="am-req" title="مطلوب">*</span>`
          : '';
        const optional =
          s.rule_code === 'purchases' && invOn
            ? `<span class="am-opt"> — اختياري (المخزون مفعّل)</span>`
            : '';
        const options =
          `<option value="">— بدون ربط —</option>` +
          leaves
            .map((a) => {
              const label = formatCode(a.code) + ' — ' + (a.name_ar || '');
              const sel = Number(s.account_id) === Number(a.id) ? 'selected' : '';
              return `<option value="${a.id}" ${sel}>${esc(label)}</option>`;
            })
            .join('');
        return `<tr>
          <td>
            <strong>${esc(s.label_ar)}</strong>${reqMark}${optional}
            <div class="am-code" dir="ltr">${esc(s.rule_code)}</div>
          </td>
          <td>
            <select class="si-field am-select" name="account[${esc(s.rule_code)}]" data-filter="1">
              ${options}
            </select>
          </td>
          <td class="am-hint">${esc(s.hint_ar || '')}</td>
        </tr>`;
      })
      .join('') || `<tr><td colspan="3" class="empty">لا توجد قواعد ربط.</td></tr>`;

  const body = `
    <link rel="stylesheet" href="/assets/css/account-mapping-node.css">
    <div class="si-stage am-page">
      ${ui.hero({
        mark: 'Ac',
        kicker: KICKER,
        title: 'ربط الحسابات المحاسبية',
        subtitle: 'تعيين حساب كل عملية ترحيل من شجرة الحسابات',
        actions: [
          { label: 'شجرة الحسابات', href: '/accounting/chart' },
          { label: 'لوحة المحاسبة', href: HUB },
        ],
      })}
      ${flashHtml(req)}

      <section class="si-surface am-section">
        <div class="si-surface-head">
          <h2>ربط العمليات المحاسبية</h2>
          <span class="si-count">${settings.length} قاعدة</span>
        </div>
        <div class="am-body">
          <div class="am-filter-row no-print">
            <label class="am-filter-label">تصفية الحسابات
              <input class="si-field" type="search" id="am-filter" placeholder="ابحث بالكود أو الاسم…" autocomplete="off">
            </label>
          </div>
          <form method="post" action="${BASE}/save" id="am-form">
            <div class="si-table-wrap">
              <table class="si-table am-table">
                <thead>
                  <tr>
                    <th>العملية / الاستخدام</th>
                    <th>الحساب في الشجرة</th>
                    <th>ملاحظة</th>
                  </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
              </table>
            </div>
            <div class="am-actions no-print">
              <button class="si-btn si-btn--primary" type="submit">حفظ الربط</button>
              <a class="si-btn" href="${BASE}">إعادة تحميل</a>
            </div>
          </form>
        </div>
      </section>
    </div>
    <script>
    (function(){
      var filter = document.getElementById('am-filter');
      if (!filter) return;
      filter.addEventListener('input', function(){
        var q = (filter.value || '').trim().toLowerCase();
        document.querySelectorAll('select.am-select').forEach(function(sel){
          var selected = sel.value;
          Array.prototype.forEach.call(sel.options, function(opt, i){
            if (i === 0) { opt.hidden = false; return; }
            var t = (opt.textContent || '').toLowerCase();
            opt.hidden = q && t.indexOf(q) === -1 && opt.value !== selected;
          });
        });
      });
    })();
    </script>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'ربط الحسابات',
      bodyHtml: body,
      css: ['/assets/css/account-mapping-node.css', '/assets/css/hr-shift-settings.css'],
      activePath: BASE,
    })
  );
});

router.post(BASE + '/save', async (req, res) => {
  if (!can(req.session.user)) return forbid(res);
  const body = req.body || {};
  const map = {};

  // account[rule] with extended:false → "account[ar_customers]"
  for (const [k, v] of Object.entries(body)) {
    const m = String(k).match(/^account\[(.+)\]$/);
    if (m) map[m[1]] = v;
  }
  if (body.account && typeof body.account === 'object') {
    Object.assign(map, body.account);
  }

  const result = await svc.saveMappings(map);
  if (!result.ok) {
    return res.redirect(BASE + '?err=' + encodeURIComponent(result.error || 'تعذر الحفظ'));
  }
  res.redirect(BASE + '?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

module.exports = router;
