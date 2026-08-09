'use strict';

const db = require('../db');

async function q(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('gps', e.message);
    return [];
  }
}

function mapUrl(lat, lng) {
  const a = Number(lat);
  const b = Number(lng);
  if (!Number.isFinite(a) || !Number.isFinite(b) || (a === 0 && b === 0)) return '';
  return `https://www.google.com/maps?q=${a},${b}`;
}

function coordsValid(lat, lng) {
  const a = Number(lat);
  const b = Number(lng);
  return (
    Number.isFinite(a) &&
    Number.isFinite(b) &&
    a >= -90 &&
    a <= 90 &&
    b >= -180 &&
    b <= 180 &&
    !(Math.abs(a) < 1e-6 && Math.abs(b) < 1e-6)
  );
}

function ageLabel(ageSec) {
  const s = Math.max(0, Number(ageSec) || 0);
  if (s < 15) return 'الآن';
  if (s < 60) return 'قبل ' + s + ' ث';
  if (s < 3600) {
    const m = Math.floor(s / 60);
    return m === 1 ? 'قبل دقيقة' : 'قبل ' + m + ' دقيقة';
  }
  if (s < 86400) {
    const h = Math.floor(s / 3600);
    return h === 1 ? 'قبل ساعة' : 'قبل ' + h + ' ساعة';
  }
  const d = Math.floor(s / 86400);
  return d === 1 ? 'قبل يوم' : 'قبل ' + d + ' يوم';
}

function sourceLabel(src) {
  const s = String(src || '').toLowerCase();
  if (s === 'mobile') return 'هاتف';
  if (s === 'desktop' || s === 'windows') return 'سطح المكتب';
  return s || '—';
}

function userLabel(full, user) {
  const f = String(full || '').trim();
  return f || String(user || '').trim() || '—';
}

function placeLabel(row) {
  const place = String(row.gps_place || '').trim();
  const mark = String(row.gps_landmark || '').trim();
  if (place && mark) return place + ' · ' + mark;
  return place || mark || '—';
}

function haversineM(lat1, lng1, lat2, lng2) {
  const R = 6371000;
  const toR = (d) => (d * Math.PI) / 180;
  const dLat = toR(lat2 - lat1);
  const dLng = toR(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toR(lat1)) * Math.cos(toR(lat2)) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(a)));
}

