'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./invoicesService');
const { renderApp, phpUrl, embedUrl } = require('../lib/layout');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const config = require('../config');
const { ensurePrintBrand, renderStandalonePrintPage } = require('../lib/printBrand');

const router = express.Router();

function canAccess(user) {
  return (
    auth.userCan(user, 'sales_invoices') ||
    auth.userCan(user, 'sales_invoices_list') ||
    user.is_admin
  );
}

function canAction(user, code) {
  return user.is_admin || auth.userCan(user, code);
}

function requireSales(req, res, next) {
  if (!canAccess(req.session.user)) {
    if (req.path.startsWith('/api/')) {
      return res.status(403).json({ ok: false, error: 'ليس لديك صلاحية فواتير المبيعات' });
    }
    return res.status(403).send(
      renderApp({
        user: req.session.user,
        title: 'ممنوع',
        bodyHtml:
          '<div class="panel"><div class="panel-head"><h2>ليس لديك صلاحية فواتير المبيعات</h2></div></div>',
      })
    );
  }
  next();
}

router.use((req, res, next) => {
  const p = req.path || '';
  const ok =
    p.startsWith('/sales/invoices') ||
    p.startsWith('/api/sales/invoices') ||
    p.startsWith('/api/sales/lookups') ||
    p === '/api/customers' ||
    p.startsWith('/api/customers?') ||
    p === '/api/items' ||
    p.startsWith('/api/items?');
  if (!ok) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireSales(req, res, next);
  });
});

function toolbarCaps(user, inv) {
  const posted = !!(inv && inv.is_posted);
  const hasId = !!(inv && inv.id);
  return {
    canSave: !posted,
    canPost: !posted && hasId && canAction(user, 'action_post_sales_invoice'),
    canUnpost: posted && canAction(user, 'action_unpost_sales_invoice'),
    canDelete: hasId && !posted && canAction(user, 'action_delete_sales_invoice'),
    canEinvoice:
      posted &&
      (canAction(user, 'sales_send_einvoice') || canAction(user, 'action_post_sales_invoice')),
    canArchive: hasId && canAction(user, 'action_archive_sales_invoice'),
    canPrint: hasId,
    canPdf: hasId,
    canExcel: hasId,
    canEmail: hasId,
  };
}

function toolbarHtml(caps, inv) {
  const id = inv && inv.id ? Number(inv.id) : 0;
  const posted = !!(inv && inv.is_posted);
  const b = (idAttr, label, cls, disabled, extra = '') =>
    `<button type="button" class="si-tb ${cls || ''}" id="${idAttr}" ${
      disabled ? 'disabled' : ''
    }${extra}>${esc(label)}</button>`;

  return `
    <div class="si-cmd si-doc-toolbar" id="si-doc-bar" role="toolbar" aria-label="إجراءات الفاتورة"
         data-invoice-id="${id}" data-posted="${posted ? '1' : '0'}">
      <div class="si-tb-group si-tb-group--core">
        ${b('si-save', 'حفظ', 'si-tb--save', !caps.canSave, ' data-hx-save="1" title="F10 حفظ"')}
        ${b('si-post', 'ترحيل', 'si-tb--post', !caps.canPost && !(caps.canSave && !id))}
        ${b('si-add-line', '＋ سطر', 'si-tb--accent', !caps.canSave, ' data-hx-add-line="1" title="F2 سطر جديد"')}
      </div>
      <div class="si-tb-group">
        ${b('si-search', 'بحث', 'si-tb--ghost', false)}
        ${b('si-pdf', 'PDF', '', !caps.canPdf)}
        ${b('si-print', 'طباعة', '', !caps.canPrint)}
        ${b('si-excel', 'Excel', '', !caps.canExcel)}
        ${b('si-archive', 'أرشيف', '', !caps.canArchive)}
        ${b('si-email', 'Email', '', !caps.canEmail)}
        ${b('si-einvoice', 'الفوترة', 'si-tb--accent', !caps.canEinvoice)}
      </div>
      <div class="si-tb-group si-tb-group--risk">
        ${b('si-unpost', 'فك الترحيل', '', !caps.canUnpost)}
        ${b('si-delete', 'حذف', 'si-tb--danger', !caps.canDelete)}
      </div>
      <div class="si-tb-group si-tb-group--status">
        <span class="si-msg" id="si-msg"></span>
      </div>
    </div>`;
}

