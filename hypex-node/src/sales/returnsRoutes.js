'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./returnsService');
const { renderApp, embedUrl } = require('../lib/layout');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');

const router = express.Router();

function canAccess(user) {
  return (
    auth.userCan(user, 'sales_returns') ||
    auth.userCan(user, 'sales_returns_list') ||
    auth.userCan(user, 'sales_returns_documents_list') ||
    user.is_admin
  );
}
function canAction(user, code) {
  return user.is_admin || auth.userCan(user, code);
}
function forbid(res, api) {
  if (api) return res.status(403).json({ ok: false, error: 'ممنوع' });
  return res.status(403).send('ممنوع');
}

router.use((req, res, next) => {
  const p = req.path || '';
  const ok =
    p.startsWith('/sales/returns') ||
    p.startsWith('/api/sales/returns') ||
    p.startsWith('/api/sales/return-invoices') ||
    p.startsWith('/api/sales/return-lines');
  if (!ok) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    if (!canAccess(req.session.user)) return forbid(res, p.startsWith('/api/'));
    return next();
  });
});

function caps(user, doc) {
  const posted = !!(doc && doc.is_posted);
  const hasId = !!(doc && doc.id);
  return {
    canSave: !posted,
    canPost: !posted && canAction(user, 'action_post_sales_return'),
    canUnpost: posted && canAction(user, 'action_unpost_sales_return'),
    canDelete: hasId && !posted && canAction(user, 'action_delete_sales_return'),
    canEinvoice:
      posted &&
      (canAction(user, 'sales_send_einvoice') || canAction(user, 'action_post_sales_return')),
    canPrint: hasId,
    canPdf: hasId,
  };
}

function toolbar(cs) {
  const b = (id, label, cls, dis, extra = '') =>
    `<button type="button" class="si-tb ${cls || ''}" id="${id}" ${dis ? 'disabled' : ''}${extra}>${esc(
      label
    )}</button>`;
  return `
    <div class="si-cmd si-doc-toolbar" id="sr-doc-bar" role="toolbar" aria-label="إجراءات المرتجع">
      <div class="si-tb-group si-tb-group--core">
        ${b('sr-save', 'حفظ', 'si-tb--save', !cs.canSave, ' data-hx-save="1" title="F10 حفظ"')}
        ${b('sr-post', 'ترحيل', 'si-tb--post', !cs.canPost && !cs.canSave)}
      </div>
      <div class="si-tb-group">
        ${b('sr-search', 'بحث', 'si-tb--ghost', false)}
        ${b('sr-pdf', 'PDF', '', !cs.canPdf)}
        ${b('sr-print', 'طباعة', '', !cs.canPrint)}
        ${b('sr-archive', 'أرشيف', '', !cs.canPrint)}
        ${b('sr-email', 'Email', '', !cs.canPrint)}
        ${b('sr-einvoice', 'الفوترة', 'si-tb--accent', !cs.canEinvoice)}
      </div>
      <div class="si-tb-group si-tb-group--risk">
        ${b('sr-unpost', 'فك الترحيل', '', !cs.canUnpost)}
        ${b('sr-delete', 'حذف', 'si-tb--danger', !cs.canDelete)}
      </div>
      <div class="si-tb-group si-tb-group--status">
        <span class="si-msg" id="sr-msg"></span>
      </div>
    </div>`;
}

/* Redirect landing /sales/returns → form or list */
router.get('/sales/returns', (req, res) => {
  if (req.query.id) return res.redirect('/sales/returns/form/' + Number(req.query.id));
  return res.redirect('/sales/returns/form/new');
});

