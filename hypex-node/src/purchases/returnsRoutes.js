'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./returnsService');
const ui = require('../lib/salesUi');
const { esc, todayIso } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex Purchases · Node';
const HUB = '/purchases';
const LIST = '/purchases/returns';

function can(user, code) {
  return user.is_admin || auth.userCan(user, code);
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    !p.startsWith('/purchases/returns') &&
    !p.startsWith('/api/purchases/returns')
  ) {
    return next('router');
  }
  // list/posting handled in menuRoutes — claim entry + api + numeric ids
  if (p === '/purchases/returns' || p === '/purchases/returns/posting') {
    return next('router');
  }
  return auth.requireAuth(req, res, next);
});

function fmt(n) {
  return ui.fmtAmt(n);
}

async function formNew(req, res) {
  if (!can(req.session.user, 'purchase_returns')) return res.status(403).send('ممنوع');
  const err = String(req.query.err || '');
  const suppliers = await svc.listSuppliers();
  const supplierId = Number(req.query.supplier_id || 0) || 0;
  const invoiceId = Number(req.query.invoice_id || 0) || 0;
  let invoices = [];
  let lines = [];
  if (supplierId > 0) invoices = await svc.invoicesForSupplier(supplierId);
  if (invoiceId > 0) lines = await svc.fetchInvoiceLines(invoiceId);

  const supOpts = suppliers
    .map(
      (s) =>
        `<option value="${s.id}" ${supplierId === Number(s.id) ? 'selected' : ''}>${esc(
          (s.code ? s.code + ' — ' : '') + (s.name_ar || '')
        )}</option>`
    )
    .join('');
  const invOpts = invoices
    .map(
      (i) =>
        `<option value="${i.id}" ${invoiceId === Number(i.id) ? 'selected' : ''}>${esc(
          i.invoice_no
        )} — ${esc(ui.isoToDmy(i.invoice_date))} — ${esc(fmt(i.total))}</option>`
    )
    .join('');

  const linesHtml =
    lines
      .map((ln, idx) => {
        const a = linePreview(0, ln.unit_price, ln.tax_rate_percent);
        return `<tr data-line-id="${ln.invoice_line_id}" data-unit="${ln.unit_price}" data-tax="${ln.tax_rate_percent}" data-max="${ln.qty_remaining}">
        <td><input type="checkbox" class="pr-sel" name="selected" value="${ln.invoice_line_id}"></td>
        <td class="si-num" dir="ltr">${esc(ln.barcode || '')}</td>
        <td class="si-num" dir="ltr">${idx + 1}</td>
        <td>${esc(ln.name_ar || ln.line_desc || '')}</td>
        <td class="si-num" dir="ltr">${esc(fmt(ln.qty_remaining))}</td>
        <td>
          <input class="si-field si-field--mono pr-qty" type="number" step="0.001" min="0" max="${ln.qty_remaining}"
            name="qty_${ln.invoice_line_id}" value="0" dir="ltr" style="min-width:5rem;width:5.5rem">
        </td>
        <td class="si-num pr-price" dir="ltr">${esc(fmt(ln.unit_price))}</td>
        <td class="si-num pr-sub" dir="ltr">${esc(fmt(a.line_subtotal))}</td>
        <td class="si-num pr-tax" dir="ltr">${esc(fmt(a.tax_amount))}</td>
        <td class="si-num pr-gross" dir="ltr">${esc(fmt(a.line_gross))}</td>
      </tr>`;
      })
      .join('') ||
    `<tr><td colspan="10" class="muted" style="text-align:center">اختر المورد ثم فاتورة شراء مرحّلة لعرض البنود</td></tr>`;

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: '↩',
        kicker: KICKER,
        title: 'مردود مشتريات',
        subtitle: 'اختر المورد والفاتورة — حدّد المواد والكميات ثم احفظ. الترحيل يحدّث المخزون وذمة المورد.',
        actions: [
          { label: 'القائمة', href: LIST },
          { label: 'لوحة المشتريات', href: HUB },
        ],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface">
        <div class="si-surface-head"><h2>بيانات المردود</h2></div>
        <form method="get" action="/purchases/returns/new" id="pr-filter" class="si-meta" style="padding:1rem 1.1rem .5rem">
          <label>المورد *
            <select class="si-field" name="supplier_id" id="pr-supplier" required onchange="this.form.invoice_id && (this.form.invoice_id.value=''); this.form.submit()">
              <option value="">— اختر المورد —</option>
              ${supOpts}
            </select>
          </label>
          <label>فاتورة الشراء *
            <select class="si-field" name="invoice_id" id="pr-invoice" onchange="this.form.submit()" ${
              supplierId ? '' : 'disabled'
            }>
              <option value="">${supplierId ? '— اختر الفاتورة —' : '— اختر المورد أولاً —'}</option>
              ${invOpts}
            </select>
          </label>
          <label>تاريخ المردود
            <input class="si-field" type="date" name="return_date_keep" value="${esc(todayIso())}" form="pr-save">
          </label>
        </form>
        <p class="muted" style="padding:0 1.1rem .5rem;margin:0;font-size:.88rem">اختر المورد وفاتورة الشراء — تظهر بنود الفاتورة. فعّل البند وأدخل كمية الإرجاع ثم احفظ.</p>
        <form method="post" action="/purchases/returns/new" id="pr-save" style="padding:0 0 1rem">
          <input type="hidden" name="supplier_id" value="${supplierId}">
          <input type="hidden" name="invoice_id" value="${invoiceId}">
          <input type="hidden" name="return_date" id="pr-date" value="${esc(todayIso())}">
          <div class="table-wrap" style="padding:0 1.1rem;overflow:auto">
            <table class="si-table" id="pr-lines">
              <thead>
                <tr>
                  <th></th><th>باركود</th><th>#</th><th>المادة</th><th>متبقي</th>
                  <th>كمية الإرجاع</th><th>سعر الوحدة</th><th>قبل الضريبة</th><th>الضريبة</th><th>مع الضريبة</th>
                </tr>
              </thead>
              <tbody>${linesHtml}</tbody>
            </table>
          </div>
          <div class="si-meta" style="padding:1rem 1.1rem;align-items:flex-start">
            <label class="si-span-2">ملاحظات
              <textarea class="si-field" name="notes" rows="2" style="min-height:3.5rem" placeholder="اختياري"></textarea>
            </label>
            <div style="margin-inline-start:auto;text-align:start;font-weight:700;line-height:1.8">
              <div>المجموع بدون ضريبة: <span id="t-sub" dir="ltr">0.000</span></div>
              <div>مجموع الضريبة: <span id="t-tax" dir="ltr">0.000</span></div>
              <div style="color:#0b6bcb">الإجمالي: <span id="t-gross" dir="ltr">0.000</span></div>
            </div>
            <div class="si-span-2" style="display:flex;gap:.5rem;margin-top:.5rem">
              <button class="si-btn si-btn--primary" type="submit" ${invoiceId ? '' : 'disabled'}>حفظ</button>
              <a class="si-btn" href="${LIST}">إلغاء</a>
            </div>
          </div>
        </form>
      </section>
    </div>
    <script>
      (function(){
        function r3(n){ return Math.round((Number(n)||0)*1000)/1000; }
        function fmt(n){ return r3(n).toFixed(3); }
        function recalc(){
          var sub=0,tax=0,gross=0;
          document.querySelectorAll('#pr-lines tbody tr[data-line-id]').forEach(function(tr){
            var max=Number(tr.getAttribute('data-max')||0);
            var unit=Number(tr.getAttribute('data-unit')||0);
            var rate=Number(tr.getAttribute('data-tax')||0);
            var qtyInp=tr.querySelector('.pr-qty');
            var qty=Number(qtyInp && qtyInp.value || 0);
            if(qty>max){ qty=max; if(qtyInp) qtyInp.value=max; }
            if(qty<0){ qty=0; if(qtyInp) qtyInp.value=0; }
            var s=r3(qty*unit); var t=r3(s*rate/100); var g=r3(s+t);
            var el;
            if((el=tr.querySelector('.pr-sub'))) el.textContent=fmt(s);
            if((el=tr.querySelector('.pr-tax'))) el.textContent=fmt(t);
            if((el=tr.querySelector('.pr-gross'))) el.textContent=fmt(g);
            if(qty>0){ sub+=s; tax+=t; gross+=g; }
            var chk=tr.querySelector('.pr-sel');
            if(chk) chk.checked = qty>0;
          });
          var d; if((d=document.getElementById('t-sub'))) d.textContent=fmt(sub);
          if((d=document.getElementById('t-tax'))) d.textContent=fmt(tax);
          if((d=document.getElementById('t-gross'))) d.textContent=fmt(gross);
        }
        document.querySelectorAll('.pr-qty').forEach(function(inp){
          inp.addEventListener('input', recalc);
          inp.addEventListener('change', recalc);
        });
        document.querySelectorAll('.pr-sel').forEach(function(chk){
          chk.addEventListener('change', function(){
            var tr=chk.closest('tr');
            var qty=tr && tr.querySelector('.pr-qty');
            if(!qty) return;
            if(chk.checked){
              if(Number(qty.value)||0 <= 0) qty.value = tr.getAttribute('data-max') || 1;
            } else qty.value = 0;
            recalc();
          });
        });
        var dateKeep=document.querySelector('[name="return_date_keep"]');
        var dateHid=document.getElementById('pr-date');
        if(dateKeep && dateHid){
          dateKeep.addEventListener('change', function(){ dateHid.value=dateKeep.value; });
          dateHid.value=dateKeep.value;
        }
        document.getElementById('pr-save') && document.getElementById('pr-save').addEventListener('submit', function(e){
          if(dateKeep && dateHid) dateHid.value=dateKeep.value;
          var any=false;
          document.querySelectorAll('.pr-qty').forEach(function(inp){ if(Number(inp.value)>0) any=true; });
          if(!any){ e.preventDefault(); alert('أدخل كمية إرجاع لمادة واحدة على الأقل.'); }
        });
        recalc();
      })();
    </script>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'مردود مشتريات', bodyHtml: body }));
}