/** قائمة الفواتير */
router.get('/sales/invoices', async (req, res) => {
  try {
    const q = String(req.query.q || '').trim();
    const filter = ['all', 'unposted', 'posted'].includes(String(req.query.filter || ''))
      ? String(req.query.filter)
      : 'all';
    const page = Number(req.query.page || 1);
    const data = await svc.listInvoices({ q, filter, page, pageSize: 50 });
    const canPost = canAction(req.session.user, 'action_post_sales_invoice');

    const rows = data.rows
      .map((r, i) => {
        const pay = r.payment_type === 'credit' ? 'ذمم' : 'نقدي';
        const st = r.is_posted
          ? '<span class="si-pill si-pill--live">مرحّلة</span>'
          : '<span class="si-pill si-pill--wait">غير مرحّلة</span>';
        return `<tr style="animation-delay:${Math.min(i, 12) * 0.03}s">
          <td class="si-num" dir="ltr">${r.id}</td>
          <td dir="ltr"><a class="si-inv-no" href="/sales/invoices/${r.id}">${esc(r.invoice_no)}</a></td>
          <td class="si-num" dir="ltr">${esc(isoToDmy(r.invoice_date))}</td>
          <td>${esc(r.customer_name)}</td>
          <td>${esc(pay)}</td>
          <td class="si-num" dir="ltr">${esc(fmtAmt(r.total))}</td>
          <td>${st}</td>
          <td><div class="si-act">
            <a class="si-btn" href="/sales/invoices/${r.id}">فتح</a>
            ${
              !r.is_posted && canPost
                ? `<button type="button" class="si-btn js-list-post" data-id="${r.id}">ترحيل</button>`
                : ''
            }
          </div></td>
        </tr>`;
      })
      .join('');

    const bodyHtml = `
      <div class="si-stage">
        <header class="si-hero">
          <div class="si-brand-lockup">
            <div class="si-brand-text">
              <h1>فواتير المبيعات</h1>
            </div>
          </div>
          <div class="si-hero-actions">
            <a class="si-btn si-btn--ghost" href="/app">لوحة التحكم</a>
            <a class="si-btn si-btn--primary" href="/sales/invoices/new">＋ فاتورة جديدة</a>
          </div>
        </header>

        <div class="si-rail">
          <div class="si-seg" role="tablist">
            <a class="${filter === 'all' ? 'is-active' : ''}" href="/sales/invoices?filter=all&q=${encodeURIComponent(q)}">الكل</a>
            <a class="${filter === 'unposted' ? 'is-active' : ''}" href="/sales/invoices?filter=unposted&q=${encodeURIComponent(q)}">غير مرحّلة</a>
            <a class="${filter === 'posted' ? 'is-active' : ''}" href="/sales/invoices?filter=posted&q=${encodeURIComponent(q)}">مرحّلة</a>
          </div>
          <form class="si-search" method="get" action="/sales/invoices" id="si-list-search">
            <input type="hidden" name="filter" value="${esc(filter)}">
            <input type="search" name="q" id="si-list-q" value="${esc(q)}" placeholder="ابحث بالرقم أو العميل…" autocomplete="off">
            <button class="si-btn si-btn--primary" type="submit">بحث</button>
          </form>
        </div>

        <section class="si-surface">
          <div class="si-surface-head">
            <h2>سجل الفواتير</h2>
            <span class="si-count" dir="ltr">${data.total} rows · p${data.page}</span>
          </div>
          <div class="si-table-wrap">
            <table class="si-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>الرقم</th>
                  <th>التاريخ</th>
                  <th>العميل</th>
                  <th>النوع</th>
                  <th>الإجمالي</th>
                  <th>الحالة</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                ${rows || '<tr><td colspan="8" class="empty">لا توجد فواتير بعد — أنشئ أول فاتورة</td></tr>'}
              </tbody>
            </table>
          </div>
        </section>
      </div>
      <script>
      (function(){
        document.querySelectorAll('.js-list-post').forEach(function(btn){
          btn.addEventListener('click', function(){
            var id = btn.getAttribute('data-id');
            if(!id||!confirm('ترحيل الفاتورة؟ (قيود + مستودع ثم الفوترة)')) return;
            btn.disabled = true;
            fetch('/api/sales/invoices/'+id+'/post', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'})
              .then(function(r){return r.json()})
              .then(function(d){
                alert(d.message||d.error||(d.ok?'تم':'فشل'));
                if(d.ok) location.reload();
                else btn.disabled=false;
              })
              .catch(function(){ btn.disabled=false; alert('تعذر الاتصال'); });
          });
        });
      })();
      </script>
    `;

    res.send(
      renderApp({
        user: req.session.user,
        title: 'فواتير المبيعات',
        bodyHtml,
        bodyClass: 'si-2027',
        mainClass: 'main si-main',
        css: ['/assets/css/sales-2027.css'],
      })
    );
  } catch (e) {
    console.error(e);
    res.status(500).send('Error: ' + e.message);
  }
});

