'use strict';

const express = require('express');
const multer = require('multer');
const auth = require('../auth');
const svc = require('./adminService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex System · Node';
const HUB = '/system';

const logoUpload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 2 * 1024 * 1024 },
  fileFilter(_req, file, cb) {
    const ok = ['image/jpeg', 'image/png', 'image/webp'].includes(file.mimetype);
    cb(null, ok);
  },
});


const PREFIXES = [
  '/system/groups',
  '/system/permissions',
  '/system/sessions',
  '/system/settings',
  '/system/dashboard-accounts',
  '/system/tax-rates',
  '/system/backup',
  '/system/einvoice',
];

function can(user, code) {
  return user.is_admin || auth.userCan(user, code);
}

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : esc(s);
}

function flashHtml(msg, err) {
  return (
    (msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : '') +
    (err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : '')
  );
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (!PREFIXES.some((x) => p === x || p.startsWith(x + '/'))) return next('router');
  return auth.requireAuth(req, res, next);
});

/* ═══════════ GROUPS ═══════════ */
router.get('/system/groups', async (req, res) => {
  if (!can(req.session.user, 'groups')) return forbid(res);
  const isNew = String(req.query.id || '') === 'new';
  const editId = isNew ? 0 : Number(req.query.id || 0) || 0;
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const rows = await svc.listGroups();
  let row = { id: 0, code: '', name_ar: '', description: '', user_count: 0, perm_count: 0 };
  if (!isNew) {
    const tid = editId > 0 ? editId : rows[0] ? Number(rows[0].id) : 0;
    if (tid > 0) {
      const loaded = await svc.getGroup(tid);
      if (loaded) row = loaded;
    }
  }
  const codeRo = !isNew && String(row.code || '') === 'ADMINS';
  const postAction = isNew || !row.id ? '/system/groups/new' : '/system/groups/' + row.id;
  const listHtml =
    rows
      .map(
        (r, i) => `<tr class="${Number(r.id) === Number(row.id) && !isNew ? 'is-active-row' : ''}">
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr"><a href="/system/groups?id=${r.id}"><code>${esc(r.code)}</code></a></td>
      <td>${esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${Number(r.user_count || 0)}</td>
      <td class="si-num" dir="ltr">${Number(r.perm_count || 0)}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(5, 'لا مجموعات');

  const body = `
    <style>
      .sg-wrap{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(260px,.9fr);gap:1rem;align-items:start}
      @media (max-width:980px){.sg-wrap{grid-template-columns:1fr}}
      .sg-wrap .si-table tr.is-active-row td{background:rgba(11,107,203,.09)}
      .sg-wrap .si-table a{color:inherit;font-weight:700;text-decoration:none}
      .sg-wrap .si-table a:hover{color:#0b63ce;text-decoration:underline}
      .sg-form{display:flex;flex-direction:column;gap:.85rem;padding:1rem 1.15rem 1.25rem}
      .sg-form .sg-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
      @media (max-width:640px){.sg-form .sg-row{grid-template-columns:1fr}}
      .sg-form .sg-row--1{grid-template-columns:1fr}
      .sg-form label.sg-field{display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;font-weight:700;color:#3d4659;min-width:0}
      .sg-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.25rem}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Gp',
        kicker: KICKER,
        title: 'المجموعات',
        subtitle: 'مجموعات المستخدمين والصلاحيات',
        actions: [
          { label: '＋ جديد', href: '/system/groups?id=new', primary: true },
          { label: 'المستخدمون', href: '/system/users' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <div class="sg-wrap">
        <section class="si-surface">
          <div class="si-surface-head"><h2>مجموعات المستخدمين</h2><span class="si-count">${rows.length}</span></div>
          <p class="muted" style="padding:0 1rem;margin:.35rem 0 .5rem;font-size:.85rem">اختر مجموعة للتعديل أو اضغط «جديد».</p>
          <div class="si-table-wrap" style="padding:0 0 1rem">
            <table class="si-table">
              <thead><tr><th>#</th><th>الرمز</th><th>الاسم</th><th>مستخدمون</th><th>صلاحيات</th></tr></thead>
              <tbody>${listHtml}</tbody>
            </table>
          </div>
        </section>
        <section class="si-surface">
          <div class="si-surface-head"><h2>${isNew ? 'مجموعة جديدة' : 'بيانات المجموعة'}</h2></div>
          ${
            !isNew && row.id
              ? `<p class="muted" style="padding:0 1.1rem;font-size:.85rem">مرتبطة بـ <strong>${Number(
                  row.user_count || 0
                )}</strong> مستخدم — <strong>${Number(row.perm_count || 0)}</strong> صلاحية.
                <a href="/system/users">إدارة المستخدمين</a></p>`
              : ''
          }
          <form method="post" action="${postAction}" class="sg-form">
            <div class="sg-row">
              <label class="sg-field">الرمز *
                <input class="si-field si-field--mono" name="code" required value="${esc(
                  row.code || ''
                )}" dir="ltr" ${codeRo ? 'readonly' : ''} maxlength="40" placeholder="ADMINS">
              </label>
              <label class="sg-field">الاسم *
                <input class="si-field" name="name_ar" required value="${esc(row.name_ar || '')}" maxlength="120">
              </label>
            </div>
            <div class="sg-row sg-row--1">
              <label class="sg-field">الوصف
                <input class="si-field" name="description" value="${esc(row.description || '')}" maxlength="255">
              </label>
            </div>
            <div class="sg-actions">
              <button class="si-btn si-btn--primary" type="submit">حفظ</button>
              <a class="si-btn" href="/system/groups?id=new">جديد</a>
              ${
                !isNew && row.id
                  ? `<a class="si-btn si-btn--primary" href="/system/permissions?group_id=${row.id}">صلاحيات الشاشات والتقارير</a>`
                  : ''
              }
            </div>
          </form>
        </section>
      </div>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'المجموعات', bodyHtml: body }));
});

async function handleGroupSave(req, res, idForce) {
  if (!can(req.session.user, 'groups')) return forbid(res);
  const body = { ...(req.body || {}), id: idForce != null ? idForce : req.body?.id };
  const result = await svc.saveGroup(body);
  if (!result.ok) {
    const id = Number(body.id || 0);
    return res.redirect(
      '/system/groups?err=' + encodeURIComponent(result.error) + (id > 0 ? '&id=' + id : '&id=new')
    );
  }
  res.redirect('/system/groups?id=' + result.id + '&msg=' + encodeURIComponent(result.message));
}

router.post('/system/groups/new', (req, res) => handleGroupSave(req, res, 0));
router.post('/system/groups/:id', (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  return handleGroupSave(req, res, id);
});