function linePreview(qty, unit, tax) {
  const sub = Math.round(qty * unit * 1000) / 1000;
  const t = Math.round(((sub * (tax || 0)) / 100) * 1000) / 1000;
  return { line_subtotal: sub, tax_amount: t, line_gross: Math.round((sub + t) * 1000) / 1000 };
}

router.get('/purchases/returns/entry', (req, res) => {
  const id = Number(req.query.id || 0);
  if (id > 0) return res.redirect('/purchases/returns/' + id);
  return res.redirect('/purchases/returns/new');
});

router.get('/purchases/returns/new', formNew);

router.post('/purchases/returns/new', async (req, res) => {
  if (!can(req.session.user, 'purchase_returns')) return res.status(403).send('ممنوع');
  const body = req.body || {};
  const lines = [];
  for (const [k, v] of Object.entries(body)) {
    if (!k.startsWith('qty_')) continue;
    const lineId = Number(k.slice(4));
    const qty = Number(v || 0);
    if (lineId > 0 && qty > 0) lines.push({ invoice_line_id: lineId, qty });
  }
  const result = await svc.saveReturn(
    {
      supplier_id: body.supplier_id,
      invoice_id: body.invoice_id,
      return_date: body.return_date,
      notes: body.notes,
      lines,
    },
    req.session.user?.id
  );
  if (!result.ok) {
    const sid = Number(body.supplier_id || 0);
    const iid = Number(body.invoice_id || 0);
    return res.redirect(
      '/purchases/returns/new?err=' +
        encodeURIComponent(result.error) +
        (sid ? '&supplier_id=' + sid : '') +
        (iid ? '&invoice_id=' + iid : '')
    );
  }
  res.redirect('/purchases/returns/' + result.id + '?msg=' + encodeURIComponent(result.message || 'تم الحفظ'));
});

