'use strict';

const { runOracleAction } = require('./oracleWorker');

/**
 * @param {import('express').Router} router
 * @param {{ guard: Function, ui: any, q: any, HUB: string, KICKER: string }} deps
 */
function registerOracleSyncRoutes(router, { guard, ui, q, HUB, KICKER }) {
  function takeFlash(req) {
    const flash = req.session.oracleSyncFlash || null;
    if (req.session.oracleSyncFlash) delete req.session.oracleSyncFlash;
    return flash;
  }

  function setFlash(req, data) {
    req.session.oracleSyncFlash = data;
  }

  function redirectWithResult(req, res, result, keepWorkspace) {
    const ok = !!result.ok;
    const msg = String(result.message || (ok ? 'تمت العملية' : 'فشلت العملية'));
    const flash = {
      msg: ok ? msg : '',
      err: ok ? '' : msg,
      workspace: keepWorkspace
        ? {
            owner: result.owner || '',
            table: result.table || '',
            table_filter: result.table_filter || '',
            map: result.map || {},
            candidate_tables: result.candidate_tables || [],
            app_tables: result.app_tables || [],
            describe_cols: result.describe_cols || [],
            preview_rows: result.preview_rows || [],
            sync: result.sync || null,
          }
        : null,
    };
    setFlash(req, flash);
    res.redirect('/customers/oracle-sync');
  }

  function suggestCol(cols, needles) {
    for (const c of cols) {
      const n = String(c.column_name || '').toUpperCase();
      for (const needle of needles) {
        if (n.includes(needle)) return n;
      }
    }
    return '';
  }

  function mergeMap(base, cols) {
    const map = { ...(base || {}) };
    if (!cols || !cols.length) return map;
    if (!map.oracle_key) {
      map.oracle_key =
        suggestCol(cols, ['CUST_ID', 'CUSTOMER_ID', 'CLIENT_ID', 'CUS_NUM', 'ID_NO', 'CODE', 'NO']) ||
        String(cols[0].column_name || '');
    }
    if (!map.code) map.code = suggestCol(cols, ['CUS_NUM', 'CODE', 'NO', 'NUM']);
    if (!map.name_ar) map.name_ar = suggestCol(cols, ['NAME', 'NAME_A', 'CNAME']);
    if (!map.phone) map.phone = suggestCol(cols, ['PHONE', 'TEL', 'MOBILE']);
    if (!map.tax_number) map.tax_number = suggestCol(cols, ['TAX', 'VAT']);
    if (!map.address_ar) map.address_ar = suggestCol(cols, ['ADDR', 'ADDRESS']);
    return map;
  }

  function colOptions(cols, selected) {
    const sel = String(selected || '').toUpperCase();
    const opts = [`<option value="">—</option>`];
    for (const c of cols) {
      const cn = String(c.column_name || '');
      const dt = String(c.data_type || '');
      const selectedAttr = cn.toUpperCase() === sel ? ' selected' : '';
      opts.push(
        `<option value="${ui.esc(cn)}"${selectedAttr}>${ui.esc(cn)}${dt ? ' (' + ui.esc(dt) + ')' : ''}</option>`
      );
    }
    return opts.join('');
  }

  function tablesHtml(tables, title) {
    if (!tables || !tables.length) return '';
    const rows = tables
      .map(
        (t) => `<tr>
      <td><code dir="ltr">${ui.esc(t.owner || '')}</code></td>
      <td><code dir="ltr">${ui.esc(t.table_name || '')}</code></td>
      <td>
        <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري فتح الجدول…" style="display:inline">
          <input type="hidden" name="action" value="describe">
          <input type="hidden" name="owner" value="${ui.esc(t.owner || '')}">
          <input type="hidden" name="table" value="${ui.esc(t.table_name || '')}">
          <button class="si-btn" type="submit" style="font-size:.78rem;padding:.25rem .55rem">عرض الأعمدة + عينة</button>
        </form>
      </td>
    </tr>`
      )
      .join('');
    return `
      <section class="si-surface" style="margin-bottom:.75rem">
        <div class="si-surface-head"><h2>${ui.esc(title)}</h2></div>
        <div class="si-table-wrap" style="max-height:360px;overflow:auto;padding:0 0 .5rem">
          <table class="si-table">
            <thead><tr><th>Schema</th><th>الجدول</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </section>`;
  }

  function previewHtml(rows) {
    if (!rows || !rows.length) return '';
    const keys = Object.keys(rows[0] || {});
    const head = keys.map((k) => `<th>${ui.esc(k)}</th>`).join('');
    const body = rows
      .map((pr) => {
        const cells = keys
          .map((k) => {
            const cell = pr[k];
            const text =
              cell == null || typeof cell === 'string' || typeof cell === 'number' || typeof cell === 'boolean'
                ? String(cell ?? '')
                : JSON.stringify(cell);
            return `<td>${ui.esc(text)}</td>`;
          })
          .join('');
        return `<tr>${cells}</tr>`;
      })
      .join('');
    return `
      <section class="si-surface" style="margin-bottom:.75rem">
        <div class="si-surface-head"><h2>عينة (أول ${rows.length} صف)</h2></div>
        <div class="si-table-wrap" style="max-height:320px;overflow:auto;font-size:.85rem">
          <table class="si-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
        </div>
      </section>`;
  }

  router.get('/customers/oracle-sync', guard('oracle_customers_sync'), async (req, res) => {
    const linked = await q.oracleLinkedCount();
    let allCount = linked;
    try {
      const r = await q.reportCustomers({ activeOnly: false });
      allCount = r.length;
    } catch {
      /* */
    }

    let status = runOracleAction('status');
    const flash = takeFlash(req);
    const ws = (flash && flash.workspace) || {};
    const msg = (flash && flash.msg) || String(req.query.msg || '');
    const err = (flash && flash.err) || String(req.query.err || '');

    const cfg = status.config || {};
    const drivers = status.drivers || {};
    const mapping = status.mapping || null;
    const owner = String(ws.owner || (mapping && mapping.owner) || '');
    const table = String(ws.table || (mapping && mapping.table) || '');
    const tableFilter = String(ws.table_filter || '');
    let map = Object.assign(
      {
        oracle_key: '',
        code: '',
        name_ar: '',
        phone: '',
        email: '',
        tax_number: '',
        address_ar: '',
        is_active: '',
      },
      (mapping && mapping.columns) || {},
      ws.map || {}
    );
    const describeCols = ws.describe_cols || [];
    map = mergeMap(map, describeCols);
    const candidateTables = ws.candidate_tables || [];
    const appTables = ws.app_tables || [];
    const previewRows = ws.preview_rows || [];
    const syncResult = ws.sync || null;

    const enabled = !!(status.enabled || cfg.enabled);
    const hasDriver = !!status.has_driver;
    const mapLabel = mapping ? `${mapping.owner}.${mapping.table}` : '— غير محفوظ —';
    const lastSync = mapping && mapping.last_synced_at ? mapping.last_synced_at : '—';
    const passPh = !!cfg.has_password;

    const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Or',
        kicker: KICKER,
        title: 'تكامل Oracle — ربط ومزامنة العملاء',
        subtitle: 'اتصال بـ Oracle واستكشاف جداول العملاء ثم مزامنتها إلى Hypex — واجهة Node.',
        actions: [
          { label: 'لوحة العملاء', href: HUB },
          { label: 'قائمة العملاء', href: '/customers' },
        ],
      })}
      ${
        msg
          ? `<div id="oracle-action-result" class="si-surface" style="padding:.85rem 1rem;margin-bottom:.75rem;border-color:#86efac;background:#f0fdf4;white-space:pre-wrap"><strong>نتيجة العملية:</strong><br>${ui.esc(
              msg
            )}</div>`
          : ''
      }
      ${
        err
          ? `<div id="oracle-action-result" class="si-surface" style="padding:.85rem 1rem;margin-bottom:.75rem;border-color:#fca5a5;background:#fef2f2;white-space:pre-wrap"><strong>نتيجة العملية (خطأ):</strong><br>${ui.esc(
              err
            )}</div>`
          : ''
      }

      <section class="si-surface" style="margin-bottom:.75rem">
        <div class="si-surface-head"><h2>حالة الربط</h2></div>
        <div style="padding:1rem 1.1rem;display:grid;gap:.55rem;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr))">
          <div><div class="muted" style="font-size:.75rem">عملاء Hypex</div><strong dir="ltr">${allCount}</strong></div>
          <div><div class="muted" style="font-size:.75rem">مربوطون بـ Oracle</div><strong dir="ltr">${linked}</strong></div>
          <div><div class="muted" style="font-size:.75rem">التعيين المحفوظ</div><strong dir="ltr">${ui.esc(mapLabel)}</strong></div>
          <div><div class="muted" style="font-size:.75rem">آخر مزامنة</div><strong dir="ltr">${ui.esc(String(lastSync))}</strong></div>
        </div>
      </section>

      <section class="si-surface" style="margin-bottom:.75rem;border-color:${
        enabled ? '#bbf7d0' : '#fecaca'
      };background:${enabled ? '#ecfdf5' : '#fef2f2'}">
        <div class="si-surface-head"><h2>1) ملف الإعداد</h2></div>
        <div style="padding:0 1.1rem 1rem;white-space:pre-wrap;color:${enabled ? '#166534' : '#991b1b'}">
