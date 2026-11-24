'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const auth = require('../auth');
const q = require('./domainQueries');
const masters = require('./mastersService');
const ui = require('../lib/salesUi');
const { salesRepsCatalog } = require('./catalog');
const { esc, todayIso, parseDateToIso, isoToDmy, fmtAmt } = require('../lib/html');
const { renderStandalonePrintPage } = require('../lib/printBrand');
const accNative = require('../accounting/nativeService');
const salesQ = require('../sales/domainQueries');

const router = express.Router();
const HUB = '/sales-reps';
const KICKER = 'Hypex Sales Reps · Node';

function can(user, code) {
  return auth.userCan(user, code) || user.is_admin;
}

/** يقبل مفتاح id مرة أو عدة مرات أو مفصولة بفواصل */
function parseIdsQuery(query = {}, keys = []) {
  const parts = [];
  for (const key of keys) {
    const raw = query[key];
    if (raw == null || raw === '') continue;
    const arr = Array.isArray(raw) ? raw : String(raw).split(',');
    for (const item of arr) {
      const n = Number(String(item).trim()) || 0;
      if (n > 0) parts.push(n);
    }
  }
  return [...new Set(parts)];
}

function parseCustomerIdsQuery(query = {}) {
  return parseIdsQuery(query, ['customer_id', 'customer_ids']);
}

function parseSalesRepIdsQuery(query = {}) {
  return parseIdsQuery(query, ['sales_rep_id', 'sales_rep_ids']);
}

function forbid(res) {
  res.status(403).send('<div class="si-stage"><p class="muted">لا توجد صلاحية.</p></div>');
}

function requireAny(req, res, next) {
  const u = req.session.user;
  if (u.is_admin) return next();
  const flat = salesRepsCatalog.flatMap((g) => g.items);
  const any = flat.some((it) => can(u, it.r));
  // روابط قديمة للتقارير — بصلاحية التقرير فقط (بدون توسيع لمندوبين/جولات)
  const reportOk =
    req.path.startsWith('/sales-reps/reports/') &&
    (can(u, 'report_sales_by_rep') ||
      can(u, 'report_sales_by_region') ||
      can(u, 'report_sales_rep_tours') ||
      can(u, 'report_sales_rep_visits') ||
      can(u, 'report_sales_rep_customers') ||
      can(u, 'report_oracle_rep_collections'));
  if (!any && !reportOk) return res.status(403).send('ممنوع');
  next();
}

function guard(code) {
  return (req, res, next) => {
    if (!can(req.session.user, code)) return res.status(403).send('ممنوع');
    next();
  };
}

router.use((req, res, next) => {
  const p = req.path || '';
  if (!(p.startsWith('/sales-reps') || p.startsWith('/api/sales-reps'))) return next('router');
  return auth.requireAuth(req, res, (err) => {
    if (err) return next(err);
    return requireAny(req, res, next);
  });
});

function dash(v) {
  const s = v == null || v === '' ? '' : String(v);
  return s === '' ? '—' : ui.esc(s);
}

