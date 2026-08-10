'use strict';

const express = require('express');
const auth = require('../auth');
const svc = require('./gpsService');
const ui = require('../lib/salesUi');
const { esc, isoToDmy, todayIso } = require('../lib/html');

const router = express.Router();
const KICKER = 'Hypex System · Node';
const HUB = '/system';

const PREFIXES = [
  '/system/user-locations',
  '/system/gps-tracker',
  '/system/gps-settings',
  '/system/invoice-gps',
  '/api/system/gps-live',
  '/api/system/gps-track-day',
];

function can(user, code) {
  return user.is_admin || auth.userCan(user, code);
}

function canAny(user, codes) {
  return codes.some((c) => can(user, c));
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

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function parseRange(query) {
  let from = String(query.from || '').slice(0, 10);
  let to = String(query.to || '').slice(0, 10);
  if (!from) from = monthStart();
  if (!to) to = todayIso();
  return { from, to };
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (!PREFIXES.some((x) => p === x || p.startsWith(x + '/'))) return next('router');
  return auth.requireAuth(req, res, next);
});

/* ── API live (متوافق مع PHP user_gps_tracker_live.php + JS) ── */
router.get('/api/system/gps-live', async (req, res) => {
  if (!canAny(req.session.user, ['user_gps_tracker', 'user_gps_locations', 'm_user_gps_tracker'])) {
    return res.status(403).json({ ok: false, error: 'forbidden', message: 'ممنوع' });
  }
  const onlineSec = Number(req.query.online_seconds || req.query.online_minutes * 60 || 60) || 60;
  const includeStale = String(req.query.include_stale || '') === '1';
  const qv = String(req.query.q || '');
  const data = await svc.liveTrackerPayload({ onlineSec, includeStale, q: qv });
  res.json(data);
});

router.get('/api/system/gps-track-day', async (req, res) => {
  if (!canAny(req.session.user, ['user_gps_tracker', 'm_user_gps_tracker'])) {
    return res.status(403).json({ ok: false, error: 'forbidden', message: 'ممنوع' });
  }
  const userId = Number(req.query.user_id || 0) || 0;
  const date = String(req.query.date || '');
  const data = await svc.trackDayPayload({ userId, date });
  res.json(data);
});