/** صورة QR من حقل الفوترة (نص / رابط / base64) — null إن غير مرسلة */
function einvQrImageSrc(einvQr) {
  const qr = String(einvQr || '').trim();
  if (!qr) return null;
  if (qr.startsWith('data:') || qr.startsWith('http://') || qr.startsWith('https://')) return qr;
  if (qr.startsWith('iVBORw0KGgo')) return 'data:image/png;base64,' + qr;
  if (qr.startsWith('/9j/')) return 'data:image/jpeg;base64,' + qr;
  return (
    'https://api.qrserver.com/v1/create-qr-code/?size=140x140&format=png&margin=4&ecc=H&data=' +
    encodeURIComponent(qr)
  );
}

/** طباعة فاتورة البيع — شكل فوترة مبيعات كلاسيكي */
router.get('/sales/invoices/:id/print', async (req, res) => {
  try {
    await ensurePrintBrand();
    const inv = await svc.getInvoice(req.params.id);
    if (!inv) return res.status(404).send('الفاتورة غير موجودة');

    const payLabel = inv.payment_type === 'cash' ? 'نقدي' : 'ذمم';
    const custLabel =
      (inv.customer_code ? inv.customer_code + ' — ' : '') + (inv.customer_name || '—');

    // QR فقط بعد الإرسال للفوترة (وجود einv_qr)؛ المجاميع دائماً ظاهرة
    const einvSent = !!inv.einv_sent;
    const qrSrc = einvSent ? einvQrImageSrc(inv.einv_qr) : null;

    const bodyRows =
      inv.lines
        .map((ln, i) => {
          const qty = Number(ln.qty) || 0;
          const qtyExtra = Number(ln.qty_extra) || 0;
          const discPct = Number(ln.discount_pct) || 0;
          const discAmt = Number(ln.discount_amount) || 0;
          const taxPct = Number(ln.tax_rate_percent) || 0;
          const unitNet = Number(ln.unit_price) || 0;
          const unitGross = unitNet * (1 + taxPct / 100);
          // عرض الخصم: النسبة إن وُجدت، وإلا مبلغ الخصم (يبقى العمود دائماً)
          const discCell =
            discPct > 0.000001
              ? `${fmtAmt(discPct)}%`
              : fmtAmt(discAmt);
          return `<tr>
            <td class="c-idx" dir="ltr">${i + 1}</td>
            <td class="c-code" dir="ltr">${esc(ln.item_code || '')}</td>
            <td class="c-name">${esc(ln.name_ar || '')}</td>
            <td class="c-unit">${esc(ln.unit_name || 'قطعة')}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(qty))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(qtyExtra))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(unitNet))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(unitGross))}</td>
            <td class="c-num c-disc" dir="ltr">${esc(discCell)}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(ln.line_total))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(ln.tax_amount))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(taxPct))}%</td>
            <td class="c-num c-gross" dir="ltr">${esc(fmtAmt(ln.line_gross))}</td>
          </tr>`;
        })
        .join('') ||
      `<tr><td colspan="13" class="empty">لا بنود</td></tr>`;

    const discLinesTotal = inv.lines.reduce(
      (a, ln) => a + (Number(ln.discount_amount) || 0),
      0
    );
    const invDiscLabel = String(inv.invoice_discount_input || '').trim() || fmtAmt(0);

    const qrBlock =
      qrSrc
        ? `<div class="inv-v1-qr"><img src="${esc(qrSrc)}" width="120" height="120" alt="QR الفوترة"></div>`
        : '';

    // المجاميع دائماً ظاهرة تحت الجدول؛ QR فقط إن وُجدت بعد الإرسال
    const sumsBlock = `<div class="inv-v1-sumwrap">
            <table class="inv-v1-sum">
              <tr>
                <td class="lbl">خصم الفاتورة</td>
                <td class="val" dir="ltr">${esc(invDiscLabel)}</td>
              </tr>
              <tr>
                <td class="lbl">مجموع الخصم</td>
                <td class="val" dir="ltr">${esc(fmtAmt(discLinesTotal))}</td>
              </tr>
              <tr>
                <td class="lbl">المجموع بدون ضريبة</td>
                <td class="val" dir="ltr">${esc(fmtAmt(inv.subtotal))}</td>
              </tr>
              <tr>
                <td class="lbl">مجموع الضريبة</td>
                <td class="val" dir="ltr">${esc(fmtAmt(inv.tax_amount))}</td>
              </tr>
              <tr class="grand">
                <td class="lbl">الإجمالي</td>
                <td class="val" dir="ltr">${esc(fmtAmt(inv.total))}</td>
              </tr>
            </table>
            ${
              inv.notes
                ? `<div class="inv-v1-notes"><span>ملاحظات:</span> ${esc(inv.notes)}</div>`
                : ''
            }
          </div>`;

    const contentHtml = `
      <div class="inv-v1${einvSent ? ' inv-v1--einv' : ' inv-v1--draft'}" dir="rtl">
        <div class="inv-v1-top">
          <div class="inv-v1-meta">
            <div><span>رقم الفاتورة:</span> <strong dir="ltr">${esc(inv.invoice_no || '—')}</strong></div>
            <div><span>التاريخ:</span> <strong dir="ltr">${esc(isoToDmy(inv.invoice_date))}</strong></div>
            <div><span>العميل:</span> <strong>${esc(custLabel)}</strong></div>
            <div><span>النوع:</span> <strong>${esc(payLabel)}</strong></div>
            <div><span>المندوب:</span> <strong>${esc(inv.sales_rep_name || '—')}</strong></div>
            <div><span>المستودع:</span> <strong>${esc(inv.warehouse_name || '—')}</strong></div>
          </div>
          <div class="inv-v1-title-block">
            <h1 class="inv-v1-title">فاتورة مبيعات</h1>
          </div>
          ${qrBlock}
        </div>

        <table class="inv-v1-table">
          <thead>
            <tr>
              <th>تسلسل</th>
              <th>رقم المادة</th>
              <th>اسم المادة</th>
              <th>الوحدة</th>
              <th>الكمية</th>
              <th>الكمية الإضافية</th>
              <th>الافرادي غ.ش</th>
              <th>الافرادي ش.</th>
              <th>الخصم</th>
              <th>السعر الإجمالي</th>
              <th>مبلغ الضريبة</th>
              <th>نسبة الضريبة</th>
              <th>الإجمالي مع الضريبة</th>
            </tr>
          </thead>
          <tbody>${bodyRows}</tbody>
        </table>

        <div class="inv-v1-foot">
          ${sumsBlock}
          <div class="inv-v1-sign">
            <div class="inv-v1-sign-label">توقيع المستلم</div>
            <div class="inv-v1-sign-line"></div>
          </div>
        </div>
      </div>`;

    // لا تفتح حوار الطباعة تلقائياً — فقط بعد ضغط زر «طباعة» في صفحة المعاينة
    const autoPrint = false;

    res.send(
      await renderStandalonePrintPage({
        user: req.session.user,
        documentTitle: 'فاتورة مبيعات',
        backHref: `/sales/invoices/${inv.id}`,
        contentHtml,
        autoPrint,
        printMode: 'sheet',
        theme: 'invoice-v1',
      })
    );
  } catch (e) {
    console.error(e);
    res.status(500).send(e.message || 'خطأ');
  }
});

