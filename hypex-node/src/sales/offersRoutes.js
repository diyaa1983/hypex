'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const auth = require('../auth');
const svc = require('./offersService');
const offerApply = require('./offerApply');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const { renderStandalonePrintPage } = require('../lib/printBrand');

const router = express.Router();
const KICKER = 'Hypex Sales · Node';
const HUB = '/sales';
const SCREEN = 'sales_offers';
const REPORT = 'report_sales_offers';
const JS_PATH = path.join(__dirname, '..', '..', 'public', 'js', 'sales-offers.js');

function offersJsSrc() {
  let v = String(Date.now());
  try {
    v = String(Math.floor(fs.statSync(JS_PATH).mtimeMs));
  } catch {
    /* keep */
  }
  return '/assets/js/sales-offers.js?v=' + v;
}

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function canOffers(user) {
  return (
    can(user, SCREEN) ||
    can(user, 'sales_invoices') ||
    can(user, 'sales_customer_orders') ||
    can(user, 'sales_customer_order_entry')
  );
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

function page(user, title, bodyHtml, opts) {
  opts = opts || {};
  return ui.salesPage({
    user,
    title,
    bodyHtml,
    js: opts.js || [],
  });
}

function alertHtml(type, msg) {
  if (!msg) return '';
  const cls = type === 'err' || type === 'error' ? 'si-pill--lock' : 'si-pill--ok';
  return `<p class="si-pill ${cls}" style="display:inline-block;margin:.35rem 0">${esc(msg)}</p>`;
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    !(
      p.startsWith('/sales/offers') ||
      p.startsWith('/sales/reports/offers') ||
      p.startsWith('/api/sales/offers')
    )
  ) {
    return next('router');
  }
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return next();
  });
});

/* ── API preview (طلب/فاتورة) ── */
router.get('/api/sales/offers/preview', async (req, res) => {
  try {
    if (!req.session.user) return res.status(401).json({ ok: false, error: 'غير مصرح' });
    const docDate = String(req.query.date || svc.todayIso()).slice(0, 10);
    let lines = [];
    try {
      lines = JSON.parse(String(req.query.lines || '[]'));
    } catch {
      lines = [];
    }
    if (!Array.isArray(lines) || !lines.length) {
      // صيغة بسيطة: item_id + qty
      const itemId = Number(req.query.item_id || 0);
      const qty = Number(req.query.qty || 0);
      if (itemId) lines = [{ item_id: itemId, qty }];
    }
    const apps = await offerApply.previewOffers(lines, docDate);
    res.json({ ok: true, applications: apps });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'خطأ' });
  }
});

router.get('/api/sales/offers/for-item', async (req, res) => {
  try {
    if (!req.session.user) return res.status(401).json({ ok: false, error: 'غير مصرح' });
    const docDate = String(req.query.date || svc.todayIso()).slice(0, 10);
    const itemId = Number(req.query.item_id || 0);
    const qty = Number(req.query.qty || 0);
    const unitFactor = Number(req.query.unit_factor || 0) > 0 ? Number(req.query.unit_factor) : 1;
    if (!itemId) return res.json({ ok: true, effect: null });
    const map = await svc.activeOfferMapForDate(docDate);
    const offer = map.get(itemId) || null;
    const effect = svc.computeOfferEffect(qty, offer, { unit_factor: unitFactor });
    res.json({
      ok: true,
      effect: effect.applied
        ? {
            offer_no: offer.offer_no,
            offer_name: offer.offer_name,
            offer_type: offer.offer_type,
            trigger_qty: offer.trigger_qty,
            bonus_qty: effect.bonus_qty,
            discount_pct: effect.discount_pct,
          }
        : null,
    });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'خطأ' });
  }
});

router.get('/api/sales/offers/by-no', async (req, res) => {
  try {
    if (!req.session.user) return res.status(401).json({ ok: false, error: 'غير مصرح' });
    const id = await svc.findOfferIdByNo(String(req.query.no || ''));
    if (!id) return res.json({ ok: false, error: 'لم يُعثر على العرض' });
    res.json({ ok: true, id });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message || 'خطأ' });
  }
});

