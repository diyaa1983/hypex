'use strict';

const { createDomainRouter } = require('../lib/domainFactory');
const { systemCatalog } = require('./catalog');
const q = require('./domainQueries');

function dash(ui, v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

function kvRows(ui, obj, keys) {
  if (!obj) return ui.emptyRow(2, 'لا توجد بيانات');
  return keys
    .filter((k) => Object.prototype.hasOwnProperty.call(obj, k) || true)
    .map((k) => {
      let val = obj[k];
      if (val == null) val = '';
      // أخفِ الأسرار
      if (/password|token|secret|key/i.test(k) && String(val).length > 0) {
        val = '••••••••';
      }
      return `<tr>
        <td class="si-num" dir="ltr">${ui.esc(k)}</td>
        <td>${dash(ui, val)}</td>
      </tr>`;
    })
    .join('');
}

module.exports = createDomainRouter({
  basePath: '/system',
  mark: 'Sy',
  kicker: 'Hypex System · Node',
  hubTitle: 'النظام',
  hubSubtitle: 'المستخدمون والصلاحيات والإعدادات والسجلات — إدارة أصلية على Node حيث توفّرت.',
  catalog: systemCatalog,
  listHandlers: {
    // users/admin/gps routes: usersRoutes + adminRoutes + gpsRoutes
  },
  reportHandlers: {
    '/system/audit-log': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const qv = String(req.query.q || '');
      const rows = await q.listAuditLog({ q: qv, from: range.from, to: range.to });
      return {
        subtitle: 'سجل التعديلات',
        headers: ['الوقت', 'المستخدم', 'الشاشة', 'الإجراء', 'المرجع', 'ملخص'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.logged_at)}</td>
            <td>${dash(ui, r.full_name_ar || r.username)}</td>
            <td>${dash(ui, r.screen_label_ar || r.screen_code)}</td>
            <td>${dash(ui, r.action_label_ar || r.action_code)}</td>
            <td class="si-num" dir="ltr">${dash(ui, r.entity_ref || r.entity_id)}</td>
            <td>${dash(ui, r.summary)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
        from: range.from,
        to: range.to,
        filtersHtml: `
          <div class="si-rail no-print">
            <form class="si-search" method="get" action="/system/audit-log" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">من
                <input class="si-field si-field--mono" type="date" name="from" value="${ui.esc(range.from)}">
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى
                <input class="si-field si-field--mono" type="date" name="to" value="${ui.esc(range.to)}">
              </label>
              <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث…" style="min-width:10rem">
              <button class="si-btn si-btn--primary" type="submit">عرض</button>
              ${ui.siPrintBtnHtml('طباعة')}
            </form>
          </div>`,
      };
    },
    '/system/error-log': async (req, { ui }) => {
      const range = q.dateRange(String(req.query.from || ''), String(req.query.to || ''));
      const qv = String(req.query.q || '');
      const rows = await q.listErrorLog({ q: qv, from: range.from, to: range.to });
      return {
        subtitle: 'أخطاء النظام',
        headers: ['الوقت', 'المصدر', 'المستوى', 'الرسالة', 'المستخدم', 'مرات'],
        rowsHtml:
          rows
            .map(
              (r) => `<tr>
            <td class="si-num" dir="ltr">${dash(ui, r.logged_at)}</td>
            <td>${dash(ui, r.source)}</td>
            <td>${dash(ui, r.level)}</td>
            <td>${dash(ui, r.message)}</td>
            <td>${dash(ui, r.username)}</td>
            <td class="si-num" dir="ltr">${Number(r.occurrence_count || 1)}</td>
          </tr>`
            )
            .join('') || ui.emptyRow(6),
        count: rows.length,
        from: range.from,
        to: range.to,
        filtersHtml: `
          <div class="si-rail no-print">
            <form class="si-search" method="get" action="/system/error-log" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">من
                <input class="si-field si-field--mono" type="date" name="from" value="${ui.esc(range.from)}">
              </label>
              <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى
                <input class="si-field si-field--mono" type="date" name="to" value="${ui.esc(range.to)}">
              </label>
              <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث…" style="min-width:10rem">
              <button class="si-btn si-btn--primary" type="submit">عرض</button>
              ${ui.siPrintBtnHtml('طباعة')}
            </form>
          </div>`,
      };
    },
  },
});