/** نموذج جديد / تعديل */
router.get(['/sales/invoices/new', '/sales/invoices/:id'], async (req, res) => {
  try {
    const isNew = !req.params.id || req.params.id === 'new';
    let inv = null;
    if (!isNew) {
      inv = await svc.getInvoice(req.params.id);
      if (!inv) {
        return res.status(404).send('الفاتورة غير موجودة');
      }
    }

    const lookups = await svc.lookups();
    const caps = toolbarCaps(req.session.user, inv || { id: 0, is_posted: false });
    const initial = {
      id: inv ? inv.id : 0,
      invoice_no: inv ? inv.invoice_no : '',
      invoice_date: inv ? inv.invoice_date : todayIso(),
      customer_id: inv ? inv.customer_id : 0,
      customer_label: inv ? `${inv.customer_code || ''} — ${inv.customer_name}` : '',
      sales_rep_id: inv ? inv.sales_rep_id : null,
      warehouse_id: inv ? inv.warehouse_id : lookups.warehouses[0]?.id || null,
      payment_type: inv ? inv.payment_type : 'credit',
      notes: inv ? inv.notes : '',
      invoice_discount: inv ? inv.invoice_discount_input : '',
      is_posted: inv ? inv.is_posted : false,
      caps,
      lines:
        inv && inv.lines.length
          ? inv.lines
          : [
              {
                item_id: 0,
                item_code: '',
                name_ar: '',
                qty: 1,
                qty_extra: 0,
                unit_price: 0,
                discount_pct: 0,
                tax_rate_percent: lookups.default_tax,
              },
            ],
      defaults: {
        tax: lookups.default_tax,
        warehouses: lookups.warehouses,
        tax_rates: lookups.tax_rates,
        phpPostUrl: inv ? phpUrl('sales_invoices', '&id=' + inv.id) : phpUrl('sales_invoices'),
        phpBase: config.phpBaseUrl,
        archiveUrl: inv ? embedUrl('sales_invoices', 'id=' + inv.id) : '',
      },
    };

    const whOpts = lookups.warehouses
      .map(
        (w) =>
          `<option value="${w.id}"${
            Number(initial.warehouse_id) === Number(w.id) ? ' selected' : ''
          }>${esc(w.name_ar)}</option>`
      )
      .join('');

    const badge = initial.is_posted
      ? '<span class="si-pill si-pill--lock">مرحّلة — قراءة فقط</span>'
      : '<span class="si-pill si-pill--wait">مسودة</span>';

    const titleLine = initial.invoice_no
      ? `فاتورة ${esc(initial.invoice_no)}`
      : 'فاتورة مبيعات جديدة';

    const bodyHtml = `
      <div class="si-stage">
        <header class="si-hero">
          <div class="si-brand-lockup">
            <div class="si-brand-text">
              <h1>${titleLine}</h1>
              ${badge ? `<div class="si-hero-badge">${badge}</div>` : ''}
            </div>
          </div>
          <div class="si-hero-actions">
            <a class="si-btn" href="/sales/invoices">القائمة</a>
            <a class="si-btn" href="/sales/invoices/new">جديد</a>
          </div>
        </header>

        ${toolbarHtml(caps, initial)}

        <section class="si-surface">
          <div class="si-surface-head">
            <h2>بيانات المستند</h2>
            <span class="si-count">header</span>
          </div>
          <div class="si-meta">
            <label>رقم الفاتورة
              <input class="si-field si-field--mono" id="inv_no" type="text" value="${esc(
                initial.invoice_no
              )}" readonly placeholder="—" dir="ltr">
            </label>
            <label>التاريخ
              <input class="si-field si-field--mono" id="inv_date" type="date" value="${esc(
                String(initial.invoice_date).slice(0, 10)
              )}" ${initial.is_posted ? 'readonly' : ''}>
            </label>
            <label>النوع
              <select class="si-field" id="inv_pay" ${initial.is_posted ? 'disabled' : ''}>
                <option value="credit"${
                  initial.payment_type === 'credit' ? ' selected' : ''
                }>ذمم</option>
                <option value="cash"${
                  initial.payment_type === 'cash' ? ' selected' : ''
                }>نقدي</option>
              </select>
            </label>
            <label class="si-span-2">العميل
              <div class="si-cust-wrap">
                <input type="hidden" id="inv_customer_id" value="${initial.customer_id || ''}">
                <input class="si-field" id="inv_customer" type="search" placeholder="ابحث بالاسم أو الرمز…"
                       value="${esc(initial.customer_label)}" autocomplete="off" ${
                         initial.is_posted ? 'readonly' : ''
                       }>
                <div class="si-suggest" id="cust_suggest" hidden></div>
              </div>
            </label>
            <label>المستودع
              <select class="si-field" id="inv_wh" ${initial.is_posted ? 'disabled' : ''}>
                <option value="">—</option>
                ${whOpts}
              </select>
            </label>
          </div>
        </section>

        <section class="si-surface">
          <div class="si-surface-head">
            <h2>بنود الفاتورة</h2>
            <span class="si-count">line items</span>
          </div>
          <div class="si-lines-wrap">
            <table class="si-lines" id="si-lines">
              <thead>
                <tr>
                  <th style="width:2.2rem">#</th>
                  <th>المادة</th>
                  <th style="width:6.2rem">الكمية</th>
                  <th style="width:6.2rem">إضافية</th>
                  <th style="width:7rem">السعر</th>
                  <th style="width:5.2rem">خصم %</th>
                  <th style="width:5.2rem">ضريبة %</th>
                  <th style="width:7rem">الصافي</th>
                  <th style="width:7rem">الإجمالي</th>
                  <th style="width:2.6rem"></th>
                </tr>
              </thead>
              <tbody id="si-lines-body"></tbody>
            </table>
          </div>
          <div class="si-sum-strip" aria-label="ملخص الفاتورة">
            <div class="si-sum-box">
              <span>بدون ضريبة</span>
              <strong id="sum_sub" dir="ltr">0.000</strong>
            </div>
            <div class="si-sum-box">
              <span>الضريبة</span>
              <strong id="sum_tax" dir="ltr">0.000</strong>
            </div>
            <div class="si-sum-box si-sum-box--grand">
              <span>الإجمالي</span>
              <strong id="sum_grand" dir="ltr">0.000</strong>
            </div>
          </div>
          <div class="si-doc-foot">
            <label class="si-notes">ملاحظات
              <textarea id="inv_notes" rows="3" ${
                initial.is_posted ? 'readonly' : ''
              } placeholder="اختياري…">${esc(initial.notes)}</textarea>
            </label>
            <div class="si-totals">
              <label>خصم مستوى الفاتورة
                <input class="si-field" id="inv_discount" type="text" value="${esc(
                  initial.invoice_discount
                )}"
                       placeholder="10 أو 10% أو 1.000" ${initial.is_posted ? 'readonly' : ''}>
              </label>
            </div>
          </div>
        </section>
      </div>
      <script type="application/json" id="si-initial">${JSON.stringify(initial).replace(
        /</g,
        '\\u003c'
      )}</script>
    `;

    res.send(
      renderApp({
        user: req.session.user,
        title: isNew ? 'فاتورة مبيعات جديدة' : `فاتورة ${initial.invoice_no}`,
        bodyHtml,
        bodyClass: 'si-2027',
        mainClass: 'main si-main',
        css: ['/assets/css/sales-2027.css'],
        js: ['/assets/js/sales-invoice.js'],
      })
    );
  } catch (e) {
    console.error(e);
    res.status(500).send('Error: ' + e.message);
  }
});