router.get('/sales/returns/documents', async (req, res) => {
  try {
    const q = String(req.query.q || '');
    const filter = ['all', 'unposted', 'posted'].includes(String(req.query.filter || ''))
      ? String(req.query.filter)
      : 'all';
    const data = await svc.listReturns({ q, filter, page: Number(req.query.page || 1) });
    const canPost = canAction(req.session.user, 'action_post_sales_return');
    const rows = data.rows
      .map((r) => {
        const st = r.is_posted
          ? '<span class="si-pill si-pill--live">مرحّل</span>'
          : '<span class="si-pill si-pill--wait">مسودة</span>';
        return `<tr>
          <td dir="ltr"><a class="si-inv-no" href="/sales/returns/form/${r.id}">${esc(
            r.return_no
          )}</a></td>
          <td class="si-num" dir="ltr">${esc(isoToDmy(r.return_date))}</td>
          <td>${esc(r.customer_name)}</td>
          <td dir="ltr">${esc(r.invoice_no || '')}</td>
          <td class="si-num" dir="ltr">${esc(fmtAmt(r.total))}</td>
          <td>${st}</td>
          <td>
            <a class="si-btn" href="/sales/returns/form/${r.id}">فتح</a>
            ${
              !r.is_posted && canPost
                ? `<button type="button" class="si-btn js-list-post" data-id="${r.id}">ترحيل</button>`
                : ''
            }
          </td>
        </tr>`;
      })
      .join('');
    const body = `
      <div class="si-stage">
        <header class="si-hero">
          <div class="si-brand-lockup">
            <div class="si-brand-text">
              <h1>مرتجعات المبيعات</h1>
            </div>
          </div>
          <div class="si-hero-actions">
            <a class="si-btn si-btn--primary" href="/sales/returns/form/new">＋ مرتجع جديد</a>
            <a class="si-btn" href="/sales">لوحة المبيعات</a>
          </div>
        </header>
        <div class="si-rail">
          <div class="si-seg">
            <a class="${filter === 'all' ? 'is-active' : ''}" href="?filter=all&q=${encodeURIComponent(q)}">الكل</a>
            <a class="${filter === 'unposted' ? 'is-active' : ''}" href="?filter=unposted&q=${encodeURIComponent(q)}">غير مرحّلة</a>
            <a class="${filter === 'posted' ? 'is-active' : ''}" href="?filter=posted&q=${encodeURIComponent(q)}">مرحّلة</a>
          </div>
          <form class="si-search" method="get">
            <input type="hidden" name="filter" value="${esc(filter)}">
            <input type="search" name="q" value="${esc(q)}" placeholder="بحث…">
            <button class="si-btn si-btn--primary" type="submit">بحث</button>
          </form>
        </div>
        <section class="si-surface">
          <div class="si-table-wrap">
            <table class="si-table">
              <thead><tr>
                <th>الرقم</th><th>التاريخ</th><th>العميل</th><th>الفاتورة</th><th>الإجمالي</th><th>الحالة</th><th></th>
              </tr></thead>
              <tbody>${rows || '<tr><td colspan="7" class="empty">لا مرتجعات</td></tr>'}</tbody>
            </table>
          </div>
        </section>
      </div>
      <script>
      document.querySelectorAll('.js-list-post').forEach(function(btn){
        btn.addEventListener('click',function(){
          var id=btn.getAttribute('data-id');
          if(!id||!confirm('ترحيل المرتجع؟ (مخزون + قيود ثم فوترة)'))return;
          btn.disabled=true;
          fetch('/api/sales/returns/'+id+'/post',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({reason:'إرجاع بضاعة'})})
            .then(function(r){return r.json()}).then(function(d){alert(d.message||d.error||(d.ok?'تم':'فشل'));if(d.ok)location.reload();else btn.disabled=false;})
            .catch(function(){btn.disabled=false;alert('تعذر الاتصال');});
        });
      });
      </script>`;
    res.send(
      renderApp({
        user: req.session.user,
        title: 'قائمة المرتجعات',
        bodyHtml: body,
        bodyClass: 'si-2027',
        mainClass: 'main si-main',
        css: ['/assets/css/sales-2027.css'],
      })
    );
  } catch (e) {
    res.status(500).send(e.message);
  }
});

router.get('/sales/returns/posting', async (req, res) => {
  req.query.filter = 'unposted';
  // reuse list with unposted filter redirect
  return res.redirect('/sales/returns/documents?filter=unposted');
});

