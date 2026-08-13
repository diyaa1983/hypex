'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./invoicesService');
const { renderApp, phpUrl, embedUrl } = require('../lib/layout');
const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const ui = require('../lib/salesUi');
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
  const allowPost = canAction(user, 'action_post_sales_invoice');
  const allowUnpost = canAction(user, 'action_unpost_sales_invoice');
  const allowDelete = canAction(user, 'action_delete_sales_invoice');
  const allowArchive = canAction(user, 'action_archive_sales_invoice');
  const allowEinvoice =
    canAction(user, 'sales_send_einvoice') || canAction(user, 'action_post_sales_invoice');
  return {
    canSave: !posted,
    canPost: !posted && hasId && allowPost,
    canUnpost: posted && allowUnpost,
    canDelete: hasId && !posted && allowDelete,
    canEinvoice: posted && allowEinvoice,
    canArchive: hasId && allowArchive,
    canPrint: hasId,
    canPdf: hasId,
    canExcel: hasId,
    canEmail: hasId,
    // صلاحيات مستقلة عن وجود id — لتفعيل الأزرار فوراً بعد الحفظ بدون إعادة تحميل
    allowPost,
    allowUnpost,
    allowDelete,
    allowArchive,
    allowEinvoice,
  };
}

function toolbarHtml(caps, inv) {
  const id = inv && inv.id ? Number(inv.id) : 0;
  const posted = !!(inv && inv.is_posted);
  const b = (idAttr, label, cls, disabled, extra = '', key = '') => {
    const keyHtml = key
      ? `<kbd class="si-tb-key" title="${esc(key)}">${esc(key)}</kbd>`
      : '';
    return `<button type="button" class="si-tb ${cls || ''}" id="${idAttr}" ${
      disabled ? 'disabled' : ''
    }${extra}><span class="si-tb-lbl">${esc(label)}</span>${keyHtml}</button>`;
  };
  const d = (on) => (on ? '1' : '0');

  return `
    <div class="si-cmd si-doc-toolbar" id="si-doc-bar" role="toolbar" aria-label="إجراءات الفاتورة"
         data-invoice-id="${id}" data-posted="${posted ? '1' : '0'}"
         data-allow-post="${d(caps.allowPost)}" data-allow-unpost="${d(caps.allowUnpost)}"
         data-allow-delete="${d(caps.allowDelete)}" data-allow-archive="${d(caps.allowArchive)}"
         data-allow-einvoice="${d(caps.allowEinvoice)}">
      <div class="si-tb-group si-tb-group--core">
        ${b('si-save', 'حفظ', 'si-tb--save', !caps.canSave, ' data-hx-save="1" title="حفظ — F10"', 'F10')}
        ${b('si-post', 'ترحيل', 'si-tb--post', !caps.canPost && !(caps.canSave && !id), ' title="ترحيل"')}
      </div>
      <div class="si-tb-group">
        ${b('si-search', 'بحث', 'si-tb--ghost', false, ' title="قائمة الفواتير"')}
        ${b('si-pdf', 'PDF', '', !caps.canPdf)}
        ${b('si-print', 'طباعة', '', !caps.canPrint)}
        ${b('si-excel', 'Excel', '', !caps.canExcel)}
        ${b('si-archive', 'أرشيف', '', !caps.canArchive)}
        ${b('si-email', 'Email', '', !caps.canEmail)}
        ${b('si-einvoice', 'الفوترة', 'si-tb--accent', !caps.canEinvoice)}
      </div>
      <div class="si-tb-group si-tb-group--risk">
        ${b('si-unpost', 'فك الترحيل', '', !caps.canUnpost)}
        ${b(
          'si-delete',
          'حذف',
          'si-tb--danger',
          !caps.canDelete,
          ' data-hx-delete="1" title="حذف الفاتورة"'
        )}
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
      .map((r) => {
        const pay = r.payment_type === 'cash' ? 'نقدي' : 'ذمم';
        const st = r.is_posted ? ui.statusPill('ok', 'مرحّلة') : ui.statusPill('wait', 'مسودة');
        return `<tr>
          <td class="si-num" dir="ltr">${esc(r.invoice_no)}</td>
          <td class="si-num" dir="ltr">${esc(isoToDmy(r.invoice_date))}</td>
          <td>${esc(r.customer_name || '—')}</td>
          <td>${esc(pay)}</td>
          <td>${st}</td>
          <td class="si-num" dir="ltr">${esc(fmtAmt(r.total))}</td>
          <td><div class="si-act">
            <a class="si-btn" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" href="/sales/invoices/${r.id}">فتح</a>
            ${
              !r.is_posted && canPost
                ? `<button type="button" class="si-btn js-list-post" style="min-height:1.7rem;padding:.2rem .55rem;font-size:.75rem;border-radius:8px" data-id="${r.id}">ترحيل</button>`
                : ''
            }
          </div></td>
        </tr>`;
      })
      .join('');

    const bodyHtml = `
      <div class="si-stage">
        ${ui.hero({
          mark: 'SI',
          kicker: 'Hypex Sales · Node',
          title: 'فواتير المبيعات',
          subtitle: 'قائمة الفواتير — فتح وتعديل داخل Node',
          actions: [
            { label: '＋ فاتورة جديدة', href: '/sales/invoices/new', primary: true },
            { label: 'لوحة المبيعات', href: '/sales' },
          ],
        })}

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

        ${ui.tableSurface(
          'سجل الفواتير',
          `${data.total} صف`,
          ['الرقم', 'التاريخ', 'العميل', 'النوع', 'الحالة', 'الإجمالي', ''],
          rows || ui.emptyRow(7, 'لا توجد فواتير بعد — أنشئ أول فاتورة')
        )}
      </div>
      <script>
      (function(){
        function ask(msg){
          if(window.HypexUI&&window.HypexUI.confirm) return window.HypexUI.confirm(msg,{title:'ترحيل',okLabel:'ترحيل',cancelLabel:'إلغاء'});
          return Promise.resolve(window.confirm(msg));
        }
        document.querySelectorAll('.js-list-post').forEach(function(btn){
          btn.addEventListener('click', function(){
            var id = btn.getAttribute('data-id');
            if(!id) return;
            ask('ترحيل الفاتورة؟ (قيود + مستودع ثم الفوترة)').then(function(ok){
              if(!ok) return;
              btn.disabled = true;
              fetch('/api/sales/invoices/'+id+'/post', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'})
                .then(function(r){return r.json()})
                .then(function(d){
                  if(window.HypexUI&&window.HypexUI.alert) window.HypexUI.alert(d.message||d.error||(d.ok?'تم':'فشل'), d.ok?'ok':'error');
                  else alert(d.message||d.error||(d.ok?'تم':'فشل'));
                  if(d.ok) location.reload();
                  else btn.disabled=false;
                })
                .catch(function(){ btn.disabled=false; if(window.HypexUI) window.HypexUI.alert('تعذر الاتصال','error'); else alert('تعذر الاتصال'); });
            });
          });
        });
      })();
      </script>
    `;

    res.send(
      ui.salesPage({
        user: req.session.user,
        title: 'فواتير المبيعات',
        bodyHtml,
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
    // اسم العميل فقط دون الرمز
    const custLabel = String(inv.customer_name || '').trim() || '—';

    const einvSent = !!inv.einv_sent;
    const qrSrc = einvSent ? einvQrImageSrc(inv.einv_qr) : null;

    const lines = Array.isArray(inv.lines) ? inv.lines : [];
    const showExtra = lines.some((ln) => (Number(ln.qty_extra) || 0) > 0.000001);
    const discLinesTotal = lines.reduce((a, ln) => a + (Number(ln.discount_amount) || 0), 0);
    const hasLineDisc = lines.some(
      (ln) =>
        (Number(ln.discount_pct) || 0) > 0.000001 || (Number(ln.discount_amount) || 0) > 0.000001
    );
    const invDiscRaw = String(inv.invoice_discount_input || '').trim();
    // أي خصم فاتورة غير فارغ/غير صفري يظهر
    let hasInvDisc = false;
    if (invDiscRaw) {
      const stripped = invDiscRaw.replace(/%/g, '').replace(/,/g, '').trim();
      const n = Number(stripped);
      hasInvDisc = Number.isFinite(n) ? Math.abs(n) > 0.000001 : invDiscRaw !== '0' && invDiscRaw !== '0.000';
    }
    const showDisc = hasLineDisc || hasInvDisc || discLinesTotal > 0.000001;
    const invDiscLabel = invDiscRaw || fmtAmt(0);
    const colCount = 11 + (showExtra ? 1 : 0) + (showDisc ? 1 : 0);

    const bodyRows =
      lines
        .map((ln, i) => {
          const qty = Number(ln.qty) || 0;
          const qtyExtra = Number(ln.qty_extra) || 0;
          const discPct = Number(ln.discount_pct) || 0;
          const discAmt = Number(ln.discount_amount) || 0;
          const taxPct = Number(ln.tax_rate_percent) || 0;
          const unitNet = Number(ln.unit_price) || 0;
          const unitGross = unitNet * (1 + taxPct / 100);
          const discCell =
            discPct > 0.000001 ? `${fmtAmt(discPct)}%` : fmtAmt(discAmt);
          return `<tr>
            <td class="c-idx" dir="ltr">${i + 1}</td>
            <td class="c-code" dir="ltr">${esc(ln.item_code || '')}</td>
            <td class="c-name">${esc(ln.name_ar || '')}</td>
            <td class="c-unit">${esc(ln.unit_name || 'قطعة')}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(qty))}</td>
            ${
              showExtra
                ? `<td class="c-num" dir="ltr">${esc(fmtAmt(qtyExtra))}</td>`
                : ''
            }
            <td class="c-num" dir="ltr">${esc(fmtAmt(unitNet))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(unitGross))}</td>
            ${
              showDisc
                ? `<td class="c-num c-disc" dir="ltr">${esc(discCell)}</td>`
                : ''
            }
            <td class="c-num" dir="ltr">${esc(fmtAmt(ln.line_total))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(ln.tax_amount))}</td>
            <td class="c-num" dir="ltr">${esc(fmtAmt(taxPct))}%</td>
            <td class="c-num c-gross" dir="ltr">${esc(fmtAmt(ln.line_gross))}</td>
          </tr>`;
        })
        .join('') ||
      `<tr><td colspan="${colCount}" class="empty">لا بنود</td></tr>`;

    const qrBlock =
      qrSrc
        ? `<div class="inv-v1-qr"><img src="${esc(qrSrc)}" width="120" height="120" alt="QR الفوترة"></div>`
        : '';

    const discSumRows = showDisc
      ? `<tr>
                <td class="lbl">خصم الفاتورة</td>
                <td class="val" dir="ltr">${esc(invDiscLabel)}</td>
              </tr>
              <tr>
                <td class="lbl">مجموع الخصم</td>
                <td class="val" dir="ltr">${esc(fmtAmt(discLinesTotal))}</td>
              </tr>`
      : '';

    const sumsBlock = `<div class="inv-v1-sumwrap">
            <table class="inv-v1-sum">
              ${discSumRows}
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
              <th>الباركود</th>
              <th>اسم المادة</th>
              <th>الوحدة</th>
              <th>الكمية</th>
              ${showExtra ? '<th>الكمية الإضافية</th>' : ''}
              <th>الافرادي غ.ش</th>
              <th>الافرادي ش.</th>
              ${showDisc ? '<th>الخصم</th>' : ''}
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
    const nav = inv
      ? await svc.browseNeighbors(inv.id)
      : await svc.browseNeighbors(0);
    const initial = {
      id: inv ? inv.id : 0,
      invoice_no: inv ? inv.invoice_no : '',
      invoice_date: inv ? inv.invoice_date : todayIso(),
      customer_id: inv ? inv.customer_id : 0,
      customer_label: inv ? `${inv.customer_code || ''} — ${inv.customer_name}` : '',
      customer_email: inv ? inv.customer_email || '' : '',
      use_wholesale_price: inv ? Number(inv.use_wholesale_price) === 1 ? 1 : 0 : 0,
      sales_rep_id: inv ? inv.sales_rep_id : null,
      warehouse_id: inv ? inv.warehouse_id : lookups.warehouses[0]?.id || null,
      payment_type: inv ? inv.payment_type : 'credit',
      notes: inv ? inv.notes : '',
      invoice_discount: inv ? inv.invoice_discount_input : '',
      is_posted: inv ? inv.is_posted : false,
      prev_id: nav.prev_id || 0,
      next_id: nav.next_id || 0,
      first_id: nav.first_id || 0,
      last_id: nav.last_id || 0,
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
        archiveUrl: inv ? '/sales/invoices/' + inv.id + '/print' : '',
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
          <div class="si-meta si-meta--invoice">
            <label class="si-f si-f--docno">
              <span class="si-f-head">رقم الفاتورة</span>
              <div class="si-docno-row" dir="ltr">
                <button type="button" class="si-btn si-docno-btn" id="inv_first" title="أول فاتورة">«</button>
                <button type="button" class="si-btn si-docno-btn" id="inv_prev" title="السابق">‹</button>
                <input class="si-field si-field--mono si-docno-input" id="inv_no" type="text" value="${esc(
                  initial.invoice_no
                )}" readonly placeholder="رقم — Enter" dir="ltr"
                       title="← سابق · → آخر · ↑↓ سابق/تالٍ · Enter بحث">
                <button type="button" class="si-btn si-docno-btn" id="inv_next" title="التالي">›</button>
                <button type="button" class="si-btn si-docno-btn si-docno-btn--last" id="inv_last" title="آخر فاتورة (أكبر رقم)">»</button>
              </div>
            </label>
            <label class="si-f si-f--date">
              <span class="si-f-head">التاريخ</span>
              <input class="si-field si-field--mono" id="inv_date" type="date" value="${esc(
                String(initial.invoice_date).slice(0, 10)
              )}" ${initial.is_posted ? 'readonly' : ''}>
            </label>
            <label class="si-f si-f--pay">
              <span class="si-f-head">النوع</span>
              <select class="si-field" id="inv_pay" ${initial.is_posted ? 'disabled' : ''}>
                <option value="credit"${
                  initial.payment_type === 'credit' ? ' selected' : ''
                }>ذمم</option>
                <option value="cash"${
                  initial.payment_type === 'cash' ? ' selected' : ''
                }>نقدي</option>
              </select>
            </label>
            <label class="si-f si-f--wh">
              <span class="si-f-head">المستودع</span>
              <select class="si-field" id="inv_wh" ${initial.is_posted ? 'disabled' : ''}>
                <option value="">—</option>
                ${whOpts}
              </select>
            </label>
            <label class="si-f si-f--cust">
              <span class="si-f-head">
                العميل
                <span class="si-key-hint" title="اختيار عميل"><kbd class="si-field-key">F7</kbd><span class="si-key-desc">بحث عميل</span></span>
                <span id="inv_price_mode_hint" class="si-price-mode" hidden></span>
              </span>
              <div class="si-cust-wrap">
                <input type="hidden" id="inv_customer_id" value="${initial.customer_id || ''}">
                <input class="si-field" id="inv_customer" type="search" placeholder="ابحث بالاسم أو الرمز…"
                       value="${esc(initial.customer_label)}" autocomplete="off" ${
                         initial.is_posted ? 'readonly' : ''
                       } data-nav="1">
                <div class="si-suggest" id="cust_suggest" hidden></div>
              </div>
            </label>
          </div>
        </section>

        <section class="si-surface">
          <div class="si-surface-head">
            <h2>بنود الفاتورة</h2>
            <span class="si-count si-count--keys">
              <span class="si-key-hint" title="سطر بند جديد"><kbd class="si-field-key">F2</kbd><span class="si-key-desc">سطر جديد</span></span>
              <span class="si-key-hint" title="قائمة المواد"><kbd class="si-field-key">F3</kbd><span class="si-key-desc">قائمة مواد</span></span>
              <span class="si-key-hint" title="حذف بند المادة"><kbd class="si-field-key">F4</kbd><span class="si-key-desc">حذف بند</span></span>
              <span class="si-key-hint" title="حفظ"><kbd class="si-field-key">F10</kbd><span class="si-key-desc">حفظ</span></span>
            </span>
          </div>
          <div class="si-lines-wrap">
            <table class="si-lines si-lines--co" id="si-lines">
              <thead>
                <tr>
                  <th style="width:2rem">#</th>
                  <th>الباركود</th>
                  <th>اسم المادة</th>
                  <th>الوحدة</th>
                  <th style="width:5.5rem">الكمية</th>
                  <th style="width:5.2rem">إضافية</th>
                  <th style="width:6.2rem">السعر</th>
                  <th style="width:4.8rem">خصم %</th>
                  <th style="width:5rem">ضريبة %</th>
                  <th style="width:6.2rem">الصافي</th>
                  <th style="width:6.2rem">الإجمالي</th>
                  <th style="width:2.4rem"></th>
                </tr>
              </thead>
              <tbody id="si-lines-body"></tbody>
            </table>
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
              <div class="si-tot-row"><span>بدون ضريبة</span><strong id="sum_sub" dir="ltr">0.000</strong></div>
              <div class="si-tot-row"><span>الضريبة</span><strong id="sum_tax" dir="ltr">0.000</strong></div>
              <div class="si-tot-row si-tot-grand"><span>الإجمالي</span><strong id="sum_grand" dir="ltr">0.000</strong></div>
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
        js: ['/assets/js/doc-nav.js', '/assets/js/hx-offers-client.js', '/assets/js/sales-invoice.js'],
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

router.get('/api/sales/invoices/by-no', async (req, res) => {
  try {
    const id = await svc.findInvoiceIdByNo(req.query.no);
    if (!id) return res.status(404).json({ ok: false, error: 'لم يُعثر على فاتورة بهذا الرقم' });
    res.json({ ok: true, id });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

router.get('/api/sales/invoices/:id', async (req, res) => {
  try {
    const inv = await svc.getInvoice(req.params.id);
    if (!inv) return res.status(404).json({ ok: false, error: 'not_found' });
    const nav = await svc.browseNeighbors(inv.id);
    res.json({
      ok: true,
      invoice: inv,
      nav: {
        prev_id: nav.prev_id || 0,
        next_id: nav.next_id || 0,
        first_id: nav.first_id || 0,
        last_id: nav.last_id || 0,
      },
      caps: toolbarCaps(req.session.user, inv),
    });
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

router.post('/api/sales/invoices/:id/email', async (req, res) => {
  try {
    const id = Number(req.params.id);
    if (id < 1) return res.status(400).json({ ok: false, error: 'فاتورة غير صالحة.' });
    const body = req.body || {};
    const to = String(body.to_email || body.email || '').trim();
    const result = await svc.sendInvoiceEmail(id, to, req.session.user.id);
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