/** Live markers — شكل متوافق مع PHP api/user_gps_tracker_live.php */
async function liveTrackerPayload({ onlineSec = 60, includeStale = false, q: search = '' } = {}) {
  const onlineSeconds = Math.max(15, Math.min(12 * 3600, Number(onlineSec) || 60));
  const windowSec = includeStale ? Math.max(onlineSeconds, 3600) : onlineSeconds;

  const where = [
    'u.is_active = 1',
    `ul.captured_at >= DATE_SUB(NOW(), INTERVAL ${Number(windowSec)} SECOND)`,
  ];
  const params = [];
  if (search) {
    const like = `%${search}%`;
    where.push(`(u.username LIKE ? OR IFNULL(u.full_name_ar,'') LIKE ?)`);
    params.push(like, like);
  }

  const raw = await q(
    `SELECT ul.user_id, ul.latitude, ul.longitude, ul.gps_accuracy, ul.gps_source,
            ul.gps_place, ul.gps_landmark, ul.captured_at,
            u.username, u.full_name_ar,
            TIMESTAMPDIFF(SECOND, ul.captured_at, NOW()) AS age_sec
     FROM sys_user_location ul
     INNER JOIN sys_user u ON u.id = ul.user_id
     WHERE ${where.join(' AND ')}
     ORDER BY ul.captured_at DESC
     LIMIT 500`,
    params
  );

  const markers = [];
  for (const r of raw) {
    const lat = Number(r.latitude);
    const lng = Number(r.longitude);
    if (!coordsValid(lat, lng)) continue;
    const ageSec = Math.max(0, Number(r.age_sec) || 0);
    const isOnline = ageSec <= onlineSeconds;
    if (!includeStale && !isOnline) continue;
    const rawSrc = String(r.gps_source || '').trim();
    markers.push({
      user_id: Number(r.user_id),
      user_label: userLabel(r.full_name_ar, r.username),
      username: String(r.username || ''),
      latitude: lat,
      longitude: lng,
      gps_accuracy:
        r.gps_accuracy != null && r.gps_accuracy !== '' ? Number(r.gps_accuracy) : null,
      accuracy_label:
        r.gps_accuracy != null && r.gps_accuracy !== ''
          ? Math.round(Number(r.gps_accuracy)) + ' م'
          : '',
      gps_source: rawSrc || null,
      source_label: sourceLabel(rawSrc),
      place_label: placeLabel(r),
      captured_at: r.captured_at,
      age_sec: ageSec,
      age_label: ageLabel(ageSec),
      is_online: isOnline,
      status: isOnline ? 'online' : 'offline',
      status_label: isOnline ? 'متصل' : 'غير متصل',
      map_url: mapUrl(lat, lng),
    });
  }

  const lastPings = await recentSnapshots(8);
  let hint = '';
  if (!markers.length && lastPings.length) {
    const top = lastPings[0];
    hint =
      'لا يوجد متصل الآن. آخر موقع محفوظ: ' +
      (top.user_label || '') +
      ' — ' +
      (top.age_label || '') +
      '.';
    if (!top.coords_valid) hint += ' الإحداثيات غير صالحة للعرض على الخريطة.';
    else if ((top.age_sec || 0) > onlineSeconds) {
      hint += ' النبضة أقدم من نافذة الاتصال (' + onlineSeconds + ' ثانية).';
    }
  } else if (!markers.length) {
    hint =
      'لا يوجد متصل الآن. تأكد أن تطبيق المندوب يرسل الموقع كل 10 ثوانٍ إلى نفس هذا السيرفر.';
  }

  const online = markers.filter((m) => m.is_online).length;
  return {
    ok: true,
    server_time: new Date().toISOString(),
    online_seconds: onlineSeconds,
    stale_seconds: windowSec,
    include_stale: includeStale,
    online_minutes: Math.max(1, Math.ceil(onlineSeconds / 60)),
    stale_minutes: Math.max(1, Math.ceil(windowSec / 60)),
    counts: {
      total: markers.length,
      online,
      away: markers.length - online,
      offline: markers.length - online,
    },
    hint,
    last_pings: lastPings,
    map: {
      tile_url:
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
      attribution: '© Esri — OpenStreetMap contributors',
      map_provider: 'esri',
      default_lat: 31.9539,
      default_lng: 35.9106,
      default_zoom: 8,
    },
    markers,
    // توافق مبسّط إن اعتمدت الواجهة القديمة
    points: markers.map((m) => ({
      user_id: m.user_id,
      label: m.user_label,
      lat: m.latitude,
      lng: m.longitude,
      ...m,
    })),
    online,
    total: markers.length,
  };
}

async function recentSnapshots(limit = 8) {
  const lim = Math.max(1, Math.min(30, limit));
  const rows = await q(
    `SELECT ul.user_id, ul.captured_at, ul.gps_source, ul.latitude, ul.longitude,
            u.username, u.full_name_ar,
            TIMESTAMPDIFF(SECOND, ul.captured_at, NOW()) AS age_sec
     FROM sys_user_location ul
     INNER JOIN sys_user u ON u.id = ul.user_id AND u.is_active = 1
     ORDER BY ul.captured_at DESC
     LIMIT ${lim}`
  );
  return rows.map((row) => {
    const age = Number(row.age_sec) || 0;
    const lat = Number(row.latitude) || 0;
    const lng = Number(row.longitude) || 0;
    return {
      user_id: Number(row.user_id),
      user_label: userLabel(row.full_name_ar, row.username),
      captured_at: String(row.captured_at || ''),
      age_sec: age,
      age_label: ageLabel(age),
      gps_source: String(row.gps_source || ''),
      latitude: lat,
      longitude: lng,
      coords_valid: coordsValid(lat, lng),
    };
  });
}