router.get(['/sales/returns/form/new', '/sales/returns/form/:id'], async (req, res) => {
  try {
    const isNew = !req.params.id || req.params.id === 'new';
    let doc = null;
    if (!isNew) {
      doc = await svc.getReturn(req.params.id);
      if (!doc) return res.status(404).send('المرتجع غير موجود');
    }
    const cs = caps(req.session.user, doc || { id: 0, is_posted: false });
    const initial = {
      id: doc ? doc.id : 0,
      return_no: doc ? doc.return_no : '',
      return_date: doc ? String(doc.return_date).slice(0, 10) : todayIso(),
      customer_id: doc ? doc.customer_id : 0,
      customer_label: doc
        ? (doc.customer_code ? doc.customer_code + ' — ' : '') + doc.customer_name
        : '',
      invoice_id: doc ? doc.invoice_id : 0,
      invoice_no: doc ? doc.invoice_no : '',
      notes: doc ? doc.notes : '',
      reason_return: doc ? doc.reason_return : '',
      is_posted: doc ? doc.is_posted : false,
      lines: doc ? doc.lines : [],
      caps: cs,
      archiveUrl: doc ? embedUrl('sales_returns', 'id=' + doc.id) : '',
    };
    const badge = initial.is_posted
      ? '<span class="si-pill si-pill--lock">مرحّل — قراءة فقط</span>'
      : '<span class="si-pill si-pill--wait">مسودة</span>';
    const title = initial.return_no ? `مرتجع ${esc(initial.return_no)}` : 'مرتجع مبيعات جديد';
    const body = `
      <div class="si-stage">
        <header class="si-hero">
          <div class="si-brand-lockup">
            <div class="si-brand-text">
              <h1>${title}</h1>
              ${badge ? `<div class="si-hero-badge">${badge}</div>` : ''}
            </div>
          </div>
          <div class="si-hero-actions">
            <a class="si-btn" href="/sales/returns/documents">القائمة</a>
            <a class="si-btn si-btn--primary" href="/sales/returns/form/new">＋ مرتجع جديد</a>
          </div>
        </header>
        ${toolbar(cs)}
        <section class="si-surface">
          <div class="si-surface-head"><h2>بيانات المرتجع</h2></div>
          <div class="si-meta">
            <label>رقم الإرجاع
              <input class="si-field si-field--mono" id="ret_no" value="${esc(
                initial.return_no
              )}" readonly dir="ltr" placeholder="—">
            </label>
            <label>تاريخ الإرجاع
              <input class="si-field si-field--mono" id="ret_date" type="date" value="${esc(
                initial.return_date
              )}" ${initial.is_posted ? 'readonly' : ''}>
            </label>
            <label class="si-span-2">العميل
              <div class="si-cust-wrap">
                <input type="hidden" id="ret_customer_id" value="${initial.customer_id || ''}">
                <input class="si-field" id="ret_customer" type="search"
                       value="${esc(initial.customer_label)}"
                       placeholder="اضغط لاختيار العميل" ${initial.is_posted ? 'readonly' : ''} autocomplete="off">
                <div class="si-suggest" id="ret_cust_suggest" hidden></div>
              </div>
            </label>
            <label class="si-span-2">فاتورة البيع
              <select class="si-field" id="ret_invoice" ${initial.is_posted ? 'disabled' : ''}>
                <option value="">— اختر العميل أولاً —</option>
              </select>
            </label>
            <label class="si-span-2">سبب الإرجاع (للفوترة)
              <input class="si-field" id="ret_reason" value="${esc(
                initial.reason_return
              )}" placeholder="مثال: بضاعة تالفة / رفض العميل" ${
                initial.is_posted ? 'readonly' : ''
              }>
            </label>
          </div>
        </section>
        <section class="si-surface">
          <div class="si-surface-head"><h2>مواد المرتجع</h2></div>
          <p class="muted" style="font-size:.82rem;margin:.25rem 0 .6rem">
            فقط فواتير البيع <strong>المرحّلة</strong>. حدّد الكميات القابلة للإرجاع.
          </p>
          <div style="overflow:auto">
            <table class="sr-lines" id="sr-lines">
              <thead>
                <tr>
                  <th class="sr-check">✓</th>
                  <th>#</th>
                  <th>باركود</th>
                  <th>المادة</th>
                  <th>المتاح</th>
                  <th>كمية الإرجاع</th>
                  <th>ك. إضافية</th>
                  <th>سعر</th>
                  <th>قبل الضريبة</th>
                  <th>الضريبة</th>
                  <th>مع الضريبة</th>
                </tr>
              </thead>
              <tbody id="sr-lines-body">
                <tr><td colspan="11" class="muted" style="text-align:center;padding:1rem">اختر الفاتورة لتحميل البنود</td></tr>
              </tbody>
            </table>
          </div>
          <div class="si-doc-foot" style="margin-top:1rem">
            <label class="si-notes">ملاحظات
              <textarea id="ret_notes" rows="3" ${initial.is_posted ? 'readonly' : ''}>${esc(
                initial.notes
              )}</textarea>
            </label>
            <div class="si-totals">
              <div class="si-tot-row"><span>بدون ضريبة</span><strong id="sum_sub" dir="ltr">0.000</strong></div>
              <div class="si-tot-row"><span>الضريبة</span><strong id="sum_tax" dir="ltr">0.000</strong></div>
              <div class="si-tot-row si-tot-grand"><span>الإجمالي</span><strong id="sum_grand" dir="ltr">0.000</strong></div>
            </div>
          </div>
        </section>
      </div>
      <script type="application/json" id="sr-initial">${JSON.stringify(initial).replace(
        /</g,
        '\\u003c'
      )}</script>`;
    res.send(
      renderApp({
        user: req.session.user,
        title: isNew ? 'مرتجع جديد' : `مرتجع ${initial.return_no}`,
        bodyHtml: body,
        bodyClass: 'si-2027',
        mainClass: 'main si-main',
        css: ['/assets/css/sales-2027.css'],
        js: ['/assets/js/sales-return-node.js'],
      })
    );
  } catch (e) {
    console.error(e);
    res.status(500).send(e.message);
  }
});