/* ── APIs ── */
router.get('/api/sales/invoices', async (req, res) => {
  try {
    const data = await svc.listInvoices({
      q: String(req.query.q || ''),
      filter: String(req.query.filter || 'all'),
      page: Number(req.query.page || 1),
    });
    res.json({ ok: true, ...data });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/invoices/:id', async (req, res) => {
  try {
    const inv = await svc.getInvoice(req.params.id);
    if (!inv) return res.status(404).json({ ok: false, error: 'not_found' });
    res.json({ ok: true, invoice: inv });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/invoices', async (req, res) => {
  try {
    const result = await svc.saveInvoice(req.body || {}, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json({ ...result, message: result.message || 'تم حفظ الفاتورة بدون قيود محاسبية.' });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/invoices/:id/post', async (req, res) => {
  try {
    if (!canAction(req.session.user, 'action_post_sales_invoice')) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية ترحيل.' });
    }
    const id = Number(req.params.id);
    const body = req.body || {};
    // اختياري: حفظ تعديلات قبل الترحيل
    if (body.save_first && body.payload) {
      const saved = await svc.saveInvoice({ ...body.payload, id }, req.session.user.id);
      if (!saved.ok) return res.status(400).json(saved);
    }
    const autoEinvoice = body.auto_einvoice !== false && body.auto_einvoice !== 0;
    const result = await svc.postInvoice(id, req.session.user.id, { autoEinvoice });
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/invoices/:id/unpost', async (req, res) => {
  try {
    if (!canAction(req.session.user, 'action_unpost_sales_invoice')) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية فك الترحيل.' });
    }
    const result = await svc.unpostInvoice(req.params.id, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/invoices/:id/delete', async (req, res) => {
  try {
    if (!canAction(req.session.user, 'action_delete_sales_invoice')) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية حذف.' });
    }
    const result = await svc.deleteInvoice(req.params.id, req.session.user.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.post('/api/sales/invoices/:id/einvoice', async (req, res) => {
  try {
    const u = req.session.user;
    if (
      !canAction(u, 'sales_send_einvoice') &&
      !canAction(u, 'action_post_sales_invoice')
    ) {
      return res.status(403).json({ ok: false, error: 'لا صلاحية إرسال الفوترة.' });
    }
    const result = await svc.sendEinvoice(req.params.id, u.id);
    if (!result.ok) return res.status(400).json(result);
    res.json(result);
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/customers', async (req, res) => {
  try {
    const rows = await svc.searchCustomers(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/items', async (req, res) => {
  try {
    const rows = await svc.searchItems(String(req.query.q || ''));
    res.json({ ok: true, rows });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/lookups', async (req, res) => {
  try {
    const data = await svc.lookups();
    res.json({ ok: true, ...data });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

module.exports = router;