/* ═══════════ PERMISSIONS ═══════════ */
router.get('/system/permissions', async (req, res) => {
  if (!can(req.session.user, 'permissions')) return forbid(res);
  const groupId = Number(req.query.group_id || 0) || 0;
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  let matrix = await svc.listPermissionsMatrix(groupId);
  const groups = matrix.groups;
  const gid = groupId > 0 ? groupId : groups[0] ? Number(groups[0].id) : 0;
  if (gid !== groupId) matrix = await svc.listPermissionsMatrix(gid);
  const { allowed, isMobile, panels, treeByDomain } = matrix;

  const opts = groups
    .map(
      (g) =>
        `<option value="${g.id}" ${gid === Number(g.id) ? 'selected' : ''}>${esc(g.name_ar)} (${esc(
          g.code
        )})</option>`
    )
    .join('');

  /** خيارات «اسم القائمة»: مجال كامل أو قسم فرعي */
  const menuFilterOpts = [];
  menuFilterOpts.push({ value: '', label: 'كل القوائم' });
  for (const domId of Object.keys(treeByDomain || {})) {
    const dom = treeByDomain[domId];
    const domTitle = String(dom.title || domId);
    menuFilterOpts.push({ value: 'domain:' + domId, label: '▸ ' + domTitle });
    for (const n of dom.nodes || []) {
      menuFilterOpts.push({
        value: 'panel:' + n.id,
        label: '  · ' + domTitle + ' / ' + n.title + ' (' + n.count + ')',
      });
    }
  }
  const menuOptsHtml = menuFilterOpts
    .map((o) => `<option value="${esc(o.value)}">${esc(o.label)}</option>`)
    .join('');

  let totalScreens = 0;
  let allowedCount = 0;
  const rowsHtml = [];
  for (const panel of panels) {
    const domainId = String(panel.domainId || '');
    const domainTitle = String(panel.domainTitle || '');
    const panelId = String(panel.id || '');
    const panelTitle = String(panel.title || '');
    const menuLabel = domainTitle + (panelTitle ? ' / ' + panelTitle : '');
    for (const it of panel.items) {
      totalScreens += 1;
      const sid = Number(it.id);
      if (allowed.has(sid)) allowedCount += 1;
      const searchKey = (
        it.label +
        ' ' +
        it.code +
        ' ' +
        it.typeLabel +
        ' ' +
        menuLabel +
        ' ' +
        domainTitle +
        ' ' +
        panelTitle
      ).toLowerCase();
      rowsHtml.push(`<label class="perm-row" data-domain="${esc(domainId)}" data-panel="${esc(panelId)}"
        data-kind="${esc(it.filterKind || 'screen')}" data-search="${esc(searchKey)}"
        style="display:flex;gap:.45rem;align-items:flex-start;padding:.4rem .25rem;border-bottom:1px solid #eef1f6">
        <input type="checkbox" name="screens" value="${sid}" ${allowed.has(sid) ? 'checked' : ''}>
        <span style="flex:1;min-width:0">
          <strong>${esc(it.label)}</strong>
          <span class="muted" dir="ltr" style="font-size:.78rem"> · ${esc(it.typeLabel || 'شاشة')} · ${esc(
            it.code
          )}</span>
          <br><span class="muted" style="font-size:.75rem">القائمة: ${esc(menuLabel)}</span>
        </span>
      </label>`);
    }
  }

  const body = `
    <style>
      .perm-row[hidden]{display:none!important}
      .perm-type-filters label{margin-inline-start:.5rem;font-size:.82rem;cursor:pointer}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Pm',
        kicker: KICKER,
        title: 'الصلاحيات',
        subtitle: 'صلاحيات الشاشات والمجموعات — فلترة باسم القائمة',
        actions: [
          { label: 'المجموعات', href: '/system/groups' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <form method="get" action="/system/permissions" class="si-rail"
            style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
        <label style="font-weight:700;font-size:.85rem">المجموعة
          <select name="group_id" class="si-field" style="min-height:2.1rem;min-width:14rem;display:block"
                  onchange="this.form.submit()">
            ${opts}
          </select>
        </label>
        <span class="muted" style="font-size:.85rem;padding-bottom:.35rem" id="perm-count-label">
          ${allowedCount} / ${totalScreens} مسموح
        </span>
        ${
          isMobile
            ? '<span class="si-pill" style="font-size:.78rem">مجموعة هاتف: شاشات التطبيق فقط</span>'
            : ''
        }
      </form>
      ${
        gid
          ? `<form method="post" action="/system/permissions" class="si-surface" id="permissions-form"
               style="padding:1rem 1.1rem">
          <input type="hidden" name="group_id" value="${gid}">
          <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.75rem">
            <label style="font-weight:700;font-size:.85rem;flex:1;min-width:14rem">اسم القائمة
              <select id="perm-menu-filter" class="si-field" style="min-height:2.1rem;width:100%;display:block">
                ${menuOptsHtml}
              </select>
            </label>
            <label style="font-weight:700;font-size:.85rem;flex:1;min-width:12rem">بحث
              <input class="si-field" type="search" id="perm-screen-search" placeholder="اسم الشاشة أو الكود..."
                     style="min-height:2.1rem;width:100%;display:block" autocomplete="off">
            </label>
            <div class="perm-type-filters" style="padding-bottom:.35rem">
              <span class="muted" style="font-size:.82rem">النوع:</span>
              <label><input type="radio" name="perm_type_filter" value="all" checked> الكل</label>
              <label><input type="radio" name="perm_type_filter" value="screen"> شاشة</label>
              <label><input type="radio" name="perm_type_filter" value="report"> تقرير</label>
              <label><input type="radio" name="perm_type_filter" value="action"> إجراء</label>
            </div>
          </div>
          <div style="display:flex;gap:.5rem;margin-bottom:.65rem;flex-wrap:wrap;align-items:center">
            <button class="si-btn si-btn--primary" type="submit">حفظ الصلاحيات</button>
            <button class="si-btn" type="button" id="perm-select-all">تحديد الكل (الظاهر)</button>
            <button class="si-btn" type="button" id="perm-clear-all">إلغاء الكل (الظاهر)</button>
            <span class="muted" style="font-size:.85rem" id="perm-visible-label"></span>
          </div>
          <div id="perm-rows" style="max-height:62vh;overflow:auto">
            ${rowsHtml.join('') || '<p class="muted">لا شاشات</p>'}
          </div>
          <div id="perm-global-empty" class="muted" style="padding:.75rem;text-align:center" hidden>
            لا توجد نتائج مطابقة لاسم القائمة أو البحث.
          </div>
          <div style="margin-top:.75rem">
            <button class="si-btn si-btn--primary" type="submit">حفظ الصلاحيات</button>
          </div>
        </form>
        <script>
        (function(){
          var form=document.getElementById('permissions-form');
          if(!form) return;
          var menuSel=document.getElementById('perm-menu-filter');
          var search=document.getElementById('perm-screen-search');
          var emptyEl=document.getElementById('perm-global-empty');
          var visLabel=document.getElementById('perm-visible-label');
          var rows=[].slice.call(form.querySelectorAll('.perm-row'));
          function norm(v){return String(v||'').toLowerCase().trim();}
          function typeVal(){
            var c=form.querySelector('input[name="perm_type_filter"]:checked');
            return c?String(c.value||'all'):'all';
          }
          function applyFilter(){
            var menu=menuSel?String(menuSel.value||''):'';
            var term=norm(search&&search.value);
            var tf=typeVal();
            var vis=0;
            rows.forEach(function(row){
              var domain=row.getAttribute('data-domain')||'';
              var panel=row.getAttribute('data-panel')||'';
              var kind=row.getAttribute('data-kind')||'screen';
              var menuOk=true;
              if(menu.indexOf('domain:')===0) menuOk=domain===menu.slice(7);
              else if(menu.indexOf('panel:')===0) menuOk=panel===menu.slice(6);
              var typeOk=tf==='all'||kind===tf;
              var textOk=!term||norm(row.getAttribute('data-search')||row.textContent).indexOf(term)!==-1;
              var show=menuOk&&typeOk&&textOk;
              row.hidden=!show;
              if(show) vis++;
            });
            if(emptyEl) emptyEl.hidden=vis>0||rows.length===0;
            if(visLabel) visLabel.textContent='ظاهر: '+vis+' / '+rows.length;
          }
          function visCbs(){
            return rows.filter(function(r){return !r.hidden;})
              .map(function(r){return r.querySelector('input[type=checkbox]');})
              .filter(function(el){return el&&!el.disabled;});
          }
          if(menuSel) menuSel.addEventListener('change',applyFilter);
          if(search) search.addEventListener('input',applyFilter);
          [].forEach.call(form.querySelectorAll('input[name="perm_type_filter"]'),function(r){
            r.addEventListener('change',applyFilter);
          });
          var sa=document.getElementById('perm-select-all');
          var ca=document.getElementById('perm-clear-all');
          if(sa) sa.addEventListener('click',function(){ visCbs().forEach(function(c){c.checked=true;}); });
          if(ca) ca.addEventListener('click',function(){ visCbs().forEach(function(c){c.checked=false;}); });
          applyFilter();
        })();
        </script>`
          : '<p class="muted">لا توجد مجموعات.</p>'
      }
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'الصلاحيات', bodyHtml: body }));
});

router.post('/system/permissions', async (req, res) => {
  if (!can(req.session.user, 'permissions')) return forbid(res);
  const gid = Number(req.body.group_id || 0);
  const result = await svc.savePermissions(gid, req.body.screens);
  if (!result.ok) {
    return res.redirect(
      '/system/permissions?group_id=' + gid + '&err=' + encodeURIComponent(result.error)
    );
  }
  res.redirect(
    '/system/permissions?group_id=' + gid + '&msg=' + encodeURIComponent(result.message)
  );
});

/* ═══════════ SESSIONS ═══════════ */
router.get('/system/sessions', async (req, res) => {
  if (!can(req.session.user, 'open_sessions')) return forbid(res);
  const qv = String(req.query.q || '');
  const clientType = String(req.query.client_type || '');
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const rows = await svc.listActiveSessions({ q: qv, clientType });

  const rowsHtml =
    rows
      .map((r) => {
        const type = String(r.client_type || 'windows');
        const badge =
          type === 'mobile'
            ? '<span class="si-pill si-pill--live">📱 Mobile</span>'
            : '<span class="si-pill" style="background:#e8f1ff;color:#0b63ce">🖥 Windows</span>';
        return `<tr>
          <td><strong>${esc(r.full_name_ar || r.username || '')}</strong><br><span class="muted" style="font-size:.78rem">${esc(
            r.username || ''
          )}</span></td>
          <td>${badge}</td>
          <td>${dash(r.client_label)}</td>
          <td class="si-num" dir="ltr">${dash(r.ip_address)}</td>
          <td>${dash(r.location_text)}</td>
          <td class="si-num" dir="ltr">${dash(r.login_at)}</td>
          <td class="si-num" dir="ltr">${dash(r.last_seen_at)}</td>
          <td>
            <form method="post" action="/system/sessions/kill" style="margin:0" onsubmit="return confirm('إنهاء هذه الجلسة؟');">
              <input type="hidden" name="session_id" value="${r.id}">
              <button class="si-btn" type="submit" style="background:#c0392b;color:#fff;border-color:#c0392b">إنهاء</button>
            </form>
          </td>
        </tr>`;
      })
      .join('') || ui.emptyRow(8, 'لا توجد جلسات مفتوحة حالياً');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Se',
        kicker: KICKER,
        title: 'الجلسات المفتوحة',
        subtitle: 'جلسات Windows / Mobile النشطة — إنهاء أصلي على Node',
        actions: [
          { label: 'تحديث', href: '/system/sessions' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <div class="si-rail">
        <form class="si-search" method="get" action="/system/sessions" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
          <input type="search" name="q" value="${esc(qv)}" placeholder="مستخدم / IP / جهاز" style="flex:1;min-width:10rem">
          <select name="client_type" class="si-field" style="min-height:2.1rem;width:auto">
            <option value="">الكل</option>
            <option value="windows" ${clientType === 'windows' ? 'selected' : ''}>Windows</option>
            <option value="mobile" ${clientType === 'mobile' ? 'selected' : ''}>Mobile</option>
          </select>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
          <a class="si-btn" href="/system/sessions">تحديث</a>
        </form>
      </div>
      <p class="muted" style="font-size:.85rem">تُعرض الجلسات النشطة فقط (Windows خلال 30 دقيقة، Mobile خلال 3 دقائق). إنهاء الجلسة يفصل المستخدم عند الطلب التالي.</p>
      ${ui.tableSurface(
        `الجلسات النشطة`,
        `${rows.length}`,
        ['المستخدم', 'النوع', 'الجهاز', 'IP', 'المكان', 'دخول', 'آخر نشاط', ''],
        rowsHtml
      )}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'الجلسات المفتوحة', bodyHtml: body }));
});

router.post('/system/sessions/kill', async (req, res) => {
  if (!can(req.session.user, 'open_sessions')) return forbid(res);
  const result = await svc.killSession(req.body.session_id, req.session.user.id);
  res.redirect(
    '/system/sessions?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

/* ═══════════ SETTINGS ═══════════ */
router.get('/system/settings', async (req, res) => {
  if (!can(req.session.user, 'settings')) return forbid(res);
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const row = (await svc.getCompanySettings()) || {};
  const taxRates = await svc.listActiveTaxRates();
  const currentTax = Number(row.tax_rate_percent || 0);
  let selectedTaxId = 0;
  for (const t of taxRates) {
    if (Math.abs(Number(t.rate_percent) - currentTax) < 0.0005) {
      selectedTaxId = Number(t.id);
      break;
    }
  }
  if (!selectedTaxId && taxRates[0]) selectedTaxId = Number(taxRates[0].id);

  const taxOpts = taxRates.length
    ? taxRates
        .map((t) => {
          const pct = Number(t.rate_percent);
          const sel = Number(t.id) === selectedTaxId ? 'selected' : '';
          return `<option value="${t.id}" ${sel}>${esc(t.name_ar || '')} (${pct}%)</option>`;
        })
        .join('')
    : '';

  const rpp = Number(row.rows_per_page || 15);
  const rppOpts = [10, 15, 20]
    .map((n) => `<option value="${n}" ${rpp === n ? 'selected' : ''}>${n}</option>`)
    .join('');
  const curCode = String(row.currency_code || 'JOD').toUpperCase();
  const currencies = svc
    .currencyCatalogList()
    .map(
      (c) =>
        `<option value="${c.code}" ${curCode === c.code ? 'selected' : ''}>${esc(
          c.name_ar
        )} (${esc(c.symbol)})</option>`
    )
    .join('');

  const smtpSec = String(row.smtp_secure || 'tls').toLowerCase();
  const recaptchaOn = Number(row.login_recaptcha_enabled) === 1;
  const recaptchaLive =
    recaptchaOn &&
    String(row.login_recaptcha_site_key || '').trim() &&
    String(row.login_recaptcha_secret_key || '').trim();
  const checkOn = Number(row.check_email_enabled) === 1;
  const checkDays = Math.max(1, Math.min(60, Number(row.check_email_days_before || 5) || 5));
  const checkDueDay = row.check_email_on_due_day == null || Number(row.check_email_on_due_day) === 1;
  const outCheckOn = Number(row.out_check_email_enabled) === 1;
  const outCheckDays = Math.max(
    1,
    Math.min(60, Number(row.out_check_email_days_before || 5) || 5)
  );
  const outCheckDueDay =
    row.out_check_email_on_due_day == null || Number(row.out_check_email_on_due_day) === 1;
  const archiveRec = svc.archiveRecommendedDir();
  const archiveDir = String(row.document_archive_dir || '');
  const archiveMax = Math.max(1, Math.min(100, Number(row.document_archive_max_mb || 10) || 10));
  const logoUrl = row.logo_path
    ? '/hypex/' + String(row.logo_path).replace(/^\/+/, '')
    : '';

  const body = `
    <style>
      .ss-form{display:flex;flex-direction:column;gap:1rem}
      .ss-panel{background:var(--si-surface,#fff);border:1px solid var(--si-line,#e4e8f0);border-radius:14px;padding:1rem 1.2rem 1.25rem}
      .ss-panel h2{margin:0 0 .85rem;font-size:1.05rem;font-weight:800}
      .ss-panel h3{margin:1.1rem 0 .55rem;font-size:.92rem;font-weight:800;color:#3d4659}
      .ss-panel h3:first-of-type{margin-top:.25rem}
      .ss-hint{font-size:.8rem;font-weight:500;color:#6b7385;margin:.15rem 0 0;line-height:1.45}
      .ss-row{display:grid;gap:.75rem;margin-bottom:.75rem}
      .ss-row--2{grid-template-columns:1fr 1fr}
      .ss-row--3{grid-template-columns:1fr 1fr 1fr}
      .ss-row--4{grid-template-columns:repeat(4,minmax(0,1fr))}
      @media (max-width:1000px){.ss-row--4{grid-template-columns:1fr 1fr}}
      @media (max-width:720px){.ss-row--2,.ss-row--3,.ss-row--4{grid-template-columns:1fr}}
      .ss-field{display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;font-weight:700;color:#3d4659;min-width:0}
      .ss-field .si-field{width:100%}
      .ss-check{display:flex!important;flex-direction:row!important;align-items:center;gap:.45rem;font-weight:700;font-size:.88rem;margin:.4rem 0}
      .ss-check input{width:1.05rem;height:1.05rem}
      .ss-actions{display:flex;flex-wrap:wrap;gap:.5rem;position:sticky;bottom:.5rem;background:rgba(248,250,252,.92);backdrop-filter:blur(6px);padding:.65rem .2rem;border-radius:10px;z-index:2}
      .ss-logo{display:flex;align-items:center;gap:.75rem;margin-top:.35rem}
      .ss-logo img{max-height:56px;max-width:160px;object-fit:contain;border:1px solid #e4e8f0;border-radius:8px;padding:.25rem;background:#fff}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'St',
        kicker: KICKER,
        title: 'الإعدادات',
        subtitle: 'بيانات الشركة والبريد وواتساب والأمان وأرشيف السندات',
        actions: [
          { label: 'معدّلات الضريبة', href: '/system/tax-rates' },
          { label: 'الفاتورة الإلكترونية', href: '/system/einvoice' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <form method="post" action="/system/settings" enctype="multipart/form-data" class="ss-form">
        <section class="ss-panel">
          <h2>بيانات الشركة</h2>
          <h3>الهوية</h3>
          <div class="ss-row ss-row--2">
            <label class="ss-field">اسم الشركة *
              <input class="si-field" name="company_name_ar" required value="${esc(row.company_name_ar || '')}" autocomplete="organization">
            </label>
            <label class="ss-field">النسبة الافتراضية للضريبة
              ${
                taxOpts
                  ? `<select class="si-field" name="default_tax_rate_id" required>${taxOpts}</select>
                <span class="ss-hint"><a href="/system/tax-rates">لإضافة أو تعديل المعدّلات: معدّلات الضريبة</a></span>`
                  : `<input class="si-field" name="tax_rate_percent" type="number" step="0.001" min="0" max="100" value="${esc(
                      String(row.tax_rate_percent ?? '0')
                    )}">
                <span class="ss-hint">جدول المعدّلات غير متوفر — أدخل النسبة يدوياً</span>`
              }
            </label>
          </div>

          <h3>الخانات العشرية والطباعة</h3>
          <div class="ss-row ss-row--4">
            <label class="ss-field">خانات النظام
              <input class="si-field" type="number" min="0" max="6" name="decimal_places" value="${Number(row.decimal_places ?? 3)}">
              <span class="ss-hint">المبالغ على الشاشة والتقارير</span>
            </label>
            <label class="ss-field">سعر الوحدة (الفواتير)
              <input class="si-field" type="number" min="0" max="6" name="invoice_unit_price_decimal_places" value="${Number(row.invoice_unit_price_decimal_places ?? 3)}">
              <span class="ss-hint">عمود سعر الوحدة في البيع/الشراء</span>
            </label>
            <label class="ss-field">طباعة — المبالغ
              <input class="si-field" type="number" min="0" max="6" name="invoice_print_decimal_places" value="${Number(row.invoice_print_decimal_places ?? 3)}">
              <span class="ss-hint">عند طباعة الفاتورة فقط</span>
            </label>
            <label class="ss-field">طباعة — سعر الوحدة
              <input class="si-field" type="number" min="0" max="6" name="invoice_print_unit_price_decimal_places" value="${Number(row.invoice_print_unit_price_decimal_places ?? 3)}">
              <span class="ss-hint">سعر الوحدة في PDF/الطباعة</span>
            </label>
          </div>

          <h3>العرض واللغة</h3>
          <div class="ss-row ss-row--4">
            <label class="ss-field">أسطر الصفحة
              <select class="si-field" name="rows_per_page">${rppOpts}</select>
              <span class="ss-hint">قوائم العملاء والفواتير</span>
            </label>
            <label class="ss-field">واجهة النظام
              <select class="si-field" name="ui_theme">
                <option value="classic" ${row.ui_theme === 'classic' ? 'selected' : ''}>classic</option>
                <option value="basic" ${row.ui_theme !== 'classic' ? 'selected' : ''}>basic</option>
              </select>
              <span class="ss-hint">classic أو basic للنظام كاملاً</span>
            </label>
            <label class="ss-field">لغة النظام
              <select class="si-field" name="ui_lang">
                <option value="ar" ${row.ui_lang !== 'en' ? 'selected' : ''}>العربية</option>
                <option value="en" ${row.ui_lang === 'en' ? 'selected' : ''}>الإنجليزية</option>
              </select>
            </label>
            <label class="ss-field">العملة
              <select class="si-field" name="currency_code">${currencies}</select>
              <span class="ss-hint">للتفقيط في السندات والطباعة</span>
            </label>
          </div>

          <h3>التواصل والشعار</h3>
          <div class="ss-row" style="grid-template-columns:1fr">
            <label class="ss-field">العنوان
              <textarea class="si-field" name="address_ar" rows="2" style="min-height:4rem">${esc(row.address_ar || '')}</textarea>
            </label>
          </div>
          <div class="ss-row ss-row--2">
            <label class="ss-field">الهاتف
              <input class="si-field" name="phone" value="${esc(row.phone || '')}" dir="ltr">
            </label>
            <label class="ss-field">البريد
              <input class="si-field" name="email" type="email" value="${esc(row.email || '')}" dir="ltr">
            </label>
          </div>
          <label class="ss-field">شعار الشركة (PNG / JPG / WebP — حد 2 ميجا)
            <input class="si-field" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
          </label>
          ${
            logoUrl
              ? `<div class="ss-logo"><span class="ss-hint">الشعار الحالي:</span><img src="${esc(logoUrl)}" alt="logo"></div>`
              : ''
          }
        </section>

        <section class="ss-panel">
          <h2>إعدادات إرسال البريد (SMTP)</h2>
          <p class="ss-hint" style="margin:0 0 .75rem">لإرسال الفواتير والمرتجعات كـ PDF. لـ Gmail: <code>smtp.gmail.com</code> منفذ 587 مع TLS وكلمة مرور تطبيق.</p>
          <div class="ss-row ss-row--3">
            <label class="ss-field">خادم SMTP (Host)
              <input class="si-field" name="smtp_host" value="${esc(row.smtp_host || '')}" placeholder="smtp.gmail.com" dir="ltr">
            </label>
            <label class="ss-field">المنفذ
              <input class="si-field" name="smtp_port" type="number" min="1" max="65535" value="${Number(row.smtp_port || 587)}">
            </label>
            <label class="ss-field">التشفير
              <select class="si-field" name="smtp_secure">
                <option value="tls" ${smtpSec === 'tls' ? 'selected' : ''}>TLS (StartTLS)</option>
                <option value="ssl" ${smtpSec === 'ssl' ? 'selected' : ''}>SSL</option>
                <option value="none" ${smtpSec === 'none' ? 'selected' : ''}>بدون تشفير</option>
              </select>
            </label>
          </div>
          <div class="ss-row ss-row--2">
            <label class="ss-field">اسم المستخدم
              <input class="si-field" name="smtp_username" value="${esc(row.smtp_username || '')}" autocomplete="off" dir="ltr">
            </label>
            <label class="ss-field">كلمة المرور <span class="ss-hint" style="display:inline;font-weight:500">(فارغ = بدون تغيير)</span>
              <input class="si-field" name="smtp_password" type="password" value="" autocomplete="new-password" dir="ltr" placeholder="${row.smtp_password ? '•••••• محفوظة' : ''}">
            </label>
          </div>
          <div class="ss-row ss-row--2">
            <label class="ss-field">البريد المرسل منه (From)
              <input class="si-field" name="smtp_from_email" type="email" value="${esc(row.smtp_from_email || '')}" dir="ltr">
            </label>
            <label class="ss-field">اسم المرسل
              <input class="si-field" name="smtp_from_name" value="${esc(row.smtp_from_name || '')}" placeholder="${esc(row.company_name_ar || 'الشركة')}">
            </label>
          </div>
        </section>

        <section class="ss-panel">
          <h2>أمان تسجيل الدخول (Google reCAPTCHA)</h2>
          <p class="ss-hint" style="margin:0 0 .55rem">
            ${
              recaptchaLive
                ? '<strong style="color:#15803d">● reCAPTCHA مفعّل حالياً</strong>'
                : '<strong style="color:#b45309">● reCAPTCHA غير مفعّل</strong> — أدخل Site Key و Secret Key ثم احفظ'
            }
            — من <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">Google reCAPTCHA Admin</a> (v2 Checkbox). أضف النطاق <code>localhost</code>.
          </p>
          <label class="ss-check">
            <input type="checkbox" name="login_recaptcha_enabled" value="1" ${recaptchaOn ? 'checked' : ''}>
            <span>تفعيل reCAPTCHA على تسجيل الدخول واستعادة كلمة المرور</span>
          </label>
          <div class="ss-row ss-row--2">
            <label class="ss-field">Site Key
              <input class="si-field" name="login_recaptcha_site_key" dir="ltr" value="${esc(row.login_recaptcha_site_key || '')}" autocomplete="off" placeholder="6Lf...">
            </label>
            <label class="ss-field">Secret Key <span class="ss-hint" style="display:inline;font-weight:500">(فارغ = بدون تغيير)</span>
              <input class="si-field" name="login_recaptcha_secret_key" type="password" dir="ltr" value="" autocomplete="new-password" placeholder="${row.login_recaptcha_secret_key ? '•••••• محفوظ' : 'مطلوب أول مرة'}">
            </label>
          </div>
        </section>

        <section class="ss-panel">
          <h2>تنبيهات استحقاق الشيكات الواردة (بريد تلقائي)</h2>
          <p class="ss-hint" style="margin:0 0 .55rem">بريد يومي لكل شيك وارد ضمن نافذة الأيام قبل الاستحقاق. يتطلب SMTP ومستلماً واحداً على الأقل.</p>
          <label class="ss-check">
            <input type="checkbox" name="check_email_enabled" value="1" ${checkOn ? 'checked' : ''}>
            <span>تفعيل التنبيهات التلقائية للشيكات الواردة</span>
          </label>
          <div class="ss-row ss-row--2">
            <label class="ss-field">عدد الأيام قبل الاستحقاق
              <input class="si-field" name="check_email_days_before" type="number" min="1" max="60" value="${checkDays}">
              <span class="ss-hint">من 1 إلى 60</span>
            </label>
            <label class="ss-check" style="margin-top:1.5rem">
              <input type="checkbox" name="check_email_on_due_day" value="1" ${checkDueDay ? 'checked' : ''}>
              <span>إرسال تنبيه يوم الاستحقاق أيضاً</span>
            </label>
          </div>
          <label class="ss-field">بريد المستلمين (سطر لكل عنوان)
            <textarea class="si-field" name="check_email_recipients" rows="3" dir="ltr" style="min-height:4.5rem">${esc(row.check_email_recipients || '')}</textarea>
            <span class="ss-hint">إن تُرك فارغاً يُستخدم حقل «البريد» الرئيسي</span>
          </label>
        </section>

        <section class="ss-panel">
          <h2>تنبيهات استحقاق الشيكات الصادرة (بريد تلقائي)</h2>
          <p class="ss-hint" style="margin:0 0 .55rem">خاص بشيكات سندات الصرف المرحّلة — مستقل عن الواردة أعلاه.</p>
          <label class="ss-check">
            <input type="checkbox" name="out_check_email_enabled" value="1" ${outCheckOn ? 'checked' : ''}>
            <span>تفعيل التنبيهات التلقائية للشيكات الصادرة</span>
          </label>
          <div class="ss-row ss-row--2">
            <label class="ss-field">عدد الأيام قبل الاستحقاق
              <input class="si-field" name="out_check_email_days_before" type="number" min="1" max="60" value="${outCheckDays}">
            </label>
            <label class="ss-check" style="margin-top:1.5rem">
              <input type="checkbox" name="out_check_email_on_due_day" value="1" ${outCheckDueDay ? 'checked' : ''}>
              <span>إرسال تنبيه يوم الاستحقاق أيضاً</span>
            </label>
          </div>
          <label class="ss-field">بريد المستلمين (سطر لكل عنوان)
            <textarea class="si-field" name="out_check_email_recipients" rows="3" dir="ltr" style="min-height:4.5rem">${esc(row.out_check_email_recipients || '')}</textarea>
          </label>
        </section>

        <section class="ss-panel">
          <h2>أرشيف مرفقات السندات</h2>
          <p class="ss-hint" style="margin:0 0 .55rem">مجلد على الخادم لمرفقات سندات القبض والصرف والقيد (حسب اليوم / نوع السند / الرقم).</p>
          <div class="ss-row ss-row--2">
            <label class="ss-field">مسار مجلد الأرشيف
              <input class="si-field si-field--mono" name="document_archive_dir" id="document-archive-dir-input" dir="ltr" value="${esc(archiveDir)}" placeholder="${esc(archiveRec)}" autocomplete="off">
              <span class="ss-hint">المقترح: <code dir="ltr">${esc(archiveRec)}</code>
                <button type="button" class="si-btn" id="document-archive-use-recommended" style="margin-inline-start:.35rem;padding:.2rem .55rem;font-size:.78rem">استخدام المقترح</button>
              </span>
            </label>
            <label class="ss-field">الحد الأقصى لحجم الملف (ميجابايت)
              <input class="si-field" type="number" name="document_archive_max_mb" min="1" max="100" value="${archiveMax}">
              <span class="ss-hint">PDF، Word، JPG، PNG — من 1 إلى 100</span>
            </label>
          </div>
        </section>

        <section class="ss-panel">
          <h2>إعدادات WhatsApp</h2>
          <p class="ss-hint" style="margin:0 0 .55rem">Cloud API الرسمي — من <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">Meta for Developers</a>.</p>
          <div class="ss-row ss-row--2">
            <label class="ss-field">Phone Number ID
              <input class="si-field" name="wa_phone_id" value="${esc(row.wa_phone_id || '')}" dir="ltr" autocomplete="off">
            </label>
            <label class="ss-field">إصدار API
              <input class="si-field" name="wa_api_version" value="${esc(row.wa_api_version || 'v20.0')}" dir="ltr" placeholder="v20.0">
            </label>
          </div>
          <div class="ss-row ss-row--2">
            <label class="ss-field">Access Token <span class="ss-hint" style="display:inline;font-weight:500">(فارغ = بدون تغيير)</span>
              <input class="si-field" name="wa_access_token" type="password" value="" autocomplete="new-password" dir="ltr" placeholder="${row.wa_access_token ? '•••••• محفوظ' : 'EAAJ...'}">
            </label>
            <label class="ss-field">كود الدولة الافتراضي
              <input class="si-field" name="wa_default_country" value="${esc(row.wa_default_country || '')}" dir="ltr" maxlength="5" placeholder="962">
              <span class="ss-hint">يُستخدم عند رقم محلي يبدأ بـ 0</span>
            </label>
          </div>
        </section>

        <div class="ss-actions">
          <button class="si-btn si-btn--primary" type="submit">حفظ الإعدادات</button>
          <a class="si-btn" href="/system/settings">إلغاء</a>
        </div>
      </form>
    </div>
    <script>
      document.getElementById('document-archive-use-recommended')?.addEventListener('click', function () {
        var input = document.getElementById('document-archive-dir-input');
        if (input) { input.value = ${JSON.stringify(archiveRec)}; input.focus(); }
      });
    </script>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'الإعدادات', bodyHtml: body }));
});

router.post('/system/settings', (req, res) => {
  if (!can(req.session.user, 'settings')) return forbid(res);

  const finish = async (uploadErr) => {
    if (uploadErr) {
      return res.redirect(
        '/system/settings?err=' +
          encodeURIComponent('تعذر رفع الشعار: ' + (uploadErr.message || String(uploadErr)))
      );
    }
    try {
      const body = req.body && typeof req.body === 'object' ? req.body : {};
      const logoFile = req.file
        ? { buffer: req.file.buffer, mimetype: req.file.mimetype, size: req.file.size }
        : null;
      if (!Object.keys(body).length) {
        console.warn('settings POST: empty body, content-type=', req.headers['content-type']);
      }
      const result = await svc.saveCompanySettings(body, logoFile);
      res.redirect(
        '/system/settings?' +
          (result.ok ? 'msg=' : 'err=') +
          encodeURIComponent(result.message || result.error || '')
      );
    } catch (e) {
      console.error('settings save', e);
      res.redirect('/system/settings?err=' + encodeURIComponent(e.message || 'خطأ غير متوقع'));
    }
  };

  const ct = String(req.headers['content-type'] || '');
  if (ct.includes('multipart/form-data')) {
    logoUpload.single('logo')(req, res, finish);
  } else {
    // النموذج بدون ملفات / إن وصل urlencoded
    finish(null);
  }
});

/* ═══════════ DASHBOARD ACCOUNTS ═══════════ */
router.get('/system/dashboard-accounts', async (req, res) => {
  if (!can(req.session.user, 'dashboard_accounts_settings') && !can(req.session.user, 'settings')) {
    return forbid(res);
  }
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const rows = await svc.listDashboardAccountsFull();
  const visible = rows.filter((r) => Number(r.is_visible) === 1).length;
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td><input type="checkbox" name="visible" value="${r.id}" ${
          Number(r.is_visible) === 1 ? 'checked' : ''
        }></td>
      <td class="si-num" dir="ltr"><code>${esc(r.code || '')}</code></td>
      <td>${esc(r.name_ar || '')}</td>
      <td>${esc(svc.ACCOUNT_TYPE_AR[r.account_type] || r.account_type || '')}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(4, 'لا توجد حسابات نهائية نشطة');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Da',
        kicker: KICKER,
        title: 'حسابات الشاشة الرئيسية',
        subtitle: `${visible} / ${rows.length} ظاهر — اختيار أصلي على Node`,
        actions: [
          { label: 'لوحة التحكم', href: '/app' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <form method="post" action="/system/dashboard-accounts" class="si-surface" style="padding:1rem 1.1rem">
        <h2 style="margin:0 0 .35rem;font-size:1.05rem">اختيار الحسابات للوحة التحكم</h2>
        <p class="muted" style="font-size:.85rem;margin:0 0 .75rem">
          حدّد الحسابات التي تظهر في لوحة التحكم (الصندوق / الذمم). أي حساب نهائي جديد يظهر هنا تلقائياً.
        </p>
        <div style="margin-bottom:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="si-btn si-btn--primary" type="submit">حفظ</button>
          <button class="si-btn" type="button" onclick="document.querySelectorAll('input[name=visible]').forEach(c=>c.checked=true)">تحديد الكل</button>
          <button class="si-btn" type="button" onclick="document.querySelectorAll('input[name=visible]').forEach(c=>c.checked=false)">إلغاء الكل</button>
        </div>
        <div style="max-height:65vh;overflow:auto">
          <table class="si-table">
            <thead><tr><th>إظهار</th><th>الرقم</th><th>اسم الحساب</th><th>النوع</th></tr></thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
        <div style="margin-top:.75rem"><button class="si-btn si-btn--primary" type="submit">حفظ</button></div>
      </form>
    </div>`;
  res.send(
    ui.salesPage({ user: req.session.user, title: 'حسابات الشاشة الرئيسية', bodyHtml: body })
  );
});

router.post('/system/dashboard-accounts', async (req, res) => {
  if (!can(req.session.user, 'dashboard_accounts_settings') && !can(req.session.user, 'settings')) {
    return forbid(res);
  }
  const result = await svc.saveDashboardVisibility(req.body.visible);
  res.redirect(
    '/system/dashboard-accounts?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

/* ═══════════ TAX RATES ═══════════ */
router.get('/system/tax-rates', async (req, res) => {
  if (!can(req.session.user, 'tax_rates_settings')) return forbid(res);
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const rows = await svc.listTaxRates();

  const editRows = rows
    .map(
      (r) => `<tr>
      <td><input class="si-field" name="rates[${r.id}][name_ar]" value="${esc(r.name_ar || '')}" required></td>
      <td><input class="si-field si-field--mono" type="number" step="0.001" min="0" max="100" name="rates[${r.id}][rate_percent]" value="${Number(
        r.rate_percent
      )}" required></td>
      <td><input class="si-field si-field--mono" type="number" name="rates[${r.id}][sort_order]" value="${Number(
        r.sort_order || 0
      )}"></td>
      <td style="text-align:center"><input type="checkbox" name="rates[${r.id}][is_active]" value="1" ${
        Number(r.is_active) === 1 ? 'checked' : ''
      }></td>
      <td>
        <button class="si-btn" type="submit" formaction="/system/tax-rates/delete" name="id" value="${r.id}"
          onclick="return confirm('حذف هذا المعدّل؟');"
          style="background:#c0392b;color:#fff;border-color:#c0392b;min-height:1.8rem;font-size:.8rem">حذف</button>
      </td>
    </tr>`
    )
    .join('');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: '%',
        kicker: KICKER,
        title: 'معدّلات الضريبة',
        subtitle: 'تظهر في فواتير البيع/الشراء — إدارة أصلية على Node',
        actions: [
          { label: 'الإعدادات', href: '/system/settings' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <p class="muted" style="font-size:.85rem">النسبة الافتراضية عند إضافة بند تُضبط من <a href="/system/settings">الإعدادات العامة</a>.</p>
      <section class="si-surface" style="padding:1rem 1.1rem;margin-bottom:.85rem">
        <h2 style="margin:0 0 .65rem;font-size:1rem">إضافة معدّل جديد</h2>
        <form method="post" action="/system/tax-rates/add" class="si-meta" style="align-items:end">
          <label>الاسم (عربي)
            <input class="si-field" name="name_ar" required maxlength="100" placeholder="ضريبة مخفّضة">
          </label>
          <label>النسبة %
            <input class="si-field" type="number" step="0.001" min="0" max="100" name="rate_percent" value="0" required>
          </label>
          <label>ترتيب
            <input class="si-field" type="number" name="sort_order" value="10">
          </label>
          <button class="si-btn si-btn--primary" type="submit">إضافة</button>
        </form>
      </section>
      <form method="post" action="/system/tax-rates/save" class="si-surface" style="padding:1rem 1.1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.65rem;flex-wrap:wrap;gap:.5rem">
          <h2 style="margin:0;font-size:1rem">المعدّلات الحالية</h2>
          <button class="si-btn si-btn--primary" type="submit">حفظ</button>
        </div>
        <div style="overflow:auto">
          <table class="si-table">
            <thead><tr><th>الاسم</th><th>النسبة %</th><th>ترتيب العرض</th><th>نشط</th><th>إجراءات</th></tr></thead>
            <tbody>${editRows || ui.emptyRow(5)}</tbody>
          </table>
        </div>
        <div style="margin-top:.75rem"><button class="si-btn si-btn--primary" type="submit">حفظ</button></div>
      </form>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'معدّلات الضريبة', bodyHtml: body }));
});

router.post('/system/tax-rates/add', async (req, res) => {
  if (!can(req.session.user, 'tax_rates_settings')) return forbid(res);
  const result = await svc.addTaxRate(req.body || {});
  res.redirect(
    '/system/tax-rates?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/system/tax-rates/save', async (req, res) => {
  if (!can(req.session.user, 'tax_rates_settings')) return forbid(res);
  // nested rates[id][field] with extended:false may not parse deep — handle flat
  let rates = req.body.rates;
  if (!rates || typeof rates !== 'object') {
    rates = {};
    for (const [k, v] of Object.entries(req.body || {})) {
      const m = /^rates\[(\d+)\]\[(\w+)\]$/.exec(k);
      if (m) {
        rates[m[1]] = rates[m[1]] || {};
        rates[m[1]][m[2]] = v;
      }
    }
  }
  const result = await svc.saveTaxRatesAll(rates);
  res.redirect(
    '/system/tax-rates?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/system/tax-rates/delete', async (req, res) => {
  if (!can(req.session.user, 'tax_rates_settings')) return forbid(res);
  const result = await svc.deleteTaxRate(req.body.id);
  res.redirect(
    '/system/tax-rates?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

/* ═══════════ BACKUP ═══════════ */
router.get('/system/backup', async (req, res) => {
  if (!can(req.session.user, 'system_backup')) return forbid(res);
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const settings = await svc.getBackupSettings();
  const recommended = svc.backupRecommendedDir();
  const backupDir = String(settings.backup_dir || '');
  const recent = svc.listRecentBackups(settings);
  const today = svc.todayFolderName();
  const isWin = process.platform === 'win32';

  const recentHtml = recent.length
    ? `<ul style="margin:.5rem 0;padding-inline-start:1.2rem">${recent
        .map(
          (r) =>
            `<li><code dir="ltr">${esc(r.name)}</code> <span class="muted" dir="ltr" style="font-size:.8rem">${esc(
              r.path
            )}</span></li>`
        )
        .join('')}</ul>`
    : '<p class="muted">لا توجد نسخ محفوظة بعد.</p>';

  const body = `
    <style>
      .bk-panel{background:var(--si-surface,#fff);border:1px solid var(--si-line,#e4e8f0);border-radius:14px;padding:1rem 1.2rem 1.25rem;margin-bottom:.85rem}
      .bk-panel h2{margin:0 0 .5rem;font-size:1.05rem;font-weight:800}
      .bk-hint{font-size:.85rem;color:#6b7385;margin:0 0 .85rem;line-height:1.5}
      .bk-field{display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;font-weight:700;color:#3d4659;min-width:0}
      .bk-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-top:.75rem}
      .bk-actions .si-btn{min-height:2.4rem;padding:.45rem .95rem;width:auto;align-self:center}
      .bk-list{margin:.35rem 0 .85rem;padding-inline-start:1.2rem;font-size:.88rem;color:#6b7385;line-height:1.55}
      .bk-recent ul{margin:.5rem 0;padding-inline-start:1.2rem}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Bk',
        kicker: KICKER,
        title: 'النسخ الاحتياطي',
        subtitle: 'مجلد الحفظ + قاعدة البيانات + ملفات النظام',
        actions: [{ label: 'لوحة النظام', href: HUB }],
      })}
      ${flashHtml(flash, err)}
      <section class="bk-panel">
        <h2>مجلد حفظ النسخ</h2>
        <p class="bk-hint">
          الخادم الحالي: <strong dir="ltr">${isWin ? 'Windows' : process.platform}</strong>.
          كل نسخة تُنشأ في مجلد باسم تاريخ اليوم (مثل: <code dir="ltr">${esc(today)}</code>).
        </p>
        <form method="post" action="/system/backup/save-dir">
          <label class="bk-field">مسار مجلد النسخ الاحتياطي
            <input class="si-field si-field--mono" name="backup_dir" required dir="ltr"
              value="${esc(backupDir || recommended)}" placeholder="${esc(recommended)}">
          </label>
          <p class="bk-hint" style="margin:.45rem 0 0">المسار المقترح: <code dir="ltr">${esc(recommended)}</code></p>
          <div class="bk-actions">
            <button class="si-btn si-btn--primary" type="submit">حفظ المسار</button>
            <button class="si-btn" type="submit" formaction="/system/backup/use-recommended" formmethod="post">استخدام المسار المقترح</button>
          </div>
        </form>
      </section>
      <section class="bk-panel">
        <h2>أخذ نسخة احتياطية</h2>
        <ul class="bk-list">
          <li>نسخة من قاعدة البيانات (ملف <code dir="ltr">database.sql</code>)</li>
          <li>نسخة من ملفات النظام (ملف <code dir="ltr">system_files.zip</code>)</li>
        </ul>
        ${
          settings.last_backup_at
            ? `<p style="font-size:.88rem;margin:0 0 .75rem"><strong>آخر نسخة:</strong> <span dir="ltr">${esc(
                String(settings.last_backup_at)
              )}</span><br><span class="muted" dir="ltr" style="font-size:.8rem">${esc(
                String(settings.last_backup_path || '')
              )}</span></p>`
            : ''
        }
        <form method="post" action="/system/backup/run" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='جاري الإنشاء…'">
          <div class="bk-actions" style="margin-top:0">
            <button class="si-btn si-btn--primary" type="submit">أخذ نسخة احتياطية الآن</button>
          </div>
        </form>
        ${
          backupDir
            ? `<p class="bk-hint" style="margin-top:.85rem;margin-bottom:0">المسار: <code dir="ltr">${esc(
                backupDir
              )}</code><br>مجلد اليوم: <code dir="ltr">${esc(
                require('path').join(backupDir, today)
              )}</code></p>`
            : '<p class="bk-hint" style="margin-top:.85rem;margin-bottom:0">احفظ مسار المجلد أولاً.</p>'
        }
      </section>
      <section class="bk-panel bk-recent">
        <h2>النسخ الحديثة على الخادم</h2>
        ${recentHtml}
      </section>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'النسخ الاحتياطي', bodyHtml: body }));
});

router.post('/system/backup/save-dir', async (req, res) => {
  if (!can(req.session.user, 'system_backup')) return forbid(res);
  const result = await svc.saveBackupDir(req.body.backup_dir, req.session.user.id);
  res.redirect(
    '/system/backup?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/system/backup/use-recommended', async (req, res) => {
  if (!can(req.session.user, 'system_backup')) return forbid(res);
  const result = await svc.saveBackupDir(svc.backupRecommendedDir(), req.session.user.id);
  res.redirect(
    '/system/backup?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(
        result.ok ? 'تم حفظ المسار المقترح: ' + result.path : result.error || ''
      )
  );
});

router.post('/system/backup/run', async (req, res) => {
  if (!can(req.session.user, 'system_backup')) return forbid(res);
  const result = await svc.runBackup(req.session.user.id);
  res.redirect(
    '/system/backup?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

/* ═══════════ E-INVOICE / JoFotara ═══════════ */
function optsHtml(map, selected) {
  const sel = String(selected || '');
  let html = Object.entries(map)
    .map(
      ([v, lab]) =>
        `<option value="${esc(v)}" ${sel === String(v) ? 'selected' : ''}>${esc(lab)}</option>`
    )
    .join('');
  if (sel && !Object.prototype.hasOwnProperty.call(map, sel)) {
    html += `<option value="${esc(sel)}" selected>قيمة محفوظة: ${esc(sel)}</option>`;
  }
  return html;
}

router.get('/system/einvoice', async (req, res) => {
  if (!can(req.session.user, 'einvoice_settings')) return forbid(res);
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const action = String(req.query.action || '');
  const row = (await svc.getEinvoiceSettings()) || {};

  let actionBanner = '';
  if (action === 'test') {
    const t = await svc.testEinvoiceConnection();
    const cls = t.level === 'success' ? 'si-pill--ok' : 'si-pill--lock';
    actionBanner = `<div class="ei-banner">
      <p class="si-pill ${cls}" style="display:inline-block;margin:0 0 .5rem">${esc(t.title)}</p>
      <p style="margin:.25rem 0">${esc(t.message)}</p>
      <p class="ei-hint" style="margin:0">HTTP: <code dir="ltr">${t.http_code}</code> · URL: <code dir="ltr">${esc(
        t.url
      )}</code></p>
      ${
        t.raw
          ? `<details style="margin-top:.5rem"><summary class="ei-hint" style="cursor:pointer">عرض رد الخادم</summary><pre dir="ltr">${esc(
              t.raw
            )}</pre></details>`
          : ''
      }
    </div>`;
  } else if (action === 'verify') {
    const v = await svc.verifyEinvoiceCredentials();
    const tr = v.rows
      .map(
        (r) =>
          `<tr><td>${esc(r.src)}</td><td class="si-num" dir="ltr">${esc(r.client)}</td><td class="si-num" dir="ltr">${esc(
            r.secret
          )}</td></tr>`
      )
      .join('');
    actionBanner = `<div class="ei-banner">
      <h2 style="margin:0 0 .5rem;font-size:1rem">مقارنة الاعتماد المحلية</h2>
      <div class="si-table-wrap">
        <table class="si-table"><thead><tr><th>المصدر</th><th>Client ID</th><th>Secret Key</th></tr></thead>
        <tbody>${tr}</tbody></table>
      </div>
      <p class="ei-hint" style="margin:.5rem 0 0">${esc(v.matchNote)}</p>
      <p style="margin:.65rem 0 0"><a class="si-btn" href="/system/einvoice">العودة للنموذج</a></p>
    </div>`;
  }

  const apiUrl = String(row.jofotara_api_url || svc.DEFAULT_JOFOTARA_URL);

  const body = `
    <style>
      .ei-form{display:flex;flex-direction:column;gap:1rem}
      .ei-panel{background:var(--si-surface,#fff);border:1px solid var(--si-line,#e4e8f0);border-radius:14px;padding:1rem 1.2rem 1.25rem}
      .ei-panel h2{margin:0 0 .35rem;font-size:1.05rem;font-weight:800}
      .ei-hint{font-size:.82rem;font-weight:500;color:#6b7385;margin:0 0 .85rem;line-height:1.45}
      .ei-hint.inline{margin:.2rem 0 0;font-size:.75rem}
      .ei-row{display:grid;gap:.75rem;margin-bottom:.75rem}
      .ei-row--2{grid-template-columns:1fr 1fr}
      .ei-row--3{grid-template-columns:1fr 1fr 1fr}
      @media (max-width:900px){.ei-row--3{grid-template-columns:1fr 1fr}}
      @media (max-width:640px){.ei-row--2,.ei-row--3{grid-template-columns:1fr}}
      .ei-field{display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;font-weight:700;color:#3d4659;min-width:0}
      .ei-field .si-field{width:100%}
      .ei-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-bottom:.25rem}
      .ei-toolbar .si-btn,
      .ei-actions .si-btn{min-height:2.4rem;padding:.45rem .95rem;width:auto;font-size:.88rem;line-height:1.2}
      .ei-toolbar form{margin:0;display:inline-flex}
      .ei-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-top:.15rem}
      .ei-banner{background:var(--si-surface,#fff);border:1px solid var(--si-line,#e4e8f0);border-radius:14px;padding:1rem 1.15rem;margin-bottom:.25rem}
      .ei-banner pre{white-space:pre-wrap;font-size:.78rem;margin:.35rem 0 0}
    </style>
    <div class="si-stage">
      ${ui.hero({
        mark: 'Ei',
        kicker: KICKER,
        title: 'إعدادات الفوترة',
        subtitle: 'بيانات البائع واعتماد JoFotara للربط مع نظام الفوترة الأردني',
        actions: [
          { label: 'الإعدادات العامة', href: '/system/settings' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <div class="ei-toolbar">
        <a class="si-btn si-btn--primary" href="/system/einvoice?action=test">اختبار الاتصال</a>
        <form method="post" action="/system/einvoice/import-admin" onsubmit="return confirm('استيراد من admin؟');">
          <button class="si-btn" type="submit">استيراد admin</button>
        </form>
        <form method="post" action="/system/einvoice/copy-galaxy" onsubmit="return confirm('نسخ من Galaxy؟');">
          <button class="si-btn" type="submit">نسخ Galaxy</button>
        </form>
        <a class="si-btn" href="/system/einvoice?action=verify">مقارنة محلية</a>
      </div>
      ${actionBanner}
      <form method="post" action="/system/einvoice" class="ei-form">
        <section class="ei-panel">
          <h2>معلومات الشركة (البائع)</h2>
          <p class="ei-hint">تأكد من صحة البيانات عند الإرسال إلى نظام الفوترة الأردني.</p>
          <div class="ei-row ei-row--2">
            <label class="ei-field">اسم الشركة *
              <input class="si-field" name="company_name" required value="${esc(row.company_name || '')}">
            </label>
            <label class="ei-field">الاسم التجاري
              <input class="si-field" name="trade_name" value="${esc(row.trade_name || '')}">
            </label>
          </div>
          <div class="ei-row ei-row--2">
            <label class="ei-field">الرقم الضريبي (VAT) *
              <input class="si-field" name="vat_no" required value="${esc(row.vat_no || '')}" dir="ltr">
              <span class="ei-hint inline">بيانات المورد (Supplier Party)</span>
            </label>
            <label class="ei-field">رقم GST *
              <input class="si-field" name="gst_no" required value="${esc(row.gst_no || '')}" dir="ltr">
              <span class="ei-hint inline">بيانات البائع (Seller Party)</span>
            </label>
          </div>
          <div class="ei-row ei-row--2">
            <label class="ei-field">البريد الإلكتروني
              <input class="si-field" type="email" name="company_email" value="${esc(row.company_email || '')}" dir="ltr">
            </label>
            <label class="ei-field">الهاتف
              <input class="si-field" name="company_phone" value="${esc(row.company_phone || '')}" dir="ltr">
            </label>
          </div>
          <div class="ei-row ei-row--2">
            <label class="ei-field">المدينة
              <input class="si-field" name="city" value="${esc(row.city || '')}">
            </label>
            <label class="ei-field">العنوان
              <textarea class="si-field" name="address" rows="2" style="min-height:3.5rem">${esc(
                row.address || ''
              )}</textarea>
            </label>
          </div>
        </section>

        <section class="ei-panel">
          <h2>أنواع الفواتير</h2>
          <div class="ei-row ei-row--3">
            <label class="ei-field">نوع الضريبة
              <select class="si-field" name="taxes_type">
                <option value="1" ${Number(row.taxes_type) === 1 ? 'selected' : ''}>فاتورة دخل</option>
                <option value="2" ${Number(row.taxes_type) !== 1 ? 'selected' : ''}>فاتورة مبيعات (ضريبة)</option>
              </select>
            </label>
            <label class="ei-field">كود فاتورة نقدية *
              <select class="si-field" name="invoice_cash" required>${optsHtml(
                svc.INVOICE_CASH_OPTS,
                row.invoice_cash || '011'
              )}</select>
            </label>
            <label class="ei-field">كود فاتورة آجلة *
              <select class="si-field" name="invoice_debit" required>${optsHtml(
                svc.INVOICE_DEBIT_OPTS,
                row.invoice_debit || '021'
              )}</select>
            </label>
          </div>
        </section>

        <section class="ei-panel">
          <h2>الربط مع نظام الفوترة الأردني</h2>
          <p class="ei-hint">بيانات الاعتماد من بوابة الفوترة. admin/Galaxy عبر env اختياري (<code dir="ltr">EINV_*</code>).</p>
          <div class="ei-row ei-row--2">
            <label class="ei-field">Client ID *
              <input class="si-field si-field--mono" name="client_id" required value="${esc(
                row.client_id || ''
              )}" dir="ltr" autocomplete="off">
            </label>
            <label class="ei-field">Secret Key *
              <input class="si-field si-field--mono" name="secret_key" required value="${esc(
                row.secret_key || ''
              )}" dir="ltr" autocomplete="off">
            </label>
          </div>
          <div class="ei-row ei-row--2">
            <label class="ei-field">بريد المسؤول
              <input class="si-field" type="email" name="admin_email" value="${esc(
                row.admin_email || ''
              )}" dir="ltr" placeholder="example@domain.com">
            </label>
            <label class="ei-field">رابط API
              <input class="si-field si-field--mono" type="url" name="jofotara_api_url" value="${esc(
                apiUrl
              )}" dir="ltr">
              <span class="ei-hint inline">POST — JSON + Base64 XML — Headers: Client-Id, Secret-Key</span>
            </label>
          </div>
          <div class="ei-row" style="grid-template-columns:1fr;margin-bottom:0">
            <label class="ei-field">ملاحظات داخلية
              <textarea class="si-field" name="notes" rows="2" style="min-height:3.5rem">${esc(
                row.notes || ''
              )}</textarea>
            </label>
          </div>
        </section>

        <div class="ei-actions">
          <button class="si-btn si-btn--primary" type="submit">حفظ إعدادات الفوترة</button>
          <a class="si-btn" href="/system/settings">الإعدادات العامة</a>
        </div>
      </form>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'إعدادات الفوترة', bodyHtml: body }));
});

router.post('/system/einvoice', async (req, res) => {
  if (!can(req.session.user, 'einvoice_settings')) return forbid(res);
  const result = await svc.saveEinvoiceSettings(req.body || {});
  res.redirect(
    '/system/einvoice?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/system/einvoice/import-admin', async (req, res) => {
  if (!can(req.session.user, 'einvoice_settings')) return forbid(res);
  const result = await svc.importEinvoiceFromAdmin();
  res.redirect(
    '/system/einvoice?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/system/einvoice/copy-galaxy', async (req, res) => {
  if (!can(req.session.user, 'einvoice_settings')) return forbid(res);
  const result = await svc.copyEinvoiceFromGalaxy();
  res.redirect(
    '/system/einvoice?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

module.exports = router;