router.get('/sales-reps', (req, res) => {
  const user = req.session.user;
  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rp',
        kicker: KICKER,
        title: 'المندوبين',
        subtitle: 'دليل المندوبين وجولات الزيارات — كلها على Node.',
        actions: [
          { label: 'قائمة المندوبين', href: '/sales-reps/list', primary: true },
          { label: 'لوحة التحكم', href: '/app', ghost: true },
        ],
      })}
      ${ui.hubTiles(can, user, salesRepsCatalog)}
    </div>`;
  res.send(ui.salesPage({ user, title: 'المندوبين', bodyHtml: body }));
});

/** تتبع مواقع المندوبين — نفس شاشة التتبع الحي تحت قائمة المندوبين. */
router.get('/sales-reps/gps-tracker', (req, res) => {
  if (!can(req.session.user, 'user_gps_tracker') && !can(req.session.user, 'm_user_gps_tracker')) {
    return forbid(res);
  }
  res.redirect(302, '/system/gps-tracker');
});

/* ── خريطة جولة المندوب: اختيار مندوب + جولة → مواقع العملاء على الخريطة ── */
router.get('/sales-reps/tour-map', async (req, res) => {
  try {
    const adminService = require('../system/adminService');
    if (adminService.ensureSalesRepTourMapScreen) {
      await adminService.ensureSalesRepTourMapScreen();
    }
  } catch (e) {
    console.error('ensureSalesRepTourMapScreen', e.message || e);
  }
  if (
    !can(req.session.user, 'sales_rep_tour_map') &&
    !can(req.session.user, 'sales_rep_route') &&
    !can(req.session.user, 'sales_reps')
  ) {
    return forbid(res);
  }

  await masters.ensureTourSchema();
  const reps = await q.listRepsSimple();
  let salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  let tourId = Number(req.query.tour_id || 0) || 0;
  const mapDate = masters.normalizeIsoDate(req.query.map_date || '');

  let mapData = null;
  if (tourId > 0) {
    mapData = await masters.getTourMapData(tourId, { mapDate });
    if (mapData && !salesRepId) {
      salesRepId = Number(mapData.tour.sales_rep_id) || 0;
    }
  }

  const tours = salesRepId > 0 ? await masters.listTours({ salesRepId, limit: 120 }) : [];
  if (tourId > 0 && salesRepId > 0 && !tours.some((t) => Number(t.id) === tourId) && mapData) {
    tours.unshift({
      id: mapData.tour.id,
      date_from: mapData.tour.date_from,
      date_to: mapData.tour.date_to,
      customer_count: mapData.totals.customers,
      status: mapData.tour.status,
      sales_rep_name: mapData.tour.sales_rep_name,
    });
  }

  let gpsCfg = { map_provider: 'esri', map_engine: 'leaflet', google_maps_api_key: '' };
  try {
    const gpsService = require('../system/gpsService');
    gpsCfg = await gpsService.getGpsSettings();
  } catch (e) {
    console.error('tour-map gps settings', e.message || e);
  }
  let mapProvider = String(gpsCfg.map_provider || 'esri').toLowerCase();
  if (mapProvider === 'carto') mapProvider = 'osm';
  const tileUrl =
    mapProvider === 'osm'
      ? 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
      : 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';
  const attribution = mapProvider === 'osm' ? '© OpenStreetMap' : '© Esri — OpenStreetMap contributors';
  const assetV = String(Date.now());

  const repOptions =
    (reps || [])
      .map((r) => {
        const id = Number(r.id) || 0;
        const sel = id === salesRepId ? ' selected' : '';
        return `<option value="${id}"${sel}>${esc(r.name_ar || '')}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`;
      })
      .join('') || '<option value="">— لا مندوبين —</option>';

  const tourOptions =
    tours.length < 1
      ? '<option value="">— اختر المندوب أولاً —</option>'
      : `<option value="">— اختر الجولة —</option>` +
        tours
          .map((t) => {
            const id = Number(t.id) || 0;
            const sel = id === tourId ? ' selected' : '';
            const label = `#${id} · ${isoToDmy(String(t.date_from || '').slice(0, 10))} → ${isoToDmy(
              String(t.date_to || '').slice(0, 10)
            )} · ${Number(t.customer_count || 0)} عميل`;
            return `<option value="${id}"${sel}>${esc(label)}</option>`;
          })
          .join('');

  const pointsJson = JSON.stringify(mapData?.points || []).replace(/</g, '\\u003c');
  const totals = mapData?.totals || { customers: 0, with_gps: 0, without_gps: 0, visited: 0, visited_on_map: 0, not_visited: 0 };
  let tourTitle = mapData
    ? `${mapData.tour.sales_rep_name || 'مندوب'} · ${isoToDmy(mapData.tour.date_from)} → ${isoToDmy(mapData.tour.date_to)}`
    : '';
  if (mapData && mapDate) {
    const wdLabel =
      mapData.tour.map_weekday_label ||
      (masters.WEEKDAY_LABELS || [])[mapData.tour.map_weekday] ||
      '';
    tourTitle += ` · يوم ${isoToDmy(mapDate)} (${wdLabel})`;
  }
  const mapDayMin = mapData ? esc(mapData.tour.date_from || '') : '';
  const mapDayMax = mapData ? esc(mapData.tour.date_to || '') : '';

  const missingPoints = mapData ? (mapData.points || []).filter((p) => !p.has_gps) : [];
  const missingList =
    mapData && missingPoints.length > 0
      ? missingPoints
          .slice(0, 40)
          .map((p) => {
            const tag = p.visited ? ' <small>(تمت الزيارة — بلا موقع على الخريطة)</small>' : '';
            return `<li>${esc(p.name || p.code || '#' + p.customer_id)}${tag}</li>`;
          })
          .join('')
      : '';

  const body = `
    <div class="si-stage si-report-page" data-report-route="sales_rep_tour_map">
      <style>
        .stm-filters{
          display:flex;
          flex-wrap:nowrap;
          align-items:flex-end;
          gap:.55rem .7rem;
          margin:0;
          width:100%;
        }
        .stm-filters label{
          display:flex;
          flex-direction:column;
          gap:.22rem;
          font-size:.78rem;
          font-weight:700;
          color:#5c6578;
          margin:0;
          min-width:0;
        }
        .stm-filters label.stm-f-rep{flex:0 0 12.5rem;width:12.5rem}
        .stm-filters label.stm-f-tour{flex:1 1 auto;min-width:14rem;max-width:28rem}
        .stm-filters label.stm-f-day{flex:0 0 11rem;width:11rem}
        .stm-filters .si-field,
        .stm-filters select.si-field{
          width:100%;
          min-width:0;
          height:2.4rem;
          box-sizing:border-box;
        }
        .stm-filters__actions{
          flex:0 0 auto;
          display:flex;
          align-items:flex-end;
        }
        .stm-filters__actions .si-btn{
          white-space:nowrap;
          height:2.4rem;
          min-height:2.4rem;
          padding-inline:1rem;
        }
        .stm-filters-note{
          margin:.4rem 0 0;
          font-size:.72rem;
          color:#64748b;
          font-weight:500;
        }
        @media (max-width:720px){
          .stm-filters{flex-wrap:wrap}
          .stm-filters label.stm-f-rep,
          .stm-filters label.stm-f-day{flex:1 1 10rem;width:auto}
          .stm-filters label.stm-f-tour{flex:1 1 100%;max-width:none}
        }
        .stm-map-wrap{position:relative;height:min(68vh,640px);min-height:420px;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden;background:#e8eef6}
        .stm-map-wrap .leaflet-container{height:100%;width:100%;font-family:inherit;direction:ltr}
        .stm-side{display:grid;grid-template-columns:minmax(0,1fr) minmax(14rem,18rem);gap:.75rem;align-items:stretch}
        @media (max-width:900px){.stm-side{grid-template-columns:1fr}}
        .stm-list{max-height:min(68vh,640px);overflow:auto;border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:.4rem}
        .stm-list h3{margin:.25rem .4rem .5rem;font-size:.88rem}
        .stm-item{display:flex;gap:.45rem;align-items:flex-start;padding:.45rem .5rem;border-radius:8px;cursor:pointer;border:1px solid transparent}
        .stm-item:hover,.stm-item.is-active{background:#f1f5f9;border-color:#cbd5e1}
        .stm-item__n{flex:0 0 1.5rem;width:1.5rem;height:1.5rem;border-radius:999px;background:#16a34a;color:#fff;font-size:.72rem;font-weight:800;display:flex;align-items:center;justify-content:center}
        .stm-item.is-miss .stm-item__n{background:#94a3b8}
        .stm-item__t{min-width:0;flex:1}
        .stm-item__t strong{display:block;font-size:.82rem;line-height:1.35}
        .stm-item__t small{display:block;color:#64748b;font-size:.72rem;margin-top:.1rem}
        .stm-marker.leaflet-div-icon{background:transparent!important;border:none!important;width:auto!important;height:auto!important;margin:0!important;overflow:visible!important}
        .stm-marker__wrap{display:inline-flex;flex-direction:row;align-items:center;gap:4px;white-space:nowrap;pointer-events:auto;line-height:1}
        .stm-pin-label{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;min-width:26px;height:26px;padding:0 7px;background:#16a34a;color:#fff;border-radius:999px;font-weight:800;font-size:12px;line-height:1;box-shadow:0 1px 4px rgba(15,23,42,.28);white-space:nowrap}
        .stm-marker__name{display:inline-block;flex:0 1 auto;max-width:160px;padding:4px 8px;border-radius:8px;background:rgba(255,255,255,.97);border:1px solid #cbd5e1;box-shadow:0 1px 3px rgba(15,23,42,.16);color:#0f172a;font-size:12px;font-weight:700;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;direction:rtl;unicode-bidi:plaintext;text-align:right;writing-mode:horizontal-tb}
        .stm-stats{display:flex;flex-wrap:wrap;gap:.4rem;margin:.35rem 0 .75rem}
        .stm-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .55rem;border-radius:999px;background:#f1f5f9;font-size:.78rem;font-weight:700;color:#334155}
        .stm-pin-label.is-visited{background:#dc2626;color:#fff}
        .stm-pin-label.is-pending{background:#16a34a;color:#fff}
        .stm-item.is-visited .stm-item__n{background:#dc2626}
        .stm-item.is-pending .stm-item__n{background:#16a34a}
        .stm-item.is-pending .stm-item__t strong{color:#64748b}
        .stm-visit-tag{display:inline-block;margin-top:.2rem;padding:.1rem .4rem;border-radius:6px;background:#fef2f2;color:#b91c1c;font-size:.72rem;font-weight:800}
      </style>
      ${ui.hero({
        mark: '🧭',
        kicker: KICKER,
        title: 'خريطة جولة المندوب',
        subtitle: 'اختر المندوب ثم الجولة. اختر يوماً محدداً لعرض عملاء ذلك اليوم وزياراته، أو اترك اليوم فارغاً لعرض كل أيام الجولة. يظهر العملاء المزارون بإحداثيات الدخول إن لم يكن للعميل موقع ثابت.',
        actions: [
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      <div class="si-surface no-print" style="margin:0 0 1rem;padding:.85rem 1rem">
        <form method="get" action="/sales-reps/tour-map" class="stm-filters" data-app-busy-msg="جاري فتح الخريطة…">
          <label class="stm-f-rep">المندوب
            <select class="si-field" name="sales_rep_id" id="stm-rep" required>
              <option value="">— اختر المندوب —</option>
              ${repOptions}
            </select>
          </label>
          <label class="stm-f-tour">الجولة
            <select class="si-field" name="tour_id" id="stm-tour" ${salesRepId < 1 ? 'disabled' : ''}>
              ${tourOptions}
            </select>
          </label>
          <label class="stm-f-day">اليوم
            <input class="si-field" type="date" name="map_date" id="stm-day"
              value="${esc(mapDate)}"
              ${mapDayMin ? `min="${mapDayMin}"` : ''}
              ${mapDayMax ? `max="${mapDayMax}"` : ''}
              ${salesRepId < 1 ? 'disabled' : ''}>
          </label>
          <div class="stm-filters__actions">
            <button class="si-btn si-btn--primary" type="submit">عرض الخريطة</button>
          </div>
        </form>
        <p class="stm-filters-note no-print">اليوم اختياري — اتركه فارغاً لعرض كل أيام الجولة</p>
      </div>
      ${
        mapData
          ? `<div class="si-surface" style="padding:.85rem 1rem 1rem">
        <div class="si-surface-head" style="margin-bottom:.35rem">
          <h2 style="margin:0;font-size:1rem">${esc(tourTitle)}</h2>
          <span class="si-count">جولة #${esc(mapData.tour.id)}</span>
        </div>
        <div class="stm-stats">
          ${
            mapDate
              ? `<span class="stm-chip">يوم العرض: <b>${esc(isoToDmy(mapDate))}</b>${mapData.tour.map_weekday_label ? ' · ' + esc(mapData.tour.map_weekday_label) : ''}</span>`
              : ''
          }
          <span class="stm-chip">عملاء الجولة: <b>${esc(totals.customers)}</b></span>
          <span class="stm-chip">على الخريطة: <b>${esc(totals.with_gps)}</b></span>
          <span class="stm-chip">بلا موقع: <b>${esc(totals.without_gps)}</b></span>
          <span class="stm-chip">تمت زيارتهم: <b>${esc(totals.visited)}</b></span>
          <span class="stm-chip">مزارون على الخريطة: <b>${esc(totals.visited_on_map)}</b></span>
        </div>
        ${
          totals.with_gps < 1
            ? `<p class="muted" style="margin:0 0 .75rem">لا توجد إحداثيات GPS لعملاء هذه الجولة. أدخل مواقع العملاء من بطاقة العميل أو اعتماد موقع العميل.</p>`
            : ''
        }
        <div class="stm-side">
          <div class="stm-map-wrap" id="stm-map-host"
               data-tile-url="${esc(tileUrl)}"
               data-attribution="${esc(attribution)}"
               data-map-provider="${esc(mapProvider)}"
               data-google-key="${esc(gpsCfg.google_maps_api_key || '')}">
            <div id="stm-map" role="application" aria-label="خريطة جولة المندوب" style="height:100%;width:100%"></div>
          </div>
          <aside class="stm-list" id="stm-list">
            <h3>عملاء الجولة</h3>
            <p class="muted" style="margin:.15rem .4rem .45rem;font-size:.78rem" id="stm-visited-hdr"></p>
            <div id="stm-list-body"></div>
            ${
              missingList
                ? `<div style="margin-top:.75rem;padding-top:.5rem;border-top:1px solid #e2e8f0">
              <h3 style="color:#64748b">بلا موقع GPS</h3>
              <ul style="margin:.25rem .5rem 0;padding-inline-start:1.1rem;font-size:.8rem;color:#64748b">${missingList}</ul>
            </div>`
                : ''
            }
          </aside>
        </div>
        <script type="application/json" id="stm-points-json">${pointsJson}</script>
      </div>`
          : `<p class="muted" style="padding:.5rem 1rem">اختر المندوب والجولة ثم اضغط «عرض الخريطة».</p>`
      }
      <script>
        window.AppOsmConfig = {
          tileUrl: ${JSON.stringify(tileUrl)},
          attribution: ${JSON.stringify(attribution)},
          mapProvider: ${JSON.stringify(mapProvider)},
          mapEngine: ${JSON.stringify(gpsCfg.map_engine || 'leaflet')},
          googleMapsKey: ${JSON.stringify(gpsCfg.google_maps_api_key || '')}
        };
        (function(){
          function hxPath(p){
            var b = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
            b = String(b || '').replace(/\\/+$/, '');
            p = String(p || '');
            if (!p.startsWith('/')) p = '/' + p;
            if (b && (p === b || p.indexOf(b + '/') === 0)) return p;
            return b + p;
          }
          var rep = document.getElementById('stm-rep');
          if (rep) {
            rep.addEventListener('change', function(){
              var id = Number(rep.value || 0) || 0;
              if (id < 1) { window.location.href = hxPath('/sales-reps/tour-map'); return; }
              window.location.href = hxPath('/sales-reps/tour-map') + '?sales_rep_id=' + id;
            });
          }
          function whenLeaflet(cb){
            if (window.L) { cb(); return; }
            var n = 0;
            var t = setInterval(function(){
              n++;
              if (window.L || n > 80) {
                clearInterval(t);
                if (window.L) cb();
              }
            }, 50);
          }
          whenLeaflet(function(){
            var host = document.getElementById('stm-map-host');
            var jsonEl = document.getElementById('stm-points-json');
            if (!host || !jsonEl) return;
            var points = [];
            try { points = JSON.parse(jsonEl.textContent || '[]'); } catch (e) { points = []; }
            if (!Array.isArray(points)) points = [];
            var withGps = points.filter(function(p){ return p && p.has_gps && p.lat != null && p.lng != null; });
            var visitedHdr = document.getElementById("stm-visited-hdr");
            if (visitedHdr) {
              var vc = points.filter(function(p){ return p && p.visited; }).length;
              visitedHdr.textContent = vc > 0 ? ("تمت زيارتهم: " + vc) : "";
            }
            var map = L.map('stm-map', { zoomControl: true, scrollWheelZoom: true });
            var provider = host.getAttribute('data-map-provider') || 'esri';
            var tileUrl = host.getAttribute('data-tile-url') || '';
            var attrib = host.getAttribute('data-attribution') || '';
            function addTiles(){
              if (window.LeafletMapLayers && typeof window.LeafletMapLayers.attach === 'function') {
                return window.LeafletMapLayers.attach(map, {
                  tileUrl: tileUrl,
                  attribution: attrib,
                  mapProvider: provider,
                  googleKey: host.getAttribute('data-google-key') || ''
                });
              }
              L.tileLayer(tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: attrib || '© OpenStreetMap',
                maxZoom: 19
              }).addTo(map);
              return Promise.resolve('osm');
            }
            var listBody = document.getElementById('stm-list-body');
            var markers = [];
            var bounds = [];
            function focusIdx(i){
              Array.prototype.forEach.call(document.querySelectorAll('.stm-item'), function(el){
                el.classList.toggle('is-active', Number(el.getAttribute('data-idx')) === i);
              });
              var m = markers[i];
              if (m) {
                map.setView(m.getLatLng(), Math.max(map.getZoom(), 15));
                m.openPopup();
              }
            }
            addTiles().then(function(){
              withGps.forEach(function(p, i){
                try {
                  var n = i + 1;
                  var rawName = String(p.name || ('عميل ' + n)).trim();
                  var shortName = rawName.length > 28 ? rawName.slice(0, 26) + '…' : rawName;
                  function escHtml(s){
                    return String(s || '')
                      .replace(/&/g,'&amp;')
                      .replace(/</g,'&lt;')
                      .replace(/>/g,'&gt;')
                      .replace(/"/g,'&quot;');
                  }
                  // الاسم داخل الأيقونة بجانب الرقم — أدق من Tooltip في صفحات RTL
                  function visitTimeHm(v) {
                    if (!v) return '';
                    var s = String(v);
                    var m = s.match(/(\d{1,2}):(\d{2})/);
                    if (m) return (m[1].length < 2 ? '0' : '') + m[1] + ':' + m[2];
                    var d = new Date(s.replace(' ', 'T'));
                    if (!isNaN(d.getTime())) {
                      var h = d.getHours();
                      var min = d.getMinutes();
                      return (h < 10 ? '0' : '') + h + ':' + (min < 10 ? '0' : '') + min;
                    }
                    return '';
                  }
                  var pinState = p.visited ? ' is-visited' : ' is-pending';
                  var icon = L.divIcon({
                    className: 'stm-marker',
                    html: '<div class="stm-marker__wrap">'
                      + '<span class="stm-pin-label' + pinState + '">' + n + '</span>'
                      + '<span class="stm-marker__name" title="' + escHtml(rawName) + '">' + escHtml(shortName) + '</span>'
                      + '</div>',
                    iconSize: null,
                    iconAnchor: [13, 13]
                  });
                  var days = (p.weekday_labels || []).join(' · ');
                  var html = '<strong>' + escHtml(p.name || '') + '</strong>'
                    + (p.code ? '<br><span dir="ltr">' + escHtml(p.code) + '</span>' : '')
                    + (days ? '<br><small>' + escHtml(days) + '</small>' : '')
                    + ((p.region_name || p.address_name)
                        ? '<br><small>' + escHtml([p.region_name, p.address_name].filter(Boolean).join(' / ')) + '</small>'
                        : '');
                  if (p.visited) {
                    var vtm = visitTimeHm(p.visit_checkin_at);
                    html += '<br><span class="stm-visit-tag">تمت الزيارة' + (vtm ? ' · ' + escHtml(vtm) : '') + '</span>';
                    if (p.gps_source === 'visit') {
                      html += '<br><small>الموقع من تسجيل الدخول</small>';
                    }
                  }
                  var m = L.marker([p.lat, p.lng], { icon: icon }).addTo(map);
                  m.bindPopup(html);
                  markers.push(m);
                  bounds.push([p.lat, p.lng]);
                  if (listBody) {
                    var item = document.createElement('div');
                    item.className = 'stm-item' + (p.visited ? ' is-visited' : ' is-pending');
                    item.setAttribute('data-idx', String(i));
                    item.innerHTML = '<span class="stm-item__n">' + n + '</span><div class="stm-item__t"><strong></strong><small></small></div>';
                    item.querySelector('strong').textContent = p.name || ('#' + p.customer_id);
                    var sub = [p.code, days].filter(Boolean);
                    if (p.visited) sub.push('تمت الزيارة');
                    item.querySelector('small').textContent = sub.join(' · ');
                    item.addEventListener('click', function(){ focusIdx(i); });
                    listBody.appendChild(item);
                  }
                } catch (err) { /* skip bad point */ }
              });
              if (bounds.length === 1) map.setView(bounds[0], 15);
              else if (bounds.length > 1) map.fitBounds(bounds, { padding: [36, 36], maxZoom: 15 });
              else map.setView([31.95, 35.91], 8);
              setTimeout(function(){ map.invalidateSize(); }, 80);
            });
          });
        })();
      </script>
    </div>`;

  return res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'خريطة جولة المندوب',
      bodyHtml: body,
      css: [`/assets/vendor/leaflet/leaflet.css?v=${assetV}`],
      js: [
        `/assets/vendor/leaflet/leaflet.js?v=${assetV}`,
        `/assets/js/leaflet-map-layers.js?v=${assetV}`,
      ],
    })
  );
});

/* ═══════════ List ═══════════ */
router.get('/sales-reps/list', guard('sales_reps'), async (req, res) => {
  const qv = String(req.query.q || '');
  const showAll = String(req.query.all || '') === '1';
  const flash = String(req.query.msg || '');
  const rows = await q.listReps({ q: qv, activeOnly: !showAll });
  const rowsHtml =
    rows
      .map(
        (r) => `<tr>
      <td class="si-num" dir="ltr">${dash(r.code)}</td>
      <td>${ui.esc(r.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${dash(r.phone)}</td>
      <td>${dash(r.warehouse_name)}</td>
      <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
      <td>${ui.statusPill(Number(r.is_active) === 1 ? 'ok' : 'lock', Number(r.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
      <td>
        <div class="si-act">
          <a class="si-btn" href="/sales-reps/${r.id}">تعديل</a>
          <form method="post" action="/sales-reps/${r.id}/toggle" style="display:inline">
            <button type="submit" class="si-btn">${Number(r.is_active) === 1 ? 'إيقاف' : 'تفعيل'}</button>
          </form>
        </div>
      </td>
    </tr>`
      )
      .join('') || ui.emptyRow(7);

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rp',
        kicker: KICKER,
        title: 'المندوبين',
        subtitle: 'إضافة وتعديل مندوبي المبيعات على Node',
        actions: [
          { label: '＋ مندوب جديد', href: '/sales-reps/new', primary: true },
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${ui.esc(flash)}</p>` : ''}
      <div class="si-rail">
        <form class="si-search" method="get" action="/sales-reps/list" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;flex:1">
          <input type="search" name="q" value="${ui.esc(qv)}" placeholder="بحث بالرمز / الاسم / الهاتف…" style="flex:1;min-width:10rem">
          <label style="font-size:.8rem;font-weight:700;color:#5c6578;display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="all" value="1" ${showAll ? 'checked' : ''}> عرض الموقوفين
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
        </form>
      </div>
      ${ui.tableSurface('المندوبين', `${rows.length} صف`, ['الرمز', 'الاسم', 'الهاتف', 'مستودع العهدة', 'عملاء', 'الحالة', ''], rowsHtml)}
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: 'المندوبين', bodyHtml: body }));
});

/* ═══════════ Form ═══════════ */
async function repForm(req, res, id) {
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const row = id ? await masters.getRep(id) : null;
  if (id && !row) return res.status(404).send('غير موجود');
  const isNew = !row;
  const err = String(req.query.err || '');
  const warehouses = await masters.listWarehouses();
  const custCount = isNew ? 0 : await masters.countRepLinkedCustomers(id);
  const whOpts = warehouses
    .map(
      (w) =>
        `<option value="${w.id}" ${Number(row?.warehouse_id) === Number(w.id) ? 'selected' : ''}>${esc(
          (w.code ? w.code + ' — ' : '') + (w.name_ar || '')
        )}</option>`
    )
    .join('');

  const body = `
    <div class="si-stage">
      ${ui.hero({
        mark: 'Rp',
        kicker: KICKER,
        title: isNew ? 'إضافة مندوب' : 'تعديل مندوب',
        subtitle: isNew
          ? 'الرمز فارغ = تلقائي (REP-####)'
          : esc(row.code || '') + (custCount ? ` · ${custCount} عميل` : ' · بلا عملاء'),
        actions: [
          { label: 'رجوع للقائمة', href: '/sales-reps/list' },
          ...(!isNew
            ? [
                {
                  label: 'تقرير العملاء',
                  href: '/sales-reps/reports/by-customers?sales_rep_id=' + id,
                },
              ]
            : []),
        ],
      })}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface si-surface--master">
        <div class="si-surface-head">
          <h2>${isNew ? 'مندوب جديد' : esc(row.name_ar || '')}</h2>
          <div class="si-rep-head-meta">
            ${!isNew && row?.code ? `<span class="si-count" dir="ltr">${esc(row.code)}</span>` : ''}
            ${
              !isNew
                ? `<span class="si-rep-cust-chip" title="عدد العملاء المربوطين"><b dir="ltr">${custCount}</b> عميل</span>`
                : ''
            }
          </div>
        </div>
        <form method="post" action="${isNew ? '/sales-reps/new' : '/sales-reps/' + id}" class="si-master-form si-master-form--rep">
          <input type="hidden" name="id" value="${row ? row.id : 0}">

          <div class="si-master-grid si-master-grid--rep">
            <label class="si-mf si-mf--name">
              <span class="si-mf-label">اسم المندوب <em>*</em></span>
              <input class="si-field" name="name_ar" required value="${esc(row?.name_ar || '')}" autocomplete="off" placeholder="الاسم الكامل">
            </label>

            <label class="si-mf si-mf--code">
              <span class="si-mf-label">الرمز</span>
              <input class="si-field si-field--mono" name="code" value="${esc(row?.code || '')}" dir="ltr" placeholder="12" autocomplete="off" title="رقم البائع في أوراكل — فارغ = تلقائي (REP-####)">
              <span class="si-mf-note">رقم البائع في أوراكل (مثل 12). فارغ = تلقائي. للترحيل إلى INV00024 استخدم رقم البائع وليس REP-0001.</span>
            </label>

            <label class="si-mf">
              <span class="si-mf-label">الهاتف</span>
              <input class="si-field" name="phone" value="${esc(row?.phone || '')}" dir="ltr" placeholder="07xxxxxxxx" autocomplete="off">
            </label>

            <label class="si-mf">
              <span class="si-mf-label">مستودع العهدة</span>
              <select class="si-field" name="warehouse_id">
                <option value="0">— بدون —</option>
                ${whOpts}
              </select>
            </label>

            <label class="si-mf si-mf--full">
              <span class="si-mf-label">العنوان</span>
              <textarea class="si-field" name="address_ar" rows="2" placeholder="اختياري…">${esc(
                row?.address_ar || ''
              )}</textarea>
            </label>
          </div>

          <div class="si-form-actions">
            <button class="si-btn si-btn--primary" type="submit">حفظ</button>
            <a class="si-btn" href="/sales-reps/list">إلغاء</a>
          </div>
        </form>
      </section>
    </div>`;
  res.send(ui.salesPage({ user: req.session.user, title: isNew ? 'إضافة مندوب' : 'تعديل مندوب', bodyHtml: body }));
}

router.get('/sales-reps/new', (req, res) => repForm(req, res, 0));
router.post('/sales-reps/new', async (req, res) => {
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const result = await masters.saveRep(req.body || {});
  if (!result.ok) return res.redirect('/sales-reps/new?err=' + encodeURIComponent(result.error));
  res.redirect('/sales-reps/list?msg=' + encodeURIComponent(result.message || 'تم'));
});

/* ═══════════ جولات المندوبين ═══════════ */
function statusLabel(st) {
  return String(st) === 'posted' ? 'مرحّلة' : 'مسودة';
}

function statusPill(st) {
  return String(st) === 'posted'
    ? ui.statusPill('ok', 'مرحّلة')
    : ui.statusPill('wait', 'مسودة');
}

router.get('/api/sales-reps/addresses', guard('sales_rep_route'), async (req, res) => {
  const regionId = Number(req.query.region_id || 0) || 0;
  const rows = await masters.listAddressesForRegion(regionId);
  res.json({ ok: true, rows });
});

router.get('/api/sales-reps/tour-customers', guard('sales_rep_route'), async (req, res) => {
  const rows = await masters.listTourCustomers({
    salesRepId: Number(req.query.sales_rep_id || 0) || 0,
    regionId: Number(req.query.region_id || 0) || 0,
    regionAddressId: Number(req.query.region_address_id || 0) || 0,
    q: String(req.query.q || ''),
    limit: 400,
  });
  res.json({ ok: true, rows });
});

router.get('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  await masters.ensureTourSchema();
  await masters.ensureMonthlyToursRollover();
  const filterRep = Number(req.query.sales_rep_id || 0) || 0;
  const editId = Number(req.query.id || 0) || 0;
  const wantForm = editId > 0 || String(req.query.new || '') === '1';
  const flash = String(req.query.msg || '');
  const err = String(req.query.err || '');
  const reps = await q.listRepsSimple();

  /* ── شاشة القائمة فقط (مستقلة) ── */
  if (!wantForm) {
    const tours = await masters.listTours({ salesRepId: filterRep });
    const listHtml =
      tours
        .map(
          (r) => `<tr>
      <td class="si-num" dir="ltr">#${r.id}</td>
      <td>${ui.esc(r.sales_rep_name || '')}</td>
      <td class="si-num" dir="ltr">${ui.esc(ui.isoToDmy(r.date_from))} – ${ui.esc(ui.isoToDmy(r.date_to))}</td>
      <td class="si-num" dir="ltr">${Number(r.customer_count || 0)}</td>
      <td>${statusPill(r.status)}</td>
      <td>${dash(r.notes)}</td>
      <td class="srr-table-actions">
        <a class="si-btn" href="/sales-reps/route?id=${r.id}">فتح</a>
        <a class="si-btn" href="/sales-reps/tour-map?tour_id=${r.id}&sales_rep_id=${Number(r.sales_rep_id) || 0}">خريطة</a>
        <a class="si-btn" href="/sales-reps/route/${r.id}/print">طباعة</a>
        ${
          String(r.status) === 'posted'
            ? `<form method="post" action="/sales-reps/route" style="display:inline">
                <input type="hidden" name="tour_action" value="unpost">
                <input type="hidden" name="id" value="${r.id}">
                <button type="submit" class="si-btn">فك ترحيل</button>
              </form>`
            : `<form method="post" action="/sales-reps/route" style="display:inline">
                <input type="hidden" name="tour_action" value="post">
                <input type="hidden" name="id" value="${r.id}">
                <button type="submit" class="si-btn si-btn--primary">ترحيل</button>
              </form>
              <form method="post" action="/sales-reps/route" style="display:inline" onsubmit="return confirm('حذف الجولة؟');">
                <input type="hidden" name="tour_action" value="delete">
                <input type="hidden" name="id" value="${r.id}">
                <button type="submit" class="si-btn si-btn--danger-text">حذف</button>
              </form>`
        }
      </td>
    </tr>`
        )
        .join('') || ui.emptyRow(7, 'لا جولات بعد');

    const listBody = `
    <div class="si-stage srr-page srr-tour-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'الجولات',
        subtitle: 'جولة شهرية كاملة لكل مندوب · يُفتح الشهر الجديد تلقائياً من الشهر السابق',
        actions: [
          { label: '＋ جولة جديدة', href: '/sales-reps/route?new=1', primary: true },
          { label: 'خريطة الجولة', href: '/sales-reps/tour-map' },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits' },
          { label: 'المندوبين', href: '/sales-reps/list' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}
      <section class="si-surface srr-list-card">
        <div class="si-surface-head">
          <h2>الجولات</h2>
          <span class="si-count">${tours.length} صف</span>
        </div>
        <div class="srr-list-filter">
          <form method="get" action="/sales-reps/route" class="srr-filter-form">
            <label>
              <span>تصفية بالمندوب</span>
              <select name="sales_rep_id" class="si-field" onchange="this.form.submit()">
                <option value="0">كل المندوبين</option>
                ${reps
                  .map(
                    (r) =>
                      `<option value="${r.id}" ${filterRep === Number(r.id) ? 'selected' : ''}>${esc(
                        r.name_ar
                      )}</option>`
                  )
                  .join('')}
              </select>
            </label>
          </form>
        </div>
        <div class="si-table-wrap srr-table-wrap">
          <table class="si-table">
            <thead>
              <tr>
                <th>#</th>
                <th>المندوب</th>
                <th>الفترة</th>
                <th>عملاء</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
                <th></th>
              </tr>
            </thead>
            <tbody>${listHtml}</tbody>
          </table>
        </div>
      </section>
    </div>`;

    return res.send(
      ui.salesPage({
        user: req.session.user,
        title: 'الجولات',
        bodyHtml: listBody,
        css: ['/assets/css/sales-rep-route.css'],
        js: ['/assets/js/sales-print.js'],
        activePath: '/sales-reps/route',
      })
    );
  }

  /* ── شاشة النموذج (رحلة / تعديل) ── */
  const edit = editId ? await masters.getTour(editId) : null;
  const regions = await masters.listRegionsSimple();
  const selectedRep = edit ? Number(edit.sales_rep_id) : filterRep;
  const isPosted = edit && String(edit.status) === 'posted';
  const month = masters.monthBoundsIso();
  const qFrom = masters.normalizeIsoDate(req.query.date_from);
  const qTo = masters.normalizeIsoDate(req.query.date_to);
  const dateFrom = qFrom || (edit ? String(edit.date_from).slice(0, 10) : month.from);
  const dateTo = qTo || (edit ? String(edit.date_to).slice(0, 10) : month.to);
  const editLines = edit?.lines || [];
  const dayLabels = masters.WEEKDAY_LABELS || [
    'الأحد',
    'الإثنين',
    'الثلاثاء',
    'الأربعاء',
    'الخميس',
    'الجمعة',
    'السبت',
  ];
  // ترتيب العرض: السبت أولاً (مألوف إقليمياً)
  const dayOrder = [6, 0, 1, 2, 3, 4, 5];

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${selectedRep === Number(r.id) ? 'selected' : ''}>${esc(r.name_ar)}${
          r.code ? ' (' + esc(r.code) + ')' : ''
        }</option>`
    )
    .join('');

  const regionOpts = regions
    .map((r) => `<option value="${r.id}">${esc(r.name_ar)}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`)
    .join('');

  const weekdayChips = dayOrder
    .map(
      (wd) =>
        `<button type="button" class="srr-day-chip" data-weekday="${wd}" ${isPosted ? 'disabled' : ''}>
          <span class="srr-day-chip__lab">${esc(dayLabels[wd])}</span>
          <b class="srr-day-chip__n" data-count-for="${wd}">0</b>
        </button>`
    )
    .join('');

  let selectedRows = editLines.map((l) => ({
    customer_id: Number(l.customer_id),
    code: l.customer_code || '',
    name: l.customer_name || '',
    weekday: l.weekday == null || l.weekday === '' ? 0 : Number(l.weekday),
    region_id: Number(l.region_id || 0) || null,
    region_address_id: Number(l.region_address_id || 0) || null,
    region_name: l.region_name || '',
    address_name: l.address_name || '',
  }));

  // استعادة التعيينات بعد رسالة خطأ (تُمرَّر في lines_json)
  if (!selectedRows.length && req.query.lines_json && selectedRep > 0) {
    try {
      const kept = JSON.parse(String(req.query.lines_json));
      if (Array.isArray(kept) && kept.length) {
        const custRows = await masters.listTourCustomers({
          salesRepId: selectedRep,
          limit: 2000,
        });
        const byId = new Map(custRows.map((c) => [Number(c.id), c]));
        selectedRows = kept
          .map((k) => {
            const cid = Number(k.customer_id || 0);
            const c = byId.get(cid);
            if (!cid || !c) return null;
            return {
              customer_id: cid,
              code: c.code || '',
              name: c.name_ar || '',
              weekday: Number(k.weekday || 0),
              region_id: Number(k.region_id || c.region_id || 0) || null,
              region_address_id:
                Number(k.region_address_id || c.region_address_id || 0) || null,
              region_name: c.region_name || '',
              address_name: c.address_name || '',
            };
          })
          .filter(Boolean);
      }
    } catch {
      /* تجاهل */
    }
  }

  const selectedJson = JSON.stringify(selectedRows).replace(/</g, '\\u003c');

  const formTitle = edit
    ? isPosted
      ? `عرض جولة #${edit.id}`
      : `تعديل جولة #${edit.id}`
    : 'جولة جديدة';

  const body = `
    <div class="si-stage srr-page srr-tour-page">
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: formTitle,
        subtitle: 'عملاء المندوب فقط · فترة شهرية قابلة للتعديل · اختر يوم الأسبوع ثم العملاء لذلك اليوم',
        actions: [
          ...(edit
            ? [
                {
                  label: 'طباعة الجولة',
                  href: `/sales-reps/route/${edit.id}/print`,
                  primary: isPosted,
                },
              ]
            : []),
          ...(isPosted
            ? [
                {
                  label: 'فك ترحيل',
                  submit: true,
                  form: 'srr-form',
                  name: 'tour_action',
                  value: 'unpost',
                },
              ]
            : [
                {
                  label: 'حفظ',
                  submit: true,
                  form: 'srr-form',
                  primary: !edit,
                  hxSave: true,
                  title: 'F10',
                },
                {
                  label: 'حفظ وترحيل',
                  submit: true,
                  form: 'srr-form',
                  name: 'and_post',
                  value: '1',
                },
                ...(edit
                  ? [
                      {
                        label: 'ترحيل',
                        submit: true,
                        form: 'srr-form',
                        name: 'tour_action',
                        value: 'post',
                      },
                      {
                        label: 'حذف',
                        submit: true,
                        form: 'srr-form',
                        name: 'tour_action',
                        value: 'delete',
                        danger: true,
                        confirm: 'حذف هذه الجولة نهائياً؟ لا يمكن التراجع.',
                      },
                    ]
                  : []),
              ]),
          { label: 'قائمة الجولات', href: '/sales-reps/route' },
          { label: '＋ جولة جديدة', href: '/sales-reps/route?new=1' },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'المندوبين', href: '/sales-reps/list' },
        ],
      })}
      ${flash ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(flash)}</p>` : ''}
      ${err ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(err)}</p>` : ''}

      <section class="si-surface srr-card">
        <div class="si-surface-head">
          <h2>${edit ? (isPosted ? 'عرض جولة مرحّلة' : 'تعديل جولة') : 'خطة جولة جديدة'}</h2>
          <span class="si-count">${
            edit
              ? '#' + edit.id + ' · ' + statusLabel(edit.status)
              : 'جديد · مسودة'
          }</span>
        </div>
        <form method="post" action="/sales-reps/route" class="srr-form srr-tour-form" id="srr-form">
          <input type="hidden" name="id" value="${edit ? edit.id : 0}">
          <input type="hidden" name="lines_json" id="srr-lines-json" value="">

          <div class="srr-form__fields">
            <div class="srr-step" data-step="1">
              <div class="srr-step__head"><span class="srr-step__num">1</span> المندوب وفترة الجولة</div>
              <div class="srr-form__row">
                <label class="srr-field">
                  <span>المندوب <em>*</em></span>
                  <select class="si-field" name="sales_rep_id" id="srr-rep" required ${isPosted ? 'disabled' : ''}>
                    <option value="">— اختر المندوب —</option>
                    ${repOpts}
                  </select>
                  ${isPosted ? `<input type="hidden" name="sales_rep_id" value="${selectedRep}">` : ''}
                </label>
                <label class="srr-field srr-field--date">
                  <span>شهر الجولة <em>*</em></span>
                  <input class="si-field si-field--mono srr-date" type="date" name="date_from" id="srr-from"
                         required value="${esc(dateFrom)}" dir="ltr" ${isPosted ? 'readonly' : ''}
                         title="أول يوم في الشهر — يُضبط تلقائياً لشهر كامل">
                </label>
                <label class="srr-field srr-field--date">
                  <span>نهاية الشهر</span>
                  <input class="si-field si-field--mono srr-date" type="date" name="date_to" id="srr-to"
                         required value="${esc(dateTo)}" dir="ltr" ${isPosted ? 'readonly' : ''} readonly tabindex="-1">
                </label>
              </div>
              <div class="srr-period">
                <span class="srr-period__txt" id="srr-period-txt">—</span>
                ${
                  isPosted
                    ? ''
                    : `<span class="srr-period__quick">
                        <button type="button" class="si-btn srr-btn-sm" data-month="0">هذا الشهر</button>
                        <button type="button" class="si-btn srr-btn-sm" data-month="1">الشهر القادم</button>
                      </span>`
                }
              </div>
            </div>

            <div class="srr-step" data-step="2">
              <div class="srr-step__head">
                <span class="srr-step__num">2</span> أيام الأسبوع <em>*</em>
                <span class="srr-weekdays__hint muted" id="srr-day-hint">حدد يوماً ثم أضف العملاء</span>
              </div>
              <div class="srr-weekdays" id="srr-weekdays">
                <div class="srr-weekdays__chips" id="srr-day-chips">${weekdayChips}</div>
              </div>
              <details class="srr-cal-wrap">
                <summary>معاينة أيام الشهر</summary>
                <div class="srr-cal" id="srr-cal" aria-hidden="true"></div>
              </details>
            </div>

            <div class="srr-step srr-step--notes" data-step="notes">
              <label class="srr-field srr-field--full">
                <span>ملاحظات</span>
                <input class="si-field srr-notes" name="notes" type="text" placeholder="اختياري…"
                       value="${esc(String(req.query.notes || edit?.notes || ''))}" ${
                         isPosted ? 'readonly' : ''
                       }>
              </label>
            </div>

            <div class="srr-selected-panel">
              <div class="srr-selected-panel__head">
                <strong>عملاء الخطة</strong>
                <span class="srr-plan__sum" id="srr-actions-sum">لا تعيينات بعد</span>
                <span class="srr-chip-count" id="srr-selected-count">0 تعيين</span>
              </div>
              <div class="srr-plan" id="srr-plan"></div>
            </div>
          </div>

          <div class="srr-form__cust">
            <div class="srr-cust__toolbar">
              <div class="srr-cust__title">
                <strong>اختيار العملاء</strong>
                <span class="muted" id="srr-cust-day-label">— اختر يوماً —</span>
                <span class="muted" id="srr-cust-total">0</span>
              </div>
              <div class="srr-cust__filters">
                <label class="srr-field">
                  <span>المنطقة</span>
                  <select class="si-field" id="srr-region" ${isPosted ? 'disabled' : ''}>
                    <option value="0">— كل المناطق —</option>
                    ${regionOpts}
                  </select>
                </label>
                <label class="srr-field">
                  <span>العنوان</span>
                  <select class="si-field" id="srr-address" ${isPosted ? 'disabled' : ''}>
                    <option value="0">— كل العناوين —</option>
                  </select>
                </label>
              </div>
              <input type="search" class="si-field srr-cust__search" id="srr-cust-q"
                     placeholder="بحث بالاسم أو الرمز…" autocomplete="off" ${isPosted ? 'disabled' : ''}>
              <div class="srr-cust__tools">
                <button type="button" class="si-btn srr-btn-sm" id="srr-add-visible" ${
                  isPosted ? 'disabled' : ''
                }>إضافة الظاهر لليوم</button>
              </div>
            </div>
            <div class="srr-cust__list" id="srr-cust-list">
              <p class="srr-cust__empty">اختر المندوب ويوم الأسبوع لعرض عملائه</p>
            </div>
          </div>
        </form>
      </section>
    </div>
    <script>
    (function(){
      var posted = ${isPosted ? 'true' : 'false'};
      var selected = ${selectedJson};
      var dayLabels = ${JSON.stringify(dayLabels)};
      var activeWeekday = null;
      var repEl = document.getElementById('srr-rep');
      var regionEl = document.getElementById('srr-region');
      var addressEl = document.getElementById('srr-address');
      var list = document.getElementById('srr-cust-list');
      var qEl = document.getElementById('srr-cust-q');
      var totalEl = document.getElementById('srr-cust-total');
      var dayLabelEl = document.getElementById('srr-cust-day-label');
      var dayHint = document.getElementById('srr-day-hint');
      var planEl = document.getElementById('srr-plan');
      var countEl = document.getElementById('srr-selected-count');
      var sumEl = document.getElementById('srr-actions-sum');
      var calEl = document.getElementById('srr-cal');
      var linesInput = document.getElementById('srr-lines-json');
      var form = document.getElementById('srr-form');
      var addVisibleBtn = document.getElementById('srr-add-visible');
      var chips = document.getElementById('srr-day-chips');
      var fromEl = document.getElementById('srr-from');
      var toEl = document.getElementById('srr-to');
      var periodEl = document.getElementById('srr-period-txt');
      var custCache = [];
      var loadTimer = null;

      function dmy(iso){
        var m = String(iso||'').match(/^(\\d{4})-(\\d{2})-(\\d{2})$/);
        return m ? (m[3]+'-'+m[2]+'-'+m[1]) : '';
      }

      function isoOfMonth(offset, last){
        var n = new Date();
        var y = n.getFullYear(), mo = n.getMonth() + Number(offset||0);
        var d = last ? new Date(y, mo+1, 0) : new Date(y, mo, 1);
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0')
          + '-' + String(d.getDate()).padStart(2,'0');
      }

      function boundsFromAnyIso(iso){
        var m = String(iso||'').match(/^(\\d{4})-(\\d{2})-\\d{2}$/);
        if(!m) return null;
        var y = +m[1], mo = +m[2]-1;
        var last = new Date(y, mo+1, 0).getDate();
        return {
          from: m[1]+'-'+m[2]+'-01',
          to: m[1]+'-'+m[2]+'-'+String(last).padStart(2,'0')
        };
      }

      function snapFullMonth(){
        if(posted || !fromEl || !toEl) return;
        var b = boundsFromAnyIso(fromEl.value);
        if(!b) return;
        fromEl.value = b.from;
        toEl.value = b.to;
      }

      function renderPeriod(){
        if(!periodEl) return;
        var f = fromEl && fromEl.value, t = toEl && toEl.value;
        if(!f || !t){ periodEl.textContent = 'حدّد شهر الجولة (شهر تقويمي كامل).'; return; }
        var days = Math.round((new Date(t+'T12:00:00') - new Date(f+'T12:00:00')) / 86400000) + 1;
        periodEl.textContent = days > 0
          ? ('شهر الجولة: ' + dmy(f) + ' → ' + dmy(t) + ' · ' + days + ' يوماً')
          : 'تاريخ غير صالح.';
      }

      function escHtml(s){
        return String(s==null?'':s)
          .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
      }

      function countByDay(){
        var map = {};
        selected.forEach(function(r){
          var w = Number(r.weekday);
          map[w] = (map[w]||0) + 1;
        });
        return map;
      }

      function syncHidden(){
        if(linesInput) linesInput.value = JSON.stringify(selected.map(function(r){
          return {
            customer_id: r.customer_id,
            weekday: Number(r.weekday),
            region_id: r.region_id || null,
            region_address_id: r.region_address_id || null
          };
        }));
        if(countEl) countEl.textContent = selected.length + ' تعيين';
        var counts = countByDay();
        if(chips){
          chips.querySelectorAll('.srr-day-chip').forEach(function(b){
            var w = Number(b.getAttribute('data-weekday'));
            var n = counts[w] || 0;
            var badge = b.querySelector('.srr-day-chip__n');
            if(badge) badge.textContent = String(n);
            b.classList.toggle('has-cust', n > 0);
          });
        }
        if(sumEl){
          var daysUsed = Object.keys(counts).length;
          sumEl.textContent = selected.length
            ? (selected.length + ' تعيين على ' + daysUsed + ' يوم من أيام الأسبوع')
            : 'لا تعيينات بعد';
        }
        renderCal();
      }

      /** معاينة الشهر: تُبرز التواريخ التي ستُنشأ فيها زيارات */
      function renderCal(){
        if(!calEl) return;
        var f = fromEl && fromEl.value, t = toEl && toEl.value;
        if(!f || !t || f > t){ calEl.innerHTML = ''; return; }
        var counts = countByDay();
        var start = new Date(f+'T12:00:00'), end = new Date(t+'T12:00:00');
        if(isNaN(start) || isNaN(end)){ calEl.innerHTML = ''; return; }
        var span = Math.round((end - start)/86400000) + 1;
        if(span < 1 || span > 120){ calEl.innerHTML = ''; return; }
        var shortLabels = ['أحد','إثنين','ثلاثاء','أربعاء','خميس','جمعة','سبت'];
        var cells = [];
        var wdHead = [6,0,1,2,3,4,5];
        wdHead.forEach(function(w){
          cells.push('<span class="srr-cal__h">'+escHtml(shortLabels[w]||'')+'</span>');
        });
        // فراغات قبل أول يوم لمحاذاة الأعمدة (السبت أولاً)
        var lead = (start.getDay() + 1) % 7;
        for(var i=0;i<lead;i++) cells.push('<span class="srr-cal__d is-void"></span>');
        var cur = new Date(start);
        while(cur <= end){
          var w = cur.getDay();
          var n = counts[w] || 0;
          cells.push('<span class="srr-cal__d'+(n?' is-on':'')+'" title="'
            + escHtml(dayLabels[w]||'') + (n ? (' · '+n+' عميل') : '')
            + '">'+cur.getDate()+(n?'<i></i>':'')+'</span>');
          cur.setDate(cur.getDate()+1);
        }
        calEl.innerHTML = '<div class="srr-cal__grid">'+cells.join('')+'</div>';
      }

      function setActiveDay(wd){
        activeWeekday = (wd === null || wd === undefined || wd === '') ? null : Number(wd);
        if(chips){
          chips.querySelectorAll('.srr-day-chip').forEach(function(b){
            b.classList.toggle('is-active', Number(b.getAttribute('data-weekday')) === activeWeekday);
          });
        }
        if(dayLabelEl){
          dayLabelEl.textContent = activeWeekday === null ? '— اختر يوماً —' : (dayLabels[activeWeekday] || '');
        }
        if(dayHint){
          dayHint.textContent = activeWeekday === null
            ? 'حدد يوماً من أيام الأسبوع لبدء إضافة العملاء.'
            : 'اليوم النشط: ' + (dayLabels[activeWeekday] || '') + ' — أضف عملاء هذا اليوم فقط.';
        }
        if(planEl){
          planEl.querySelectorAll('.srr-plan__day').forEach(function(d){
            var b = d.querySelector('[data-goto-day]');
            d.classList.toggle('is-active', !!b && Number(b.getAttribute('data-goto-day')) === activeWeekday);
          });
        }
        renderCustList();
      }

      /** عملاء الخطة مجمّعين تحت كل يوم أسبوع */
      function renderSelected(){
        if(!planEl) return;
        if(!selected.length){
          planEl.innerHTML = '<p class="srr-plan__empty">لم يُختر عملاء بعد — اختر يوماً ثم أضف عملاءه.</p>';
          syncHidden();
          return;
        }
        var order = [6,0,1,2,3,4,5];
        var html = '';
        order.forEach(function(wd){
          var rows = selected.filter(function(r){ return Number(r.weekday)===wd; });
          if(!rows.length) return;
          rows.sort(function(a,b){
            return String(a.name||'').localeCompare(String(b.name||''),'ar');
          });
          html += '<div class="srr-plan__day'+(wd===activeWeekday?' is-active':'')+'">'
            + '<div class="srr-plan__dayhead">'
            + '<button type="button" class="srr-plan__daybtn" data-goto-day="'+wd+'">'
            + escHtml(dayLabels[wd]||'') + '</button>'
            + '<span class="srr-chip-count">'+rows.length+'</span>'
            + '</div><div class="srr-plan__items">'
            + rows.map(function(r){
                return '<span class="srr-cust-tag" title="'+escHtml([r.region_name,r.address_name].filter(Boolean).join(' / '))+'">'
                  + '<b>'+escHtml(r.name)+'</b>'
                  + '<i dir="ltr">'+escHtml(r.code)+'</i>'
                  + (posted ? '' : '<button type="button" class="srr-cust-tag__x srr-remove" data-cid="'
                      + r.customer_id + '" data-wd="' + Number(r.weekday) + '" title="حذف">×</button>')
                  + '</span>';
              }).join('')
            + '</div></div>';
        });
        planEl.innerHTML = html;
        syncHidden();
      }

      function hasAssignment(cid, wd){
        return selected.some(function(r){
          return Number(r.customer_id)===Number(cid) && Number(r.weekday)===Number(wd);
        });
      }

      function addCustomer(c){
        if(posted) return;
        if(activeWeekday === null || activeWeekday === undefined || isNaN(activeWeekday)){
          alert('اختر يوم الأسبوع أولاً.');
          return;
        }
        var id = Number(c.id);
        if(!id || hasAssignment(id, activeWeekday)) return;
        selected.push({
          customer_id: id,
          code: c.code || '',
          name: c.name_ar || c.name || '',
          weekday: Number(activeWeekday),
          region_id: c.region_id || null,
          region_address_id: c.region_address_id || null,
          region_name: c.region_name || '',
          address_name: c.address_name || ''
        });
        renderSelected();
        renderCustList();
      }

      function removeAssignment(cid, wd){
        selected = selected.filter(function(r){
          return !(Number(r.customer_id)===Number(cid) && Number(r.weekday)===Number(wd));
        });
        renderSelected();
        renderCustList();
      }

      function renderCustList(){
        if(!list) return;
        if(Number(repEl && repEl.value || 0) < 1){
          list.innerHTML = '<p class="srr-cust__empty">اختر المندوب أولاً لعرض عملائه فقط</p>';
          if(totalEl) totalEl.textContent = '0';
          return;
        }
        if(activeWeekday === null || activeWeekday === undefined || isNaN(activeWeekday)){
          list.innerHTML = '<p class="srr-cust__empty">اختر يوم الأسبوع ثم أضف عملاء هذا اليوم</p>';
          if(totalEl) totalEl.textContent = String(custCache.length || 0);
          return;
        }
        if(!custCache.length){
          list.innerHTML = '<p class="srr-cust__empty">لا يوجد عملاء تابعون لهذا المندوب'+(regionEl && Number(regionEl.value)>0 ? ' ضمن الفلتر' : '')+'</p>';
          if(totalEl) totalEl.textContent = '0';
          return;
        }
        list.innerHTML = custCache.map(function(c){
          var on = hasAssignment(c.id, activeWeekday);
          return '<label class="srr-cust__row'+(on?' is-on':'')+'">'
            + '<input type="checkbox" '+(on?'checked':'')+' data-id="'+c.id+'" '+(posted?'disabled':'')+'>'
            + '<span class="srr-cust__name">'+escHtml(c.name_ar||'')
            + (c.region_name || c.address_name
                ? ' <span class="muted">· '+escHtml([c.region_name,c.address_name].filter(Boolean).join(' / '))+'</span>'
                : '')
            + '</span>'
            + '<span class="srr-cust__code" dir="ltr">'+escHtml(c.code||'')+'</span>'
            + '</label>';
        }).join('');
        if(totalEl) totalEl.textContent = String(custCache.length);
      }

      function loadAddresses(){
        if(!addressEl) return Promise.resolve();
        var rid = Number(regionEl && regionEl.value || 0);
        addressEl.innerHTML = '<option value="0">— كل العناوين —</option>';
        if(rid < 1) return Promise.resolve();
        return fetch('/api/sales-reps/addresses?region_id='+rid, {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(j){
            (j.rows||[]).forEach(function(a){
              var o = document.createElement('option');
              o.value = a.id;
              o.textContent = a.name_ar || ('#'+a.id);
              addressEl.appendChild(o);
            });
          }).catch(function(){});
      }

      function loadCustomers(){
        if(!list) return;
        var repId = Number(repEl && repEl.value || 0);
        if(repId < 1){
          custCache = [];
          renderCustList();
          return;
        }
        list.innerHTML = '<p class="srr-cust__empty">جاري التحميل…</p>';
        var qs = new URLSearchParams({
          sales_rep_id: String(repId),
          region_id: String(regionEl && regionEl.value || 0),
          region_address_id: String(addressEl && addressEl.value || 0),
          q: String(qEl && qEl.value || '')
        });
        fetch('/api/sales-reps/tour-customers?'+qs.toString(), {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(j){
            custCache = j.rows || [];
            renderCustList();
          })
          .catch(function(){
            list.innerHTML = '<p class="srr-cust__empty">تعذر تحميل العملاء</p>';
          });
      }

      function scheduleLoad(){
        clearTimeout(loadTimer);
        loadTimer = setTimeout(loadCustomers, 200);
      }

      if(chips){
        chips.addEventListener('click', function(e){
          var btn = e.target.closest('.srr-day-chip');
          if(!btn || posted) return;
          setActiveDay(Number(btn.getAttribute('data-weekday')));
        });
      }

      if(list){
        list.addEventListener('change', function(e){
          var t = e.target;
          if(!t || t.type !== 'checkbox') return;
          var id = Number(t.getAttribute('data-id')||0);
          if(!id) return;
          if(activeWeekday === null || isNaN(activeWeekday)){
            t.checked = false;
            alert('اختر يوم الأسبوع أولاً.');
            return;
          }
          if(t.checked){
            var c = custCache.find(function(x){ return Number(x.id)===id; });
            if(c) addCustomer(c);
          } else {
            removeAssignment(id, activeWeekday);
          }
        });
      }

      if(planEl){
        planEl.addEventListener('click', function(e){
          var rm = e.target.closest('.srr-remove');
          if(rm){
            removeAssignment(Number(rm.getAttribute('data-cid')||0), Number(rm.getAttribute('data-wd')||0));
            return;
          }
          var go = e.target.closest('[data-goto-day]');
          if(go && !posted) setActiveDay(Number(go.getAttribute('data-goto-day')));
        });
      }

      if(repEl) repEl.addEventListener('change', function(){
        custCache = [];
        scheduleLoad();
      });
      if(regionEl) regionEl.addEventListener('change', function(){
        loadAddresses().then(scheduleLoad);
      });
      if(addressEl) addressEl.addEventListener('change', scheduleLoad);
      if(qEl) qEl.addEventListener('input', scheduleLoad);

      if(addVisibleBtn) addVisibleBtn.addEventListener('click', function(){
        if(activeWeekday === null || isNaN(activeWeekday)){
          alert('اختر يوم الأسبوع أولاً.');
          return;
        }
        custCache.forEach(addCustomer);
      });

      function periodChanged(){ snapFullMonth(); renderPeriod(); renderCal(); }
      if(fromEl) fromEl.addEventListener('change', periodChanged);
      if(toEl) toEl.addEventListener('change', periodChanged);
      document.querySelectorAll('.srr-period__quick [data-month]').forEach(function(b){
        b.addEventListener('click', function(){
          var off = Number(b.getAttribute('data-month')||0);
          if(fromEl) fromEl.value = isoOfMonth(off, false);
          if(toEl) toEl.value = isoOfMonth(off, true);
          periodChanged();
        });
      });
      renderPeriod();

      if(form) form.addEventListener('submit', function(e){
        syncHidden();
        if(posted) return;
        if(fromEl && toEl && fromEl.value && toEl.value && fromEl.value > toEl.value){
          var tmp = fromEl.value; fromEl.value = toEl.value; toEl.value = tmp;
        }
        if(!selected.length){
          e.preventDefault();
          alert('أضف عميلاً واحداً على الأقل بعد اختيار يوم الأسبوع.');
        }
      });

      // على التعديل: فعّل أول يوم موجود في الخطة
      if(selected.length){
        setActiveDay(Number(selected[0].weekday));
      } else {
        setActiveDay(null);
      }
      renderSelected();
      if(Number(repEl && repEl.value || 0) > 0) scheduleLoad();
    })();
    </script>`;
  res.send(
    ui.salesPage({
      user: req.session.user,
      title: formTitle,
      bodyHtml: body,
      css: ['/assets/css/sales-rep-route.css'],
      js: ['/assets/js/sales-print.js'],
      activePath: '/sales-reps/route',
    })
  );
});

router.post('/sales-reps/route', guard('sales_rep_route'), async (req, res) => {
  const body = req.body || {};
  const tourAction = String(body.tour_action || '').trim().toLowerCase();
  const tourId = Number(body.id || 0);

  if (tourAction === 'post' || tourAction === 'unpost' || tourAction === 'delete') {
    let result;
    if (tourAction === 'post') result = await masters.postTour(tourId, req.session.user?.id);
    else if (tourAction === 'unpost') result = await masters.unpostTour(tourId, req.session.user?.id);
    else result = await masters.deleteTour(tourId);
    const key = result.ok ? 'msg' : 'err';
    const qs =
      tourAction === 'delete'
        ? `${key}=${encodeURIComponent(result.message || result.error || '')}`
        : `id=${tourId}&${key}=${encodeURIComponent(result.message || result.error || '')}`;
    return res.redirect('/sales-reps/route?' + qs);
  }

  try {
    if (body.lines_json) {
      const parsed = JSON.parse(String(body.lines_json || '[]'));
      if (Array.isArray(parsed)) body.lines = parsed;
    }
  } catch {
    /* keep customer_ids if any */
  }
  const result = await masters.saveTour(body, req.session.user?.id);
  if (!result.ok) {
    // احفظ ما أدخله المستخدم حتى لا يعيد تعبئة الشاشة من الصفر
    const keep = new URLSearchParams();
    if (tourId > 0) {
      keep.set('id', String(tourId));
    } else {
      keep.set('new', '1');
      if (body.sales_rep_id) keep.set('sales_rep_id', String(body.sales_rep_id));
    }
    const from = masters.normalizeIsoDate(body.date_from);
    const to = masters.normalizeIsoDate(body.date_to);
    if (from) keep.set('date_from', from);
    if (to) keep.set('date_to', to);
    if (body.notes) keep.set('notes', String(body.notes).slice(0, 300));
    if (body.lines_json) keep.set('lines_json', String(body.lines_json).slice(0, 8000));
    keep.set('err', result.error);
    return res.redirect('/sales-reps/route?' + keep.toString());
  }
  if (String(body.and_post || '') === '1') {
    const post = await masters.postTour(result.id, req.session.user?.id);
    return res.redirect(
      '/sales-reps/route?id=' +
        result.id +
        (post.ok ? '&msg=' : '&err=') +
        encodeURIComponent(post.ok ? post.message : post.error || result.message)
    );
  }
  res.redirect(
    '/sales-reps/route?id=' +
      result.id +
      '&msg=' +
      encodeURIComponent(result.message || 'تم')
  );
});

router.post('/sales-reps/route/:id/post', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.postTour(req.params.id, req.session.user?.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales-reps/route?id=' +
      Number(req.params.id) +
      '&' +
      key +
      '=' +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales-reps/route/:id/unpost', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.unpostTour(req.params.id, req.session.user?.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect(
    '/sales-reps/route?id=' +
      Number(req.params.id) +
      '&' +
      key +
      '=' +
      encodeURIComponent(result.message || result.error || '')
  );
});

router.post('/sales-reps/route/:id/delete', guard('sales_rep_route'), async (req, res) => {
  const result = await masters.deleteTour(req.params.id);
  const key = result.ok ? 'msg' : 'err';
  res.redirect('/sales-reps/route?' + key + '=' + encodeURIComponent(result.message || result.error || ''));
});

router.get('/sales-reps/route/:id/print', guard('sales_rep_route'), async (req, res) => {
  const data = await masters.getTourPrintRows(req.params.id);
  if (!data) return res.status(404).send('الجولة غير موجودة');
  const { tour, groups = [], day_count: dayCount = 0 } = data;
  const totalCust = groups.reduce((n, g) => n + g.customers.length, 0);
  const repName = String(tour.sales_rep_name || '').trim() || 'مندوب';
  const repCode = String(tour.sales_rep_code || '').trim();
  const period = ui.isoToDmy(tour.date_from) + ' → ' + ui.isoToDmy(tour.date_to);
  const groupsHtml =
    groups
      .map((g) => {
        const dates = (g.dates || []).map((d) => ui.isoToDmy(d)).join(' · ');
        const body =
          g.customers
            .map(
              (c, i) => `<tr>
            <td class="c-idx" dir="ltr">${i + 1}</td>
            <td class="c-code" dir="ltr">${dash(c.customer_code)}</td>
            <td class="c-name">${ui.esc(c.customer_name || '')}</td>
            <td>${dash(c.region_name)}</td>
            <td>${dash(c.address_name)}</td>
          </tr>`
            )
            .join('') || '<tr><td colspan="5" class="empty">لا عملاء لهذا اليوم</td></tr>';
        return `<section class="srr-prt-day">
          <h3>
            <span>${ui.esc(g.weekday_label || '')}</span>
            <span class="srr-prt-day__n">${g.customers.length} عميل</span>
          </h3>
          ${dates ? `<p class="srr-prt-dates" dir="ltr">${ui.esc(dates)}</p>` : ''}
          <table class="srr-prt-table">
            <thead>
              <tr>
                <th style="width:2.2rem">#</th>
                <th style="width:7rem">الرمز</th>
                <th>العميل</th>
                <th>المنطقة</th>
                <th>العنوان</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </section>`;
      })
      .join('') || '<p class="empty">لا عملاء في هذه الجولة.</p>';

  const contentHtml = `
    <style>
      .srr-prt-meta{width:100%;border-collapse:collapse;margin:0 0 14px;font-size:10.5pt}
      .srr-prt-meta th{width:7.5rem;text-align:start;padding:5px 8px;background:#f1f5f9;border:1px solid #cbd5e1;font-weight:700}
      .srr-prt-meta td{padding:5px 8px;border:1px solid #cbd5e1}
      .srr-prt-day{margin:0 0 16px;break-inside:avoid}
      .srr-prt-day h3{margin:0 0 4px;font:800 12pt/1.3 Arial,Helvetica,sans-serif;color:#1e3a5f;
        display:flex;align-items:center;justify-content:space-between;gap:.5rem;
        padding:6px 8px;background:#e8eef5;border:1px solid #94a3b8}
      .srr-prt-day__n{font:700 9.5pt Arial,Helvetica,sans-serif;color:#334155}
      .srr-prt-dates{margin:0 0 6px;font:500 8.5pt/1.4 Arial,Helvetica,sans-serif;color:#475569}
      .srr-prt-table{width:100%;border-collapse:collapse;font-size:9pt}
      .srr-prt-table th{background:#5b6b7c;color:#fff;font-weight:700;padding:5px 6px;border:1px solid #4a5568;text-align:center}
      .srr-prt-table td{border:1px solid #94a3b8;padding:4px 6px;vertical-align:middle}
      .srr-prt-table .c-idx,.srr-prt-table .c-code{text-align:center;font-variant-numeric:tabular-nums}
      .srr-prt-table .c-name{font-weight:700}
      @media print{
        .srr-prt-table th{background:#5b6b7c!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        .srr-prt-day h3{background:#e8eef5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      }
    </style>
    <table class="srr-prt-meta">
      <tbody>
        <tr><th>المندوب</th><td>${ui.esc(repName)}${repCode ? ' (' + ui.esc(repCode) + ')' : ''}</td></tr>
        <tr><th>رقم الجولة</th><td dir="ltr">#${Number(tour.id) || ''}</td></tr>
        <tr><th>الفترة</th><td dir="ltr">${ui.esc(period)}${dayCount ? ' · ' + dayCount + ' يوم' : ''}</td></tr>
        <tr><th>الحالة</th><td>${statusLabel(tour.status)}</td></tr>
        <tr><th>عدد العملاء</th><td>${totalCust} عميل · ${groups.length} يوم عمل</td></tr>
        ${tour.notes ? `<tr><th>ملاحظات</th><td>${ui.esc(tour.notes)}</td></tr>` : ''}
      </tbody>
    </table>
    ${groupsHtml}`;

  res.send(
    await renderStandalonePrintPage({
      user: req.session.user,
      documentTitle: 'جولة مندوب — ' + repName,
      backHref: '/sales-reps/route?id=' + tour.id,
      contentHtml,
      autoPrint: false,
      printMode: 'sheet',
    })
  );
});

/* ═══════════ Reports → تحت المبيعات ═══════════ */
function redirectSalesReport(basePath) {
  return (req, res) => {
    const qs = new URLSearchParams(req.query).toString();
    res.redirect(basePath + (qs ? '?' + qs : ''));
  };
}

router.get('/sales-reps/reports/by-rep', redirectSalesReport('/sales/reports/by-rep'));
router.get('/sales-reps/reports/by-region', redirectSalesReport('/sales/reports/by-region'));

/** اعتماد خروج يدوي — شاشة Node أصلية (بدون iframe إلى localhost) */
async function renderVisitCheckoutApprove(req, res, { flash = '', err = '' } = {}) {
  const status = ['pending', 'approved', 'rejected', 'all'].includes(String(req.query.status || ''))
    ? String(req.query.status)
    : 'pending';
  const focusId = Number(req.query.id || 0) || 0;
  const rows = await masters.listVisitCheckoutRequests(status, 200);
  const msg = flash || String(req.query.msg || '');
  const error = err || String(req.query.err || '');

  function stLbl(st) {
    if (st === 'pending') return ui.statusPill('wait', 'معلّق');
    if (st === 'approved') return ui.statusPill('ok', 'موافق عليه');
    if (st === 'rejected') return ui.statusPill('lock', 'مرفوض');
    return esc(st);
  }
  function distLabel(v) {
    if (v == null || v === '') return '—';
    const n = Number(v);
    return Number.isFinite(n) ? `${Math.round(n)} م` : '—';
  }

  const tabs = [
    ['pending', 'معلّق'],
    ['approved', 'موافق عليه'],
    ['rejected', 'مرفوض'],
    ['all', 'الكل'],
  ]
    .map(
      ([k, lab]) =>
        `<a class="si-btn${status === k ? ' si-btn--primary' : ''}" href="/sales-reps/visit-checkout-approve?status=${k}">${esc(
          lab
        )}</a>`
    )
    .join(' ');

  const rowsHtml =
    rows
      .map((r) => {
        const id = Number(r.id) || 0;
        const st = String(r.status || '');
        const focus = focusId > 0 && focusId === id;
        const actions =
          st === 'pending'
            ? `<form method="post" action="/sales-reps/visit-checkout-approve" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="status" value="${esc(status)}">
                <input class="si-field" type="text" name="note" placeholder="ملاحظة" style="min-width:8rem">
                <button class="si-btn si-btn--primary" type="submit" name="action" value="approve">موافقة</button>
                <button class="si-btn" type="submit" name="action" value="reject">رفض</button>
              </form>`
            : `<span class="muted">${esc(r.decided_by_name || '')}</span>`;
        return `<tr${focus ? ' style="background:#fff7ed"' : ''}>
          <td class="si-num" dir="ltr">${id}</td>
          <td>${esc(r.sales_rep_name || '—')}</td>
          <td>${esc(r.customer_name || '—')}<div class="muted" dir="ltr">${esc(r.customer_code || '')}</div></td>
          <td>${esc(r.reason || '—')}</td>
          <td class="si-num" dir="ltr">${distLabel(r.request_distance_m)}</td>
          <td class="si-num" dir="ltr">${esc(r.created_at || '')}</td>
          <td>${stLbl(st)}</td>
          <td>${actions}</td>
        </tr>`;
      })
      .join('') || ui.emptyRow(8, status === 'pending' ? 'لا توجد طلبات معلّقة.' : 'لا توجد طلبات.');

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '🚪',
        kicker: KICKER,
        title: 'اعتماد خروج يدوي من الزيارة',
        subtitle: 'طلبات المندوبين الذين دخلوا بـ GPS ونسوا الخروج من موقع العميل',
        actions: [
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits', primary: true },
        ],
      })}
      ${msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : ''}
      ${error ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(error)}</p>` : ''}
      <section class="si-surface no-print" style="padding:.85rem 1rem;margin-bottom:.75rem">
        <div class="si-meta" style="align-items:center;gap:.4rem">${tabs}</div>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem">${rows.length} طلب</p>
      </section>
      ${ui.tableSurface(
        'طلبات الخروج اليدوي',
        `${rows.length} صف`,
        ['#', 'المندوب', 'العميل', 'السبب', 'المسافة', 'وقت الطلب', 'الحالة', ''],
        rowsHtml
      )}
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'اعتماد خروج يدوي',
      bodyHtml: body,
    })
  );
}

router.get('/sales-reps/visit-checkout-approve', guard('sales_rep_visit_checkout_approve'), (req, res) => {
  return renderVisitCheckoutApprove(req, res);
});

router.post('/sales-reps/visit-checkout-approve', guard('sales_rep_visit_checkout_approve'), async (req, res) => {
  const body = req.body || {};
  const result = await masters.decideVisitCheckoutRequest({
    id: body.id,
    approve: String(body.action || '') === 'approve',
    userId: req.session.user && req.session.user.id,
    note: body.note || null,
  });
  const status = String(body.status || req.query.status || 'pending');
  const q = new URLSearchParams();
  q.set('status', status);
  if (result.ok) q.set('msg', result.message || 'تم');
  else q.set('err', result.message || 'تعذّر التنفيذ');
  res.redirect('/sales-reps/visit-checkout-approve?' + q.toString());
});

function fmtGpsCoord(lat, lng) {
  if (lat == null || lat === '' || lng == null || lng === '') return '—';
  const a = Number(lat);
  const b = Number(lng);
  if (!Number.isFinite(a) || !Number.isFinite(b)) return '—';
  return `${a.toFixed(6)} ، ${b.toFixed(6)}`;
}

const REP_REPORT_CSS = ['/assets/css/report-rep-reports.css'];
function repReportStyle() {
  try {
    const cssPath = path.join(__dirname, '../../public/css/report-rep-reports.css');
    return `<style>${fs.readFileSync(cssPath, 'utf8')}</style>`;
  } catch {
    return '';
  }
}

function visitTsMethod(ts, method, fmtTsFn, methodLblFn) {
  const t = fmtTsFn(ts);
  const m = methodLblFn(method);
  if (t === '—' && m === '—') return '—';
  return `<span class="si-ts-method" dir="ltr">${t}${m !== '—' ? `<span class="muted"> · ${esc(m)}</span>` : ''}</span>`;
}

function fmtTimeOnly(v) {
  if (!v) return '';
  const s = String(v);
  if (s.length >= 16) return s.slice(11, 16);
  return s.trim();
}

function visitMethodPairLabel(cm, com) {
  if (cm === '—' && com === '—') return '—';
  if (cm !== '—' && com !== '—') return cm === com ? cm : `${cm} / ${com}`;
  return cm !== '—' ? cm : com;
}

function visitTimingCells(r, durationFn, methodLblFn) {
  const cin = fmtTimeOnly(r.visit_checkin_at) || '—';
  const cout = fmtTimeOnly(r.visit_checkout_at) || '—';
  const dur = durationFn(r.visit_checkin_at, r.visit_checkout_at);
  const checkinMethod = methodLblFn(r.checkin_method);
  const checkoutMethod = methodLblFn(r.checkout_method);
  return `<td class="si-col-checkin" dir="ltr">${cin}</td>
      <td class="si-col-checkout" dir="ltr">${cout}</td>
      <td class="si-col-duration">${dur}</td>
      <td class="si-col-method">${checkinMethod}</td>
      <td class="si-col-checkout-method">${checkoutMethod}</td>`;
}

function customerNameOnly(r) {
  return esc(r.customer_name || '—');
}

function visitRowClass(r) {
  const orderCount = Number(r.order_count || 0);
  const reasons = String(r.no_order_reasons || '').trim();
  if (orderCount <= 0 && reasons !== '' && reasons !== '—') return 'si-visits-no-order';
  return '';
}

function visitTotals(rows) {
  let mins = 0;
  let sales = 0;
  for (const r of rows) {
    if (r.visit_checkin_at && r.visit_checkout_at) {
      const t1 = Date.parse(String(r.visit_checkin_at).replace(' ', 'T'));
      const t2 = Date.parse(String(r.visit_checkout_at).replace(' ', 'T'));
      if (Number.isFinite(t1) && Number.isFinite(t2) && t2 >= t1) {
        mins += Math.floor((t2 - t1) / 60000);
      }
    }
    sales += Number(r.order_total || 0);
  }
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  const hasClosed = rows.some((r) => r.visit_checkin_at && r.visit_checkout_at);
  return {
    duration_label: hasClosed ? `${h}:${String(m).padStart(2, '0')}` : '—',
    sales_total: sales,
  };
}

function visitMoney(n) {
  return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function customerInline(r) {
  const name = esc(r.customer_name || '—');
  const code = String(r.customer_code || '').trim();
  return code ? `${name} <span class="muted si-cust-code" dir="ltr">(${esc(code)})</span>` : name;
}

function locationInline(r) {
  const reg = String(r.region_name || '').trim();
  const addr = String(r.address_name || '').trim();
  if (reg && addr) return esc(`${reg} / ${addr}`);
  return esc(reg || addr || '—');
}

function repNameInline(name, code) {
  const n = esc(name || '—');
  const c = String(code || '').trim();
  return c ? `${n} <span class="muted" dir="ltr">(${esc(c)})</span>` : n;
}

async function renderGpsChangeApprove(req, res, { flash = '', err = '' } = {}) {
  const status = ['pending', 'approved', 'rejected', 'all'].includes(String(req.query.status || ''))
    ? String(req.query.status)
    : 'pending';
  const focusId = Number(req.query.id || 0) || 0;
  const rows = await masters.listGpsChangeRequests(status, 200);
  const msg = flash || String(req.query.msg || '');
  const error = err || String(req.query.err || '');

  function stLbl(st) {
    if (st === 'pending') return ui.statusPill('wait', 'معلّق');
    if (st === 'approved') return ui.statusPill('ok', 'موافق عليه');
    if (st === 'rejected') return ui.statusPill('lock', 'مرفوض');
    return esc(st);
  }

  const tabs = [
    ['pending', 'معلّق'],
    ['approved', 'موافق عليه'],
    ['rejected', 'مرفوض'],
    ['all', 'الكل'],
  ]
    .map(
      ([k, lab]) =>
        `<a class="si-btn${status === k ? ' si-btn--primary' : ''}" href="/sales-reps/customer-gps-approve?status=${k}">${esc(
          lab
        )}</a>`
    )
    .join(' ');

  const rowsHtml =
    rows
      .map((r) => {
        const id = Number(r.id) || 0;
        const st = String(r.status || '');
        const focus = focusId > 0 && focusId === id;
        const clear = Number(r.clear_gps) === 1;
        const actions =
          st === 'pending'
            ? `<form method="post" action="/sales-reps/customer-gps-approve" style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="id" value="${id}">
                <input type="hidden" name="status" value="${esc(status)}">
                <input class="si-field" type="text" name="note" placeholder="ملاحظة" style="min-width:8rem">
                <button class="si-btn si-btn--primary" type="submit" name="action" value="approve">موافقة</button>
                <button class="si-btn" type="submit" name="action" value="reject">رفض</button>
              </form>`
            : `<span class="muted">${esc(r.decided_by_name || '')}</span>`;
        return `<tr${focus ? ' style="background:#fff7ed"' : ''}>
          <td class="si-num" dir="ltr">${id}</td>
          <td>${esc(r.sales_rep_name || '—')}</td>
          <td>${esc(r.customer_name || '—')}<div class="muted" dir="ltr">${esc(r.customer_code || '')}</div></td>
          <td class="si-num" dir="ltr">${esc(fmtGpsCoord(r.old_latitude, r.old_longitude))}</td>
          <td class="si-num" dir="ltr">${clear ? 'مسح الموقع' : esc(fmtGpsCoord(r.new_latitude, r.new_longitude))}</td>
          <td class="si-num" dir="ltr">${esc(r.created_at || '')}</td>
          <td>${stLbl(st)}</td>
          <td>${actions}</td>
        </tr>`;
      })
      .join('') || ui.emptyRow(8, status === 'pending' ? 'لا توجد طلبات معلّقة.' : 'لا توجد طلبات.');

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'اعتماد تعديل موقع العميل',
        subtitle: 'الحفظ الأول يتم مباشرة. أي تعديل لاحق يحتاج موافقة مدير المبيعات قبل تطبيقه.',
        actions: [
          { label: 'الجولات', href: '/sales-reps/route' },
          { label: 'اعتماد خروج يدوي', href: '/sales-reps/visit-checkout-approve' },
        ],
      })}
      ${msg ? `<p class="si-pill si-pill--ok" style="display:inline-block">${esc(msg)}</p>` : ''}
      ${error ? `<p class="si-pill si-pill--lock" style="display:inline-block">${esc(error)}</p>` : ''}
      <section class="si-surface no-print" style="padding:.85rem 1rem;margin-bottom:.75rem">
        <div class="si-meta" style="align-items:center;gap:.4rem">${tabs}</div>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem">${rows.length} طلب</p>
      </section>
      ${ui.tableSurface(
        'طلبات تعديل موقع العميل',
        `${rows.length} صف`,
        ['#', 'المندوب', 'العميل', 'الموقع الحالي', 'الموقع المطلوب', 'وقت الطلب', 'الحالة', ''],
        rowsHtml
      )}
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'اعتماد موقع العميل',
      bodyHtml: body,
    })
  );
}

router.get('/sales-reps/customer-gps-approve', guard('crm_customer_gps_approve'), (req, res) => {
  return renderGpsChangeApprove(req, res);
});

router.post('/sales-reps/customer-gps-approve', guard('crm_customer_gps_approve'), async (req, res) => {
  const body = req.body || {};
  const result = await masters.decideGpsChangeRequest({
    id: body.id,
    approve: String(body.action || '') === 'approve',
    userId: req.session.user && req.session.user.id,
    note: body.note || null,
  });
  const status = String(body.status || req.query.status || 'pending');
  const q = new URLSearchParams();
  q.set('status', status);
  if (result.ok) q.set('msg', result.message || 'تم');
  else q.set('err', result.message || 'تعذّر التنفيذ');
  res.redirect('/sales-reps/customer-gps-approve?' + q.toString());
});

/* ── تقرير المندوبين حسب العملاء ── */
router.get('/sales-reps/reports/by-customers', async (req, res) => {
  try {
    const adminService = require('../system/adminService');
    if (adminService.ensureReportSalesRepCustomersScreen) {
      await adminService.ensureReportSalesRepCustomersScreen();
    }
  } catch (e) {
    console.error('ensureReportSalesRepCustomersScreen', e.message || e);
  }
  if (!can(req.session.user, 'report_sales_rep_customers') && !can(req.session.user, 'sales_reps')) {
    return res.status(403).send('ممنوع');
  }

  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const showInactive = String(req.query.all || '') === '1';
  const reps = await q.listRepsSimple();
  const selectedRep = salesRepId ? reps.find((r) => Number(r.id) === salesRepId) || (await masters.getRep(salesRepId)) : null;
  const rows = salesRepId
    ? await masters.listRepLinkedCustomers(salesRepId, { limit: 5000, activeOnly: !showInactive })
    : [];

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${esc(
          r.name_ar || ''
        )}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`
    )
    .join('');

  const activeCnt = rows.filter((c) => Number(c.is_active) !== 0).length;
  const inactiveCnt = rows.length - activeCnt;

  const rowsHtml =
    rows
      .map(
        (c, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr"><a href="/customers/${Number(c.id)}">${esc(c.code || '—')}</a></td>
      <td>${esc(c.name_ar || '')}</td>
      <td class="si-num" dir="ltr">${dash(c.phone)}</td>
      <td>${esc(c.region_name || '—')}</td>
      <td>${esc(c.address_name || '—')}</td>
      <td>${ui.statusPill(Number(c.is_active) === 1 ? 'ok' : 'lock', Number(c.is_active) === 1 ? 'نشط' : 'موقوف')}</td>
    </tr>`
      )
      .join('') ||
    ui.emptyRow(
      7,
      salesRepId ? 'لا يوجد عملاء مربوطون بهذا المندوب' : 'اختر المندوب ثم اضغط عرض'
    );

  const body = `
    <div class="si-stage si-report-page">
      ${ui.hero({
        mark: '👥',
        kicker: KICKER,
        title: 'تقرير المندوبين حسب العملاء',
        subtitle: 'اختر المندوب لعرض العملاء المربوطين به',
        actions: [
          ui.printAction(),
          { label: 'المندوبين', href: '/sales-reps/list' },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      <section class="si-surface no-print" style="padding:0.85rem 1rem;margin-bottom:.75rem">
        <form method="get" action="/sales-reps/reports/by-customers" class="si-meta" style="align-items:end">
          <label>المندوب
            <select class="si-field" name="sales_rep_id" required>
              <option value="">— اختر المندوب —</option>
              ${repOpts}
            </select>
          </label>
          <label style="display:flex;align-items:center;gap:.35rem;padding-bottom:.35rem">
            <input type="checkbox" name="all" value="1" ${showInactive ? 'checked' : ''}>
            تضمين الموقوفين
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
          ${ui.siPrintBtnHtml('طباعة')}
        </form>
        ${
          salesRepId
            ? `<p class="muted" style="margin:.65rem 0 0;font-size:.82rem">
                المندوب: <b>${esc(selectedRep?.name_ar || '')}</b>
                ${selectedRep?.code ? ` <span dir="ltr">(${esc(selectedRep.code)})</span>` : ''}
                · الإجمالي <b dir="ltr">${rows.length}</b>
                · نشط <b dir="ltr">${activeCnt}</b>
                ${inactiveCnt ? ` · موقوف <b dir="ltr">${inactiveCnt}</b>` : ''}
              </p>`
            : ''
        }
      </section>
      <div class="si-print-area">
      ${ui.tableSurface(
        salesRepId
          ? `عملاء المندوب: ${esc(selectedRep?.name_ar || '')}`
          : 'عملاء المندوب',
        salesRepId ? `${rows.length} عميل` : '—',
        ['#', 'الرمز', 'اسم العميل', 'الهاتف', 'المنطقة', 'العنوان', 'الحالة'],
        rowsHtml
      )}
      </div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير المندوبين حسب العملاء',
      bodyHtml: body,
      printTitle: 'تقرير المندوبين حسب العملاء',
    })
  );
});

/* ── تقرير الجولات ── */
router.get('/sales-reps/reports/tours', async (req, res) => {
  if (!can(req.session.user, 'report_sales_rep_tours')) {
    return res.status(403).send('ممنوع');
  }

  await masters.ensureTourSchema();
  const from =
    String(req.query.from || '').slice(0, 10) ||
    (() => {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
    })();
  const to = String(req.query.to || '').slice(0, 10) || todayIso();
  const salesRepId = Number(req.query.sales_rep_id || 0) || 0;
  const status = String(req.query.status || '');
  const reps = await q.listRepsSimple();
  const rows = await masters.reportTours({ from, to, salesRepId, status });

  function fmtTs(v) {
    if (!v) return '—';
    const s = String(v);
    if (s.length >= 16) {
      const d = s.slice(0, 10);
      const t = s.slice(11, 19);
      return ui.isoToDmy(d) + ' ' + t;
    }
    return esc(s);
  }
  function methodLabel(v) {
    const m = String(v || '').trim().toUpperCase();
    if (!m) return '—';
    if (m === 'GPS') return 'GPS';
    if (m === 'MANUAL') return 'يدوي';
    return esc(v);
  }
  function statusLbl(st) {
    return String(st) === 'posted'
      ? ui.statusPill('ok', 'مرحّلة')
      : ui.statusPill('wait', 'مسودة');
  }
  function durationLabel(a, b) {
    if (!a || !b) return '—';
    const t1 = Date.parse(String(a).replace(' ', 'T'));
    const t2 = Date.parse(String(b).replace(' ', 'T'));
    if (!Number.isFinite(t1) || !Number.isFinite(t2) || t2 < t1) return '—';
    const mins = Math.floor((t2 - t1) / 60000);
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h}:${String(m).padStart(2, '0')}`;
  }

  const repOpts = reps
    .map(
      (r) =>
        `<option value="${r.id}" ${salesRepId === Number(r.id) ? 'selected' : ''}>${esc(
          r.name_ar || ''
        )}${r.code ? ' (' + esc(r.code) + ')' : ''}</option>`
    )
    .join('');

  // ملخص جولات فريدة + تفصيل العملاء
  const tourIds = new Set();
  const regionSet = new Set();
  const addressSet = new Set();
  for (const r of rows) {
    tourIds.add(Number(r.tour_id));
    if (r.region_name) regionSet.add(String(r.region_name));
    if (r.address_name) addressSet.add(String(r.address_name));
  }

  const rowsHtml =
    rows
      .map(
        (r, i) => `<tr>
      <td class="si-num" dir="ltr">${i + 1}</td>
      <td class="si-num" dir="ltr">
        <a href="/sales-reps/route?id=${Number(r.tour_id)}">${Number(r.tour_id)}</a>
      </td>
      <td class="si-col-rep">${esc(r.sales_rep_name || '—')}</td>
      <td class="si-col-date" dir="ltr">${esc(ui.isoToDmy(r.date_from))} → ${esc(ui.isoToDmy(r.date_to))}</td>
      <td class="si-col-status">${statusLbl(r.status)}</td>
      <td class="si-col-customer">${customerInline(r)}</td>
      <td class="si-col-location">${locationInline(r)}</td>
      ${visitTimingCells(r, durationLabel, methodLabel)}
    </tr>`
      )
      .join('') || ui.emptyRow(12, 'لا جولات في الفترة المحددة');

  const body = `
    <div class="si-stage si-report-page si-report-tours" data-hx-print-landscape="1">
      ${repReportStyle()}
      ${ui.hero({
        mark: '🗺️',
        kicker: KICKER,
        title: 'تقرير الجولات',
        subtitle: 'الجولات المُنشأة: بداية/نهاية · المندوب · المناطق والعناوين — وأوقات الزيارة عند الربط مع الآيباد',
        actions: [
          ui.printAction(),
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits' },
          { label: 'الجولات', href: '/sales-reps/route', primary: true },
          { label: 'لوحة المندوبين', href: HUB },
        ],
      })}
      <section class="si-surface no-print" style="padding:0.85rem 1rem;margin-bottom:.75rem">
        <form method="get" action="/sales-reps/reports/tours" class="si-meta" style="align-items:end">
          <label>من تاريخ
            <input class="si-field si-field--mono" type="date" name="from" value="${esc(from)}" dir="ltr">
          </label>
          <label>إلى تاريخ
            <input class="si-field si-field--mono" type="date" name="to" value="${esc(to)}" dir="ltr">
          </label>
          <label>المندوب
            <select class="si-field" name="sales_rep_id">
              <option value="0">— الكل —</option>
              ${repOpts}
            </select>
          </label>
          <label>الحالة
            <select class="si-field" name="status">
              <option value="" ${status === '' ? 'selected' : ''}>— الكل —</option>
              <option value="draft" ${status === 'draft' ? 'selected' : ''}>مسودة</option>
              <option value="posted" ${status === 'posted' ? 'selected' : ''}>مرحّلة</option>
            </select>
          </label>
          <button class="si-btn si-btn--primary" type="submit">عرض</button>
          ${ui.siPrintBtnHtml('طباعة')}
        </form>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem;line-height:1.45">
          ${tourIds.size} جولة · ${rows.length} عميل في الخطط
          ${regionSet.size ? ` · ${regionSet.size} منطقة` : ''}
          ${addressSet.size ? ` · ${addressSet.size} عنوان` : ''}.
          أعمدة <b>وقت الدخول · وقت الخروج · المدة · النوع</b> تظهر
          <b>GPS</b> أو <b>يدوي</b> لاحقاً عندما يسجّل المندوب الدخول/الخروج من الآيباد.
        </p>
      </section>
      <div class="si-print-area si-report-page si-report-tours" data-hx-print-landscape="1">
      ${ui.tableSurface(
        'تفاصيل الجولات والعملاء',
        `عدد السجلات: ${rows.length}`,
        [
          '#',
          'الجولة',
          'المندوب',
          'الفترة',
          'الحالة',
          'العميل',
          'الموقع',
          'وقت الدخول',
          'وقت الخروج',
          'مجموع الساعات',
          'نوع الدخول',
          'نوع الخروج',
        ],
        rowsHtml
      )}
      </div>
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير الجولات',
      bodyHtml: body,
      css: REP_REPORT_CSS,
      printTitle: 'تقرير الجولات',
    })
  );
});

/* ── تقرير زيارات العملاء ── */
router.get('/sales-reps/reports/visits/data', async (req, res) => {
  if (!can(req.session.user, 'report_sales_rep_visits')) {
    return res.status(403).json({ ok: false, error: 'forbidden' });
  }
  await masters.ensureTourSchema();
  const today = todayIso();
  const from = parseDateToIso(req.query.from || '', today);
  const to = parseDateToIso(req.query.to || '', today);
  const salesRepIds = parseSalesRepIdsQuery(req.query);
  const customerIds = parseCustomerIdsQuery(req.query);
  const method = String(req.query.method || '').toUpperCase();
  const status = String(req.query.status || '');
  const rows = await masters.reportVisits({ from, to, salesRepIds, customerIds, method, status });
  res.json({ ok: true, from, to, rows, totals: visitTotals(rows) });
});

router.post('/sales-reps/reports/visits/delete', async (req, res) => {
  if (!can(req.session.user, 'report_sales_rep_visits')) {
    return res.status(403).json({ ok: false, message: 'ممنوع' });
  }
  if (!can(req.session.user, 'action_delete_sales_rep_visit')) {
    return res.status(403).json({ ok: false, message: 'لا تملك صلاحية حذف الزيارات.' });
  }
  const lineIds = Array.isArray(req.body?.line_ids) ? req.body.line_ids : [];
  const result = await masters.deleteVisitLines(lineIds, req.session.user && req.session.user.id);
  res.json(result);
});

router.get('/sales-reps/reports/visits', async (req, res) => {
  if (!can(req.session.user, 'report_sales_rep_visits')) {
    return res.status(403).send('ممنوع');
  }

  await masters.ensureTourSchema();
  const today = todayIso();
  const from = parseDateToIso(req.query.from || '', today);
  const to = parseDateToIso(req.query.to || '', today);
  const salesRepIds = parseSalesRepIdsQuery(req.query);
  const salesRepId = salesRepIds.length === 1 ? salesRepIds[0] : 0;
  const customerIds = parseCustomerIdsQuery(req.query);
  const customerId = customerIds.length === 1 ? customerIds[0] : 0;
  const method = String(req.query.method || '').toUpperCase();
  const status = String(req.query.status || '');
  const reps = await q.listRepsSimple();
  const customers = await masters.listVisitReportCustomers({ from, to });
  /* زيارات بدون فلتر عميل — مصدر أسماء قائمة الاختيار */
  const rowsForPicker = await masters.reportVisits({
    from,
    to,
    salesRepIds,
    method,
    status,
    limit: 1500,
  });
  const rows =
    customerIds.length > 0
      ? await masters.reportVisits({ from, to, salesRepIds, customerIds, method, status })
      : rowsForPicker;

  function fmtTs(v) {
    if (!v) return '—';
    const s = String(v);
    if (s.length >= 16) {
      return ui.isoToDmy(s.slice(0, 10)) + ' ' + s.slice(11, 16);
    }
    return esc(s);
  }
  function methodLabel(v) {
    const m = String(v || '').trim().toUpperCase();
    if (!m) return '—';
    if (m === 'GPS') return 'GPS';
    if (m === 'MANUAL') return 'يدوي';
    return esc(v);
  }
  function weekdayAr(iso) {
    const s = String(iso || '').slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return '';
    const d = new Date(s + 'T12:00:00');
    if (Number.isNaN(d.getTime())) return '';
    return ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'][d.getDay()] || '';
  }
  function dateWithWeekday(iso) {
    const dmy = ui.isoToDmy(iso);
    const day = weekdayAr(iso);
    return day ? `${day}\u00A0${dmy}` : dmy;
  }
  function durationLabel(a, b) {
    if (!a || !b) return '—';
    const t1 = Date.parse(String(a).replace(' ', 'T'));
    const t2 = Date.parse(String(b).replace(' ', 'T'));
    if (!Number.isFinite(t1) || !Number.isFinite(t2) || t2 < t1) return '—';
    const mins = Math.floor((t2 - t1) / 60000);
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h}:${String(m).padStart(2, '0')}`;
  }
  function statusLbl(r) {
    if (r.pending_request_id && !r.visit_checkout_at) return ui.statusPill('wait', 'بانتظار موافقة');
    if (r.visit_checkout_at) return ui.statusPill('ok', 'مكتملة');
    return ui.statusPill('info', 'داخل الزيارة');
  }

  const selectedRepSet = new Set(salesRepIds);
  const selectedRepCount = salesRepIds.length;
  const repToggleLabel =
    selectedRepCount < 1
      ? '— الكل —'
      : selectedRepCount === 1
        ? (() => {
            const r = reps.find((x) => Number(x.id) === salesRepIds[0]);
            return r ? String(r.name_ar || `#${salesRepIds[0]}`) : 'مندوب واحد';
          })()
        : `${selectedRepCount} مندوبون محددون`;
  const repsJson = JSON.stringify(
    (reps || []).map((r) => ({
      id: Number(r.id) || 0,
      name: String(r.name_ar || ''),
      code: String(r.code || ''),
    }))
  ).replace(/</g, '\\u003c');
  const repCheckHtml = (reps || [])
    .map((r) => {
      const id = Number(r.id) || 0;
      const label = String(r.name_ar || `#${id}`);
      const checked = selectedRepSet.has(id) ? ' checked' : '';
      return `<label class="hx-cust-check-item">
        <input type="checkbox" class="hx-rep-one" value="${id}"${checked}>
        <span>${esc(label)}</span>
      </label>`;
    })
    .join('');

  /* عملاء قائمة الاختيار = كل عملاء زيارات الفترة (بدون فلتر العميل) */
  const customerMap = new Map();
  const selectedSet = new Set(customerIds);
  function pushCustomer(id, name, code) {
    const cid = Number(id) || Number(id && id.id) || 0;
    if (!cid || customerMap.has(cid)) return;
    customerMap.set(cid, {
      id: cid,
      name: String(name || '').trim(),
      code: String(code || '').trim(),
    });
  }
  for (const c of customers) {
    pushCustomer(
      c.id || c.ID,
      c.name_ar || c.name || c.NAME_AR || '',
      c.code || c.CODE || ''
    );
  }
  for (const r of rowsForPicker) {
    pushCustomer(
      r.customer_id || r.CUSTOMER_ID,
      r.customer_name || r.CUSTOMER_NAME || '',
      r.customer_code || r.CUSTOMER_CODE || ''
    );
  }
  const customerChoices = [...customerMap.values()].sort((a, b) =>
    String(a.name).localeCompare(String(b.name), 'ar')
  );
  const selectedCount = customerIds.length;
  const customerToggleLabel =
    selectedCount < 1
      ? '— الكل —'
      : selectedCount === 1
        ? (() => {
            const c = customerMap.get(customerIds[0]);
            return c
              ? `${c.code ? `${c.code} — ` : ''}${c.name || `#${c.id}`}`
              : `عميل واحد`;
          })()
        : `${selectedCount} عملاء محددون`;
  const customersJson = JSON.stringify(customerChoices).replace(/</g, '\\u003c');
  const custCheckHtml = customerChoices
    .map((c) => {
      const label = `${c.code ? `${c.code} — ` : ''}${c.name || `#${c.id}`}`;
      const checked = selectedSet.has(c.id) ? ' checked' : '';
      return `<label class="hx-cust-check-item">
        <input type="checkbox" class="hx-cust-one" value="${c.id}"${checked}>
        <span>${esc(label)}</span>
      </label>`;
    })
    .join('');

  const uniqueRepIds = [...new Set(rows.map((r) => Number(r.sales_rep_id || 0)).filter((id) => id > 0))];
  const groupByRep = selectedRepCount !== 1 && uniqueRepIds.length > 1;
  const canDeleteVisits = can(req.session.user, 'action_delete_sales_rep_visit');
  const baseColCount = groupByRep ? 13 : 14;
  const colCount = baseColCount + (canDeleteVisits ? 1 : 0);
  const grandTotals = visitTotals(rows);

  function scopeLbl(r) {
    return Number(r.in_plan ?? 1) === 1
      ? ui.statusPill('ok', 'داخل الجولة')
      : ui.statusPill('wait', 'خارج الجولة');
  }

  function visitSelectCell(r) {
    if (!canDeleteVisits) return '';
    const lineId = Number(r.line_id || 0);
    const hasOrder = Number(r.order_count || 0) > 0;
    const disabled = hasOrder ? ' disabled title="مرتبطة بطلب شراء"' : '';
    return `<td class="no-print si-col-select"><input type="checkbox" class="hx-visit-pick" value="${lineId}"${disabled}></td>`;
  }

  function orderNoCell(r) {
    const nos = String(r.order_numbers || '').trim();
    if (!nos) return '<td class="si-col-order">—</td>';
    const oid = Number(r.first_order_id || 0);
    if (oid > 0) {
      return `<td class="si-col-order" dir="ltr"><a class="si-link" href="/sales/orders/${oid}">${esc(nos)}</a></td>`;
    }
    return `<td class="si-col-order" dir="ltr">${esc(nos)}</td>`;
  }

  function visitDataCells(r, seq, includeRep) {
    const reason = esc(r.no_order_reasons || '—');
    const custIdAttr = Number(r.customer_id || r.CUSTOMER_ID || 0) || 0;
    const custNameAttr = esc(r.customer_name || r.CUSTOMER_NAME || '');
    const custCodeAttr = esc(r.customer_code || r.CUSTOMER_CODE || '');
    return `<td class="si-num" dir="ltr">${seq}</td>
      <td class="si-col-date">${esc(dateWithWeekday(r.route_date))}</td>
      ${includeRep ? `<td class="si-col-rep">${esc(r.sales_rep_name || '—')}</td>` : ''}
      <td class="si-col-customer" data-customer-id="${custIdAttr}" data-customer-name="${custNameAttr}" data-customer-code="${custCodeAttr}">${customerNameOnly(r)}</td>
      <td class="si-col-scope">${scopeLbl(r)}</td>
      ${orderNoCell(r)}
      <td class="si-col-reason" title="${reason}">${reason}</td>
      <td class="si-col-location">${locationInline(r)}</td>
      ${visitTimingCells(r, durationLabel, methodLabel)}
      <td class="si-col-sales" dir="ltr">${visitMoney(r.order_total)}</td>`;
  }

  function totalsRow(label, t) {
    // آخر 4 أعمدة ظاهرة: المدة · نوع الدخول · نوع الخروج · المبيعات
    const dataCols = colCount - (canDeleteVisits ? 1 : 0);
    const labelSpan = Math.max(1, dataCols - 4);
    const selectPad = canDeleteVisits
      ? '<td class="no-print si-col-select"></td>'
      : '';
    return `<tr class="si-visits-totals-row">
      ${selectPad}
      <td colspan="${labelSpan}" style="text-align:start"><strong>${esc(label)}</strong></td>
      <td class="si-col-duration"><strong>${esc(t.duration_label)}</strong></td>
      <td class="si-col-method"></td>
      <td class="si-col-checkout-method"></td>
      <td class="si-col-sales" dir="ltr"><strong>${visitMoney(t.sales_total)}</strong></td>
    </tr>`;
  }

  let rowsHtml = '';
  if (!rows.length) {
    rowsHtml = ui.emptyRow(colCount, 'لا تسجيلات زيارة في الفترة المحددة');
  } else if (groupByRep) {
    const groups = new Map();
    for (const r of rows) {
      const id = Number(r.sales_rep_id || 0);
      const key = id > 0 ? String(id) : '0';
      if (!groups.has(key)) {
        groups.set(key, {
          name: r.sales_rep_name || 'مندوب',
          rows: [],
        });
      }
      groups.get(key).rows.push(r);
    }
    let seq = 0;
    for (const g of groups.values()) {
      rowsHtml += `<tr class="si-rep-group-row"><td colspan="${colCount}"><strong>المندوب: ${esc(
        g.name
      )}</strong> <span class="muted">(${g.rows.length} زيارة)</span></td></tr>`;
      for (const r of g.rows) {
        seq += 1;
        rowsHtml += `<tr class="${visitRowClass(r)}">${visitSelectCell(r)}${visitDataCells(r, seq, false)}</tr>`;
      }
      rowsHtml += totalsRow('مجموع المندوب', visitTotals(g.rows));
    }
    rowsHtml += totalsRow('الإجمالي النهائي', grandTotals);
  } else {
    rowsHtml = rows
      .map((r, i) => `<tr class="${visitRowClass(r)}">${visitSelectCell(r)}${visitDataCells(r, i + 1, true)}</tr>`)
      .join('');
    rowsHtml += totalsRow('الإجمالي', grandTotals);
  }

  // رقم الطلب قبل سبب عدم الطلب — الأعمدة الزمنية ثم المدة ثم الأنواع ثم المبيعات
  const visitHeaders = groupByRep
    ? [
        '#',
        'التاريخ',
        'العميل',
        'النطاق',
        'رقم الطلب',
        'سبب عدم الطلب',
        'الموقع',
        'وقت الدخول',
        'وقت الخروج',
        'الوقت الفعال',
        'نوع الدخول',
        'نوع الخروج',
        'المبيعات',
      ]
    : [
        '#',
        'التاريخ',
        'المندوب',
        'العميل',
        'النطاق',
        'رقم الطلب',
        'سبب عدم الطلب',
        'الموقع',
        'وقت الدخول',
        'وقت الخروج',
        'الوقت الفعال',
        'نوع الدخول',
        'نوع الخروج',
        'المبيعات',
      ];
  if (canDeleteVisits) {
    visitHeaders.unshift({ label: '', className: 'no-print si-col-select' });
  }

  const deleteToolbar = canDeleteVisits
    ? `<div class="no-print" style="display:flex;gap:.5rem;align-items:center;margin:.65rem 0 0;flex-wrap:wrap">
        <button type="button" class="si-btn si-btn--danger" id="hx-visits-delete-btn" disabled>حذف المحدد</button>
        <span class="muted" style="font-size:.82rem">يمكن حذف الزيارات غير المربوطة بطلب شراء فقط</span>
      </div>`
    : '';

  const livePoll = from <= todayIso() && to >= todayIso();

  const body = `
    <div class="si-stage si-report-page si-report-visits" data-hx-print-landscape="1">
      ${repReportStyle()}
      ${ui.hero({
        mark: '📍',
        kicker: KICKER,
        title: 'تقرير زيارات العملاء',
        subtitle: 'تفاصيل دخول/خروج المندوب: وقت الدخول · وقت الخروج · المدة · نوع الدخول/الخروج',
        actions: [
          ui.printAction(),
          { label: 'تقرير الجولات', href: '/sales-reps/reports/tours' },
          { label: 'اعتماد خروج يدوي', href: '/sales-reps/visit-checkout-approve' },
          { label: 'لوحة المندوبين', href: HUB, primary: true },
        ],
      })}
      <section class="si-surface no-print" style="padding:0.85rem 1rem;margin-bottom:.75rem">
        <form method="get" action="/sales-reps/reports/visits" class="si-meta hx-visits-filters">
          <label class="hx-visits-f hx-visits-f--from">
            <span class="hx-visits-f-label">من تاريخ <span class="hx-date-weekday" id="si-weekday-from">${esc(weekdayAr(from))}</span></span>
            <span class="hx-date-field">
              <input class="si-field si-field--mono" type="date" name="from" id="si-visits-from" value="${esc(from)}" dir="ltr">
            </span>
          </label>
          <label class="hx-visits-f hx-visits-f--to">
            <span class="hx-visits-f-label">إلى تاريخ <span class="hx-date-weekday" id="si-weekday-to">${esc(weekdayAr(to))}</span></span>
            <span class="hx-date-field">
              <input class="si-field si-field--mono" type="date" name="to" id="si-visits-to" value="${esc(to)}" dir="ltr">
            </span>
          </label>
          <label class="hx-visits-f hx-visits-f--method">
            <span class="hx-visits-f-label">النوع</span>
            <select class="si-field" name="method">
              <option value="" ${method === '' ? 'selected' : ''}>— الكل —</option>
              <option value="GPS" ${method === 'GPS' ? 'selected' : ''}>GPS</option>
              <option value="MANUAL" ${method === 'MANUAL' ? 'selected' : ''}>يدوي</option>
            </select>
          </label>
          <div class="hx-visits-f hx-visits-f--rep">
            <span class="hx-visits-f-label">المندوب</span>
            <div class="hx-smart-cust" id="hx-visits-smart-rep">
              <div id="hx-visits-rep-hidden">
                ${
                  selectedRepCount
                    ? salesRepIds
                        .map((id) => `<input type="hidden" name="sales_rep_id" value="${id}">`)
                        .join('')
                    : ''
                }
              </div>
              <button type="button" class="si-field hx-smart-cust-toggle" id="hx-visits-rep-toggle"
                aria-haspopup="listbox" aria-expanded="false" aria-controls="hx-visits-rep-panel">
                <span class="hx-smart-cust-toggle-text" id="hx-visits-rep-label">${esc(repToggleLabel)}</span>
                <span class="hx-smart-cust-caret" aria-hidden="true"></span>
              </button>
              <div class="hx-smart-cust-panel" id="hx-visits-rep-panel" hidden>
                <input class="si-field hx-smart-cust-search" type="search" id="hx-visits-rep-q"
                  placeholder="ابحث عن مندوب…" autocomplete="off">
                <label class="hx-cust-check-item hx-cust-check-item--all">
                  <input type="checkbox" id="hx-rep-all" ${selectedRepCount < 1 ? 'checked' : ''}>
                  <span>تحديد الكل</span>
                </label>
                <div class="hx-smart-cust-list" id="hx-visits-rep-list" role="group">
                  ${repCheckHtml || '<div class="hx-smart-cust-empty">لا يوجد مندوبون.</div>'}
                </div>
              </div>
              <script type="application/json" id="hx-visits-reps-json">${repsJson}</script>
            </div>
          </div>
          <div class="hx-visits-f hx-visits-f--customer">
            <span class="hx-visits-f-label">العميل</span>
            <div class="hx-smart-cust" id="hx-visits-smart-cust">
              <div id="hx-visits-customer-hidden">
                ${
                  selectedCount
                    ? customerIds
                        .map((id) => `<input type="hidden" name="customer_id" value="${id}">`)
                        .join('')
                    : ''
                }
              </div>
              <button type="button" class="si-field hx-smart-cust-toggle" id="hx-visits-customer-toggle"
                aria-haspopup="listbox" aria-expanded="false" aria-controls="hx-visits-cust-panel">
                <span class="hx-smart-cust-toggle-text" id="hx-visits-customer-label">${esc(customerToggleLabel)}</span>
                <span class="hx-smart-cust-caret" aria-hidden="true"></span>
              </button>
              <div class="hx-smart-cust-panel" id="hx-visits-cust-panel" hidden>
                <input class="si-field hx-smart-cust-search" type="search" id="hx-visits-customer-q"
                  placeholder="ابحث بالاسم أو الرمز…" autocomplete="off">
                <label class="hx-cust-check-item hx-cust-check-item--all">
                  <input type="checkbox" id="hx-cust-all" ${selectedCount < 1 ? 'checked' : ''}>
                  <span>تحديد الكل</span>
                </label>
                <div class="hx-smart-cust-list" id="hx-visits-cust-list" role="group">
                  ${custCheckHtml || '<div class="hx-smart-cust-empty">لا يوجد عملاء في الفترة.</div>'}
                </div>
              </div>
              <script type="application/json" id="hx-visits-customers-json">${customersJson}</script>
            </div>
          </div>
          <label class="hx-visits-f hx-visits-f--status">
            <span class="hx-visits-f-label">الحالة</span>
            <select class="si-field" name="status">
              <option value="" ${status === '' ? 'selected' : ''}>— الكل —</option>
              <option value="open" ${status === 'open' ? 'selected' : ''}>داخل الزيارة</option>
              <option value="closed" ${status === 'closed' ? 'selected' : ''}>مكتملة</option>
              <option value="pending" ${status === 'pending' ? 'selected' : ''}>بانتظار موافقة</option>
            </select>
          </label>
          <div class="hx-visits-f-actions">
            <button class="si-btn si-btn--primary" type="submit">عرض</button>
            ${ui.siPrintBtnHtml('طباعة')}
          </div>
        </form>
        <script>
        (function(){
          var days = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
          function toIso(v){
            v = String(v||'').trim();
            if (/^\\d{4}-\\d{2}-\\d{2}$/.test(v)) return v;
            var m = v.match(/^(\\d{1,2})[\\/.\\-](\\d{1,2})[\\/.\\-](\\d{4})$/);
            if (!m) return '';
            var d = Number(m[1]), mo = Number(m[2]), y = Number(m[3]);
            if (d < 1 || d > 31 || mo < 1 || mo > 12) return '';
            return y + '-' + String(mo).padStart(2,'0') + '-' + String(d).padStart(2,'0');
          }
          function weekday(v){
            var iso = toIso(v);
            if (!iso) return '';
            var d = new Date(iso + 'T12:00:00');
            return Number.isNaN(d.getTime()) ? '' : (days[d.getDay()]||'');
          }
          function bind(id, outId){
            var el = document.getElementById(id);
            var out = document.getElementById(outId);
            if (!el || !out) return;
            var sync = function(){ out.textContent = weekday(el.value); };
            el.addEventListener('change', sync);
            el.addEventListener('input', sync);
          }
          bind('si-visits-from','si-weekday-from');
          bind('si-visits-to','si-weekday-to');

          /* قائمة عميل متعددة الاختيار (checkboxes) */
          var custToggle = document.getElementById('hx-visits-customer-toggle');
          var custLabelEl = document.getElementById('hx-visits-customer-label');
          var custPanel = document.getElementById('hx-visits-cust-panel');
          var custList = document.getElementById('hx-visits-cust-list');
          var custAllCb = document.getElementById('hx-cust-all');
          var custHidden = document.getElementById('hx-visits-customer-hidden');
          var custSearch = document.getElementById('hx-visits-customer-q');
          var custSearchTimer = null;
          var custFetchToken = 0;
          var custAll = [];
          try {
            var raw = document.getElementById('hx-visits-customers-json');
            custAll = raw ? JSON.parse(raw.textContent || '[]') : [];
          } catch (e) { custAll = []; }
          if (!Array.isArray(custAll)) custAll = [];
          function custNameById(id){
            for (var i = 0; i < custAll.length; i++){
              if (Number(custAll[i].id) === Number(id)){
                var c = custAll[i];
                return (c.code ? (c.code + ' — ') : '') + (c.name || ('#' + c.id));
              }
            }
            return 'عميل';
          }
          function isOpen(){ return custPanel && !custPanel.hidden; }
          function filterSurface(){
            var wrap = document.getElementById('hx-visits-smart-cust') || document.getElementById('hx-visits-smart-rep');
            return wrap ? wrap.closest('.si-surface') : null;
          }
          function syncSurfaceOpenClass(){
            var surf = filterSurface();
            if (!surf) return;
            var anyOpen = (custPanel && !custPanel.hidden) || (repPanel && !repPanel.hidden);
            if (anyOpen) surf.classList.add('is-cust-open');
            else surf.classList.remove('is-cust-open');
          }
          function filterCheckItems(listEl, needle, oneClass){
            if (!listEl) return;
            var q = String(needle || '').trim().toLowerCase();
            var visible = 0;
            Array.prototype.forEach.call(listEl.querySelectorAll('.hx-cust-check-item'), function(lab){
              if (lab.classList.contains('hx-cust-check-item--all')) return;
              var inp = lab.querySelector('.' + oneClass);
              if (!inp) return;
              var text = (lab.textContent || '').toLowerCase();
              var show = !q || text.indexOf(q) >= 0 || String(inp.value).indexOf(q) >= 0;
              if (show){ lab.classList.remove('is-hidden'); visible++; }
              else lab.classList.add('is-hidden');
            });
            var empty = listEl.querySelector('.hx-smart-cust-empty');
            if (!visible && q){
              if (!empty){
                empty = document.createElement('div');
                empty.className = 'hx-smart-cust-empty';
                listEl.appendChild(empty);
              }
              empty.textContent = 'لا توجد نتائج مطابقة.';
              empty.hidden = false;
              empty.removeAttribute('hidden');
            } else if (empty){
              empty.hidden = true;
              empty.setAttribute('hidden','');
            }
          }
          function ensureCustCheck(c){
            if (!custList || !c || !c.id) return;
            var id = String(c.id);
            if (custList.querySelector('.hx-cust-one[value="' + id + '"]')) return;
            var label = (c.code ? (c.code + ' — ') : '') + (c.name || ('#' + c.id));
            var lab = document.createElement('label');
            lab.className = 'hx-cust-check-item';
            lab.innerHTML = '<input type="checkbox" class="hx-cust-one" value="' + id + '"><span></span>';
            lab.querySelector('span').textContent = label;
            custList.appendChild(lab);
            custAll.push({ id: Number(c.id), name: c.name || '', code: c.code || '' });
          }
          function applyCustSearch(q){
            filterCheckItems(custList, q, 'hx-cust-one');
            var needle = String(q || '').trim();
            if (needle.length < 2) return;
            var token = ++custFetchToken;
            fetch('/api/lookup/customers?q=' + encodeURIComponent(needle), { credentials: 'same-origin' })
              .then(function(r){ return r.json(); })
              .then(function(data){
                if (token !== custFetchToken) return;
                if (!data || !data.ok) return;
                (data.rows || []).forEach(function(c){
                  ensureCustCheck({
                    id: Number(c.id) || 0,
                    name: String(c.name_ar || c.name || ''),
                    code: String(c.code || '')
                  });
                });
                filterCheckItems(custList, needle, 'hx-cust-one');
              })
              .catch(function(){});
          }
          function closePanel(){
            if (!custPanel) return;
            custPanel.hidden = true;
            custPanel.setAttribute('hidden','');
            if (custToggle) custToggle.setAttribute('aria-expanded','false');
            if (custSearch) custSearch.value = '';
            filterCheckItems(custList, '', 'hx-cust-one');
            syncSurfaceOpenClass();
          }
          function openPanel(){
            if (!custPanel) return;
            closeRepPanel();
            ensureChecksFromTable();
            custPanel.hidden = false;
            custPanel.removeAttribute('hidden');
            if (custToggle) custToggle.setAttribute('aria-expanded','true');
            syncSurfaceOpenClass();
            if (custSearch) setTimeout(function(){ custSearch.focus(); custSearch.select(); }, 0);
          }
          function ensureChecksFromTable(){
            if (!custList) return;
            var existing = {};
            Array.prototype.forEach.call(custList.querySelectorAll('.hx-cust-one'), function(el){
              existing[String(el.value)] = true;
            });
            Array.prototype.forEach.call(document.querySelectorAll('.si-col-customer[data-customer-id]'), function(td){
              var id = Number(td.getAttribute('data-customer-id') || 0) || 0;
              if (!id || existing[String(id)]) return;
              existing[String(id)] = true;
              var name = td.getAttribute('data-customer-name') || (td.textContent || '').trim();
              var code = td.getAttribute('data-customer-code') || '';
              var label = (code ? (code + ' — ') : '') + name;
              var lab = document.createElement('label');
              lab.className = 'hx-cust-check-item';
              lab.innerHTML = '<input type="checkbox" class="hx-cust-one" value="' + id + '"><span></span>';
              lab.querySelector('span').textContent = label || ('#' + id);
              custList.appendChild(lab);
              custAll.push({ id: id, name: name, code: code });
            });
            var empty = custList.querySelector('.hx-smart-cust-empty');
            if (empty && custList.querySelector('.hx-cust-one')) empty.remove();
          }
          function selectedOnes(){
            if (!custList) return [];
            return Array.prototype.slice.call(custList.querySelectorAll('.hx-cust-one:checked'))
              .map(function(el){ return Number(el.value)||0; })
              .filter(function(id){ return id > 0; });
          }
          function syncHiddenAndLabel(){
            var ids = selectedOnes();
            var allOn = !!(custAllCb && custAllCb.checked) || ids.length < 1;
            if (custAllCb && ids.length < 1) custAllCb.checked = true;
            if (custAllCb && ids.length > 0) custAllCb.checked = false;
            allOn = !!(custAllCb && custAllCb.checked);
            if (custHidden){
              custHidden.innerHTML = '';
              if (!allOn){
                ids.forEach(function(id){
                  var inp = document.createElement('input');
                  inp.type = 'hidden';
                  inp.name = 'customer_id';
                  inp.value = String(id);
                  custHidden.appendChild(inp);
                });
              }
            }
            if (custLabelEl){
              if (allOn || ids.length < 1) custLabelEl.textContent = '— الكل —';
              else if (ids.length === 1) custLabelEl.textContent = custNameById(ids[0]);
              else custLabelEl.textContent = ids.length + ' عملاء محددون';
            }
          }
          if (custToggle && custPanel){
            custToggle.addEventListener('click', function(e){
              e.preventDefault();
              if (isOpen()) closePanel();
              else openPanel();
            });
            if (custAllCb){
              custAllCb.addEventListener('change', function(){
                if (custAllCb.checked && custList){
                  Array.prototype.forEach.call(custList.querySelectorAll('.hx-cust-one'), function(el){
                    el.checked = false;
                  });
                }
                syncHiddenAndLabel();
              });
            }
            if (custList){
              custList.addEventListener('change', function(e){
                if (!e.target || !e.target.classList || !e.target.classList.contains('hx-cust-one')) return;
                if (e.target.checked && custAllCb) custAllCb.checked = false;
                syncHiddenAndLabel();
              });
            }
            if (custSearch){
              custSearch.addEventListener('input', function(){
                clearTimeout(custSearchTimer);
                var v = custSearch.value;
                custSearchTimer = setTimeout(function(){ applyCustSearch(v); }, 140);
              });
              custSearch.addEventListener('keydown', function(e){
                if (e.key === 'Escape'){
                  e.preventDefault();
                  closePanel();
                  if (custToggle) custToggle.focus();
                }
              });
            }
            syncHiddenAndLabel();
          }

          /* قائمة مندوب متعددة الاختيار (checkboxes) */
          var repToggle = document.getElementById('hx-visits-rep-toggle');
          var repLabelEl = document.getElementById('hx-visits-rep-label');
          var repPanel = document.getElementById('hx-visits-rep-panel');
          var repList = document.getElementById('hx-visits-rep-list');
          var repAllCb = document.getElementById('hx-rep-all');
          var repHidden = document.getElementById('hx-visits-rep-hidden');
          var repSearch = document.getElementById('hx-visits-rep-q');
          var repSearchTimer = null;
          var repAll = [];
          try {
            var rawRep = document.getElementById('hx-visits-reps-json');
            repAll = rawRep ? JSON.parse(rawRep.textContent || '[]') : [];
          } catch (e2) { repAll = []; }
          if (!Array.isArray(repAll)) repAll = [];
          function repNameById(id){
            for (var i = 0; i < repAll.length; i++){
              if (Number(repAll[i].id) === Number(id)) return repAll[i].name || ('#' + id);
            }
            return 'مندوب';
          }
          function isRepOpen(){ return repPanel && !repPanel.hidden; }
          function closeRepPanel(){
            if (!repPanel) return;
            repPanel.hidden = true;
            repPanel.setAttribute('hidden','');
            if (repToggle) repToggle.setAttribute('aria-expanded','false');
            if (repSearch) repSearch.value = '';
            filterCheckItems(repList, '', 'hx-rep-one');
            syncSurfaceOpenClass();
          }
          function openRepPanel(){
            if (!repPanel) return;
            closePanel();
            repPanel.hidden = false;
            repPanel.removeAttribute('hidden');
            if (repToggle) repToggle.setAttribute('aria-expanded','true');
            syncSurfaceOpenClass();
            if (repSearch) setTimeout(function(){ repSearch.focus(); repSearch.select(); }, 0);
          }
          function selectedReps(){
            if (!repList) return [];
            return Array.prototype.slice.call(repList.querySelectorAll('.hx-rep-one:checked'))
              .map(function(el){ return Number(el.value)||0; })
              .filter(function(id){ return id > 0; });
          }
          function syncRepHiddenAndLabel(){
            var ids = selectedReps();
            if (repAllCb && ids.length < 1) repAllCb.checked = true;
            if (repAllCb && ids.length > 0) repAllCb.checked = false;
            var allOn = !!(repAllCb && repAllCb.checked);
            if (repHidden){
              repHidden.innerHTML = '';
              if (!allOn){
                ids.forEach(function(id){
                  var inp = document.createElement('input');
                  inp.type = 'hidden';
                  inp.name = 'sales_rep_id';
                  inp.value = String(id);
                  repHidden.appendChild(inp);
                });
              }
            }
            if (repLabelEl){
              if (allOn || ids.length < 1) repLabelEl.textContent = '— الكل —';
              else if (ids.length === 1) repLabelEl.textContent = repNameById(ids[0]);
              else repLabelEl.textContent = ids.length + ' مندوبون محددون';
            }
          }
          if (repToggle && repPanel){
            repToggle.addEventListener('click', function(e){
              e.preventDefault();
              if (isRepOpen()) closeRepPanel();
              else openRepPanel();
            });
            if (repAllCb){
              repAllCb.addEventListener('change', function(){
                if (repAllCb.checked && repList){
                  Array.prototype.forEach.call(repList.querySelectorAll('.hx-rep-one'), function(el){
                    el.checked = false;
                  });
                }
                syncRepHiddenAndLabel();
              });
            }
            if (repList){
              repList.addEventListener('change', function(e){
                if (!e.target || !e.target.classList || !e.target.classList.contains('hx-rep-one')) return;
                if (e.target.checked && repAllCb) repAllCb.checked = false;
                syncRepHiddenAndLabel();
              });
            }
            if (repSearch){
              repSearch.addEventListener('input', function(){
                clearTimeout(repSearchTimer);
                var v = repSearch.value;
                repSearchTimer = setTimeout(function(){ filterCheckItems(repList, v, 'hx-rep-one'); }, 120);
              });
              repSearch.addEventListener('keydown', function(e){
                if (e.key === 'Escape'){
                  e.preventDefault();
                  closeRepPanel();
                  if (repToggle) repToggle.focus();
                }
              });
            }
            syncRepHiddenAndLabel();
          }

          document.addEventListener('click', function(e){
            var custWrap = document.getElementById('hx-visits-smart-cust');
            var repWrap = document.getElementById('hx-visits-smart-rep');
            if (custWrap && !custWrap.contains(e.target)) closePanel();
            if (repWrap && !repWrap.contains(e.target)) closeRepPanel();
          });
        })();
        </script>
        <p class="muted" style="margin:.65rem 0 0;font-size:.82rem;line-height:1.45">
          ${rows.length} زيارة مسجّلة من تطبيق الهاتف في الفترة المحددة${
            groupByRep ? ' · مجمّعة حسب المندوب' : ''
          }.
        </p>
        ${deleteToolbar}
      </section>
      <div class="si-print-area si-report-page si-report-visits" data-hx-print-landscape="1">
      ${ui.tableSurface(
        'تفاصيل الزيارات',
        `عدد الزيارات: ${rows.length}`,
        visitHeaders,
        rowsHtml
      )}
      </div>
      ${
        canDeleteVisits
          ? `<script>
(function(){
  function picked(){
    return Array.prototype.slice.call(document.querySelectorAll('.hx-visit-pick:checked:not(:disabled)'))
      .map(function(el){ return Number(el.value)||0; })
      .filter(function(id){ return id > 0; });
  }
  function syncBtn(){
    var btn = document.getElementById('hx-visits-delete-btn');
    if (!btn) return;
    btn.disabled = picked().length < 1;
  }
  document.addEventListener('change', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('hx-visit-pick')) syncBtn();
  });
  var btn = document.getElementById('hx-visits-delete-btn');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var ids = picked();
    if (!ids.length) return;
    var msg = 'حذف ' + ids.length + ' زيارة من التقرير؟';
    var go = function(){
      fetch('/sales-reps/reports/visits/delete', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ line_ids: ids })
      })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (window.HypexUI && window.HypexUI.toast) {
            window.HypexUI.toast((d && d.message) || 'تم', d && d.ok ? 'ok' : 'error');
          }
          if (d && d.ok) window.location.reload();
        })
        .catch(function(){
          if (window.HypexUI && window.HypexUI.toast) window.HypexUI.toast('تعذر الحذف', 'error');
        });
    };
    if (window.HypexUI && window.HypexUI.confirm) {
      window.HypexUI.confirm(msg, { title: 'حذف الزيارات', okLabel: 'حذف', cancelLabel: 'إلغاء', danger: true })
        .then(function(ok){ if (ok) go(); });
    } else if (window.confirm(msg)) go();
  });
})();
</script>`
          : ''
      }
      ${
        livePoll
          ? `<script>