router.get('/purchases/returns/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'purchase_returns') && !can(req.session.user, 'purchase_returns_documents_list')) {
    return res.status(403).send('ممنوع');
  }
  const doc = await svc.getReturn(id);
  if (!doc) return res.status(404).send('غير موجود');
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const nav = await svc.browseNeighbors(id);

  const rowsHtml =
    doc.lines
      .map(
        (ln, i) => `<tr>
      <td class="si-num" dir="ltr">${esc(ln.barcode || '')}</td>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td>${esc(ln.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${esc(fmt(ln.qty))}</td>
      <td class="si-num" dir="ltr">${esc(fmt(ln.unit_price))}</td>
      <td class="si-num" dir="ltr">${esc(fmt(ln.line_subtotal))}</td>
      <td class="si-num" dir="ltr">${esc(fmt(ln.tax_amount))}</td>
      <td class="si-num" dir="ltr">${esc(fmt(ln.line_gross))}</td>
    </tr>`
      )
      .join('') || ui.emptyRow(8);

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: '↩',
        kicker: KICKER,
        title: 'مردود ' + esc(doc.return_no),
        subtitle: `${esc(doc.supplier_name || '')} · فاتورة ${esc(doc.invoice_no || '')} · ${esc(doc.status_label)}`,
        actions: [
          { label: '＋ مردود جديد', href: '/purchases/returns/new', primary: !doc.is_posted },
          { label: 'القائمة', href: LIST },
          { label: 'لوحة المشتريات', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <div class="si-meta" style="padding:.5rem 0 1rem">
        <label>رقم المردود
          <div class="si-docno-row">
            <button type="button" class="si-btn si-docno-btn" id="pr_prev" title="السابق">‹</button>
            <input class="si-field si-field--mono" id="pr_no" type="text" value="${esc(doc.return_no || '')}"
                   readonly dir="ltr" placeholder="Enter للانتقال"
                   title="Enter للانتقال · ↑ السابق · ↓ التالي">
            <button type="button" class="si-btn si-docno-btn" id="pr_next" title="التالي">›</button>
          </div>
        </label>
        <span style="align-self:end;padding-bottom:.35rem">التاريخ: <strong dir="ltr">${esc(ui.isoToDmy(doc.return_date))}</strong></span>
        <span style="align-self:end;padding-bottom:.35rem">الإجمالي: <strong dir="ltr">${esc(fmt(doc.total))}</strong></span>
        ${
          !doc.is_posted
            ? `<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-self:end">
                <form method="post" action="/purchases/returns/${id}/post" style="display:inline">
                  <button type="submit" class="si-btn si-btn--primary">ترحيل</button>
                </form>
                <form method="post" action="/purchases/returns/${id}/delete" style="display:inline" onsubmit="return confirm('حذف المردود؟');">
                  <button type="submit" class="si-btn" style="color:#b42318">حذف</button>
                </form>
              </div>`
            : '<span class="si-pill si-pill--ok" style="align-self:end">مرحّل</span>'
        }
      </div>
      ${ui.tableSurface(
        'بنود المردود',
        `${doc.lines.length} بند`,
        ['باركود', '#', 'المادة', 'كمية', 'سعر', 'قبل الضريبة', 'ضريبة', 'مع الضريبة'],
        rowsHtml
      )}
      ${doc.notes ? `<p class="muted" style="margin-top:.75rem">ملاحظات: ${esc(doc.notes)}</p>` : ''}
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          if (!window.HypexDocNav) return;
          window.HypexDocNav.bind({
            input: 'pr_no',
            prevBtn: 'pr_prev',
            nextBtn: 'pr_next',
            prevId: ${Number(nav.prev_id) || 0},
            nextId: ${Number(nav.next_id) || 0},
            openPath: '/purchases/returns',
            findApi: '/api/purchases/returns/by-no',
            currentNo: ${JSON.stringify(String(doc.return_no || ''))}
          });
        });
      </script>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'مردود ' + doc.return_no,
      bodyHtml: body,
      js: ['/assets/js/doc-nav.js'],
    })
  );
});