/* ── List ── */
router.get('/sales/offers', async (req, res) => {
  try {
    if (!canOffers(req.session.user)) return forbid(res);
    await svc.ensureSchema();
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const err = String(req.query.err || '');
    const rows = await svc.listOffers({ q: qv });
    const today = svc.todayIso();
    const rowsHtml =
      rows
        .map((r) => {
          const from = String(r.date_from || '').slice(0, 10);
          const to = String(r.date_to || '').slice(0, 10);
          const inRange = from <= today && to >= today;
          const posted = Number(r.is_posted) === 1;
          const active = Number(r.is_active) === 1;
          let st;
          if (!active) st = ui.statusPill('lock', 'موقوف');
          else if (!posted) st = ui.statusPill('wait', 'مسودة');
          else if (inRange) st = ui.statusPill('ok', 'مرحّل · ساري');
          else st = ui.statusPill('wait', 'مرحّل · خارج الفترة');
          return `<tr>
            <td class="si-num" dir="ltr">${esc(r.offer_no || '')}</td>
            <td><strong>${esc(r.name_ar || '')}</strong></td>
            <td class="si-num" dir="ltr">${esc(ui.isoToDmy(from))}</td>
            <td class="si-num" dir="ltr">${esc(ui.isoToDmy(to))}</td>
            <td class="si-num" dir="ltr">${Number(r.lines_count) || 0}</td>
            <td>${st}</td>
            <td>
              <div class="si-act">
                <a class="si-btn" href="/sales/offers/${r.id}">فتح</a>
              </div>
            </td>
          </tr>`;
        })
        .join('') || ui.emptyRow(7, 'لا عروض بعد');

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: '🎁',
          kicker: KICKER,
          title: 'شاشة العرض',
          subtitle: 'حدد فترة العرض والمواد · كمية إضافية أو خصم % · يُطبَّق تلقائياً على طلب الشراء وفاتورة البيع',
          actions: [
            { label: '＋ عرض جديد', href: '/sales/offers/new', primary: true },
            { label: 'تقرير العروض', href: '/sales/reports/offers' },
            { label: 'لوحة المبيعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${err ? alertHtml('err', err) : ''}
        ${ui.railSearch('/sales/offers', qv)}
        ${ui.tableSurface(
          'العروض',
          `${rows.length} صف`,
          ['رقم العرض', 'الاسم', 'من', 'إلى', 'مواد', 'الحالة', ''],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'شاشة العرض', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function renderForm(req, res, id) {
  if (!canOffers(req.session.user)) return forbid(res);
  await svc.ensureSchema();
  const isNew = !id;
  const doc = id ? await svc.getOffer(id) : null;
  if (id && !doc) return res.status(404).send('العرض غير موجود');
  const err = String(req.query.err || '');
  const flash = String(req.query.msg || '');
  const dateFrom = doc ? String(doc.date_from).slice(0, 10) : svc.todayIso();
  const dateTo = doc ? String(doc.date_to).slice(0, 10) : svc.todayIso();
  const linesJson = JSON.stringify(doc?.lines || []).replace(/</g, '\\u003c');
  const activeChecked = !doc || Number(doc.is_active) === 1 ? 'checked' : '';
  const isPosted = !!(doc && Number(doc.is_posted) === 1);
  const isActive = !doc || Number(doc.is_active) === 1;
  const canPost = !!(doc && doc.id && !isPosted && isActive);
  const canUnpost = !!(doc && doc.id && isPosted);
  const canStop = !!(doc && doc.id && isActive);
  const canDelete = !!(doc && doc.id && !isPosted);
  const lockedAttr = isPosted ? 'readonly' : '';
  const lockedDis = isPosted ? 'disabled' : '';
  const nav = await svc.browseNeighbors(doc ? doc.id : 0);
  const offerNo = doc?.offer_no || '';
  const offerName = doc?.name_ar || '';
  const docNoClass = offerNo || (doc && doc.id) ? 'is-saved' : '';
  // الترحيل والإيقاف حالتان مستقلتان — نعرضهما معاً حتى يعرف المستخدم
  // أن عرضاً موقوفاً قد يبقى مرحّلاً فلا يمكن حذفه قبل فك الترحيل.
  const statusBadge = !doc
    ? ''
    : [
        isPosted ? ui.statusPill('ok', 'مرحّل') : ui.statusPill('wait', 'مسودة'),
        isActive ? '' : ui.statusPill('lock', 'موقوف'),
      ]
        .filter(Boolean)
        .join(' ');
  const subtitle = isNew
    ? 'عرض جديد — حدد الفترة والمواد ثم احفظ ورحّل'
    : `سند ${esc(offerNo)}${offerName ? ' · ' + esc(offerName) : ''}`;

  const body = `
    <div class="si-stage" id="so-root" data-so-ux="1"
         data-prev-id="${Number(nav.prev_id) || 0}"
         data-next-id="${Number(nav.next_id) || 0}"
         data-first-id="${Number(nav.first_id) || 0}"
         data-last-id="${Number(nav.last_id) || 0}"
         data-current-id="${doc ? Number(doc.id) : 0}"
         data-offer-no="${esc(offerNo)}"
         data-posted="${isPosted ? '1' : '0'}">
      ${ui.hero({
        mark: '🎁',
        kicker: KICKER,
        title: 'شاشة العرض',
        subtitle: subtitle + (statusBadge ? ' · ' + statusBadge : ''),
        actions: [
          { label: 'القائمة', href: '/sales/offers' },
          { label: 'تقرير العروض', href: '/sales/reports/offers' },
          { label: 'جديد', href: '/sales/offers/new', primary: true },
        ],
      })}
      ${err ? alertHtml('err', err) : ''}
      ${flash ? alertHtml('ok', flash) : ''}

      <div class="si-cmd si-doc-toolbar" role="toolbar">
        <div class="si-tb-group si-tb-group--core">
          <button type="submit" form="so-form" class="si-tb si-tb--save" name="action" value="save" data-hx-save="1"
                  ${isPosted ? 'disabled' : ''}>
            <span class="si-tb-lbl">حفظ</span>
            <span class="si-tb-keywrap"><kbd class="si-tb-key">F10</kbd></span>
          </button>
          ${
            doc && doc.id
              ? `<a class="si-tb" href="/sales/offers/${doc.id}/print" target="_blank" title="طباعة العرض">
                   <span class="si-tb-lbl">طباعة</span>
                 </a>`
              : `<button type="button" class="si-tb" disabled title="احفظ العرض أولاً للطباعة">
                   <span class="si-tb-lbl">طباعة</span>
                 </button>`
          }
          <form method="post" action="/sales/offers/${doc ? doc.id : 0}/post" style="display:inline">
            <button type="submit" class="si-tb si-tb--post" ${canPost ? '' : 'disabled'}>
              <span class="si-tb-lbl">ترحيل</span>
            </button>
          </form>
          <form method="post" action="/sales/offers/${doc ? doc.id : 0}/unpost" style="display:inline">
            <button type="submit" class="si-tb" ${canUnpost ? '' : 'disabled'}>
              <span class="si-tb-lbl">فك الترحيل</span>
            </button>
          </form>
          <form method="post" action="/sales/offers/${doc ? doc.id : 0}/stop" style="display:inline"
                onsubmit="return confirm('إيقاف العرض؟ لن يُطبَّق على الفواتير والطلبات.');">
            <button type="submit" class="si-tb" ${canStop ? '' : 'disabled'}>
              <span class="si-tb-lbl">إيقاف العرض</span>
            </button>
          </form>
          <form method="post" action="/sales/offers/${doc ? doc.id : 0}/delete" style="display:inline"
                onsubmit="return confirm('حذف العرض نهائياً؟ لا يمكن التراجع.');">
            <button type="submit" class="si-tb si-tb--danger" ${canDelete ? '' : 'disabled'}
                    title="${
                      canDelete
                        ? 'حذف العرض نهائياً'
                        : isPosted
                          ? 'العرض مرحّل — اضغط «فك الترحيل» ثم «حذف»'
                          : 'احفظ العرض أولاً ليصبح قابلاً للحذف'
                    }">
              <span class="si-tb-lbl">حذف</span>
            </button>
          </form>
        </div>
        <div class="si-tb-group">
          <a class="si-tb si-tb--ghost" href="/sales/offers"><span class="si-tb-lbl">إلغاء</span></a>
          <a class="si-tb si-tb--ghost" href="/sales/offers/new"><span class="si-tb-lbl">جديد</span></a>
        </div>
      </div>

      <form method="post" action="/sales/offers/save" id="so-form">
        <input type="hidden" name="id" id="so-id" value="${doc ? doc.id : 0}">
        <input type="hidden" name="lines_json" id="so-lines-json" value="">

        <section class="si-surface so-head-card">
          <div class="si-surface-head">
            <h2>بيانات العرض</h2>
            <label class="so-active">
              <input type="checkbox" name="is_active" value="1" ${activeChecked} ${lockedDis}>
              <span>نشط</span>
            </label>
          </div>
          <div class="si-meta si-meta--offer">
            <label class="si-f so-f-docno">
              <span class="si-f-head">رقم السند</span>
              <div class="si-docno-row" dir="ltr">
                <button type="button" class="si-btn si-docno-btn" id="so_first" title="أول سند">«</button>
                <button type="button" class="si-btn si-docno-btn" id="so_prev" title="السابق — ↑ / ←">‹</button>
                <input class="si-field si-field--mono si-docno-input ${docNoClass}" id="so_no" type="text"
                       value="${esc(offerNo)}" placeholder="تلقائي / أدخل رقم ثم Enter" dir="ltr"
                       title="↑/← سابق · ↓/→ تالٍ · Enter بحث برقم">
                <button type="button" class="si-btn si-docno-btn" id="so_next" title="التالي — ↓ / →">›</button>
                <button type="button" class="si-btn si-docno-btn si-docno-btn--last" id="so_last" title="آخر سند">»</button>
              </div>
            </label>
            <label class="si-f so-f-name">
              <span class="si-f-head">اسم العرض *</span>
              <input class="si-field" name="name_ar" id="so-name" required value="${esc(doc?.name_ar || '')}"
                     placeholder="مثال: عرض رمضان" autocomplete="off" ${lockedAttr}>
            </label>
            <label class="si-f so-f-from">
              <span class="si-f-head">بداية العرض *</span>
              <input class="si-field si-field--mono" type="date" name="date_from" id="so-from"
                     value="${esc(dateFrom)}" required ${lockedAttr}>
            </label>
            <label class="si-f so-f-to">
              <span class="si-f-head">نهاية العرض *</span>
              <input class="si-field si-field--mono" type="date" name="date_to" id="so-to"
                     value="${esc(dateTo)}" required ${lockedAttr}>
            </label>
            <label class="si-f so-f-notes">
              <span class="si-f-head">ملاحظات</span>
              <input class="si-field" name="notes" id="so-notes" value="${esc(doc?.notes || '')}"
                     placeholder="اختياري" ${lockedAttr}>
            </label>
          </div>
        </section>

        <section class="si-surface so-lines-card">
          <div class="si-surface-head">
            <div>
              <h2>مواد العرض</h2>
              <p class="muted so-lines-hint">
                اختر المادة ثم وحدة كمية العرض (قطعة / كرتونة) · للكمية الإضافية يُفضَّل نفس الوحدة · أو استخدم خصم %
                ${isPosted ? ' · <strong>العرض مرحّل — افك الترحيل للتعديل</strong>' : ''}
              </p>
            </div>
            <button type="button" class="si-btn si-btn--primary" id="so-add-line" ${isPosted ? 'disabled' : ''}>＋ إضافة مادة</button>
          </div>
          <div class="si-lines-wrap so-lines-wrap">
            <table class="si-lines si-lines--co si-lines--so" id="so-table">
              <thead>
                <tr>
                  <th class="so-th-idx">#</th>
                  <th class="so-th-code">الباركود</th>
                  <th class="so-th-name">اسم المادة</th>
                  <th class="so-th-unit">الوحدة</th>
                  <th class="so-th-qty">كمية العرض</th>
                  <th class="so-th-type">نوع العرض</th>
                  <th class="so-th-bonus">كمية إضافية</th>
                  <th class="so-th-bunit">وحدة الإضافة</th>
                  <th class="so-th-disc">خصم %</th>
                  <th class="si-col-del" title="حذف">حذف</th>
                </tr>
              </thead>
              <tbody id="so-tbody"></tbody>
            </table>
          </div>
        </section>
      </form>
    </div>
    <div id="so-global-suggest" class="pa-global-suggest" hidden></div>
    <script type="application/json" id="so-initial">${linesJson}</script>`;

  res.setHeader('Cache-Control', 'no-store');
  res.send(
    page(req.session.user, 'شاشة العرض', body, {
      js: ['/assets/js/doc-nav.js', offersJsSrc()],
    })
  );
}

async function renderOfferPrint(req, res, offerId) {
  const doc = await svc.getOffer(offerId);
  if (!doc) return res.status(404).send('العرض غير موجود');

  const lines = Array.isArray(doc.lines) ? doc.lines : [];
  const isPosted = Number(doc.is_posted) === 1;
  const isActive = Number(doc.is_active) === 1;
  const statusParts = [isPosted ? 'مرحّل' : 'مسودة', isActive ? 'نشط' : 'موقوف'];
  const dateFrom = ui.isoToDmy(String(doc.date_from || '').slice(0, 10));
  const dateTo = ui.isoToDmy(String(doc.date_to || '').slice(0, 10));

  const bodyRows =
    lines
      .map((ln, i) => {
        const isDisc = String(ln.offer_type) === 'discount_pct';
        const typeLabel = isDisc ? 'خصم %' : 'كمية إضافية';
        const detail = isDisc
          ? `${ui.fmtAmt(ln.discount_pct)}%`
          : `${ui.fmtAmt(ln.bonus_qty)} ${ln.bonus_unit_name || ''}`.trim();
        return `<tr>
            <td class="c-idx" dir="ltr">${i + 1}</td>
            <td class="c-code" dir="ltr">${esc(ln.item_code || '')}</td>
            <td class="c-name">${esc(ln.item_name || '')}</td>
            <td class="c-unit">${esc(ln.unit_name || 'قطعة')}</td>
            <td class="c-num" dir="ltr">${esc(ui.fmtAmt(ln.trigger_qty))}</td>
            <td>${esc(typeLabel)}</td>
            <td class="c-num" dir="ltr">${esc(detail)}</td>
          </tr>`;
      })
      .join('') || `<tr><td colspan="7" class="empty">لا مواد في العرض</td></tr>`;

  const notesHtml = doc.notes
    ? `<div class="inv-v1-notes"><span>ملاحظات:</span> ${esc(doc.notes)}</div>`
    : '';

  const contentHtml = `
      <div class="inv-v1" dir="rtl">
        <div class="inv-v1-top">
          <div class="inv-v1-meta">
            <div><span>رقم السند:</span> <strong dir="ltr">${esc(doc.offer_no || '—')}</strong></div>
            <div><span>اسم العرض:</span> <strong>${esc(doc.name_ar || '—')}</strong></div>
            <div><span>الفترة:</span> <strong dir="ltr">${esc(dateFrom)} — ${esc(dateTo)}</strong></div>
            <div><span>الحالة:</span> <strong>${esc(statusParts.join(' · '))}</strong></div>
          </div>
          <div class="inv-v1-title-block">
            <h1 class="inv-v1-title">عرض ترويجي</h1>
          </div>
        </div>

        <table class="inv-v1-table">
          <thead>
            <tr>
              <th>#</th>
              <th>الباركود</th>
              <th>اسم المادة</th>
              <th>الوحدة</th>
              <th>كمية العرض</th>
              <th>نوع العرض</th>
              <th>تفاصيل العرض</th>
            </tr>
          </thead>
          <tbody>${bodyRows}</tbody>
        </table>

        <div class="inv-v1-foot">
          ${notesHtml}
        </div>
      </div>`;

  res.send(
    await renderStandalonePrintPage({
      user: req.session.user,
      documentTitle: 'عرض ترويجي',
      backHref: `/sales/offers/${doc.id}`,
      contentHtml,
      autoPrint: false,
      printMode: 'sheet',
      theme: 'invoice-v1',
    })
  );
}

router.get('/sales/offers/new', (req, res) => renderForm(req, res, 0));
router.get('/sales/offers/:id/print', async (req, res) => {
  try {
    if (!canOffers(req.session.user)) return forbid(res);
    const id = Number(req.params.id);
    if (!id) return res.status(404).send('العرض غير موجود');
    await renderOfferPrint(req, res, id);
  } catch (e) {
    console.error(e);
    res.status(500).send(e.message || 'خطأ');
  }
});
router.get('/sales/offers/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/sales/offers');
  return renderForm(req, res, id);
});

router.post('/sales/offers/save', async (req, res) => {
  if (!canOffers(req.session.user)) return forbid(res);
  const body = req.body || {};
  let lines = [];
  try {
    lines = JSON.parse(String(body.lines_json || '[]'));
  } catch {
    lines = [];
  }
  const result = await svc.saveOffer(
    {
      id: body.id,
      name_ar: body.name_ar,
      date_from: body.date_from,
      date_to: body.date_to,
      notes: body.notes,
      is_active: body.is_active ? 1 : 0,
      lines,
    },
    req.session.user?.id
  );
  if (!result.ok) {
    return res.redirect(
      (Number(body.id) > 0 ? '/sales/offers/' + body.id : '/sales/offers/new') +
        '?err=' +
        encodeURIComponent(result.error || 'خطأ')
    );
  }
  res.redirect(
    '/sales/offers/' + result.id + '?msg=' + encodeURIComponent(result.message || 'تم الحفظ')
  );
});

router.post('/sales/offers/:id/toggle', async (req, res) => {
  if (!canOffers(req.session.user)) return forbid(res);
  const result = await svc.toggleOffer(req.params.id);
  res.redirect(
    '/sales/offers?msg=' + encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales/offers/:id/post', async (req, res) => {
  if (!canOffers(req.session.user)) return forbid(res);
  const id = Number(req.params.id);
  const result = await svc.postOffer(id);
  const q = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales/offers/' + id + '?' + q + '=' + encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales/offers/:id/unpost', async (req, res) => {
  if (!canOffers(req.session.user)) return forbid(res);
  const id = Number(req.params.id);
  const result = await svc.unpostOffer(id);
  const q = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales/offers/' + id + '?' + q + '=' + encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales/offers/:id/stop', async (req, res) => {
  if (!canOffers(req.session.user)) return forbid(res);
  const id = Number(req.params.id);
  const result = await svc.stopOffer(id);
  const q = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales/offers/' + id + '?' + q + '=' + encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales/offers/:id/delete', async (req, res) => {
  if (!canOffers(req.session.user)) return forbid(res);
  const id = Number(req.params.id);
  const result = await svc.deleteOffer(id);
  if (result.ok) {
    return res.redirect(
      '/sales/offers?msg=' + encodeURIComponent(result.message || 'تم الحذف')
    );
  }
  res.redirect(
    '/sales/offers/' +
      id +
      '?err=' +
      encodeURIComponent(result.error || 'تعذر الحذف')
  );
});

/* ── Report ── */
router.get('/sales/reports/offers', async (req, res) => {
  try {
    if (
      !can(req.session.user, REPORT) &&
      !canOffers(req.session.user)
    ) {
      return forbid(res);
    }
    await svc.ensureSchema();
    const from = String(req.query.from || '').slice(0, 10) || svc.todayIso().slice(0, 8) + '01';
    const to = String(req.query.to || '').slice(0, 10) || svc.todayIso();
    const qv = String(req.query.q || '');
    const view = String(req.query.view || 'usage') === 'defs' ? 'defs' : 'usage';

    let rowsHtml = '';
    let count = 0;
    let headers = [];
    let title = '';

    if (view === 'defs') {
      const rows = await svc.reportOfferDefinitions({ q: qv });
      count = rows.length;
      title = 'تعريف العروض والمواد';
      headers = [
        '#',
        'رقم العرض',
        'اسم العرض',
        'من',
        'إلى',
        'الحالة',
        'الباركود',
        'المادة',
        'النوع',
        'كمية العرض',
        'كمية إضافية',
        'خصم %',
      ];
      rowsHtml =
        rows
          .map((r, i) => {
            const typ = String(r.offer_type) === 'discount_pct' ? 'خصم %' : 'كمية إضافية';
            return `<tr>
              <td class="si-num" dir="ltr">${i + 1}</td>
              <td class="si-num" dir="ltr">${esc(r.offer_no || '')}</td>
              <td>${esc(r.name_ar || '')}</td>
              <td class="si-num" dir="ltr">${esc(ui.isoToDmy(String(r.date_from).slice(0, 10)))}</td>
              <td class="si-num" dir="ltr">${esc(ui.isoToDmy(String(r.date_to).slice(0, 10)))}</td>
              <td>${Number(r.is_active) ? (Number(r.is_posted) ? 'مرحّل' : 'مسودة') : 'موقوف'}</td>
              <td class="si-num" dir="ltr">${esc(r.item_code || '')}</td>
              <td>${esc(r.item_name || '')}</td>
              <td>${esc(typ)}</td>
              <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.trigger_qty))}</td>
              <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.bonus_qty))}</td>
              <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.discount_pct))}</td>
            </tr>`;
          })
          .join('') || ui.emptyRow(12, 'لا تعريفات');
    } else {
      const rows = await svc.reportOffers({ from, to, q: qv });
      count = rows.length;
      title = 'تطبيقات العروض على الطلبات والفواتير';
      headers = [
        '#',
        'التاريخ',
        'النوع',
        'رقم المستند',
        'رقم العرض',
        'اسم العرض',
        'الباركود',
        'المادة',
        'الكمية',
        'كمية إضافية',
        'خصم %',
      ];
      rowsHtml =
        rows
          .map((r, i) => {
            const dtype = String(r.doc_type) === 'order' ? 'طلب شراء' : 'فاتورة';
            return `<tr>
              <td class="si-num" dir="ltr">${i + 1}</td>
              <td class="si-num" dir="ltr">${esc(ui.isoToDmy(String(r.doc_date).slice(0, 10)))}</td>
              <td>${esc(dtype)}</td>
              <td class="si-num" dir="ltr">${esc(r.doc_no || r.doc_id || '')}</td>
              <td class="si-num" dir="ltr">${esc(r.offer_no || '')}</td>
              <td>${esc(r.offer_name || '')}</td>
              <td class="si-num" dir="ltr">${esc(r.item_code || '')}</td>
              <td>${esc(r.item_name || '')}</td>
              <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.qty))}</td>
              <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.bonus_qty))}</td>
              <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.discount_pct))}</td>
            </tr>`;
          })
          .join('') || ui.emptyRow(11, 'لا تطبيقات في الفترة');
    }

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: '📋',
          kicker: KICKER,
          title: 'تقرير العروض',
          subtitle: 'تعريف العروض وتطبيقها على طلبات الشراء وفواتير البيع',
          actions: [
            { label: 'شاشة العرض', href: '/sales/offers', primary: true },
            { label: 'لوحة المبيعات', href: HUB },
            { label: 'طباعة', print: true },
          ],
        })}
        <section class="si-surface" style="padding:.85rem 1rem;margin-bottom:.75rem">
          <form method="get" action="/sales/reports/offers" class="si-meta" style="align-items:end;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));">
            <label>العرض
              <select class="si-field" name="view">
                <option value="usage" ${view === 'usage' ? 'selected' : ''}>تطبيقات العروض</option>
                <option value="defs" ${view === 'defs' ? 'selected' : ''}>تعريف العروض</option>
              </select>
            </label>
            <label>من تاريخ
              <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}" dir="ltr">
            </label>
            <label>إلى تاريخ
              <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}" dir="ltr">
            </label>
            <label>بحث
              <input class="si-field" name="q" value="${esc(qv)}" placeholder="عرض / مادة / مستند">
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض</button>
          </form>
        </section>
        ${ui.tableSurface(title, `${count} صف`, headers, rowsHtml)}
      </div>`;
    res.send(page(req.session.user, 'تقرير العروض', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

module.exports = router;
