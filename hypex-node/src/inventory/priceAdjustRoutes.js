'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./priceAdjustService');
const ui = require('../lib/salesUi');
const { esc } = require('../lib/html');
const { todayIso } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex Inventory · Node';
const HUB = '/inventory';
const SCREEN = 'item_sale_price_adjust';
const REPORT = 'report_item_price_adjustments';

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

function forbid(res) {
  return res.status(403).send('ممنوع');
}

function page(user, title, bodyHtml) {
  return ui.salesPage({
    user,
    title,
    bodyHtml,
    css: ['/assets/css/sales-2027.css'],
  });
}

function alertHtml(type, msg) {
  if (!msg) return '';
  const cls = type === 'err' || type === 'error' ? 'si-pill--lock' : 'si-pill--ok';
  return `<p class="si-pill ${cls}" style="display:inline-block;margin:.35rem 0">${esc(msg)}</p>`;
}

function guard(req, res, next) {
  if (!req.session || !req.session.user) return auth.requireAuth(req, res, next);
  if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) {
    return forbid(res);
  }
  return next();
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (
    !(
      p.startsWith('/inventory/price-adjust') ||
      p.startsWith('/inventory/reports/price-adjustments') ||
      p.startsWith('/api/inventory/price-adjust')
    )
  ) {
    return next('router');
  }
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return next();
  });
});

/* ── API item prices ── */
router.get('/api/inventory/price-adjust/item/:id', guard, async (req, res) => {
  try {
    const item = await svc.getItemPrices(req.params.id);
    if (!item) return res.json({ ok: false, error: 'مادة غير موجودة أو موقوفة.' });
    res.json({ ok: true, item });
  } catch (e) {
    res.status(500).json({ ok: false, error: e.message });
  }
});