async function listUserLocations({ q: search = '', from = '', to = '' } = {}) {
  const where = ['u.is_active = 1'];
  const params = [];
  if (from && to) {
    where.push('DATE(ul.captured_at) BETWEEN ? AND ?');
    params.push(from, to);
  }
  if (search) {
    const like = `%${search}%`;
    where.push(
      `(u.username LIKE ? OR IFNULL(u.full_name_ar,'') LIKE ? OR IFNULL(ul.gps_place,'') LIKE ? OR IFNULL(ul.gps_landmark,'') LIKE ?)`
    );
    params.push(like, like, like, like);
  }
  const rows = await q(
    `SELECT ul.user_id, ul.latitude, ul.longitude, ul.gps_accuracy, ul.gps_source,
            ul.gps_place, ul.gps_landmark, ul.captured_at,
            u.username, u.full_name_ar
     FROM sys_user_location ul
     INNER JOIN sys_user u ON u.id = ul.user_id
     WHERE ${where.join(' AND ')}
     ORDER BY ul.captured_at DESC
     LIMIT 500`,
    params
  );
  return rows.map((r) => ({
    ...r,
    place_label: placeLabel(r),
    source_label: sourceLabel(r.gps_source),
    map_url: mapUrl(r.latitude, r.longitude),
    accuracy_label:
      r.gps_accuracy != null && r.gps_accuracy !== ''
        ? Math.round(Number(r.gps_accuracy)) + ' م'
        : '—',
  }));
}

async function trackUsers() {
  return q(
    `SELECT u.id AS user_id, u.username, u.full_name_ar
     FROM sys_user u
     WHERE u.is_active = 1
       AND (
         EXISTS (SELECT 1 FROM sys_user_location ul WHERE ul.user_id = u.id)
         OR EXISTS (SELECT 1 FROM sys_user_location_track t WHERE t.user_id = u.id)
       )
     ORDER BY u.full_name_ar, u.username
     LIMIT 300`
  ).then((rows) =>
    rows.map((r) => ({
      user_id: Number(r.user_id),
      username: String(r.username || ''),
      user_label: userLabel(r.full_name_ar, r.username),
    }))
  );
}