/* ── User locations report ── */
router.get('/system/user-locations', async (req, res) => {
  if (!can(req.session.user, 'user_gps_locations')) return forbid(res);
  const run = String(req.query.run || '') === '1' || String(req.query.run || '') === 'y';
  const range = parseRange(req.query);
  const qv = String(req.query.q || '');
  let rows = [];
  if (run) {
    rows = await svc.listUserLocations({ q: qv, from: range.from, to: range.to });
  }

  const rowsHtml = run
    ? rows
        .map(
          (r, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td>${
        r.map_url
          ? `<a class="si-btn" style="min-height:1.6rem;padding:.15rem .45rem;font-size:.75rem" href="${esc(
              r.map_url
            )}" target="_blank" rel="noopener">GPS</a>`
          : '—'
      }</td>
      <td>${esc(r.place_label)}</td>
      <td><strong>${esc(r.full_name_ar || r.username || '')}</strong><br><span class="muted" style="font-size:.78rem" dir="ltr">${esc(
        r.username || ''
      )}</span></td>
      <td>${esc(r.source_label)}</td>
      <td class="si-num" dir="ltr">${dash(r.captured_at)}</td>
      <td class="si-num" dir="ltr">${esc(r.accuracy_label)}</td>
    </tr>`
        )
        .join('') || ui.emptyRow(7, 'لا توجد مواقع مسجّلة في الفترة المحددة')
    : '';

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'مواقع المستخدمين',
        subtitle: 'آخر موقع معروف ضمن الفترة — تقرير أصلي على Node مع رابط الخريطة',
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'التتبّع الحي', href: '/system/gps-tracker' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      <div class="si-rail no-print">
        <form class="si-search" method="get" action="/system/user-locations" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
          <input type="hidden" name="run" value="1">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">من تاريخ
            <input class="si-field si-field--mono" type="date" name="from" value="${esc(range.from)}" style="min-height:2.1rem">
          </label>
          <label style="font-size:.8rem;font-weight:700;color:#5c6578">إلى تاريخ
            <input class="si-field si-field--mono" type="date" name="to" value="${esc(range.to)}" style="min-height:2.1rem">
          </label>
          <input type="search" name="q" value="${esc(qv)}" placeholder="اسم المستخدم، المنطقة، المعلم…" style="min-width:12rem;flex:1">
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      <div class="si-print-area">
        ${
          !run
            ? `<section class="si-surface" style="padding:1.5rem;text-align:center"><p class="muted" style="margin:0">حدّد الفترة واضغط «عرض» لعرض البيانات.</p></section>`
            : `${ui.tableSurface(
                'مواقع المستخدمين (GPS)',
                `${rows.length} صف`,
                ['#', 'GPS', 'الموقع', 'المستخدم', 'المصدر', 'التقاط', 'الدقة'],
                rowsHtml
              )}
             <p class="muted no-print" style="font-size:.82rem">يُحدَّث موقع كل مستخدم من تطبيق الهاتف — اضغط GPS لفتح الخريطة.</p>`
        }
      </div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'مواقع المستخدمين',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

/* ── Live tracker — واجهة PHP كاملة + JS الأصلي عبر /hypex/assets ── */
router.get('/system/gps-tracker', async (req, res) => {
  if (!can(req.session.user, 'user_gps_tracker') && !can(req.session.user, 'm_user_gps_tracker')) {
    return forbid(res);
  }
  const gps = await svc.getGpsSettings();
  const tileUrl =
    gps.map_provider === 'carto'
      ? 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png'
      : 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';
  const attribution =
    gps.map_provider === 'carto'
      ? '© OSM © CARTO'
      : '© Esri — OpenStreetMap contributors';
  const today = todayIso();
  const gkey = esc(gps.google_maps_api_key || '');
  const mapEngine = esc(gps.map_engine || 'leaflet');
  const mapProvider = esc(gps.map_provider || 'esri');

  const body = `
    <div class="si-stage" style="padding-top:.5rem">
      <div class="si-rail no-print" style="margin-bottom:.5rem;display:flex;gap:.4rem;flex-wrap:wrap">
        <a class="si-btn" href="/system/user-locations">مواقع المستخدمين</a>
        <a class="si-btn" href="/system/gps-settings">إعدادات GPS</a>
        <a class="si-btn" href="${HUB}">لوحة النظام</a>
      </div>
      <div class="ugt-page" id="ugt-root"
           data-api="/api/system/gps-live"
           data-tile-url="${esc(tileUrl)}"
           data-attribution="${esc(attribution)}"
           data-map-provider="${mapProvider}"
           data-map-engine="${mapEngine}"
           data-google-key="${gkey}"
           data-poll-sec="3"
           data-online-seconds="60"
           data-stale-seconds="60"
           data-mode="desktop">
        <div class="ugt-toolbar">
          <div class="ugt-toolbar__title">
            <span class="ugt-toolbar__icon" aria-hidden="true">📡</span>
            <div>
              <strong>تتبّع المواقع</strong>
              <small>الأجهزة الحيّة الآن وخط السير اليومي — Node</small>
            </div>
          </div>
          <div class="ugt-modeswitch" role="tablist">
            <button type="button" class="ugt-modeswitch__btn is-active" id="ugt-mode-live">التتبّع الحي</button>
            <button type="button" class="ugt-modeswitch__btn" id="ugt-mode-route">المسار اليومي</button>
          </div>
          <div class="ugt-toolbar__actions">
            <a class="btn btn-sm btn-secondary" href="${HUB}">خروج</a>
          </div>
        </div>

        <div id="ugt-live-view" class="ugt-live-view">
          <div class="ugt-subbar">
            <div class="ugt-toolbar__stats" id="ugt-stats">
              <span class="ugt-chip ugt-chip--online"><b id="ugt-cnt-online">0</b> متصل</span>
              <span class="ugt-chip"><b id="ugt-cnt-total">0</b> على الخريطة</span>
            </div>
            <div class="ugt-subbar__actions">
              <input type="search" id="ugt-search" class="ugt-search" placeholder="بحث بالاسم..." autocomplete="off">
              <button type="button" class="btn btn-sm btn-secondary" id="ugt-clear-trails" title="مسح الخطوط الحيّة">مسح الخط</button>
              <button type="button" class="btn btn-sm btn-primary" id="ugt-refresh">تحديث</button>
            </div>
          </div>
          <div class="ugt-body">
            <aside class="ugt-sidebar" id="ugt-sidebar">
              <div class="ugt-sidebar__head">المتصلون الآن</div>
              <div class="ugt-sidebar__list" id="ugt-list">
                <div class="ugt-empty">جاري التحميل...</div>
              </div>
            </aside>
            <div class="ugt-map-wrap">
              <div id="ugt-map" class="ugt-map" role="application" aria-label="خريطة التتبّع"></div>
              <div class="ugt-legend">
                <span><i class="ugt-dot ugt-dot--online"></i> متصل (آخر 60 ثانية)</span>
                <span><i class="ugt-line"></i> خط حي + حركة سلسة</span>
                <span><i class="ugt-dot ugt-dot--away"></i> غير متصل = لا يظهر على الخريطة</span>
                <span>الرقم على الخريطة = نفس الرقم في القائمة</span>
              </div>
              <div class="ugt-status" id="ugt-status">تحديث كل 3 ثوانٍ</div>
            </div>
          </div>
        </div>

        <div id="ugt-route-view" hidden>
          <div class="ugr-root" id="ugr-root"
               data-track-api="/api/system/gps-track-day"
               data-tile-url="${esc(tileUrl)}"
               data-attribution="${esc(attribution)}"
               data-map-provider="${mapProvider}"
               data-google-key="${gkey}"
               data-today="${esc(today)}"
               data-mode="desktop">
            <div class="ugr-controls">
              <label class="ugr-field">
                <span>المندوب</span>
                <select id="ugr-user" class="ugr-select"><option value="">— اختر —</option></select>
              </label>
              <label class="ugr-field">
                <span>التاريخ</span>
                <input type="date" id="ugr-date" class="ugr-date" value="${esc(today)}" max="${esc(today)}">
              </label>
              <button type="button" class="btn btn-sm btn-secondary" id="ugr-prev" title="اليوم السابق">‹</button>
              <button type="button" class="btn btn-sm btn-secondary" id="ugr-next" title="اليوم التالي">›</button>
              <button type="button" class="btn btn-sm btn-primary" id="ugr-load">عرض المسار</button>
            </div>
            <div class="ugr-summary" id="ugr-summary"></div>
            <div class="ugr-body">
              <aside class="ugr-sidebar" id="ugr-stops">
                <div class="ugr-sidebar__head">التوقفات</div>
                <div class="ugr-empty">اختر مندوباً وتاريخاً ثم اضغط «عرض المسار».</div>
              </aside>
              <div class="ugr-map-wrap">
                <div id="ugr-map" class="ugr-map" role="application" aria-label="خريطة المسار"></div>
                <div class="ugr-legend">
                  <span><i class="ugr-line"></i> خط السير</span>
                  <span><i class="ugr-dot ugr-dot--start"></i> ب = البداية</span>
                  <span><i class="ugr-dot ugr-dot--stop"></i> 1،2 = توقف</span>
                  <span><i class="ugr-dot ugr-dot--end"></i> ن = النهاية</span>
                </div>
                <div class="ugr-status" id="ugr-status"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <script>
      window.AppOsmConfig = {
        tileUrl: ${JSON.stringify(tileUrl)},
        attribution: ${JSON.stringify(attribution)},
        mapProvider: ${JSON.stringify(gps.map_provider || 'esri')},
        mapEngine: ${JSON.stringify(gps.map_engine || 'leaflet')},
        googleMapsKey: ${JSON.stringify(gps.google_maps_api_key || '')}
      };
    </script>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تتبع المواقع الحية',
      bodyHtml: body,
      css: [
        '/hypex/assets/css/user-gps-tracker.css',
        '/hypex/assets/vendor/leaflet/leaflet.css',
      ],
      js: [
        '/hypex/assets/vendor/leaflet/leaflet.js',
        '/hypex/assets/js/leaflet-map-layers.js',
        '/hypex/assets/js/user-gps-tracker.js',
        '/hypex/assets/js/user-gps-route.js',
      ],
    })
  );
});