(function(){
  var q = new URLSearchParams(window.location.search);
  function poll(){
    fetch('/sales-reps/reports/visits/data?' + q.toString(), { credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) return;
        var el = document.getElementById('hx-visits-live-count');
        if (el) el.textContent = (d.rows || []).length + ' زيارة';
      })
      .catch(function(){});
  }
  setInterval(poll, 30000);
})();
</script>
<p id="hx-visits-live-count" class="muted no-print" style="margin:.5rem 0;font-size:.82rem">تحديث تلقائي كل 30 ثانية — ${rows.length} زيارة</p>`
          : ''
      }
    </div>`;

  res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'تقرير زيارات العملاء',
      bodyHtml: body,
      css: REP_REPORT_CSS,
      printTitle: 'تقرير زيارات العملاء',
    })
  );
});

/* ── كشف تحصيلات المندوب خلال فترة (Oracle) ── */
router.get('/sales-reps/reports/oracle-collections', async (req, res) => {
  try {
    const adminService = require('../system/adminService');
    if (adminService.ensureOracleRepCollectionsScreen) {
      await adminService.ensureOracleRepCollectionsScreen();
    }
  } catch (e) {
    console.error('ensureOracleRepCollectionsScreen', e.message || e);
  }
  if (!can(req.session.user, 'report_oracle_rep_collections') && !can(req.session.user, 'sales_reps')) {
    return res.status(403).send('ممنوع');
  }

  const BASE = '/sales-reps/reports/oracle-collections';
  const fromQ = String(req.query.from || '').trim();
  const toQ = String(req.query.to || '').trim();
  const fromIso =
    parseDateToIso(fromQ) ||
    (/^\d{4}-\d{2}-\d{2}$/.test(fromQ) ? fromQ : '') ||
    (() => {
      const d = new Date();
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
    })();
  const toIso = parseDateToIso(toQ) || (/^\d{4}-\d{2}-\d{2}$/.test(toQ) ? toQ : todayIso());
  const salesRepIds = parseSalesRepIdsQuery(req.query);
  const customerIds = parseCustomerIdsQuery(req.query);
  const run =
    String(req.query.run || '') === '1' ||
    salesRepIds.length > 0 ||
    customerIds.length > 0 ||
    fromQ !== '' ||
    toQ !== '';
  const uid = Number(req.session.user?.id || 0) || 0;

  let reps = [];
  let oraNameByMan = new Map();
  try {
    const repsPayload = await accNative.run('oracle_list_rep_net_sales_reps', uid, {});
    const oraRows = Array.isArray(repsPayload?.rows) ? repsPayload.rows : [];
    const seen = new Set();
    for (const r of oraRows) {
      const manNo = Number(r.id) || 0;
      if (manNo < 1 || seen.has(manNo)) continue;
      seen.add(manNo);
      const nm = String(r.name_ar || '').trim() || `مندوب ${manNo}`;
      oraNameByMan.set(manNo, nm);
      reps.push({ id: manNo, code: String(manNo), name_ar: nm });
    }
    reps.sort((a, b) => String(a.name_ar).localeCompare(String(b.name_ar), 'ar'));
  } catch (e) {
    console.error('oracle collections reps', e.message || e);
  }

  let customers = [];
  try {
    /* قائمة كل العملاء النشطين (فلتر المندوب يتم في Oracle عبر رقم المندوب) */
    customers = await salesQ.listCustomersForReps([]);
  } catch (e) {
    console.error('oracle collections customers', e.message || e);
    customers = [];
  }

  const selectedRepSet = new Set(salesRepIds);
  const selectedRepCount = salesRepIds.length;
  const repToggleLabel =
    selectedRepCount < 1
      ? '— الكل —'
      : selectedRepCount === 1
        ? oraNameByMan.get(salesRepIds[0]) || `مندوب ${salesRepIds[0]}`
        : `${selectedRepCount} مندوبون محددون`;
  const selectedCustSet = new Set(customerIds);
  const selectedCustCount = customerIds.length;
  const customerToggleLabel =
    selectedCustCount < 1
      ? '— كل العملاء —'
      : selectedCustCount === 1
        ? (() => {
            const c = (customers || []).find((x) => Number(x.id) === customerIds[0]);
            return c ? String(c.name_ar || c.code || `#${customerIds[0]}`) : 'عميل واحد';
          })()
        : `${selectedCustCount} عملاء محددون`;

  const customerAccs = [];
  if (selectedCustCount > 0) {
    for (const c of customers || []) {
      const id = Number(c.id) || 0;
      if (!selectedCustSet.has(id)) continue;
      const acc = String(c.oracle_key || c.code || '').trim();
      if (acc) customerAccs.push(acc);
    }
  }

  let err = '';
  let rows = [];
  let totals = { count: 0, amount: 0 };
  let printTitle = 'كشف تحصيلات المندوب خلال فترة';
  if (selectedRepCount === 1) {
    printTitle = `كشف تحصيلات المندوب — ${oraNameByMan.get(salesRepIds[0]) || salesRepIds[0]}`;
  } else if (selectedRepCount > 1) {
    printTitle = `كشف تحصيلات المندوبين (${selectedRepCount})`;
  }

  if (run) {
    if (selectedCustCount > 0 && customerAccs.length < 1) {
      err = 'العملاء المحددون بلا رمز/مفتاح Oracle — لا يمكن تصفية التحصيلات.';
    } else {
      const payload = {
        from: fromIso,
        to: toIso,
        comp_num: 1,
      };
      if (salesRepIds.length === 1) {
        payload.rep_from = salesRepIds[0];
        payload.rep_to = salesRepIds[0];
      } else if (salesRepIds.length > 1) {
        payload.rep_ids = salesRepIds;
      }
      if (customerAccs.length) payload.customer_accs = customerAccs;

      const data = await accNative.run('oracle_rep_collections', uid, payload);
      if (!data || data.ok === false) {
        err = String(data?.error || data?.message || 'تعذر الاتصال بـ Oracle.');
      } else {
        rows = Array.isArray(data.rows) ? data.rows : [];
        totals = data.totals && typeof data.totals === 'object' ? data.totals : totals;
        if (!rows.length && data.message) err = String(data.message);
      }
    }
  }

  const byRep = new Map();
  for (const r of rows) {
    const k = Number(r.rep_no) || 0;
    if (!byRep.has(k)) {
      byRep.set(k, {
        rep_no: k,
        rep_name: String(r.rep_name || oraNameByMan.get(k) || `مندوب ${k}`),
        comp_num: Number(r.comp_num) || 1,
        rows: [],
        amount: 0,
      });
    }
    const block = byRep.get(k);
    block.rows.push(r);
    block.amount += Number(r.amount) || 0;
  }

  const dmy = (iso) => {
    const s = String(iso || '');
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) return isoToDmy(s.slice(0, 10));
    return s;
  };

  const repsJson = JSON.stringify(
    (reps || []).map((r) => ({
      id: Number(r.id) || 0,
      name: String(r.name_ar || ''),
      code: String(r.code || ''),
    }))
  ).replace(/</g, '\\u003c');
  const customersJson = JSON.stringify(
    (customers || []).map((c) => ({
      id: Number(c.id) || 0,
      name: String(c.name_ar || ''),
      code: String(c.code || c.oracle_key || ''),
    }))
  ).replace(/</g, '\\u003c');
  const repCheckHtml = (reps || [])
    .map((r) => {
      const id = Number(r.id) || 0;
      const label = String(r.name_ar || `#${id}`);
      const checked = selectedRepSet.has(id) ? ' checked' : '';
      return `<label class="hx-cust-check-item">
        <input type="checkbox" class="hx-rep-one" value="${id}"${checked}>
        <span>${esc(label)} <span dir="ltr" class="muted">#${id}</span></span>
      </label>`;
    })
    .join('');
  const custCheckHtml = (customers || [])
    .map((c) => {
      const id = Number(c.id) || 0;
      const label = String(c.name_ar || c.code || `#${id}`);
      const code = String(c.code || c.oracle_key || '');
      const checked = selectedCustSet.has(id) ? ' checked' : '';
      return `<label class="hx-cust-check-item">
        <input type="checkbox" class="hx-cust-one" value="${id}"${checked}>
        <span>${esc(label)}${code ? ` <span dir="ltr" class="muted">${esc(code)}</span>` : ''}</span>
      </label>`;
    })
    .join('');

  let tableBlock = '';
  if (err) {
    tableBlock = `<p class="si-pill si-pill--lock" style="display:inline-block;max-width:100%;white-space:normal;line-height:1.45">${esc(err)}</p>`;
  } else if (run && !rows.length) {
    tableBlock = `<p class="muted" style="padding:.75rem 0">لا توجد تحصيلات في الفترة المحددة.</p>`;
  } else if (run) {
    const blocks = [...byRep.values()]
      .map((block) => {
        const body = block.rows
          .map(
            (r) => `<tr>
          <td class="si-num ora-coll-no" dir="ltr">${esc(r.rcv_no || '')}</td>
          <td class="ora-coll-type">${esc(r.rcv_type || '')}</td>
          <td class="ora-coll-cust">${esc(r.customer_name || r.customer_acc || '—')}</td>
          <td class="si-num ora-coll-date" dir="ltr">${esc(dmy(r.rcv_date))}</td>
          <td class="si-num ora-coll-chq" dir="ltr">${esc(r.chq_no || '')}</td>
          <td class="si-num ora-coll-due" dir="ltr">${esc(r.chq_due ? dmy(r.chq_due) : '')}</td>
          <td class="si-num ora-coll-amt" dir="ltr">${esc(fmtAmt(r.amount))}</td>
        </tr>`
          )
          .join('');
        const foot = `<tr class="ora-coll-foot">
          <td colspan="6" style="font-weight:800">مجموع المندوب</td>
          <td class="si-num ora-coll-amt" dir="ltr" style="font-weight:800">${esc(fmtAmt(block.amount))}</td>
        </tr>`;
        return `<section class="ora-coll-block" style="margin:0 0 1rem">
          <h3 style="margin:0 0 .4rem;font-size:.95rem">
            رقم المندوب: <span dir="ltr">${esc(block.rep_no)}</span>
            ${block.rep_name ? ` · ${esc(block.rep_name)}` : ''}
            · رقم الشركة: <span dir="ltr">${esc(block.comp_num)}</span>
          </h3>
          <div class="si-table-wrap">
            <table class="si-table ora-coll-table">
              <thead><tr>
                <th>رقم القبض</th>
                <th>نوع القبض</th>
                <th>العميل</th>
                <th>تاريخ القبض</th>
                <th>رقم الشيك</th>
                <th>تاريخ حق الشيك</th>
                <th>القيمة</th>
              </tr></thead>
              <tbody>${body}${foot}</tbody>
            </table>
          </div>
        </section>`;
      })
      .join('');
    tableBlock =
      `<style>
        .si-report-page[data-report-route="report_oracle_rep_collections"] .ora-coll-table {
          table-layout: auto;
          width: 100%;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .ora-coll-table th,
        .si-report-page[data-report-route="report_oracle_rep_collections"] .ora-coll-table td {
          overflow: visible !important;
          text-overflow: clip !important;
          vertical-align: top;
          line-height: 1.45;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .ora-coll-type {
          white-space: nowrap;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .ora-coll-cust {
          white-space: normal !important;
          min-width: 14rem;
          max-width: 26rem;
          font-weight: 600;
        }
      </style>` +
      blocks +
      `<p style="margin:.5rem 0 0;font-weight:800">الإجمالي (${esc(totals.count || rows.length)} حركة): <span dir="ltr">${esc(
        fmtAmt(totals.amount || 0)
      )}</span></p>`;
  } else {
    tableBlock = `<p class="muted" style="padding:.5rem 0">حدّد الفترة ثم اضغط «عرض التقرير».</p>`;
  }

  const body = `
    <div class="si-stage si-report-page report-sales-page si-report-visits" data-report-route="report_oracle_rep_collections">
      <style>
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters {
          display: flex !important;
          flex-wrap: nowrap;
          align-items: flex-end;
          gap: 0.5rem 0.65rem;
          width: 100%;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters > .hx-visits-f,
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters > .hx-visits-f-actions {
          margin: 0;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters .hx-visits-f--rep,
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters .hx-visits-f--customer {
          flex: 1 1 0;
          min-width: 11rem;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters .hx-visits-f--from,
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters .hx-visits-f--to {
          flex: 0 0 9.5rem;
          width: 9.5rem;
        }
        .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters .hx-visits-f-actions {
          flex: 0 0 auto;
          padding-bottom: 1px;
        }
        @media (max-width: 900px) {
          .si-report-page[data-report-route="report_oracle_rep_collections"] .hx-coll-filters {
            flex-wrap: wrap;
          }
        }
      </style>
      ${ui.hero({
        mark: '💵',
        kicker: KICKER,
        title: 'كشف تحصيلات المندوب خلال فترة (Oracle)',
        subtitle: 'سندات القبض النقدي والشيكات من نظام المحاسبة والذمم',
        actions: [
          ui.printAction(),
          { label: 'لوحة المندوبين', href: HUB },
          { label: 'تقرير الزيارات', href: '/sales-reps/reports/visits' },
        ],
      })}
      <p class="muted no-print" style="margin:0 1rem .35rem;font-size:.8rem">البيانات من Oracle · مثل تقرير Forms «كشف تحصيلات المندوبين».</p>
      <div class="si-surface no-print" style="margin:0 1rem 1rem;padding:.85rem 1rem;overflow:visible">
        <form method="get" action="${esc(BASE)}" class="hx-visits-filters hx-coll-filters" data-app-busy-msg="جاري تحميل التحصيلات…">
          <input type="hidden" name="run" value="1" />
          <div class="hx-visits-f hx-visits-f--rep">
            <span class="hx-visits-f-label">المندوب</span>
            <div class="hx-smart-cust" id="hx-coll-smart-rep">
              <div id="hx-coll-rep-hidden">
                ${
                  selectedRepCount
                    ? salesRepIds
                        .map((id) => `<input type="hidden" name="sales_rep_id" value="${id}">`)
                        .join('')
                    : ''
                }
              </div>
              <button type="button" class="si-field hx-smart-cust-toggle" id="hx-coll-rep-toggle"
                aria-haspopup="listbox" aria-expanded="false" aria-controls="hx-coll-rep-panel">
                <span class="hx-smart-cust-toggle-text" id="hx-coll-rep-label">${esc(repToggleLabel)}</span>
                <span class="hx-smart-cust-caret" aria-hidden="true"></span>
              </button>
              <div class="hx-smart-cust-panel" id="hx-coll-rep-panel" hidden>
                <input class="si-field hx-smart-cust-search" type="search" id="hx-coll-rep-q"
                  placeholder="ابحث عن مندوب…" autocomplete="off">
                <label class="hx-cust-check-item hx-cust-check-item--all">
                  <input type="checkbox" id="hx-coll-rep-all" ${selectedRepCount < 1 ? 'checked' : ''}>
                  <span>كل المندوبين</span>
                </label>
                <div class="hx-smart-cust-list" id="hx-coll-rep-list" role="group">
                  ${repCheckHtml || '<div class="hx-smart-cust-empty">لا يوجد مندوبون.</div>'}
                </div>
              </div>
              <script type="application/json" id="hx-coll-reps-json">${repsJson}</script>
            </div>
          </div>
          <div class="hx-visits-f hx-visits-f--customer">
            <span class="hx-visits-f-label">العميل</span>
            <div class="hx-smart-cust" id="hx-coll-smart-cust">
              <div id="hx-coll-customer-hidden">
                ${
                  selectedCustCount
                    ? customerIds
                        .map((id) => `<input type="hidden" name="customer_id" value="${id}">`)
                        .join('')
                    : ''
                }
              </div>
              <button type="button" class="si-field hx-smart-cust-toggle" id="hx-coll-customer-toggle"
                aria-haspopup="listbox" aria-expanded="false" aria-controls="hx-coll-cust-panel">
                <span class="hx-smart-cust-toggle-text" id="hx-coll-customer-label">${esc(customerToggleLabel)}</span>
                <span class="hx-smart-cust-caret" aria-hidden="true"></span>
              </button>
              <div class="hx-smart-cust-panel" id="hx-coll-cust-panel" hidden>
                <input class="si-field hx-smart-cust-search" type="search" id="hx-coll-customer-q"
                  placeholder="ابحث بالاسم أو الرمز…" autocomplete="off">
                <label class="hx-cust-check-item hx-cust-check-item--all">
                  <input type="checkbox" id="hx-coll-cust-all" ${selectedCustCount < 1 ? 'checked' : ''}>
                  <span>كل العملاء</span>
                </label>
                <div class="hx-smart-cust-list" id="hx-coll-cust-list" role="group">
                  ${custCheckHtml || '<div class="hx-smart-cust-empty">لا يوجد عملاء.</div>'}
                </div>
              </div>
              <script type="application/json" id="hx-coll-customers-json">${customersJson}</script>
            </div>
          </div>
          <label class="hx-visits-f hx-visits-f--from">
            <span class="hx-visits-f-label">من تاريخ</span>
            <input class="si-field js-date-dmy" type="text" name="from" value="${esc(isoToDmy(fromIso))}"
                   placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
          </label>
          <label class="hx-visits-f hx-visits-f--to">
            <span class="hx-visits-f-label">إلى تاريخ</span>
            <input class="si-field js-date-dmy" type="text" name="to" value="${esc(isoToDmy(toIso))}"
                   placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
          </label>
          <div class="hx-visits-f-actions">
            <button class="si-btn si-btn--primary" type="submit">عرض التقرير</button>
          </div>
        </form>
        <script>
        (function(){
          function filterCheckItems(list, q, cls){
            if (!list) return;
            q = String(q||'').trim().toLowerCase();
            Array.prototype.forEach.call(list.querySelectorAll('.' + cls), function(el){
              var lab = el.closest('.hx-cust-check-item');
              if (!lab) return;
              var txt = (lab.textContent || '').toLowerCase();
              if (!q || txt.indexOf(q) !== -1) lab.classList.remove('is-hidden');
              else lab.classList.add('is-hidden');
            });
          }
          function wireMulti(opts){
            var toggle = document.getElementById(opts.toggleId);
            var labelEl = document.getElementById(opts.labelId);
            var panel = document.getElementById(opts.panelId);
            var list = document.getElementById(opts.listId);
            var allCb = document.getElementById(opts.allId);
            var hidden = document.getElementById(opts.hiddenId);
            var search = document.getElementById(opts.searchId);
            var oneCls = opts.oneCls;
            var fieldName = opts.fieldName;
            var allLabel = opts.allLabel || '— الكل —';
            var oneLabel = opts.oneLabel || 'واحد';
            var manyLabel = opts.manyLabel || 'محددون';
            var jsonId = opts.jsonId;
            var all = [];
            try {
              var raw = document.getElementById(jsonId);
              all = raw ? JSON.parse(raw.textContent || '[]') : [];
            } catch (e) { all = []; }
            if (!Array.isArray(all)) all = [];
            function nameById(id){
              for (var i = 0; i < all.length; i++){
                if (Number(all[i].id) === Number(id)) return all[i].name || ('#' + id);
              }
              return oneLabel;
            }
            function selected(){
              if (!list) return [];
              return Array.prototype.slice.call(list.querySelectorAll('.' + oneCls + ':checked'))
                .map(function(el){ return Number(el.value)||0; })
                .filter(function(id){ return id > 0; });
            }
            function sync(){
              var ids = selected();
              if (allCb && ids.length < 1) allCb.checked = true;
              if (allCb && ids.length > 0) allCb.checked = false;
              var allOn = !!(allCb && allCb.checked);
              if (hidden){
                hidden.innerHTML = '';
                if (!allOn){
                  ids.forEach(function(id){
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = fieldName;
                    inp.value = String(id);
                    hidden.appendChild(inp);
                  });
                }
              }
              if (labelEl){
                if (allOn || ids.length < 1) labelEl.textContent = allLabel;
                else if (ids.length === 1) labelEl.textContent = nameById(ids[0]);
                else labelEl.textContent = ids.length + ' ' + manyLabel;
              }
            }
            function close(){
              if (!panel) return;
              panel.hidden = true;
              panel.setAttribute('hidden','');
              if (toggle) toggle.setAttribute('aria-expanded','false');
              if (search) search.value = '';
              filterCheckItems(list, '', oneCls);
            }
            function open(){
              if (!panel) return;
              document.querySelectorAll('.hx-smart-cust-panel').forEach(function(p){
                if (p !== panel){ p.hidden = true; p.setAttribute('hidden',''); }
              });
              panel.hidden = false;
              panel.removeAttribute('hidden');
              if (toggle) toggle.setAttribute('aria-expanded','true');
              if (search) setTimeout(function(){ search.focus(); search.select(); }, 0);
            }
            if (toggle && panel){
              toggle.addEventListener('click', function(e){
                e.preventDefault();
                if (panel.hidden) open(); else close();
              });
            }
            if (allCb){
              allCb.addEventListener('change', function(){
                if (allCb.checked && list){
                  Array.prototype.forEach.call(list.querySelectorAll('.' + oneCls), function(el){ el.checked = false; });
                }
                sync();
              });
            }
            if (list){
              list.addEventListener('change', function(e){
                if (!e.target || !e.target.classList || !e.target.classList.contains(oneCls)) return;
                if (e.target.checked && allCb) allCb.checked = false;
                sync();
              });
            }
            if (search){
              var t = null;
              search.addEventListener('input', function(){
                clearTimeout(t);
                var v = search.value;
                t = setTimeout(function(){ filterCheckItems(list, v, oneCls); }, 120);
              });
              search.addEventListener('keydown', function(e){
                if (e.key === 'Escape'){ e.preventDefault(); close(); if (toggle) toggle.focus(); }
              });
            }
            document.addEventListener('click', function(e){
              if (!panel || panel.hidden) return;
              var root = toggle && toggle.closest('.hx-smart-cust');
              if (root && !root.contains(e.target)) close();
            });
            sync();
          }
          wireMulti({
            toggleId:'hx-coll-rep-toggle', labelId:'hx-coll-rep-label', panelId:'hx-coll-rep-panel',
            listId:'hx-coll-rep-list', allId:'hx-coll-rep-all', hiddenId:'hx-coll-rep-hidden',
            searchId:'hx-coll-rep-q', oneCls:'hx-rep-one', fieldName:'sales_rep_id',
            allLabel:'— الكل —', oneLabel:'مندوب', manyLabel:'مندوبون محددون', jsonId:'hx-coll-reps-json'
          });
          wireMulti({
            toggleId:'hx-coll-customer-toggle', labelId:'hx-coll-customer-label', panelId:'hx-coll-cust-panel',
            listId:'hx-coll-cust-list', allId:'hx-coll-cust-all', hiddenId:'hx-coll-customer-hidden',
            searchId:'hx-coll-customer-q', oneCls:'hx-cust-one', fieldName:'customer_id',
            allLabel:'— كل العملاء —', oneLabel:'عميل', manyLabel:'عملاء محددون', jsonId:'hx-coll-customers-json'
          });
        })();
        </script>
      </div>
      <div class="si-print-area report-sales-result">${tableBlock}</div>
    </div>`;

  return res.send(
    ui.salesPage({
      user: req.session.user,
      title: 'كشف تحصيلات المندوب خلال فترة Oracle',
      bodyHtml: body,
      css: ['/assets/css/report-sales.css', '/assets/css/report-rep-reports.css'],
      js: ['/assets/js/sales-print.js'],
      printTitle,
    })
  );
});

/* ═══════════ Dynamic :id last ═══════════ */
router.get('/sales-reps/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  return repForm(req, res, id);
});
router.post('/sales-reps/:id', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const result = await masters.saveRep({ ...(req.body || {}), id });
  if (!result.ok) return res.redirect('/sales-reps/' + id + '?err=' + encodeURIComponent(result.error));
  res.redirect('/sales-reps/list?msg=' + encodeURIComponent(result.message || 'تم'));
});
router.post('/sales-reps/:id/toggle', async (req, res, next) => {
  const id = Number(req.params.id);
  if (!Number.isFinite(id) || id < 1) return next();
  if (!can(req.session.user, 'sales_reps')) return res.status(403).send('ممنوع');
  const result = await masters.toggleRep(id);
  res.redirect('/sales-reps/list?msg=' + encodeURIComponent(result.message || result.error || ''));
});

module.exports = router;