/** خط السير اليومي — مبسّط ومتوافق مع user-gps-route.js */
async function trackDayPayload({ userId = 0, date = '' } = {}) {
  const users = await trackUsers();
  let d = String(date || '').slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) {
    d = new Date().toISOString().slice(0, 10);
  }
  const [yy, mm, dd] = d.split('-');
  const dateDmy = `${dd}-${mm}-${yy}`;

  const empty = {
    ok: true,
    server_time: new Date().toISOString(),
    date: d,
    date_dmy: dateDmy,
    user_id: userId,
    users,
    map: {
      tile_url:
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
      attribution: '© Esri — OpenStreetMap contributors',
      map_provider: 'esri',
      default_lat: 31.9539,
      default_lng: 35.9106,
      default_zoom: 8,
    },
    points: [],
    segments: [],
    stops: [],
    road_path: [],
    road_paths: [],
    track_lines: [],
    road_matched: false,
    presence: [],
    summary: null,
    user_label: '',
  };

  if (userId < 1) return empty;

  const rows = await q(
    `SELECT latitude, longitude, gps_accuracy, gps_source, captured_at,
            DATE_FORMAT(captured_at, '%H:%i') AS time,
            DATE_FORMAT(captured_at, '%H:%i:%s') AS time_full
     FROM sys_user_location_track
     WHERE user_id = ? AND DATE(captured_at) = ?
     ORDER BY captured_at ASC, id ASC
     LIMIT 5000`,
    [userId, d]
  );

  const points = [];
  for (const row of rows) {
    const lat = Number(row.latitude);
    const lng = Number(row.longitude);
    if (!coordsValid(lat, lng)) continue;
    const capturedAt = String(row.captured_at || '');
    const ts = capturedAt ? Date.parse(capturedAt.replace(' ', 'T')) / 1000 : 0;
    if (!ts) continue;
    points.push({
      latitude: lat,
      longitude: lng,
      gps_accuracy:
        row.gps_accuracy != null && row.gps_accuracy !== '' ? Number(row.gps_accuracy) : null,
      accuracy_label:
        row.gps_accuracy != null && row.gps_accuracy !== ''
          ? Math.round(Number(row.gps_accuracy)) + ' م'
          : '',
      gps_source: row.gps_source || null,
      source_label: sourceLabel(row.gps_source),
      captured_at: capturedAt,
      ts: Math.floor(ts),
      time: String(row.time || ''),
      time_full: String(row.time_full || ''),
    });
  }

  let totalM = 0;
  const segments = [];
  let current = [];
  const gapBreakSec = 30 * 60;
  for (let i = 0; i < points.length; i++) {
    if (i === 0) {
      current = [0];
      continue;
    }
    const prev = points[i - 1];
    const cur = points[i];
    const gap = Math.max(0, cur.ts - prev.ts);
    const dist = haversineM(prev.latitude, prev.longitude, cur.latitude, cur.longitude);
    if (gap > gapBreakSec && dist > 200) {
      if (current.length >= 2) segments.push(current.slice());
      current = [i];
    } else {
      current.push(i);
      totalM += dist;
    }
  }
  if (current.length >= 2) segments.push(current);

  const track_lines = segments.map((idxs) =>
    idxs.map((i) => ({ lat: points[i].latitude, lng: points[i].longitude }))
  );

  // توقفات مبسطة: سلسلة نقاط ضمن 70م لأكثر من 5 دقائق
  const stops = [];
  let si = 0;
  while (si < points.length) {
    let ei = si;
    let cx = points[si].latitude;
    let cy = points[si].longitude;
    while (ei + 1 < points.length) {
      const d = haversineM(cx, cy, points[ei + 1].latitude, points[ei + 1].longitude);
      if (d > 70) break;
      ei++;
      cx = (cx + points[ei].latitude) / 2;
      cy = (cy + points[ei].longitude) / 2;
    }
    const dwell = points[ei].ts - points[si].ts;
    if (dwell >= 5 * 60 && ei > si) {
      stops.push({
        latitude: cx,
        longitude: cy,
        start_time: points[si].time,
        end_time: points[ei].time,
        duration_sec: dwell,
        duration_label: ageLabel(dwell).replace('قبل ', ''),
        index: stops.length + 1,
      });
    }
    si = ei + 1;
  }

  const km = totalM / 1000;
  const label = (users.find((u) => Number(u.user_id) === Number(userId)) || {}).user_label || '';

  empty.points = points;
  empty.segments = segments;
  empty.stops = stops;
  empty.track_lines = track_lines;
  empty.user_id = userId;
  empty.user_label = label;
  empty.summary = {
    points_count: points.length,
    distance_km: Math.round(km * 100) / 100,
    distance_label: (Math.round(km * 10) / 10) + ' كم',
    first_time: points[0] ? points[0].time : '',
    last_time: points.length ? points[points.length - 1].time : '',
    active_label:
      points.length > 1
        ? ageLabel(points[points.length - 1].ts - points[0].ts).replace('قبل ', '')
        : '',
    stops_count: stops.length,
    road_matched: false,
    travel_segments: segments.length,
    presence_count: 0,
    avg_speed_kmh: null,
    max_speed_kmh: null,
    avg_speed_label: '',
    max_speed_label: '',
    coverage_note: points.length ? '' : 'لا نقاط لهذا اليوم.',
  };

  return empty;
}

async function listGpsTracks({ q: search = '', from = '', to = '', userId = 0 } = {}) {
  const where = ['1=1'];
  const params = [];
  if (from && to) {
    where.push('DATE(t.captured_at) BETWEEN ? AND ?');
    params.push(from, to);
  }
  if (userId > 0) {
    where.push('t.user_id = ?');
    params.push(userId);
  }
  if (search) {
    const like = `%${search}%`;
    where.push(`(u.username LIKE ? OR IFNULL(u.full_name_ar,'') LIKE ?)`);
    params.push(like, like);
  }
  return q(
    `SELECT t.id, t.user_id, t.latitude, t.longitude, t.gps_accuracy, t.gps_source, t.captured_at,
            u.username, u.full_name_ar
     FROM sys_user_location_track t
     LEFT JOIN sys_user u ON u.id = t.user_id
     WHERE ${where.join(' AND ')}
     ORDER BY t.captured_at DESC
     LIMIT 800`,
    params
  );
}

