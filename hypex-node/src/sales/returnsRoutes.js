'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./returnsService');
const { renderApp, embedUrl } = require('../lib/layout');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const { ensurePrintBrand, renderStandalonePrintPage } = require('../lib/printBrand');

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
    const nav = doc ? await svc.browseNeighbors(doc.id) : { prev_id: 0, next_id: 0 };
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
      prev_id: nav.prev_id || 0,
      next_id: nav.next_id || 0,
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
              <div class="si-docno-row">
                <button type="button" class="si-btn si-docno-btn" id="ret_prev" title="السابق">‹</button>
                <input class="si-field si-field--mono" id="ret_no" value="${esc(
                  initial.return_no
                )}" readonly dir="ltr" placeholder="Enter للانتقال"
                       title="Enter للانتقال · ↑ السابق · ↓ التالي">
                <button type="button" class="si-btn si-docno-btn" id="ret_next" title="التالي">›</button>
              </div>
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
        js: ['/assets/js/doc-nav.js', '/assets/js/sales-return-node.js'],
      })
    );
  } catch (e) {
    console.error(e);
    res.status(500).send(e.message);
  }
});

router.get('/sales/returns/:id/print', async (req, res) => {
  try {
    await ensurePrintBrand();
    const doc = await svc.getReturn(req.params.id);
    if (!doc) return res.status(404).send('غير موجود');
    const bodyRows =
      doc.lines
        .map(
          (ln, i) => `<tr>
        <td dir="ltr">${i + 1}</td>
        <td dir="ltr">${esc(ln.item_code || '')}</td>
        <td>${esc(ln.name_ar || '')}</td>
        <td dir="ltr">${esc(fmtAmt(ln.qty))}</td>
        <td dir="ltr">${esc(fmtAmt(ln.unit_price))}</td>
        <td dir="ltr"><strong>${esc(fmtAmt(ln.line_gross))}</strong></td>
      </tr>`
        )
        .join('') || '<tr><td colspan="6" class="empty">لا بنود</td></tr>';

    const contentHtml = `
      <div class="ora-stmt print-area">
        <header class="ora-stmt-head">
          <div class="ora-stmt-head__main">
            <p class="ora-stmt-kicker">مرتجع مبيعات</p>
            <h2 class="ora-stmt-name">رقم ${esc(doc.return_no || '—')}</h2>
            <p class="ora-stmt-meta">
              التاريخ: <strong dir="ltr">${esc(isoToDmy(doc.return_date))}</strong>
              <span aria-hidden="true"> · </span>
              العميل: <strong>${esc(doc.customer_name || '')}</strong>
              <span aria-hidden="true"> · </span>
              فاتورة البيع: <strong dir="ltr">${esc(doc.invoice_no || '—')}</strong>
              <span aria-hidden="true"> · </span>
              الحالة: <strong>${doc.is_posted ? 'مرحّل' : 'مسودة'}</strong>
            </p>
          </div>
          <div class="ora-stmt-totals" style="grid-template-columns:1fr">
            <div class="ora-stat ora-stat--balance">
              <span>الإجمالي</span>
              <strong dir="ltr">${esc(fmtAmt(doc.total))}</strong>
            </div>
          </div>
        </header>
        <div class="si-surface ora-stmt-body">
          <div class="si-surface-head">بنود المرتجع</div>
          <div class="si-table-wrap">
            <table class="si-table ora-table">
              <thead>
                <tr>
                  <th>#</th><th>الرمز</th><th>المادة</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th>
                </tr>
              </thead>
              <tbody>${bodyRows}</tbody>
              <tbody>
                <tr class="hx-print-total-row">
                  <td colspan="5">الإجمالي</td>
                  <td dir="ltr"><strong>${esc(fmtAmt(doc.total))}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>`;

    const autoPrint =
      String(req.query.pdf || '') === '1' || String(req.query.auto || '') === '1';

    res.send(
      await renderStandalonePrintPage({
        user: req.session.user,
        documentTitle: `مرتجع مبيعات ${doc.return_no || doc.id}`,
        backHref: `/sales/returns/form/${doc.id}`,
        contentHtml,
        autoPrint,
      })
    );
  } catch (e) {
    res.status(500).send(e.message);
  }
});

/* APIs */
router.get('/api/sales/returns/by-no', async (req, res) => {
  try {
    const id = await svc.findReturnIdByNo(req.query.no);
    if (!id) return res.status(404).json({ ok: false, error: 'لم يُعثر على مرتجع بهذا الرقم' });
    res.json({ ok: true, id });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

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