router.get('/sales/returns/:id/print', async (req, res) => {
  try {
    const doc = await svc.getReturn(req.params.id);
    if (!doc) return res.status(404).send('غير موجود');
    const lines = doc.lines
      .map(
        (ln, i) => `<tr>
        <td>${i + 1}</td><td>${esc(ln.item_code || '')}</td><td>${esc(ln.name_ar || '')}</td>
        <td dir="ltr">${esc(fmtAmt(ln.qty))}</td><td dir="ltr">${esc(fmtAmt(ln.unit_price))}</td>
        <td dir="ltr">${esc(fmtAmt(ln.line_gross))}</td>
      </tr>`
      )
      .join('');
    const autoPdf = String(req.query.pdf || '') === '1';
    res.send(`<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">
<title>مرتجع ${esc(doc.return_no)}</title>
<style>body{font-family:Arial, Helvetica, sans-serif;margin:1.2rem}table{width:100%;border-collapse:collapse;font-size:.85rem}th,td{border:1px solid #ccc;padding:.35rem;text-align:right}th{background:#f3f4f6}@media print{.no-print{display:none}}</style></head><body>
<div class="no-print" style="margin-bottom:1rem"><button onclick="window.print()">طباعة / PDF</button> · <a href="/sales/returns/form/${doc.id}">عودة</a></div>
<h1>مرتجع مبيعات ${esc(doc.return_no)}</h1>
<p>التاريخ: <strong dir="ltr">${esc(isoToDmy(doc.return_date))}</strong><br>
العميل: <strong>${esc(doc.customer_name)}</strong><br>
فاتورة البيع: <strong dir="ltr">${esc(doc.invoice_no)}</strong> · ${
      doc.is_posted ? 'مرحّل' : 'مسودة'
    }</p>
<table><thead><tr><th>#</th><th>رمز</th><th>مادة</th><th>كمية</th><th>سعر</th><th>الإجمالي</th></tr></thead>
<tbody>${lines || '<tr><td colspan="6">لا بنود</td></tr>'}</tbody></table>
<p style="text-align:left;font-weight:800;margin-top:1rem">الإجمالي: <span dir="ltr">${esc(
      fmtAmt(doc.total)
    )}</span></p>
${autoPdf ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},250)})</script>' : ''}
</body></html>`);
  } catch (e) {
    res.status(500).send(e.message);
  }
});

/* APIs */
router.post('/api/sales/returns', async (req, res) => {
  try {
    const r = await svc.saveReturn(req.body || {}, req.session.user.id);
    if (!r.ok) return res.status(400).json(r);
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/returns/:id/post', async (req, res) => {
  try {
    if (!canAction(req.session.user, 'action_post_sales_return')) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية ترحيل.' });
    }
    const body = req.body || {};
    if (body.save_first && body.payload) {
      const saved = await svc.saveReturn(
        { ...body.payload, id: Number(req.params.id) },
        req.session.user.id
      );
      if (!saved.ok) return res.status(400).json(saved);
    }
    const r = await svc.postReturn(req.params.id, req.session.user.id, {
      autoEinvoice: body.auto_einvoice !== false,
      reason: body.reason || body.reason_return || '',
    });
    if (!r.ok) return res.status(400).json(r);
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/returns/:id/unpost', async (req, res) => {
  try {
    if (!canAction(req.session.user, 'action_unpost_sales_return')) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية فك الترحيل.' });
    }
    const r = await svc.unpostReturn(req.params.id, req.session.user.id);
    if (!r.ok) return res.status(400).json(r);
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/returns/:id/delete', async (req, res) => {
  try {
    if (!canAction(req.session.user, 'action_delete_sales_return')) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية حذف.' });
    }
    const r = await svc.deleteReturn(req.params.id, req.session.user.id);
    if (!r.ok) return res.status(400).json(r);
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/returns/:id/einvoice', async (req, res) => {
  try {
    const r = await svc.sendEinvoice(
      req.params.id,
      req.session.user.id,
      (req.body && (req.body.reason || req.body.reason_return)) || ''
    );
    if (!r.ok) return res.status(400).json(r);
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/return-invoices', async (req, res) => {
  try {
    const r = await svc.invoicesForCustomer(req.query.customer_id, req.session.user.id);
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/return-lines', async (req, res) => {
  try {
    const r = await svc.linesForInvoice(
      req.query.invoice_id,
      req.query.exclude_return_id,
      req.query.customer_id,
      req.session.user.id
    );
    res.json(r);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/return-customers', async (req, res) => {
  try {
    const rows = await svc.searchCustomers(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

module.exports = router;