/* ── GPS settings ── */
router.get('/system/gps-settings', async (req, res) => {
  if (!canAny(req.session.user, ['gps_tracking_settings', 'settings'])) return forbid(res);
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const s = await svc.getGpsSettings();
  const intervalOpts = [10, 15, 30, 60, 120, 300]
    .map(
      (n) =>
        `<option value="${n}" ${s.interval_sec === n ? 'selected' : ''}>${
          n < 60 ? n + ' ثانية' : n / 60 + ' دقيقة'
        }</option>`
    )
    .join('');
  const distOpts = [0, 15, 30, 50, 100]
    .map(
      (n) =>
        `<option value="${n}" ${s.min_distance_m === n ? 'selected' : ''}>${
          n === 0 ? 'بدون حد' : n + ' م'
        }</option>`
    )
    .join('');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: '⚙',
        kicker: KICKER,
        title: 'إعدادات تتبّع الهاتف',
        subtitle: 'سلوك GPS لمستخدمي التطبيق — حفظ أصلي على Node',
        actions: [
          { label: 'التتبّع الحي', href: '/system/gps-tracker' },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      ${flashHtml(flash, err)}
      <form method="post" action="/system/gps-settings" class="si-surface" style="padding:1rem 1.2rem">
        <h2 style="margin:0 0 .65rem;font-size:1.05rem">سلوك التتبّع</h2>
        <p class="muted" style="font-size:.85rem">تُطبَّق على مستخدمي تطبيق الهاتف.</p>
        <label style="display:flex;gap:.45rem;align-items:flex-start;margin:.5rem 0;font-weight:600">
          <input type="checkbox" name="gps_mobile_auto_enable" value="1" ${s.auto_enable ? 'checked' : ''}>
          <span>تفعيل التتبّع تلقائياً عند تسجيل الدخول</span>
        </label>
        <div class="si-meta">
          <label>مدة إرسال الموقع
            <select class="si-field" name="gps_mobile_interval_sec">${intervalOpts}</select>
          </label>
          <label>أقل مسافة للإرسال
            <select class="si-field" name="gps_mobile_min_distance_m">${distOpts}</select>
          </label>
        </div>
        <label style="display:flex;gap:.45rem;align-items:flex-start;margin:.75rem 0;font-weight:600">
          <input type="checkbox" name="gps_mobile_user_can_disable" value="1" ${
            s.user_can_disable ? 'checked' : ''
          }>
          <span>السماح للمستخدم بإيقاف التتبّع من التطبيق</span>
        </label>
        <label style="display:flex;gap:.45rem;align-items:flex-start;margin:.5rem 0 .35rem;font-weight:600">
          <input type="checkbox" name="sales_rep_visit_geofence" value="1" ${
            s.rep_visit_geofence ? 'checked' : ''
          }>
          <span>تفعيل حدود منطقة العميل للمندوب (فاتورة / طلب شراء)</span>
        </label>
        <p class="muted" style="font-size:.8rem;margin:0 0 .65rem;line-height:1.45">
          عند التفعيل لا يُسمح للمندوب بإنشاء فاتورة أو طلب شراء من التطبيق إلا إذا كان ضمن نصف القطر حول موقع العميل.
          تطبيق APK المندوب سيستخدم هذا الإعداد لاحقاً.
        </p>
        <div class="si-meta" style="margin-bottom:1rem">
          <label>حدود منطقة العميل (متر)
            <input class="si-field si-field--mono" type="number" name="sales_rep_visit_radius_m"
                   min="10" max="5000" step="10" value="${Number(s.visit_radius_m) || 200}" dir="ltr">
          </label>
          <p class="muted" style="font-size:.78rem;margin:.35rem 0 0;grid-column:1/-1">
            مثال: 200 متر — المدى 10–5000 (الافتراضي 200).
          </p>
        </div>
        <h2 style="margin:0 0 .65rem;font-size:1.05rem">الخريطة</h2>
        <div class="si-meta">
          <label>محرك الخريطة
            <select class="si-field" name="gps_map_engine">
              <option value="leaflet" ${s.map_engine === 'leaflet' ? 'selected' : ''}>Leaflet / OSM</option>
              <option value="google" ${s.map_engine === 'google' ? 'selected' : ''}>Google Maps</option>
            </select>
          </label>
          <label>مزوّد البلاط
            <select class="si-field" name="gps_map_provider">
              ${['esri', 'carto', 'osm', 'google']
                .map(
                  (p) =>
                    `<option value="${p}" ${s.map_provider === p ? 'selected' : ''}>${p}</option>`
                )
                .join('')}
            </select>
          </label>
          <label class="si-span-2">مفتاح Google Maps API
            <input class="si-field si-field--mono" name="gps_google_maps_api_key" value="${esc(
              s.google_maps_api_key
            )}" dir="ltr" autocomplete="off">
          </label>
        </div>
        <div style="margin-top:1rem">
          <button class="si-btn si-btn--primary" type="submit">حفظ</button>
        </div>
      </form>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'إعدادات تتبّع الهاتف', bodyHtml: body }));
});