router.get('/api/purchases/returns/by-no', async (req, res) => {
  try {
    const id = await svc.findReturnIdByNo(req.query.no);
    if (!id) return res.status(404).json({ ok: false, error: 'لم يُعثر على مردود بهذا الرقم' });
    res.json({ ok: true, id });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/purchases/returns/:id/post', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'purchase_returns') && !can(req.session.user, 'purchase_returns_list')) {
    return res.status(403).send('ممنوع');
  }
  const result = await svc.postReturn(id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect('/purchases/returns/' + id + '?' + key + '=' + encodeURIComponent(result.message || result.error || ''));
});

router.post('/purchases/returns/:id/delete', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'purchase_returns')) return res.status(403).send('ممنوع');
  const result = await svc.deleteReturn(id);
  if (!result.ok) {
    return res.redirect('/purchases/returns/' + id + '?err=' + encodeURIComponent(result.error));
  }
  res.redirect(LIST + '?msg=' + encodeURIComponent(result.message || 'تم الحذف'));
});

// JSON APIs for future JS
router.get('/api/purchases/returns/invoices', async (req, res) => {
  if (!can(req.session.user, 'purchase_returns')) return res.status(403).json({ rows: [] });
  const rows = await svc.invoicesForSupplier(req.query.supplier_id);
  res.json({ rows });
});

router.get('/api/purchases/returns/invoice-lines', async (req, res) => {
  if (!can(req.session.user, 'purchase_returns')) return res.status(403).json({ rows: [] });
  const rows = await svc.fetchInvoiceLines(req.query.invoice_id);
  res.json({ rows });
});

module.exports = router;