async function getGpsSettings() {
  try {
    const rows = await db.query(
      `SELECT gps_mobile_auto_enable, gps_mobile_interval_sec, gps_mobile_min_distance_m,
              gps_mobile_user_can_disable, gps_google_maps_api_key, gps_map_provider,
              gps_map_engine, sales_rep_visit_geofence
       FROM sys_company_settings WHERE id = 1 LIMIT 1`
    );
    const r = rows[0] || {};
    return {
      auto_enable: Number(r.gps_mobile_auto_enable) === 1,
      interval_sec: Number(r.gps_mobile_interval_sec) || 10,
      min_distance_m: Number(r.gps_mobile_min_distance_m) || 0,
      user_can_disable: Number(r.gps_mobile_user_can_disable) === 1,
      google_maps_api_key: String(r.gps_google_maps_api_key || ''),
      map_provider: String(r.gps_map_provider || 'esri'),
      map_engine: String(r.gps_map_engine || 'leaflet'),
      rep_visit_geofence: Number(r.sales_rep_visit_geofence) === 1,
    };
  } catch (e) {
    return {
      auto_enable: true,
      interval_sec: 10,
      min_distance_m: 0,
      user_can_disable: false,
      google_maps_api_key: '',
      map_provider: 'esri',
      map_engine: 'leaflet',
      rep_visit_geofence: false,
    };
  }
}

async function saveGpsSettings(payload) {
  const auto = payload.gps_mobile_auto_enable === '1' || payload.gps_mobile_auto_enable === 'on' ? 1 : 0;
  let interval = Number(payload.gps_mobile_interval_sec || 10);
  if (![10, 15, 30, 60, 120, 300].includes(interval)) interval = 10;
  let dist = Number(payload.gps_mobile_min_distance_m || 0);
  if (![0, 15, 30, 50, 100].includes(dist)) dist = 0;
  const canDisable =
    payload.gps_mobile_user_can_disable === '1' || payload.gps_mobile_user_can_disable === 'on' ? 1 : 0;
  const geofence =
    payload.sales_rep_visit_geofence === '1' || payload.sales_rep_visit_geofence === 'on' ? 1 : 0;
  let provider = String(payload.gps_map_provider || 'esri').toLowerCase();
  if (!['esri', 'carto', 'osm', 'google', 'natgeo'].includes(provider)) provider = 'esri';
  let engine = String(payload.gps_map_engine || 'leaflet').toLowerCase();
  if (!['leaflet', 'google'].includes(engine)) engine = 'leaflet';
  const gkey = String(payload.gps_google_maps_api_key || '').trim();
  try {
    await db.query(
      `UPDATE sys_company_settings SET
         gps_mobile_auto_enable = ?, gps_mobile_interval_sec = ?, gps_mobile_min_distance_m = ?,
         gps_mobile_user_can_disable = ?, sales_rep_visit_geofence = ?,
         gps_google_maps_api_key = ?, gps_map_provider = ?, gps_map_engine = ?, updated_at = NOW()
       WHERE id = 1`,
      [auto, interval, dist, canDisable, geofence, gkey || null, provider, engine]
    );
    return { ok: true, message: 'تم حفظ إعدادات تتبّع موقع تطبيق الهاتف.' };
  } catch (e) {
    return { ok: false, error: 'تعذر حفظ الإعدادات: ' + e.message };
  }
}

async function listInvoiceGps({ from, to, q: search = '' } = {}) {
  const where = [
    'i.post_latitude IS NOT NULL',
    'i.post_longitude IS NOT NULL',
    'i.invoice_date BETWEEN ? AND ?',
  ];
  const params = [from, to];
  if (search) {
    const like = `%${search}%`;
    where.push(
      `(i.invoice_no LIKE ? OR IFNULL(c.name_ar,'') LIKE ? OR IFNULL(i.post_gps_place,'') LIKE ?)`
    );
    params.push(like, like, like);
  }
  const rows = await q(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.post_latitude, i.post_longitude,
            i.post_gps_accuracy, i.post_gps_at, i.post_gps_place, c.name_ar AS customer_name
     FROM sal_invoice i
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.id DESC
     LIMIT 400`,
    params
  );
  return rows.map((r) => ({ ...r, map_url: mapUrl(r.post_latitude, r.post_longitude) }));
}

module.exports = {
  liveTrackerPayload,
  listUserLocations,
  listGpsTracks,
  trackUsers,
  trackDayPayload,
  getGpsSettings,
  saveGpsSettings,
  listInvoiceGps,
  mapUrl,
  sourceLabel,
};