المسار: <code dir="ltr">${ui.esc(String(cfg.path || ''))}</code>
الحالة: ${enabled ? 'مفعّل' : 'غير مفعّل'}
${!enabled && cfg.status_message ? ui.esc(String(cfg.status_message)) : ''}
        </div>
      </section>

      <section class="si-surface" style="margin-bottom:.75rem;border-color:${
        hasDriver ? '#bbf7d0' : '#fecaca'
      };background:${hasDriver ? '#ecfdf5' : '#fef2f2'}">
        <div class="si-surface-head"><h2>2) مشغّل الاتصال (OCI) على هذا الجهاز</h2></div>
        <div style="padding:0 1.1rem 1rem;white-space:pre-wrap;color:${hasDriver ? '#166534' : '#991b1b'}">
pdo_oci: ${drivers.pdo_oci ? 'موجود' : 'غير محمّل'}
oci8: ${drivers.oci8 ? 'موجود' : 'غير محمّل'}
pdo_odbc: ${drivers.pdo_odbc ? 'موجود' : 'غير محمّل'}
${!hasDriver ? '\nثبّت Oracle Instant Client وفعّل pdo_oci أو oci8 ثم أعد تشغيل الخدمات.' : ''}
        </div>
      </section>

      <section class="si-surface" style="margin-bottom:.75rem">
        <div class="si-surface-head"><h2>إعداد الاتصال</h2></div>
        <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري حفظ الإعداد…" style="padding:1rem 1.1rem">
          <input type="hidden" name="action" value="save_config">
          <input type="hidden" name="cfg_charset" value="${ui.esc(String(cfg.charset || 'AL32UTF8'))}">
          <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:end">
            <label style="display:flex;align-items:center;gap:.35rem;font-size:.85rem;font-weight:700">
              <input type="checkbox" name="cfg_enabled" value="1" ${
                enabled || !cfg.file_exists ? 'checked' : ''
              }> مفعّل
            </label>
            <label style="flex:1 1 10rem"><span class="muted" style="font-size:.75rem;display:block">Host</span>
              <input class="si-field" name="cfg_host" required value="${ui.esc(
                String(cfg.host || '192.168.100.2')
              )}"></label>
            <label style="flex:0 1 6rem"><span class="muted" style="font-size:.75rem;display:block">Port</span>
              <input class="si-field" name="cfg_port" type="number" value="${ui.esc(
                String(cfg.port || 1521)
              )}"></label>
            <label style="flex:1 1 8rem"><span class="muted" style="font-size:.75rem;display:block">SID</span>
              <input class="si-field" name="cfg_sid" value="${ui.esc(String(cfg.sid || 'taqwa'))}"></label>
            <label style="flex:1 1 8rem"><span class="muted" style="font-size:.75rem;display:block">Service Name</span>
              <input class="si-field" name="cfg_service" value="${ui.esc(
                String(cfg.service_name || '')
              )}"></label>
            <label style="flex:1 1 8rem"><span class="muted" style="font-size:.75rem;display:block">User</span>
              <input class="si-field" name="cfg_user" required value="${ui.esc(String(cfg.user || ''))}"></label>
            <label style="flex:1 1 8rem"><span class="muted" style="font-size:.75rem;display:block">Password${
              passPh ? ' (فارغ = الإبقاء)' : ''
            }</span>
              <input class="si-field" type="password" name="cfg_pass" value="" autocomplete="new-password" ${
                passPh ? '' : 'required'
              }></label>
            <label style="flex:1 1 10rem"><span class="muted" style="font-size:.75rem;display:block">ODBC DSN</span>
              <input class="si-field" name="cfg_odbc" value="${ui.esc(String(cfg.odbc_dsn || ''))}"></label>
          </div>
          <div style="margin-top:.85rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ الإعداد على هذا السيرفر</button>
          </div>
        </form>
      </section>

      <section class="si-surface" style="margin-bottom:.75rem">
        <div class="si-surface-head"><h2>اختبار واكتشاف ومزامنة</h2></div>
        <div style="padding:1rem 1.1rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
          <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري اختبار الاتصال بـ Oracle…">
            <input type="hidden" name="action" value="test">
            <button class="si-btn si-btn--primary" type="submit">1) اختبار الاتصال</button>
          </form>
          <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري اكتشاف الجداول…">
            <input type="hidden" name="action" value="discover">
            <button class="si-btn" type="submit">2) اكتشاف مرشّحي العملاء</button>
          </form>
          <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري جلب جداول التطبيق…" style="display:inline-flex;gap:.35rem;align-items:center">
            <input type="hidden" name="action" value="list_tables">
            <input class="si-field" name="table_filter" value="${ui.esc(
              tableFilter
            )}" placeholder="فلتر (مثل CUST)" style="width:10rem;min-height:2.1rem">
            <button class="si-btn" type="submit">2ب) كل جداول التطبيق</button>
          </form>
          ${
            mapping
              ? `<form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري المزامنة…">
            <input type="hidden" name="action" value="sync_saved">
            <button class="si-btn si-btn--primary" type="submit">مزامنة بالتعيين المحفوظ</button>
          </form>`
              : ''
          }
        </div>
        <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري فتح الجدول…" style="padding:0 1.1rem 1rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:end">
          <input type="hidden" name="action" value="open_manual">
          <label style="flex:1 1 8rem"><span class="muted" style="font-size:.75rem;display:block">Schema (Owner)</span>
            <input class="si-field" name="owner" value="${ui.esc(owner)}" placeholder="مثل ACCINV"></label>
          <label style="flex:1 1 10rem"><span class="muted" style="font-size:.75rem;display:block">اسم الجدول</span>
            <input class="si-field" name="table" value="${ui.esc(table)}" placeholder="CUSTOMER"></label>
          <button class="si-btn" type="submit">فتح الجدول يدوياً</button>
        </form>
        ${
          mapping
            ? `<p class="muted" style="margin:0 1.1rem 1rem;font-size:.9rem">تعيين محفوظ: <code dir="ltr">${ui.esc(
                mapping.owner + '.' + mapping.table
              )}</code>${
                mapping.last_synced_at
                  ? ' — آخر مزامنة: ' + ui.esc(String(mapping.last_synced_at))
                  : ''
              }</p>`
            : ''
        }
        <p id="oracle-wait-msg" class="muted" style="display:none;font-weight:600;color:#a60;margin:0 1.1rem 1rem"></p>
      </section>

      ${tablesHtml(candidateTables, 'جداول مرشّحة (عملاء)')}
      ${!candidateTables.length ? tablesHtml(appTables, 'جداول التطبيق') : ''}

      ${
        owner && table
          ? `
      <section class="si-surface" style="margin-bottom:.75rem">
        <div class="si-surface-head"><h2>تعيين الأعمدة — <span dir="ltr">${ui.esc(
          owner + '.' + table
        )}</span></h2></div>
        <form method="post" action="/customers/oracle-sync/action" class="js-oracle-action-form" data-wait="جاري المزامنة…" style="padding:1rem 1.1rem">
          <input type="hidden" name="action" value="sync">
          <input type="hidden" name="owner" value="${ui.esc(owner)}">
          <input type="hidden" name="table" value="${ui.esc(table)}">
          <div style="display:flex;flex-wrap:wrap;gap:.75rem">
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">مفتاح Oracle *</span>
              <select class="si-field" name="map_oracle_key">${colOptions(
                describeCols,
                map.oracle_key
              )}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">رمز العميل</span>
              <select class="si-field" name="map_code">${colOptions(describeCols, map.code)}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">الاسم (اختياري إن كان من GL)</span>
              <select class="si-field" name="map_name">${colOptions(describeCols, map.name_ar)}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">الهاتف</span>
              <select class="si-field" name="map_phone">${colOptions(describeCols, map.phone)}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">البريد</span>
              <select class="si-field" name="map_email">${colOptions(describeCols, map.email)}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">الرقم الضريبي</span>
              <select class="si-field" name="map_tax">${colOptions(
                describeCols,
                map.tax_number
              )}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">العنوان</span>
              <select class="si-field" name="map_address">${colOptions(
                describeCols,
                map.address_ar
              )}</select></label>
            <label style="flex:1 1 12rem"><span class="muted" style="font-size:.75rem;display:block">الحالة</span>
              <select class="si-field" name="map_active">${colOptions(
                describeCols,
                map.is_active
              )}</select></label>
          </div>
          <div style="margin-top:.85rem;display:flex;flex-wrap:wrap;gap:.5rem">
            <button class="si-btn si-btn--primary" type="submit">3) مزامنة العملاء إلى Hypex</button>
            <button class="si-btn" type="submit" name="action" value="describe" formnovalidate>تحديث العينة</button>
          </div>
        </form>
        ${
          describeCols.length
            ? `<p class="muted" style="margin:0 1.1rem 1rem;font-size:.85rem">${describeCols
                .map((c) => ui.esc((c.column_name || '') + ':' + (c.data_type || '')))
                .join(' · ')}</p>`
            : ''
        }
      </section>
      ${previewHtml(previewRows)}`
          : ''
      }

      ${
        syncResult
          ? `<p class="muted" style="margin:.5rem 0 0">النتيجة: مدرج ${Number(
              syncResult.inserted || 0
            )} — محدّث ${Number(syncResult.updated || 0)} — متجاوز ${Number(
              syncResult.skipped || 0
            )}</p>`
          : ''
      }
    </div>
    <script>
    (function () {
      var wait = document.getElementById('oracle-wait-msg');
      document.querySelectorAll('.js-oracle-action-form').forEach(function (form) {
        form.addEventListener('submit', function () {
          if (!wait) return;
          wait.style.display = 'block';
          wait.textContent = form.getAttribute('data-wait') || 'جاري التنفيذ…';
          var btn = form.querySelector('button[type="submit"]');
          if (btn) { btn.disabled = true; }
        });
      });
      var r = document.getElementById('oracle-action-result');
      if (r) r.scrollIntoView({ behavior: 'smooth', block: 'center' });
    })();
    </script>`;

    res.send(ui.salesPage({ user: req.session.user, title: 'مزامنة عملاء Oracle', bodyHtml: body }));
  });

  router.post('/customers/oracle-sync/action', guard('oracle_customers_sync'), (req, res) => {
    const action = String(req.body.action || '').trim();
    const owner = String(req.body.owner || '').trim();
    const table = String(req.body.table || '').trim();
    const tableFilter = String(req.body.table_filter || '').trim();
    const map = {
      oracle_key: String(req.body.map_oracle_key || '').trim(),
      code: String(req.body.map_code || '').trim(),
      name_ar: String(req.body.map_name || '').trim(),
      phone: String(req.body.map_phone || '').trim(),
      email: String(req.body.map_email || '').trim(),
      tax_number: String(req.body.map_tax || '').trim(),
      address_ar: String(req.body.map_address || '').trim(),
      is_active: String(req.body.map_active || '').trim(),
    };

    let result;
    if (action === 'save_config') {
      result = runOracleAction('save_config', {
        config: {
          enabled: String(req.body.cfg_enabled || '') === '1',
          host: String(req.body.cfg_host || ''),
          port: Number(req.body.cfg_port || 1521),
          sid: String(req.body.cfg_sid || ''),
          service_name: String(req.body.cfg_service || ''),
          user: String(req.body.cfg_user || ''),
          pass: String(req.body.cfg_pass || ''),
          charset: String(req.body.cfg_charset || 'AL32UTF8'),
          odbc_dsn: String(req.body.cfg_odbc || ''),
        },
      });
      return redirectWithResult(req, res, result, false);
    }

    if (action === 'test') {
      result = runOracleAction('test');
      return redirectWithResult(req, res, result, false);
    }
    if (action === 'discover') {
      result = runOracleAction('discover');
      return redirectWithResult(req, res, result, true);
    }
    if (action === 'list_tables') {
      result = runOracleAction('list_tables', { table_filter: tableFilter });
      return redirectWithResult(req, res, result, true);
    }
    if (action === 'open_manual' || action === 'describe') {
      result = runOracleAction(action === 'describe' ? 'describe' : 'open_manual', {
        owner,
        table,
        map,
      });
      return redirectWithResult(req, res, result, true);
    }
    if (action === 'sync') {
      result = runOracleAction('sync', { owner, table, map });
      return redirectWithResult(req, res, result, true);
    }
    if (action === 'sync_saved') {
      result = runOracleAction('sync_saved');
      return redirectWithResult(req, res, result, false);
    }

    setFlash(req, { msg: '', err: 'إجراء غير معروف', workspace: null });
    res.redirect('/customers/oracle-sync');
  });

  // توافق مع الروابط القديمة من الشاشة الجزئية
  router.post('/customers/oracle-sync/test', guard('oracle_customers_sync'), (req, res) => {
    const r = runOracleAction('test');
    redirectWithResult(req, res, r, false);
  });
  router.post('/customers/oracle-sync/run', guard('oracle_customers_sync'), (req, res) => {
    const r = runOracleAction('sync_saved');
    redirectWithResult(req, res, r, false);
  });
}

module.exports = { registerOracleSyncRoutes };
