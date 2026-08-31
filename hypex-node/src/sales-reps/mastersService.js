'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('sales-reps masters', e.message);
    throw e;
  }
}

function nullIfEmpty(v) {
  const s = String(v || '').trim();
  return s === '' ? null : s;
}

function isIsoDate(s) {
  return /^\d{4}-\d{2}-\d{2}$/.test(String(s || ''));
}

/**
 * يقبل ISO أو dd-mm-yyyy أو dd/mm/yyyy أو yyyy/mm/dd (حسب لغة المتصفح) ويعيد ISO.
 */
function normalizeIsoDate(v) {
  const s = String(v == null ? '' : v).trim();
  if (s === '') return '';
  if (isIsoDate(s)) return s;
  const m = s.slice(0, 10).match(/^(\d{1,4})[-/.](\d{1,2})[-/.](\d{1,4})$/);
  if (!m) return '';
  let y;
  let mo;
  let d;
  if (m[1].length === 4) {
    y = m[1];
    mo = m[2];
    d = m[3];
  } else {
    d = m[1];
    mo = m[2];
    y = m[3];
  }
  if (String(y).length !== 4) return '';
  const iso = `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  return isIsoDate(iso) ? iso : '';
}

function nextDay(iso) {
  const d = new Date(String(iso) + 'T12:00:00');
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}

function isoToDmyLocal(v) {
  const s = String(v == null ? '' : v).slice(0, 10);
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? `${m[3]}-${m[2]}-${m[1]}` : s;
}

function daysBetween(from, to) {
  const out = [];
  if (!isIsoDate(from) || !isIsoDate(to) || from > to) return out;
  let cur = from;
  let guard = 0;
  while (cur <= to && guard < 400) {
    out.push(cur);
    cur = nextDay(cur);
    guard += 1;
  }
  return out;
}

/** 0=الأحد … 6=السبت (نفس Date#getDay) */
function weekdayOfIso(iso) {
  if (!isIsoDate(iso)) return null;
  return new Date(String(iso) + 'T12:00:00').getDay();
}

function monthBoundsIso(d = new Date()) {
  const y = d.getFullYear();
  const m = d.getMonth();
  const from = `${y}-${String(m + 1).padStart(2, '0')}-01`;
  const last = new Date(y, m + 1, 0).getDate();
  const to = `${y}-${String(m + 1).padStart(2, '0')}-${String(last).padStart(2, '0')}`;
  return { from, to };
}

const WEEKDAY_LABELS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

async function ensureWeekdayColumn() {
  try {
    await db.query(`SELECT weekday FROM sal_rep_tour_line LIMIT 1`);
    return true;
  } catch {
    try {
      await db.query(
        `ALTER TABLE sal_rep_tour_line ADD COLUMN weekday TINYINT UNSIGNED NOT NULL DEFAULT 0`
      );
    } catch {
      /* */
    }
  }
  try {
    await db.query(`ALTER TABLE sal_rep_tour_line DROP INDEX uq_sal_rep_tour_line`);
  } catch {
    /* */
  }
  try {
    await db.query(
      `ALTER TABLE sal_rep_tour_line ADD UNIQUE KEY uq_sal_rep_tour_line_wd (tour_id, customer_id, weekday)`
    );
  } catch {
    /* */
  }
  try {
    await db.query(`SELECT weekday FROM sal_rep_tour_line LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

let schemaReady = false;

async function ensureVisitColumns() {
  await ensureWeekdayColumn();
  for (const col of [
    ['sal_rep_tour_line', 'visit_checkin_at', 'DATETIME NULL DEFAULT NULL'],
    ['sal_rep_tour_line', 'visit_checkout_at', 'DATETIME NULL DEFAULT NULL'],
    ['sal_rep_tour_line', 'checkin_method', 'VARCHAR(20) NULL DEFAULT NULL'],
    ['sal_rep_tour_line', 'checkout_method', 'VARCHAR(20) NULL DEFAULT NULL'],
    ['sal_rep_route_line', 'visit_checkin_at', 'DATETIME NULL DEFAULT NULL'],
    ['sal_rep_route_line', 'visit_checkout_at', 'DATETIME NULL DEFAULT NULL'],
    ['sal_rep_route_line', 'checkin_method', 'VARCHAR(20) NULL DEFAULT NULL'],
    ['sal_rep_route_line', 'checkout_method', 'VARCHAR(20) NULL DEFAULT NULL'],
    ['sal_rep_route_line', 'in_plan', 'TINYINT(1) NOT NULL DEFAULT 1'],
  ]) {
    try {
      await db.query(`SELECT \`${col[1]}\` FROM \`${col[0]}\` LIMIT 1`);
    } catch {
      try {
        await db.query(`ALTER TABLE \`${col[0]}\` ADD COLUMN \`${col[1]}\` ${col[2]}`);
      } catch {
        /* table may not exist yet */
      }
    }
  }
}

async function ensureTourSchema() {
  if (schemaReady) {
    await ensureVisitColumns();
    return true;
  }
  try {
    await db.query(`SELECT id FROM sal_rep_tour LIMIT 1`);
    await ensureVisitColumns();
    schemaReady = true;
    return true;
  } catch {
    /* continue */
  }
  try {
    await db.query(`
      CREATE TABLE IF NOT EXISTS sal_rep_tour (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sales_rep_id INT UNSIGNED NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        notes VARCHAR(500) NULL DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        posted_at DATETIME NULL DEFAULT NULL,
        posted_by INT UNSIGNED NULL DEFAULT NULL,
        created_by INT UNSIGNED NULL DEFAULT NULL,
        updated_by INT UNSIGNED NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_sal_rep_tour_rep (sales_rep_id),
        KEY idx_sal_rep_tour_dates (date_from, date_to),
        KEY idx_sal_rep_tour_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
    await db.query(`
      CREATE TABLE IF NOT EXISTS sal_rep_tour_line (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tour_id INT UNSIGNED NOT NULL,
        customer_id INT UNSIGNED NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        region_id INT UNSIGNED NULL DEFAULT NULL,
        region_address_id INT UNSIGNED NULL DEFAULT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_sal_rep_tour_line (tour_id, customer_id),
        KEY idx_sal_rep_tour_line_cust (customer_id),
        KEY idx_sal_rep_tour_line_dates (date_from, date_to)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
    try {
      await db.query(
        `ALTER TABLE sal_rep_route ADD COLUMN tour_id INT UNSIGNED NULL DEFAULT NULL AFTER notes`
      );
    } catch {
      /* column may exist */
    }
    try {
      await db.query(`ALTER TABLE sal_rep_route ADD KEY idx_sal_rep_route_tour (tour_id)`);
    } catch {
      /* index may exist */
    }
    try {
      await db.query(`UPDATE sys_screen SET name_ar = 'جولات المندوبين' WHERE code = 'sales_rep_route'`);
    } catch {
      /* */
    }
    await ensureVisitColumns();
    schemaReady = true;
    return true;
  } catch (e) {
    console.error('ensureTourSchema', e.message);
    return false;
  }
}

async function getRep(id) {
  const rows = await safeQuery(`SELECT * FROM crm_sales_rep WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function nextRepCode() {
  const m = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM crm_sales_rep`);
  return 'REP-' + String(Number(m[0]?.m || 0) + 1).padStart(4, '0');
}

async function listWarehouses() {
  try {
    return await safeQuery(
      `SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar LIMIT 300`
    );
  } catch {
    return [];
  }
}

async function saveRep(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '').trim();
  const name = String(payload.name_ar || '').trim();
  const phone = nullIfEmpty(payload.phone);
  const address = nullIfEmpty(payload.address_ar);
  let warehouseId = Number(payload.warehouse_id || 0) || null;
  if (warehouseId !== null && warehouseId < 1) warehouseId = null;

  if (!name) return { ok: false, error: 'اسم المندوب مطلوب.' };
  if (!code) code = await nextRepCode();

  const dup = id
    ? await safeQuery(`SELECT id FROM crm_sales_rep WHERE code = ? AND id <> ? LIMIT 1`, [code, id])
    : await safeQuery(`SELECT id FROM crm_sales_rep WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) return { ok: false, error: 'رمز المندوب مستخدم مسبقاً.' };

  if (id > 0) {
    try {
      await safeQuery(
        `UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=?, warehouse_id=? WHERE id=?`,
        [code, name, phone, address, warehouseId, id]
      );
    } catch {
      await safeQuery(`UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=? WHERE id=?`, [
        code,
        name,
        phone,
        address,
        id,
      ]);
    }
    return { ok: true, id, message: 'تم تحديث بيانات المندوب.' };
  }

  try {
    const [result] = await db.getPool().execute(
      `INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, warehouse_id, is_active)
       VALUES (?,?,?,?,?,1)`,
      [code, name, phone, address, warehouseId]
    );
    return { ok: true, id: Number(result.insertId), message: 'تم إضافة المندوب.' };
  } catch {
    const [result] = await db.getPool().execute(
      `INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, is_active) VALUES (?,?,?,?,1)`,
      [code, name, phone, address]
    );
    return { ok: true, id: Number(result.insertId), message: 'تم إضافة المندوب.' };
  }
}

async function toggleRep(id) {
  const repId = Number(id);
  if (!repId) return { ok: false, error: 'معرّف غير صالح.' };
  await safeQuery(`UPDATE crm_sales_rep SET is_active = 1 - is_active WHERE id = ?`, [repId]);
  return { ok: true, message: 'تم تحديث حالة المندوب.' };
}

async function listRegionsSimple() {
  try {
    return await safeQuery(
      `SELECT id, code, name_ar FROM crm_region WHERE is_active = 1 ORDER BY sort_order, name_ar LIMIT 500`
    );
  } catch {
    return [];
  }
}

async function listAddressesForRegion(regionId) {
  const rid = Number(regionId || 0);
  if (rid < 1) return [];
  try {
    return await safeQuery(
      `SELECT id, region_id, name_ar FROM crm_region_address
       WHERE region_id = ? AND is_active = 1
       ORDER BY sort_order, name_ar LIMIT 500`,
      [rid]
    );
  } catch {
    return [];
  }
}

async function listTourCustomers({ salesRepId = 0, regionId = 0, regionAddressId = 0, q = '', limit = 400 } = {}) {
  const repId = Number(salesRepId || 0);
  // لا تعرض أي عملاء قبل اختيار المندوب — فقط العملاء التابعون له
  if (repId < 1) return [];

  const where = [
    'c.is_active = 1',
    `(
      c.sales_rep_id = ?
      OR EXISTS (
        SELECT 1 FROM crm_customer_sales_rep csr
        WHERE csr.customer_id = c.id AND csr.sales_rep_id = ?
      )
    )`,
  ];
  const params = [repId, repId];
  if (regionId > 0) {
    where.push('c.region_id = ?');
    params.push(regionId);
  }
  if (regionAddressId > 0) {
    where.push('c.region_address_id = ?');
    params.push(regionAddressId);
  }
  if (q) {
    const like = `%${String(q).trim()}%`;
    where.push(`(c.name_ar LIKE ? OR c.code LIKE ?)`);
    params.push(like, like);
  }
  try {
    return await safeQuery(
      `SELECT c.id, c.code, c.name_ar, c.sales_rep_id, c.region_id, c.region_address_id,
              COALESCE(rg.name_ar, '') AS region_name,
              COALESCE(ra.name_ar, '') AS address_name
       FROM crm_customer c
       LEFT JOIN crm_region rg ON rg.id = c.region_id
       LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id
       WHERE ${where.join(' AND ')}
       ORDER BY c.name_ar ASC
       LIMIT ${Math.min(500, Math.max(1, limit))}`,
      params
    );
  } catch (e) {
    // بدون جدول الربط m2m
    try {
      const where2 = ['c.is_active = 1', 'c.sales_rep_id = ?'];
      const params2 = [repId];
      if (regionId > 0) {
        where2.push('c.region_id = ?');
        params2.push(regionId);
      }
      if (regionAddressId > 0) {
        where2.push('c.region_address_id = ?');
        params2.push(regionAddressId);
      }
      if (q) {
        const like = `%${String(q).trim()}%`;
        where2.push(`(c.name_ar LIKE ? OR c.code LIKE ?)`);
        params2.push(like, like);
      }
      return await safeQuery(
        `SELECT c.id, c.code, c.name_ar, c.sales_rep_id, c.region_id, c.region_address_id,
                COALESCE(rg.name_ar, '') AS region_name,
                COALESCE(ra.name_ar, '') AS address_name
         FROM crm_customer c
         LEFT JOIN crm_region rg ON rg.id = c.region_id
         LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id
         WHERE ${where2.join(' AND ')}
         ORDER BY c.name_ar ASC
         LIMIT ${Math.min(500, Math.max(1, limit))}`,
        params2
      );
    } catch (e2) {
      console.error('listTourCustomers', e2.message);
      return [];
    }
  }
}

/* ── Tours (جولات) ── */
async function listTours({ salesRepId = 0, limit = 80 } = {}) {
  await ensureTourSchema();
  const params = [];
  let where = 't.is_active = 1';
  if (salesRepId > 0) {
    where += ' AND t.sales_rep_id = ?';
    params.push(Number(salesRepId));
  }
  try {
    return await safeQuery(
      `SELECT t.id, t.sales_rep_id, t.date_from, t.date_to, t.notes, t.status, t.posted_at,
              COALESCE(sr.name_ar, '') AS sales_rep_name,
              COALESCE(lc.cnt, 0) AS customer_count
       FROM sal_rep_tour t
       INNER JOIN crm_sales_rep sr ON sr.id = t.sales_rep_id
       LEFT JOIN (
         SELECT tour_id, COUNT(*) AS cnt FROM sal_rep_tour_line GROUP BY tour_id
       ) lc ON lc.tour_id = t.id
       WHERE ${where}
       ORDER BY t.date_from DESC, t.id DESC
       LIMIT ${Math.min(200, limit)}`,
      params
    );
  } catch (e) {
    console.error('listTours', e.message);
    return [];
  }
}

async function getTour(id) {
  await ensureTourSchema();
  const tourId = Number(id);
  if (!tourId) return null;
  try {
    const rows = await safeQuery(
      `SELECT t.*, COALESCE(sr.name_ar, '') AS sales_rep_name, COALESCE(sr.code, '') AS sales_rep_code
       FROM sal_rep_tour t
       INNER JOIN crm_sales_rep sr ON sr.id = t.sales_rep_id
       WHERE t.id = ? LIMIT 1`,
      [tourId]
    );
    if (!rows[0]) return null;
    const hasWd = await ensureWeekdayColumn();
    const lines = await safeQuery(
      hasWd
        ? `SELECT l.id, l.customer_id, l.date_from, l.date_to, l.region_id, l.region_address_id, l.sort_order,
                  l.weekday,
                  c.code AS customer_code, c.name_ar AS customer_name,
                  COALESCE(rg.name_ar, '') AS region_name,
                  COALESCE(ra.name_ar, '') AS address_name
           FROM sal_rep_tour_line l
           INNER JOIN crm_customer c ON c.id = l.customer_id
           LEFT JOIN crm_region rg ON rg.id = COALESCE(l.region_id, c.region_id)
           LEFT JOIN crm_region_address ra ON ra.id = COALESCE(l.region_address_id, c.region_address_id)
           WHERE l.tour_id = ?
           ORDER BY l.weekday, l.sort_order, l.id`
        : `SELECT l.id, l.customer_id, l.date_from, l.date_to, l.region_id, l.region_address_id, l.sort_order,
                  0 AS weekday,
                  c.code AS customer_code, c.name_ar AS customer_name,
                  COALESCE(rg.name_ar, '') AS region_name,
                  COALESCE(ra.name_ar, '') AS address_name
           FROM sal_rep_tour_line l
           INNER JOIN crm_customer c ON c.id = l.customer_id
           LEFT JOIN crm_region rg ON rg.id = COALESCE(l.region_id, c.region_id)
           LEFT JOIN crm_region_address ra ON ra.id = COALESCE(l.region_address_id, c.region_address_id)
           WHERE l.tour_id = ?
           ORDER BY l.sort_order, l.id`,
      [tourId]
    );
    return { ...rows[0], lines };
  } catch (e) {
    console.error('getTour', e.message);
    return null;
  }
}

function parseTourLines(payload, tourFrom, tourTo) {
  const lines = [];
  const rawLines = payload.lines;
  if (Array.isArray(rawLines)) {
    for (const ln of rawLines) {
      const customerId = Number(ln.customer_id || ln.id || 0);
      if (customerId < 1) continue;
      let wd = Number(ln.weekday);
      if (!Number.isFinite(wd) || wd < 0 || wd > 6) continue;
      wd = Math.floor(wd);
      lines.push({
        customer_id: customerId,
        date_from: tourFrom,
        date_to: tourTo,
        weekday: wd,
        region_id: Number(ln.region_id || 0) || null,
        region_address_id: Number(ln.region_address_id || 0) || null,
      });
    }
  } else {
    let custIds = [];
    if (Array.isArray(payload.customer_ids)) {
      custIds = payload.customer_ids.map(Number).filter((n) => n > 0);
    } else if (payload.customer_ids != null) {
      custIds = [Number(payload.customer_ids)].filter((n) => n > 0);
    }
    // بدون يوم = لا نضيف (يلزم اختيار اليوم)
    for (const cid of custIds) {
      lines.push({
        customer_id: cid,
        date_from: tourFrom,
        date_to: tourTo,
        weekday: 0,
        region_id: null,
        region_address_id: null,
      });
    }
  }
  const seen = new Set();
  return lines.filter((ln) => {
    const key = `${ln.customer_id}:${ln.weekday}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

async function rebuildDailyRoutes(conn, salesRepId, from, to) {
  const hasWd = await ensureWeekdayColumn();
  const days = daysBetween(from, to);
  for (const day of days) {
    const jsWd = weekdayOfIso(day);
    let custRows;
    if (hasWd) {
      // DAYOFWEEK: 1=Sun … 7=Sat → نفس JS getDay = DAYOFWEEK-1
      const [rows] = await conn.execute(
        `SELECT DISTINCT tl.customer_id, MIN(tl.sort_order) AS sort_order, MIN(t.id) AS tour_id
         FROM sal_rep_tour t
         INNER JOIN sal_rep_tour_line tl ON tl.tour_id = t.id
         WHERE t.sales_rep_id = ?
           AND t.status = 'posted'
           AND t.is_active = 1
           AND tl.date_from <= ?
           AND tl.date_to >= ?
           AND tl.weekday = ?
         GROUP BY tl.customer_id
         ORDER BY sort_order, tl.customer_id`,
        [salesRepId, day, day, jsWd]
      );
      custRows = rows;
    } else {
      const [rows] = await conn.execute(
        `SELECT DISTINCT tl.customer_id, MIN(tl.sort_order) AS sort_order, MIN(t.id) AS tour_id
         FROM sal_rep_tour t
         INNER JOIN sal_rep_tour_line tl ON tl.tour_id = t.id
         WHERE t.sales_rep_id = ?
           AND t.status = 'posted'
           AND t.is_active = 1
           AND tl.date_from <= ?
           AND tl.date_to >= ?
         GROUP BY tl.customer_id
         ORDER BY sort_order, tl.customer_id`,
        [salesRepId, day, day]
      );
      custRows = rows;
    }
    const [existRows] = await conn.execute(
      `SELECT id, tour_id FROM sal_rep_route WHERE sales_rep_id = ? AND route_date = ? LIMIT 1`,
      [salesRepId, day]
    );
    const existing = existRows[0] || null;

    if (!custRows.length) {
      if (existing && existing.tour_id != null) {
        await conn.execute(`DELETE FROM sal_rep_route WHERE id = ?`, [existing.id]);
      }
      continue;
    }

    let routeId = existing ? Number(existing.id) : 0;
    const tourId = Number(custRows[0].tour_id || 0) || null;
    if (routeId > 0) {
      try {
        await conn.execute(
          `UPDATE sal_rep_route SET is_active=1, tour_id=?, notes=COALESCE(notes, 'جولة مندوب'), updated_at=NOW() WHERE id=?`,
          [tourId, routeId]
        );
      } catch {
        await conn.execute(`UPDATE sal_rep_route SET is_active=1 WHERE id=?`, [routeId]);
      }
      await conn.execute(`DELETE FROM sal_rep_route_line WHERE route_id = ?`, [routeId]);
    } else {
      try {
        const [ins] = await conn.execute(
          `INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, tour_id, is_active)
           VALUES (?,?,?,?,1)`,
          [salesRepId, day, 'جولة مندوب', tourId]
        );
        routeId = Number(ins.insertId);
      } catch {
        const [ins] = await conn.execute(
          `INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, is_active) VALUES (?,?,?,1)`,
          [salesRepId, day, 'جولة مندوب']
        );
        routeId = Number(ins.insertId);
      }
    }

    let sort = 0;
    for (const c of custRows) {
      await conn.execute(
        `INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order, in_plan) VALUES (?,?,?,1)`,
        [routeId, Number(c.customer_id), sort++]
      );
    }
  }
}

async function saveTour(payload, userId) {
  await ensureTourSchema();
  const id = Number(payload.id || 0);
  const salesRepId = Number(payload.sales_rep_id || 0);
  let dateFrom = normalizeIsoDate(payload.date_from);
  let dateTo = normalizeIsoDate(payload.date_to);
  const notes = nullIfEmpty(payload.notes);

  if (salesRepId < 1) return { ok: false, error: 'اختر المندوب.' };
  // إن غاب أحد التاريخين نكمله من حدود الشهر (الفترة الافتراضية للجولة)
  if (dateFrom === '' || dateTo === '') {
    const bounds = monthBoundsIso(
      dateFrom || dateTo ? new Date(`${dateFrom || dateTo}T12:00:00`) : new Date()
    );
    if (dateFrom === '') dateFrom = bounds.from;
    if (dateTo === '') dateTo = bounds.to;
  }
  if (!isIsoDate(dateFrom) || !isIsoDate(dateTo)) return { ok: false, error: 'حدد تاريخ البداية والنهاية.' };
  if (dateFrom > dateTo) {
    const tmp = dateFrom;
    dateFrom = dateTo;
    dateTo = tmp;
  }
  const span = daysBetween(dateFrom, dateTo).length;
  if (span < 1 || span > 93) return { ok: false, error: 'مدة الجولة يجب أن تكون بين يوم و 93 يوماً.' };

  const lines = parseTourLines(payload, dateFrom, dateTo);
  if (!lines.length) {
    return { ok: false, error: 'اختر يوماً من أيام الأسبوع، ثم أضف عميلاً واحداً على الأقل لذلك اليوم.' };
  }

  const rep = await safeQuery(`SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1`, [
    salesRepId,
  ]);
  if (!rep[0]) return { ok: false, error: 'المندوب غير موجود أو غير نشط.' };

  // منع تداخل الفترات: لا يجوز أن تتقاطع جولة هذا المندوب مع جولة أخرى له (تداخل الفترات)
  // شرط التداخل: existing.date_from <= new.date_to AND existing.date_to >= new.date_from
  const overlap = await safeQuery(
    `SELECT id, date_from, date_to, status
     FROM sal_rep_tour
     WHERE sales_rep_id = ?
       AND is_active = 1
       AND id <> ?
       AND date_from <= ?
       AND date_to >= ?
     ORDER BY date_from ASC
     LIMIT 1`,
    [salesRepId, id || 0, dateTo, dateFrom]
  );
  if (overlap[0]) {
    const o = overlap[0];
    const f = isoToDmyLocal(o.date_from);
    const t = isoToDmyLocal(o.date_to);
    return {
      ok: false,
      error: `تتداخل الفترة مع جولة أخرى لنفس المندوب (#${o.id} · ${f} → ${t}). اختر فترة لا تتقاطع معها.`,
    };
  }

  await ensureWeekdayColumn();

  // fill region/address from customer if missing
  for (const ln of lines) {
    if (!ln.region_id || !ln.region_address_id) {
      const cust = await safeQuery(
        `SELECT region_id, region_address_id FROM crm_customer WHERE id = ? LIMIT 1`,
        [ln.customer_id]
      );
      if (cust[0]) {
        if (!ln.region_id) ln.region_id = Number(cust[0].region_id || 0) || null;
        if (!ln.region_address_id) ln.region_address_id = Number(cust[0].region_address_id || 0) || null;
      }
    }
  }

  const conn = await db.getPool().getConnection();
  try {
    await conn.beginTransaction();
    let tourId = id;
    if (tourId > 0) {
      const [cur] = await conn.execute(`SELECT id, status FROM sal_rep_tour WHERE id = ? LIMIT 1`, [tourId]);
      if (!cur[0]) {
        await conn.rollback();
        return { ok: false, error: 'الجولة غير موجودة.' };
      }
      if (String(cur[0].status) === 'posted') {
        await conn.rollback();
        return { ok: false, error: 'الجولة مرحّلة — فك الترحيل أولاً للتعديل.' };
      }
      await conn.execute(
        `UPDATE sal_rep_tour SET sales_rep_id=?, date_from=?, date_to=?, notes=?, updated_by=?, is_active=1, status='draft'
         WHERE id=?`,
        [salesRepId, dateFrom, dateTo, notes, userId || null, tourId]
      );
      await conn.execute(`DELETE FROM sal_rep_tour_line WHERE tour_id = ?`, [tourId]);
    } else {
      const [ins] = await conn.execute(
        `INSERT INTO sal_rep_tour (sales_rep_id, date_from, date_to, notes, status, is_active, created_by, updated_by)
         VALUES (?,?,?,?, 'draft', 1, ?, ?)`,
        [salesRepId, dateFrom, dateTo, notes, userId || null, userId || null]
      );
      tourId = Number(ins.insertId);
    }

    let sort = 0;
    for (const ln of lines) {
      try {
        await conn.execute(
          `INSERT INTO sal_rep_tour_line
             (tour_id, customer_id, date_from, date_to, region_id, region_address_id, sort_order, weekday)
           VALUES (?,?,?,?,?,?,?,?)`,
          [
            tourId,
            ln.customer_id,
            ln.date_from,
            ln.date_to,
            ln.region_id,
            ln.region_address_id,
            sort++,
            ln.weekday,
          ]
        );
      } catch {
        await conn.execute(
          `INSERT INTO sal_rep_tour_line
             (tour_id, customer_id, date_from, date_to, region_id, region_address_id, sort_order)
           VALUES (?,?,?,?,?,?,?)`,
          [
            tourId,
            ln.customer_id,
            ln.date_from,
            ln.date_to,
            ln.region_id,
            ln.region_address_id,
            sort++,
          ]
        );
      }
    }
    await conn.commit();
    return {
      ok: true,
      id: tourId,
      message: `تم حفظ الجولة (مسودة) · ${lines.length} تعيين · ${dateFrom} → ${dateTo}`,
    };
  } catch (e) {
    await conn.rollback();
    console.error('saveTour', e.message);
    return { ok: false, error: 'تعذر حفظ الجولة. تأكد من تشغيل ترحيل قاعدة البيانات 267.' };
  } finally {
    conn.release();
  }
}

async function postTour(id, userId) {
  await ensureTourSchema();
  const tourId = Number(id);
  if (!tourId) return { ok: false, error: 'معرّف غير صالح.' };
  const tour = await getTour(tourId);
  if (!tour) return { ok: false, error: 'الجولة غير موجودة.' };
  if (String(tour.status) === 'posted') return { ok: false, error: 'الجولة مرحّلة مسبقاً.' };
  if (!(tour.lines || []).length) return { ok: false, error: 'لا يمكن ترحيل جولة بلا عملاء.' };

  const conn = await db.getPool().getConnection();
  try {
    await conn.beginTransaction();
    await conn.execute(
      `UPDATE sal_rep_tour SET status='posted', posted_at=NOW(), posted_by=?, updated_by=? WHERE id=?`,
      [userId || null, userId || null, tourId]
    );
    await rebuildDailyRoutes(conn, Number(tour.sales_rep_id), String(tour.date_from).slice(0, 10), String(tour.date_to).slice(0, 10));
    await conn.commit();
    return { ok: true, id: tourId, message: 'تم ترحيل الجولة وربطها بخط السير اليومي لتطبيق المندوب.' };
  } catch (e) {
    await conn.rollback();
    console.error('postTour', e.message);
    return { ok: false, error: 'تعذر ترحيل الجولة: ' + (e.message || '') };
  } finally {
    conn.release();
  }
}

async function unpostTour(id, userId) {
  await ensureTourSchema();
  const tourId = Number(id);
  if (!tourId) return { ok: false, error: 'معرّف غير صالح.' };
  const tour = await getTour(tourId);
  if (!tour) return { ok: false, error: 'الجولة غير موجودة.' };
  if (String(tour.status) !== 'posted') return { ok: false, error: 'الجولة ليست مرحّلة.' };

  const conn = await db.getPool().getConnection();
  try {
    await conn.beginTransaction();
    await conn.execute(
      `UPDATE sal_rep_tour SET status='draft', posted_at=NULL, posted_by=NULL, updated_by=? WHERE id=?`,
      [userId || null, tourId]
    );
    await rebuildDailyRoutes(
      conn,
      Number(tour.sales_rep_id),
      String(tour.date_from).slice(0, 10),
      String(tour.date_to).slice(0, 10)
    );
    await conn.commit();
    return { ok: true, id: tourId, message: 'تم فك ترحيل الجولة.' };
  } catch (e) {
    await conn.rollback();
    console.error('unpostTour', e.message);
    return { ok: false, error: 'تعذر فك الترحيل: ' + (e.message || '') };
  } finally {
    conn.release();
  }
}

async function deleteTour(id) {
  await ensureTourSchema();
  const tourId = Number(id);
  if (!tourId) return { ok: false, error: 'معرّف غير صالح.' };
  const tour = await getTour(tourId);
  if (!tour) return { ok: false, error: 'الجولة غير موجودة.' };
  if (String(tour.status) === 'posted') {
    return { ok: false, error: 'لا يمكن حذف جولة مرحّلة — فك الترحيل أولاً.' };
  }
  try {
    await safeQuery(`DELETE FROM sal_rep_tour WHERE id = ?`, [tourId]);
    return { ok: true, message: 'تم حذف الجولة.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  }
}

/** تفاصيل الطباعة: العملاء مجمّعون حسب يوم الأسبوع للمندوب */
async function getTourPrintRows(id) {
  const tour = await getTour(id);
  if (!tour) return null;
  const tourFrom = String(tour.date_from || '').slice(0, 10);
  const tourTo = String(tour.date_to || '').slice(0, 10);
  const days = daysBetween(tourFrom, tourTo);
  const datesByWd = new Map();
  for (const day of days) {
    const wd = weekdayOfIso(day);
    if (wd == null) continue;
    if (!datesByWd.has(wd)) datesByWd.set(wd, []);
    datesByWd.get(wd).push(day);
  }
  const byWd = new Map();
  for (const ln of tour.lines || []) {
    let wd = Number(ln.weekday);
    if (!Number.isFinite(wd) || wd < 0 || wd > 6) wd = 0;
    if (!byWd.has(wd)) byWd.set(wd, []);
    byWd.get(wd).push({
      customer_code: ln.customer_code || '',
      customer_name: ln.customer_name || '',
      region_name: ln.region_name || '',
      address_name: ln.address_name || '',
    });
  }
  for (const list of byWd.values()) {
    list.sort((a, b) => String(a.customer_name).localeCompare(String(b.customer_name), 'ar'));
  }
  const groups = [];
  for (let wd = 0; wd <= 6; wd++) {
    const customers = byWd.get(wd) || [];
    if (!customers.length) continue;
    groups.push({
      weekday: wd,
      weekday_label: WEEKDAY_LABELS[wd] || '',
      dates: datesByWd.get(wd) || [],
      customers,
    });
  }
  const rows = [];
  for (const g of groups) {
    for (const c of g.customers) {
      rows.push({
        ...c,
        weekday_label: g.weekday_label,
        visit_date: g.dates[0] || tourFrom,
      });
    }
  }
  return { tour, groups, rows, day_count: days.length };
}

/**
 * تقرير الجولات: كل بنود الجولات المُنشأة
 * أعمدة الدخول/الخروج وطريقة GPS جاهزة وتُقرأ إن وُجدت (آيباد لاحقاً)
 */
async function reportTours({ from = '', to = '', salesRepId = 0, status = '', limit = 800 } = {}) {
  await ensureTourSchema();
  // أعمدة الزيارة قد تُضاف بعد إنشاء الجدول — تأكد
  for (const col of [
    ['sal_rep_tour_line', 'visit_checkin_at', 'DATETIME NULL DEFAULT NULL'],
    ['sal_rep_tour_line', 'visit_checkout_at', 'DATETIME NULL DEFAULT NULL'],
    ['sal_rep_tour_line', 'checkin_method', 'VARCHAR(20) NULL DEFAULT NULL'],
    ['sal_rep_tour_line', 'checkout_method', 'VARCHAR(20) NULL DEFAULT NULL'],
  ]) {
    try {
      await db.query(`SELECT \`${col[1]}\` FROM \`${col[0]}\` LIMIT 1`);
    } catch {
      try {
        await db.query(`ALTER TABLE \`${col[0]}\` ADD COLUMN \`${col[1]}\` ${col[2]}`);
      } catch {
        /* */
      }
    }
  }

  const where = ['t.is_active = 1'];
  const params = [];
  if (from) {
    where.push('t.date_to >= ?');
    params.push(from);
  }
  if (to) {
    where.push('t.date_from <= ?');
    params.push(to);
  }
  if (Number(salesRepId) > 0) {
    where.push('t.sales_rep_id = ?');
    params.push(Number(salesRepId));
  }
  if (status === 'draft' || status === 'posted') {
    where.push('t.status = ?');
    params.push(status);
  }

  let hasVisitCols = true;
  try {
    await db.query(
      `SELECT visit_checkin_at, visit_checkout_at, checkin_method, checkout_method
       FROM sal_rep_tour_line LIMIT 1`
    );
  } catch {
    hasVisitCols = false;
  }

  const visitSel = hasVisitCols
    ? `l.visit_checkin_at, l.visit_checkout_at, l.checkin_method, l.checkout_method`
    : `NULL AS visit_checkin_at, NULL AS visit_checkout_at,
       NULL AS checkin_method, NULL AS checkout_method`;

  // إن وُجد تنفيذ يومي لاحقاً: نأخذ أحدث دخول/خروج مسجّل لخط السير المربوط بالجولة
  const dailyJoin = hasVisitCols
    ? `LEFT JOIN (
         SELECT r.tour_id, rl.customer_id,
                MIN(rl.visit_checkin_at) AS daily_checkin_at,
                MAX(rl.visit_checkout_at) AS daily_checkout_at,
                MAX(rl.checkin_method) AS daily_checkin_method,
                MAX(rl.checkout_method) AS daily_checkout_method
         FROM sal_rep_route r
         INNER JOIN sal_rep_route_line rl ON rl.route_id = r.id
         WHERE r.tour_id IS NOT NULL
         GROUP BY r.tour_id, rl.customer_id
       ) dv ON dv.tour_id = t.id AND dv.customer_id = l.customer_id`
    : '';

  const visitOut = hasVisitCols
    ? `COALESCE(l.visit_checkin_at, dv.daily_checkin_at) AS visit_checkin_at,
       COALESCE(l.visit_checkout_at, dv.daily_checkout_at) AS visit_checkout_at,
       COALESCE(NULLIF(TRIM(l.checkin_method),''), NULLIF(TRIM(dv.daily_checkin_method),'')) AS checkin_method,
       COALESCE(NULLIF(TRIM(l.checkout_method),''), NULLIF(TRIM(dv.daily_checkout_method),'')) AS checkout_method`
    : visitSel;

  try {
    return await safeQuery(
      `SELECT t.id AS tour_id, t.date_from, t.date_to, t.status, t.notes, t.created_at,
              t.sales_rep_id,
              COALESCE(sr.name_ar, '') AS sales_rep_name,
              COALESCE(sr.code, '') AS sales_rep_code,
              l.id AS line_id, l.customer_id, l.sort_order,
              c.code AS customer_code, c.name_ar AS customer_name,
              COALESCE(rg.name_ar, '') AS region_name,
              COALESCE(ra.name_ar, '') AS address_name,
              ${visitOut}
       FROM sal_rep_tour t
       INNER JOIN crm_sales_rep sr ON sr.id = t.sales_rep_id
       INNER JOIN sal_rep_tour_line l ON l.tour_id = t.id
       INNER JOIN crm_customer c ON c.id = l.customer_id
       LEFT JOIN crm_region rg ON rg.id = COALESCE(l.region_id, c.region_id)
       LEFT JOIN crm_region_address ra ON ra.id = COALESCE(l.region_address_id, c.region_address_id)
       ${dailyJoin}
       WHERE ${where.join(' AND ')}
       ORDER BY t.date_from DESC, t.id DESC, l.sort_order, c.name_ar
       LIMIT ${Math.min(2000, Number(limit) || 800)}`,
      params
    );
  } catch (e) {
    console.error('reportTours', e.message);
    // fallback بدون join اليومي
    try {
      return await safeQuery(
        `SELECT t.id AS tour_id, t.date_from, t.date_to, t.status, t.notes, t.created_at,
                t.sales_rep_id,
                COALESCE(sr.name_ar, '') AS sales_rep_name,
                COALESCE(sr.code, '') AS sales_rep_code,
                l.id AS line_id, l.customer_id, l.sort_order,
                c.code AS customer_code, c.name_ar AS customer_name,
                COALESCE(rg.name_ar, '') AS region_name,
                COALESCE(ra.name_ar, '') AS address_name,
                NULL AS visit_checkin_at, NULL AS visit_checkout_at,
                NULL AS checkin_method, NULL AS checkout_method
         FROM sal_rep_tour t
         INNER JOIN crm_sales_rep sr ON sr.id = t.sales_rep_id
         INNER JOIN sal_rep_tour_line l ON l.tour_id = t.id
         INNER JOIN crm_customer c ON c.id = l.customer_id
         LEFT JOIN crm_region rg ON rg.id = COALESCE(l.region_id, c.region_id)
         LEFT JOIN crm_region_address ra ON ra.id = COALESCE(l.region_address_id, c.region_address_id)
         WHERE ${where.join(' AND ')}
         ORDER BY t.date_from DESC, t.id DESC, l.sort_order, c.name_ar
         LIMIT ${Math.min(2000, Number(limit) || 800)}`,
        params
      );
    } catch (e2) {
      console.error('reportTours fallback', e2.message);
      return [];
    }
  }
}

async function reportVisits({
  from = '',
  to = '',
  salesRepId = 0,
  customerId = 0,
  method = '',
  status = '',
  limit = 1500,
} = {}) {
  await ensureTourSchema();
  const where = ['l.visit_checkin_at IS NOT NULL'];
  const params = [];
  if (from) {
    where.push('r.route_date >= ?');
    params.push(from);
  }
  if (to) {
    where.push('r.route_date <= ?');
    params.push(to);
  }
  if (Number(salesRepId) > 0) {
    where.push('r.sales_rep_id = ?');
    params.push(Number(salesRepId));
  }
  if (Number(customerId) > 0) {
    where.push('l.customer_id = ?');
    params.push(Number(customerId));
  }
  const m = String(method || '').trim().toUpperCase();
  if (m === 'GPS' || m === 'MANUAL') {
    where.push('(UPPER(IFNULL(l.checkin_method,\'\')) = ? OR UPPER(IFNULL(l.checkout_method,\'\')) = ?)');
    params.push(m, m);
  }
  if (status === 'open') {
    where.push('l.visit_checkout_at IS NULL');
    where.push('q.id IS NULL');
  } else if (status === 'closed') {
    where.push('l.visit_checkout_at IS NOT NULL');
  } else if (status === 'pending') {
    where.push('q.id IS NOT NULL');
    where.push('l.visit_checkout_at IS NULL');
  }
  try {
    return await safeQuery(
      `SELECT l.id AS line_id, r.route_date, r.sales_rep_id,
              COALESCE(sr.name_ar,'') AS sales_rep_name, COALESCE(sr.code,'') AS sales_rep_code,
              l.customer_id, c.code AS customer_code, c.name_ar AS customer_name,
              COALESCE(rg.name_ar,'') AS region_name,
              COALESCE(ra.name_ar,'') AS address_name,
              l.visit_checkin_at, l.visit_checkout_at,
              l.checkin_method, l.checkout_method,
              l.checkin_lat, l.checkin_lng, l.checkin_accuracy, l.checkin_distance_m,
              l.checkout_lat, l.checkout_lng, l.checkout_accuracy, l.checkout_distance_m,
              COALESCE(l.in_plan, 1) AS in_plan,
              (
                SELECT GROUP_CONCAT(nr.name_ar ORDER BY nr.sort_order, nr.id SEPARATOR '، ')
                FROM sal_rep_visit_no_order_reason vr
                INNER JOIN sal_no_order_reason nr ON nr.id = vr.reason_id
                WHERE vr.route_line_id = l.id
              ) AS no_order_reasons,
              (
                SELECT COUNT(*)
                FROM sal_customer_order o
                WHERE o.visit_route_line_id = l.id
                  AND o.created_at >= l.visit_checkin_at
              ) AS order_count,
              (
                SELECT COALESCE(SUM(o.total), 0)
                FROM sal_customer_order o
                WHERE o.visit_route_line_id = l.id
                  AND o.created_at >= l.visit_checkin_at
              ) AS order_total,
              q.id AS pending_request_id, q.reason AS checkout_reason
       FROM sal_rep_route_line l
       INNER JOIN sal_rep_route r ON r.id = l.route_id
       INNER JOIN crm_customer c ON c.id = l.customer_id
       LEFT JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
       LEFT JOIN crm_region rg ON rg.id = c.region_id
       LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id
       LEFT JOIN (
         SELECT route_line_id, MAX(id) AS id
         FROM sal_rep_visit_checkout_request
         WHERE status = 'pending'
         GROUP BY route_line_id
       ) qp ON qp.route_line_id = l.id
       LEFT JOIN sal_rep_visit_checkout_request q ON q.id = qp.id
       WHERE ${where.join(' AND ')}
       ORDER BY r.route_date DESC, l.visit_checkin_at DESC, l.id DESC
       LIMIT ${Math.min(2000, Number(limit) || 1500)}`,
      params
    );
  } catch (e) {
    console.error('reportVisits', e.message);
    return [];
  }
}

async function listVisitReportCustomers({ from = '', to = '' } = {}) {
  await ensureTourSchema();
  const where = ['l.visit_checkin_at IS NOT NULL'];
  const params = [];
  if (from) {
    where.push('r.route_date >= ?');
    params.push(from);
  }
  if (to) {
    where.push('r.route_date <= ?');
    params.push(to);
  }
  try {
    return await safeQuery(
      `SELECT DISTINCT c.id, c.name_ar
       FROM sal_rep_route_line l
       INNER JOIN sal_rep_route r ON r.id = l.route_id
       INNER JOIN crm_customer c ON c.id = l.customer_id
       WHERE ${where.join(' AND ')}
       ORDER BY c.name_ar
       LIMIT 500`,
      params
    );
  } catch (e) {
    console.error('listVisitReportCustomers', e.message);
    return [];
  }
}

async function listVisitCheckoutRequests(status = 'pending', limit = 200) {
  const st = ['pending', 'approved', 'rejected', 'all'].includes(String(status))
    ? String(status)
    : 'pending';
  const lim = Math.min(300, Math.max(1, Number(limit) || 200));
  const where = st === 'all' ? '' : 'WHERE q.status = ?';
  const params = st === 'all' ? [] : [st];
  try {
    return await safeQuery(
      `SELECT q.*, c.name_ar AS customer_name, c.code AS customer_code,
              COALESCE(sr.name_ar,'') AS sales_rep_name,
              COALESCE(u.username,'') AS requested_by_name,
              COALESCE(du.username,'') AS decided_by_name
       FROM sal_rep_visit_checkout_request q
       INNER JOIN crm_customer c ON c.id = q.customer_id
       LEFT JOIN crm_sales_rep sr ON sr.id = q.sales_rep_id
       LEFT JOIN sys_user u ON u.id = q.requested_by
       LEFT JOIN sys_user du ON du.id = q.decided_by
       ${where}
       ORDER BY q.id DESC
       LIMIT ${lim}`,
      params
    );
  } catch (e) {
    console.error('listVisitCheckoutRequests', e.message);
    return [];
  }
}

async function decideVisitCheckoutRequest({ id, approve, userId, note = null }) {
  const db = require('../db');
  const reqId = Number(id) || 0;
  const uid = Number(userId) || 0;
  if (reqId < 1) return { ok: false, message: 'طلب غير صالح.' };
  let rows;
  try {
    rows = await db.query('SELECT * FROM sal_rep_visit_checkout_request WHERE id=? LIMIT 1', [reqId]);
  } catch (e) {
    return { ok: false, message: 'جدول طلبات الخروج غير جاهز بعد.' };
  }
  const req = rows && rows[0];
  if (!req) return { ok: false, message: 'الطلب غير موجود.' };
  if (String(req.status || '') !== 'pending') {
    return { ok: false, message: 'تمت معالجة هذا الطلب مسبقاً.' };
  }
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  if (!approve) {
    await db.query(
      `UPDATE sal_rep_visit_checkout_request
       SET status='rejected', decided_by=?, decided_at=?, decision_note=? WHERE id=?`,
      [uid, now, note, reqId]
    );
    await notifyVisitCheckoutDecision(req, false);
    return { ok: true, message: 'تم رفض طلب الخروج اليدوي.' };
  }
  const lineId = Number(req.route_line_id) || 0;
  let lineRows = [];
  try {
    lineRows = await db.query(
      `SELECT l.*, r.sales_rep_id, r.route_date
       FROM sal_rep_route_line l
       INNER JOIN sal_rep_route r ON r.id = l.route_id
       WHERE l.id=? LIMIT 1`,
      [lineId]
    );
  } catch (e) {
    lineRows = [];
  }
  const line = lineRows[0];
  if (!line || !line.visit_checkin_at) {
    await db.query(
      `UPDATE sal_rep_visit_checkout_request
       SET status='rejected', decided_by=?, decided_at=?, decision_note=? WHERE id=?`,
      [uid, now, 'لا يوجد دخول مفتوح', reqId]
    );
    await notifyVisitCheckoutDecision(req, false);
    return { ok: false, message: 'لا يوجد دخول مفتوح مرتبط بالطلب.' };
  }
  if (!line.visit_checkout_at) {
    await db.query(
      `UPDATE sal_rep_route_line SET
          visit_checkout_at=?, checkout_method='MANUAL',
          checkout_lat=?, checkout_lng=?, checkout_accuracy=?, checkout_distance_m=?
       WHERE id=? AND visit_checkout_at IS NULL`,
      [
        now,
        req.request_lat,
        req.request_lng,
        req.request_accuracy,
        req.request_distance_m,
        lineId,
      ]
    );
  }
  await db.query(
    `UPDATE sal_rep_visit_checkout_request
     SET status='approved', decided_by=?, decided_at=?, decision_note=? WHERE id=?`,
    [uid, now, note, reqId]
  );
  await notifyVisitCheckoutDecision(req, true);
  return { ok: true, message: 'تمت الموافقة وتسجيل الخروج اليدوي.' };
}

async function notifyVisitCheckoutDecision(req, approve) {
  try {
    const inbox = require('../notifications/userInbox');
    await inbox.pushCheckoutDecision(req, !!approve);
  } catch (e) {
    console.error('checkout inbox notify', e.message);
  }
}

async function listGpsChangeRequests(status = 'pending', limit = 200) {
  const st = ['pending', 'approved', 'rejected', 'all'].includes(String(status))
    ? String(status)
    : 'pending';
  const lim = Math.min(300, Math.max(1, Number(limit) || 200));
  const where = st === 'all' ? '' : 'WHERE q.status = ?';
  const params = st === 'all' ? [] : [st];
  try {
    return await safeQuery(
      `SELECT q.*, c.name_ar AS customer_name, c.code AS customer_code,
              COALESCE(sr.name_ar,'') AS sales_rep_name,
              COALESCE(u.username,'') AS requested_by_name,
              COALESCE(du.username,'') AS decided_by_name
       FROM crm_customer_gps_change q
       INNER JOIN crm_customer c ON c.id = q.customer_id
       LEFT JOIN crm_sales_rep sr ON sr.id = q.sales_rep_id
       LEFT JOIN sys_user u ON u.id = q.requested_by
       LEFT JOIN sys_user du ON du.id = q.decided_by
       ${where}
       ORDER BY q.created_at DESC, q.id DESC
       LIMIT ${lim}`,
      params
    );
  } catch (e) {
    console.error('listGpsChangeRequests', e.message);
    return [];
  }
}

async function decideGpsChangeRequest({ id, approve, userId, note = null }) {
  const reqId = Number(id) || 0;
  const uid = Number(userId) || 0;
  if (reqId < 1) return { ok: false, message: 'طلب غير صالح.' };
  const conn = await db.getPool().getConnection();
  try {
    const [rows] = await conn.execute(
      'SELECT * FROM crm_customer_gps_change WHERE id=? LIMIT 1',
      [reqId]
    );
    const req = rows && rows[0];
    if (!req) return { ok: false, message: 'الطلب غير موجود.' };
    if (String(req.status || '') !== 'pending') {
      return { ok: false, message: 'هذا الطلب سبق البت فيه.' };
    }
    await conn.beginTransaction();
    if (approve) {
      if (Number(req.clear_gps) === 1) {
        await conn.execute(
          'UPDATE crm_customer SET latitude=NULL, longitude=NULL, gps_accuracy=NULL, gps_at=NULL WHERE id=?',
          [req.customer_id]
        );
      } else {
        await conn.execute(
          'UPDATE crm_customer SET latitude=?, longitude=?, gps_accuracy=?, gps_at=NOW() WHERE id=?',
          [req.new_latitude, req.new_longitude, req.new_accuracy, req.customer_id]
        );
      }
    }
    const noteVal = note != null && String(note).trim() !== '' ? String(note).trim() : null;
    await conn.execute(
      `UPDATE crm_customer_gps_change
       SET status=?, decided_by=?, decided_at=NOW(), decision_note=?
       WHERE id=?`,
      [approve ? 'approved' : 'rejected', uid, noteVal, reqId]
    );
    await conn.commit();
    try {
      const inbox = require('../notifications/userInbox');
      await inbox.pushGpsDecision(req, !!approve);
    } catch (e) {
      console.error('gps inbox notify', e.message);
    }
    return {
      ok: true,
      message: approve ? 'تم اعتماد موقع العميل.' : 'تم رفض تعديل الموقع.',
    };
  } catch (e) {
    try {
      await conn.rollback();
    } catch (_) {}
    console.error('decideGpsChangeRequest', e.message);
    return { ok: false, message: 'تعذر حفظ القرار.' };
  } finally {
    conn.release();
  }
}

async function countVisitOrdersConn(conn, routeLineId) {
  const lineId = Number(routeLineId);
  if (!lineId) return 0;
  try {
    const [rows] = await conn.execute(
      `SELECT COUNT(*) AS c
       FROM sal_customer_order o
       INNER JOIN sal_rep_route_line l ON l.id = o.visit_route_line_id
       WHERE o.visit_route_line_id = ?
         AND l.visit_checkin_at IS NOT NULL
         AND o.created_at >= l.visit_checkin_at`,
      [lineId]
    );
    return Number(rows[0]?.c || 0);
  } catch {
    return 0;
  }
}

async function resetVisitLineConn(conn, routeLineId, note) {
  const lineId = Number(routeLineId);
  if (!lineId) return false;
  const [lines] = await conn.execute(
    `SELECT l.id, l.visit_checkin_at
     FROM sal_rep_route_line l
     WHERE l.id = ? LIMIT 1`,
    [lineId]
  );
  const line = lines[0];
  if (!line || !line.visit_checkin_at) return false;
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const decisionNote = note || 'حذف من تقرير الزيارات';
  try {
    await conn.execute(`DELETE FROM sal_rep_visit_no_order_reason WHERE route_line_id = ?`, [lineId]);
  } catch {
    /* ignore */
  }
  try {
    await conn.execute(
      `UPDATE sal_rep_visit_checkout_request
       SET status='rejected', decided_at=?, decision_note=?
       WHERE route_line_id=? AND status='pending'`,
      [now, decisionNote, lineId]
    );
  } catch {
    /* ignore */
  }
  await conn.execute(
    `UPDATE sal_rep_route_line SET
       visit_checkin_at=NULL, visit_checkout_at=NULL,
       checkin_method=NULL, checkout_method=NULL,
       checkin_lat=NULL, checkin_lng=NULL, checkin_accuracy=NULL, checkin_distance_m=NULL,
       checkout_lat=NULL, checkout_lng=NULL, checkout_accuracy=NULL, checkout_distance_m=NULL
     WHERE id=?`,
    [lineId]
  );
  return true;
}

async function deleteVisitLines(lineIds) {
  const ids = [...new Set((lineIds || []).map((v) => Number(v)).filter((v) => v > 0))];
  if (!ids.length) {
    return { ok: false, deleted: 0, skipped: [], message: 'لم يُحدَّد أي زيارة.' };
  }
  const conn = await db.getPool().getConnection();
  let deleted = 0;
  const skipped = [];
  try {
    for (const lineId of ids) {
      const orderCount = await countVisitOrdersConn(conn, lineId);
      if (orderCount > 0) {
        skipped.push({
          line_id: lineId,
          message: 'لا يمكن حذف زيارة مربوطة بطلب شراء.',
        });
        continue;
      }
      const ok = await resetVisitLineConn(conn, lineId);
      if (ok) deleted += 1;
      else skipped.push({ line_id: lineId, message: 'الزيارة غير موجودة أو غير مسجّلة.' });
    }
  } finally {
    conn.release();
  }
  let message = deleted > 0 ? `تم حذف ${deleted} زيارة.` : 'لم يُحذف أي زيارة.';
  if (skipped.length) message += ` ${skipped.length} زيارة لم تُحذف.`;
  return { ok: deleted > 0, deleted, skipped, message };
}

/* توافق خلفي لأسماء قديمة */
const listRoutes = listTours;
const getRoute = getTour;
const saveRoute = saveTour;
const deleteRoute = deleteTour;
const listActiveCustomers = async () => listTourCustomers({ limit: 800 });

module.exports = {
  getRep,
  saveRep,
  toggleRep,
  nextRepCode,
  listWarehouses,
  listRegionsSimple,
  listAddressesForRegion,
  listTourCustomers,
  listTours,
  getTour,
  saveTour,
  postTour,
  unpostTour,
  deleteTour,
  getTourPrintRows,
  reportTours,
  reportVisits,
  listVisitReportCustomers,
  deleteVisitLines,
  listVisitCheckoutRequests,
  decideVisitCheckoutRequest,
  listGpsChangeRequests,
  decideGpsChangeRequest,
  ensureTourSchema,
  monthBoundsIso,
  normalizeIsoDate,
  WEEKDAY_LABELS,
  // aliases
  listRoutes,
  getRoute,
  saveRoute,
  deleteRoute,
  listActiveCustomers,
};