router.post('/system/gps-settings', async (req, res) => {
  if (!canAny(req.session.user, ['gps_tracking_settings', 'settings'])) return forbid(res);
  const result = await svc.saveGpsSettings(req.body || {});
  res.redirect(
    '/system/gps-settings?' +
      (result.ok ? 'msg=' : 'err=') +
      encodeURIComponent(result.message || result.error || '')
  );
});

/* ── Invoice GPS report ── */
router.get('/system/invoice-gps', async (req, res) => {
  if (!can(req.session.user, 'sales_invoice_gps')) return forbid(res);
  const run = String(req.query.run || '') === '1';
  const range = parseRange(req.query);
  const qv = String(req.query.q || '');
  let rows = [];
  if (run) rows = await svc.listInvoiceGps({ from: range.from, to: range.to, q: qv });

  const rowsHtml = run
    ? rows
        .map(
          (r) => `<tr>
      <td class="si-num" dir="ltr">${esc(r.invoice_no || '')}</td>
      <td class="si-num" dir="ltr">${esc(isoToDmy(r.invoice_date))}</td>
      <td>${dash(r.customer_name)}</td>
      <td>${dash(r.post_gps_place)}</td>
      <td class="si-num" dir="ltr">${dash(r.post_latitude)}</td>
      <td class="si-num" dir="ltr">${dash(r.post_longitude)}</td>
      <td>${
        r.map_url
          ? `<a class="si-btn" style="min-height:1.6rem;padding:.15rem .45rem;font-size:.75rem" target="_blank" rel="noopener" href="${esc(
              r.map_url
            )}">GPS</a>`
          : '—'
      }</td>
    </tr>`
        )
        .join('') || ui.emptyRow(7, 'لا مواقع فواتير في الفترة')
    : '';

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'مواقع فواتير البيع',
        subtitle: 'فواتير مُرحَّلة بإحداثيات GPS — تقرير أصلي',
        actions: [
          { label: '🖨 طباعة', primary: true, print: true },
          { label: 'لوحة النظام', href: HUB },
        ],
      })}
      <div class="si-rail no-print">
        <form method="get" action="/system/invoice-gps" class="si-search" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
          <input type="hidden" name="run" value="1">
          <label style="font-size:.8rem;font-weight:700">من
            <input class="si-field si-field--mono" type="date" name="from" value="${esc(range.from)}">
          </label>
          <label style="font-size:.8rem;font-weight:700">إلى
            <input class="si-field si-field--mono" type="date" name="to" value="${esc(range.to)}">
          </label>
          <input type="search" name="q" value="${esc(qv)}" placeholder="فاتورة / عميل / مكان…" style="min-width:10rem">
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      <div class="si-print-area">
        ${
          !run
            ? `<section class="si-surface" style="padding:1.5rem;text-align:center"><p class="muted">حدّد الفترة واضغط «عرض».</p></section>`
            : ui.tableSurface(
                'مواقع فواتير البيع',
                `${rows.length} صف`,
                ['الفاتورة', 'التاريخ', 'العميل', 'المكان', 'عرض', 'طول', 'خريطة'],
                rowsHtml
              )
        }
      </div>
    </div>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'مواقع فواتير البيع',
      bodyHtml: body,
      js: ['/assets/js/sales-print.js'],
    })
  );
});

module.exports = router;