/* ── List docs ── */
router.get('/inventory/price-adjust', async (req, res) => {
  try {
    if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) return forbid(res);
    await svc.ensureSchema();
    const qv = String(req.query.q || '');
    const flash = String(req.query.msg || '');
    const err = String(req.query.err || '');
    const rows = await svc.listDocs({ q: qv });
    const rowsHtml =
      rows
        .map((r) => {
          const st =
            String(r.status) === 'posted'
              ? ui.statusPill('ok', 'مرحّل')
              : ui.statusPill('wait', 'مسودة');
          return `<tr>
            <td class="si-num" dir="ltr">${esc(r.adj_no || '')}</td>
            <td class="si-num" dir="ltr">${esc(ui.isoToDmy(r.adj_date))}</td>
            <td>${st}</td>
            <td class="si-num" dir="ltr">${Number(r.line_count || 0)}</td>
            <td>${esc(r.created_by_name || '—')}</td>
            <td>${esc(r.notes || '—')}</td>
            <td><a class="si-btn" href="/inventory/price-adjust/${r.id}">فتح</a></td>
          </tr>`;
        })
        .join('') || ui.emptyRow(7, 'لا تعديلات بعد');

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: '💰',
          kicker: KICKER,
          title: 'تعديل أسعار البيع',
          subtitle: 'أدخل الأسعار الجديدة للبيع والجملة — تُعتمد على بطاقة المادة عند الترحيل فقط',
          actions: [
            { label: '＋ تعديل جديد', href: '/inventory/price-adjust/new', primary: true },
            { label: 'تقرير الأسعار المعدّلة', href: '/inventory/reports/price-adjustments' },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        ${flash ? alertHtml('ok', flash) : ''}
        ${err ? alertHtml('err', err) : ''}
        ${ui.railSearch('/inventory/price-adjust', qv)}
        ${ui.tableSurface(
          'سجل حركات التعديل',
          `${rows.length} صف`,
          ['رقم الحركة', 'التاريخ', 'الحالة', 'مواد', 'الموظف', 'ملاحظات', ''],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'تعديل أسعار البيع', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

async function renderForm(req, res, id) {
  if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) return forbid(res);
  await svc.ensureSchema();
  const isNew = !id;
  const doc = id ? await svc.getDoc(id) : null;
  if (id && !doc) return res.status(404).send('الحركة غير موجودة');
  const isPosted = !!(doc && doc.is_posted);
  const err = String(req.query.err || '');
  const flash = String(req.query.msg || '');
  const adjDate = doc ? doc.adj_date : svc.todayIso();
  const linesJson = JSON.stringify(doc?.lines || []).replace(/</g, '\\u003c');

  const badge = isPosted
    ? '<span class="si-pill si-pill--lock">مرحّلة</span>'
    : '<span class="si-pill si-pill--wait">مسودة</span>';

  const body = `
    <div class="si-stage" id="pa-root">
      ${ui.hero({
        mark: '💰',
        kicker: KICKER,
        title: isNew ? 'تعديل أسعار بيع جديد' : `حركة ${esc(doc.adj_no || '')}`,
        subtitle: 'التاريخ تلقائي · السعر يُعتمد عند الترحيل فقط · أقل وحدة غير شامل الضريبة',
        actions: [
          { label: 'السجل', href: '/inventory/price-adjust' },
          { label: 'تقرير التعديلات', href: '/inventory/reports/price-adjustments' },
          { label: 'جديد', href: '/inventory/price-adjust/new' },
        ],
      })}
      ${err ? alertHtml('err', err) : ''}
      ${flash ? alertHtml('ok', flash) : ''}
      ${badge}

      <section class="si-surface" style="margin-top:.65rem">
        <div class="si-surface-head">
          <h2>بيانات الحركة</h2>
          <span class="si-count">${isPosted ? 'قراءة فقط' : 'مسودة'}</span>
        </div>
        <form method="post" action="/inventory/price-adjust/save" id="pa-form" style="padding:1rem 1.1rem">
          <input type="hidden" name="id" id="pa-id" value="${doc ? doc.id : 0}">
          <input type="hidden" name="lines_json" id="pa-lines-json" value="">
          <div class="si-meta">
            <label>رقم الحركة
              <input class="si-field si-field--mono" value="${esc(doc?.adj_no || 'تلقائي عند الحفظ')}" readonly dir="ltr">
            </label>
            <label>تاريخ التعديل
              <input class="si-field si-field--mono" type="date" name="adj_date" id="pa-date"
                     value="${esc(adjDate)}" dir="ltr" readonly title="تاريخ تلقائي — لا يُعدّل">
            </label>
            <label class="si-span-2">ملاحظات
              <input class="si-field" name="notes" id="pa-notes" value="${esc(doc?.notes || '')}"
                     ${isPosted ? 'readonly' : ''} maxlength="500" placeholder="اختياري…">
            </label>
          </div>

          <div style="margin-top:1rem">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap">
              <strong>المواد</strong>
              ${
                isPosted
                  ? ''
                  : `<button type="button" class="si-btn" id="pa-add-line">＋ إضافة مادة</button>`
              }
            </div>
            <div class="si-table-wrap">
              <table class="si-table" id="pa-table">
                <thead>
                  <tr>
                    <th style="width:2rem">#</th>
                    <th>المادة</th>
                    <th style="width:7rem">سعر البيع الحالي</th>
                    <th style="width:7rem">سعر البيع الجديد</th>
                    <th style="width:7rem">سعر الجملة الحالي</th>
                    <th style="width:7rem">سعر الجملة الجديد</th>
                    <th style="width:2.5rem"></th>
                  </tr>
                </thead>
                <tbody id="pa-tbody"></tbody>
              </table>
            </div>
            <p class="muted" style="font-size:.8rem;margin:.55rem 0 0;line-height:1.45">
              اختر المادة فيُجلب السعر الحالي تلقائياً. اكتب السعر الجديد للبيع و/أو الجملة.
              لا يُحدَّث سعر البطاقة إلا بعد <b>ترحيل</b> الحركة.
            </p>
          </div>

          <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1.1rem">
            ${
              isPosted
                ? `<a class="si-btn" href="/inventory/price-adjust">رجوع</a>`
                : `<button class="si-btn si-btn--primary" type="submit" name="action" value="save">حفظ مسودة</button>
                   ${
                     doc
                       ? `<button class="si-btn" type="submit" name="action" value="post"
                            onclick="return confirm('ترحيل الأسعار إلى بطاقات المواد؟');">ترحيل</button>`
                       : `<button class="si-btn" type="submit" name="action" value="save_post"
                            title="حفظ ثم ترحيل">حفظ وترحيل</button>`
                   }
                   <a class="si-btn" href="/inventory/price-adjust">إلغاء</a>`
            }
          </div>
        </form>
      </section>
    </div>
    <script type="application/json" id="pa-initial">${linesJson}</script>
    <script>
    (function(){
      var posted = ${isPosted ? 'true' : 'false'};
      var lines = [];
      try { lines = JSON.parse(document.getElementById('pa-initial').textContent || '[]') || []; } catch(e) { lines = []; }
      var tbody = document.getElementById('pa-tbody');
      var form = document.getElementById('pa-form');
      var linesInput = document.getElementById('pa-lines-json');
      var addBtn = document.getElementById('pa-add-line');

      function escAttr(s){
        return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
      }
      function fmt(n){
        return (Math.round((Number(n)||0)*1000)/1000).toLocaleString('en-US',{minimumFractionDigits:3,maximumFractionDigits:3});
      }

      function emptyLine(){
        return {
          item_id:0, item_code:'', item_name:'',
          old_sale_price:0, new_sale_price:'',
          old_wholesale:0, new_wholesale:''
        };
      }

      if (!lines.length) lines.push(emptyLine());

      function syncJson(){
        if (linesInput) linesInput.value = JSON.stringify(lines);
      }

      function render(){
        if (!tbody) return;
        tbody.innerHTML = '';
        lines.forEach(function(ln, idx){
          var tr = document.createElement('tr');
          tr.setAttribute('data-idx', String(idx));
          tr.innerHTML =
            '<td dir="ltr">'+(idx+1)+'</td>'+
            '<td class="si-item-cell" style="min-width:14rem">'+
              '<input type="hidden" class="js-item-id" value="'+(ln.item_id||'')+'">'+
              '<input class="si-field js-item" type="search" placeholder="باركود / اسم…" value="'+
                escAttr((ln.item_code?ln.item_code+' — ':'')+(ln.item_name||''))+'" '+
                (posted?'readonly':'')+' autocomplete="off">'+
              '<div class="si-suggest js-suggest" hidden></div>'+
            '</td>'+
            '<td class="si-num" dir="ltr"><span class="js-old-sale">'+fmt(ln.old_sale_price)+'</span></td>'+
            '<td><input class="si-field si-field--mono js-new-sale" type="number" step="0.001" min="0" dir="ltr" '+
              'value="'+escAttr(ln.new_sale_price===''||ln.new_sale_price==null?'':ln.new_sale_price)+'" '+
              (posted?'readonly':'')+' placeholder="جديد"></td>'+
            '<td class="si-num" dir="ltr"><span class="js-old-wh">'+fmt(ln.old_wholesale)+'</span></td>'+
            '<td><input class="si-field si-field--mono js-new-wh" type="number" step="0.001" min="0" dir="ltr" '+
              'value="'+escAttr(ln.new_wholesale===''||ln.new_wholesale==null?'':ln.new_wholesale)+'" '+
              (posted?'readonly':'')+' placeholder="جديد"></td>'+
            '<td>'+(posted?'':'<button type="button" class="si-btn js-del" title="حذف">×</button>')+'</td>';
          tbody.appendChild(tr);
          bindRow(tr);
        });
        syncJson();
      }

      function readRow(tr){
        var idx = Number(tr.getAttribute('data-idx'));
        var ln = lines[idx] || emptyLine();
        var hid = tr.querySelector('.js-item-id');
        if (hid) ln.item_id = Number(hid.value)||0;
        var ns = tr.querySelector('.js-new-sale');
        var nw = tr.querySelector('.js-new-wh');
        if (ns) ln.new_sale_price = ns.value;
        if (nw) ln.new_wholesale = nw.value;
        lines[idx] = ln;
        syncJson();
      }

      function applyItem(idx, it){
        lines[idx] = lines[idx] || emptyLine();
        lines[idx].item_id = it.id;
        lines[idx].item_code = it.code || it.barcode || it.sku || '';
        lines[idx].item_name = it.name_ar || '';
        lines[idx].old_sale_price = Number(it.sale_price)||0;
        lines[idx].old_wholesale = Number(it.wholesale_price)||0;
        // اقتراح نفس الحالي كقيمة ابتدائية قابلة للتعديل
        if (lines[idx].new_sale_price === '' || lines[idx].new_sale_price == null) {
          lines[idx].new_sale_price = '';
        }
        if (lines[idx].new_wholesale === '' || lines[idx].new_wholesale == null) {
          lines[idx].new_wholesale = '';
        }
        render();
      }

      function searchItems(q, box, tr){
        fetch('/api/lookup/items?q='+encodeURIComponent(q||''), {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(data){
            box.innerHTML = '';
            (data.rows||[]).slice(0,25).forEach(function(it){
              var b = document.createElement('button');
              b.type = 'button';
              b.textContent = (it.code||'')+' — '+(it.name_ar||'');
              b.addEventListener('click', function(){
                var idx = Number(tr.getAttribute('data-idx'));
                // جلب أسعار البيع والجملة من API المخصص
                fetch('/api/inventory/price-adjust/item/'+it.id, {credentials:'same-origin'})
                  .then(function(r){ return r.json(); })
                  .then(function(d){
                    if (d.ok && d.item) applyItem(idx, d.item);
                    else applyItem(idx, {
                      id: it.id, code: it.code, name_ar: it.name_ar,
                      sale_price: it.sale_price||it.base_sale||0,
                      wholesale_price: it.wholesale_price||it.base_wholesale||0
                    });
                    box.hidden = true;
                  })
                  .catch(function(){
                    applyItem(idx, {
                      id: it.id, code: it.code, name_ar: it.name_ar,
                      sale_price: it.sale_price||0, wholesale_price: 0
                    });
                    box.hidden = true;
                  });
              });
              box.appendChild(b);
            });
            box.hidden = !(data.rows && data.rows.length);
          })
          .catch(function(){ box.hidden = true; });
      }

      function bindRow(tr){
        if (posted) return;
        var itemInp = tr.querySelector('.js-item');
        var box = tr.querySelector('.js-suggest');
        var timer = null;
        if (itemInp && box) {
          itemInp.addEventListener('input', function(){
            var hid = tr.querySelector('.js-item-id');
            if (hid) hid.value = '';
            clearTimeout(timer);
            timer = setTimeout(function(){ searchItems(itemInp.value, box, tr); }, 220);
          });
          itemInp.addEventListener('focus', function(){ searchItems(itemInp.value||'', box, tr); });
        }
        ['js-new-sale','js-new-wh'].forEach(function(cls){
          var el = tr.querySelector('.'+cls);
          if (el) el.addEventListener('input', function(){ readRow(tr); });
        });
        var del = tr.querySelector('.js-del');
        if (del) del.addEventListener('click', function(){
          var idx = Number(tr.getAttribute('data-idx'));
          lines.splice(idx,1);
          if (!lines.length) lines.push(emptyLine());
          render();
        });
      }

      if (addBtn) addBtn.addEventListener('click', function(){
        lines.push(emptyLine());
        render();
      });

      if (form) form.addEventListener('submit', function(){
        tbody.querySelectorAll('tr').forEach(readRow);
        syncJson();
      });

      render();
    })();
    </script>`;

  res.send(page(req.session.user, isNew ? 'تعديل أسعار جديد' : 'تعديل أسعار', body));
}

router.get('/inventory/price-adjust/new', (req, res) => renderForm(req, res, 0));
router.get('/inventory/price-adjust/:id', async (req, res) => {
  const id = Number(req.params.id);
  if (!id) return res.redirect('/inventory/price-adjust');
  return renderForm(req, res, id);
});

router.post('/inventory/price-adjust/save', async (req, res) => {
  if (!can(req.session.user, SCREEN) && !can(req.session.user, 'items')) return forbid(res);
  const body = req.body || {};
  let lines = [];
  try {
    lines = JSON.parse(String(body.lines_json || '[]'));
  } catch {
    lines = [];
  }
  const action = String(body.action || 'save');
  const result = await svc.saveDoc(
    { id: body.id, notes: body.notes, lines },
    req.session.user?.id
  );
  if (!result.ok) {
    return res.redirect(
      (body.id > 0 ? '/inventory/price-adjust/' + body.id : '/inventory/price-adjust/new') +
        '?err=' +
        encodeURIComponent(result.error || 'خطأ')
    );
  }
  if (action === 'post' || action === 'save_post') {
    const post = await svc.postDoc(result.id, req.session.user?.id);
    return res.redirect(
      '/inventory/price-adjust/' +
        result.id +
        (post.ok ? '?msg=' : '?err=') +
        encodeURIComponent(post.ok ? post.message : post.error || '')
    );
  }
  res.redirect(
    '/inventory/price-adjust/' +
      result.id +
      '?msg=' +
      encodeURIComponent(result.message || 'تم الحفظ')
  );
});

/* ── Report ── */
router.get('/inventory/reports/price-adjustments', async (req, res) => {
  try {
    if (
      !can(req.session.user, REPORT) &&
      !can(req.session.user, SCREEN) &&
      !can(req.session.user, 'items')
    ) {
      return forbid(res);
    }
    await svc.ensureSchema();
    const from = String(req.query.from || '').slice(0, 10) || svc.todayIso().slice(0, 8) + '01';
    const to = String(req.query.to || '').slice(0, 10) || svc.todayIso();
    const qv = String(req.query.q || '');
    const rows = await svc.reportAdjustments({ from, to, q: qv });

    function fmtTs(v) {
      if (!v) return '—';
      const s = String(v);
      // MySQL datetime
      if (s.length >= 16) {
        const d = s.slice(0, 10);
        const t = s.slice(11, 19);
        return ui.isoToDmy(d) + ' ' + t;
      }
      return esc(s);
    }

    const rowsHtml =
      rows
        .map(
          (r, i) => `<tr>
        <td class="si-num" dir="ltr">${i + 1}</td>
        <td class="si-num" dir="ltr">${esc(r.adj_no || '—')}</td>
        <td class="si-num" dir="ltr">${fmtTs(r.posted_at || r.doc_posted_at || r.created_at)}</td>
        <td class="si-num" dir="ltr">${esc(r.item_code || '')}</td>
        <td>${esc(r.item_name || '')}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.old_sale_price))}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.new_sale_price))}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.old_wholesale))}</td>
        <td class="si-num" dir="ltr">${esc(ui.fmtAmt(r.new_wholesale))}</td>
        <td>${esc(r.employee_name || r.employee_user || '—')}</td>
      </tr>`
        )
        .join('') || ui.emptyRow(10, 'لا تعديلات مرحّلة في الفترة');

    const body = `
      <div class="si-stage">
        ${ui.hero({
          mark: '📋',
          kicker: KICKER,
          title: 'تقرير الأسعار المعدّلة',
          subtitle: 'كل التعديلات المرحّلة: السعر السابق والجديد والتاريخ والموظف',
          actions: [
            { label: 'شاشة التعديل', href: '/inventory/price-adjust', primary: true },
            { label: 'لوحة المستودعات', href: HUB },
          ],
        })}
        <section class="si-surface" style="padding:0.85rem 1rem;margin-bottom:.75rem">
          <form method="get" action="/inventory/reports/price-adjustments" class="si-meta" style="align-items:end">
            <label>من تاريخ
              <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}" dir="ltr">
            </label>
            <label>إلى تاريخ
              <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}" dir="ltr">
            </label>
            <label>بحث
              <input class="si-field" name="q" value="${esc(qv)}" placeholder="رقم حركة / مادة / باركود">
            </label>
            <button class="si-btn si-btn--primary" type="submit">عرض</button>
          </form>
        </section>
        ${ui.tableSurface(
          'التعديلات المرحّلة',
          `${rows.length} صف`,
          [
            '#',
            'رقم الحركة',
            'التاريخ والساعة',
            'الباركود',
            'المادة',
            'بيع قديم',
            'بيع جديد',
            'جملة قديم',
            'جملة جديد',
            'الموظف',
          ],
          rowsHtml
        )}
      </div>`;
    res.send(page(req.session.user, 'تقرير الأسعار المعدّلة', body));
  } catch (e) {
    console.error(e);
    res.status(500).send(String(e.message || e));
  }
});

module.exports = router;
