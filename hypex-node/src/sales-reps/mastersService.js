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

function nextDay(iso) {
  const d = new Date(String(iso) + 'T12:00:00');
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
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

let schemaReady = false;
async function ensureTourSchema() {
  if (schemaReady) return true;
  try {
    await db.query(`SELECT id FROM sal_rep_tour LIMIT 1`);
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
  const where = ['c.is_active = 1'];
  const params = [];
  if (regionId > 0) {
    where.push('c.region_id = ?');
    params.push(regionId);
  }
  if (regionAddressId > 0) {
    where.push('c.region_address_id = ?');
    params.push(regionAddressId);
  }
  if (salesRepId > 0) {
    where.push(`(
      c.sales_rep_id = ?
      OR EXISTS (
        SELECT 1 FROM crm_customer_sales_rep csr
        WHERE csr.customer_id = c.id AND csr.sales_rep_id = ?
      )
      OR (
        COALESCE(c.sales_rep_id, 0) = 0
        AND NOT EXISTS (SELECT 1 FROM crm_customer_sales_rep csr2 WHERE csr2.customer_id = c.id)
      )
    )`);
    params.push(salesRepId, salesRepId);
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
    console.error('listTourCustomers', e.message);
    return [];
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
    const lines = await safeQuery(
      `SELECT l.id, l.customer_id, l.date_from, l.date_to, l.region_id, l.region_address_id, l.sort_order,
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
      let df = String(ln.date_from || tourFrom || '').slice(0, 10);
      let dt = String(ln.date_to || tourTo || '').slice(0, 10);
      if (!isIsoDate(df)) df = tourFrom;
      if (!isIsoDate(dt)) dt = tourTo;
      if (df > dt) [df, dt] = [dt, df];
      if (df < tourFrom) df = tourFrom;
      if (dt > tourTo) dt = tourTo;
      lines.push({
        customer_id: customerId,
        date_from: df,
        date_to: dt,
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
    for (const cid of custIds) {
      lines.push({
        customer_id: cid,
        date_from: tourFrom,
        date_to: tourTo,
        region_id: null,
        region_address_id: null,
      });
    }
  }
  const seen = new Set();
  return lines.filter((ln) => {
    if (seen.has(ln.customer_id)) return false;
    seen.add(ln.customer_id);
    return true;
  });
}

async function rebuildDailyRoutes(conn, salesRepId, from, to) {
  const days = daysBetween(from, to);
  for (const day of days) {
    const [custRows] = await conn.execute(
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
        `INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order) VALUES (?,?,?)`,
        [routeId, Number(c.customer_id), sort++]
      );
    }
  }
}

async function saveTour(payload, userId) {
  await ensureTourSchema();
  const id = Number(payload.id || 0);
  const salesRepId = Number(payload.sales_rep_id || 0);
  let dateFrom = String(payload.date_from || '').trim().slice(0, 10);
  let dateTo = String(payload.date_to || '').trim().slice(0, 10);
  const notes = nullIfEmpty(payload.notes);

  if (salesRepId < 1) return { ok: false, error: 'اختر المندوب.' };
  if (!isIsoDate(dateFrom) || !isIsoDate(dateTo)) return { ok: false, error: 'حدد تاريخ البداية والنهاية.' };
  if (dateFrom > dateTo) {
    const tmp = dateFrom;
    dateFrom = dateTo;
    dateTo = tmp;
  }
  const span = daysBetween(dateFrom, dateTo).length;
  if (span < 1 || span > 93) return { ok: false, error: 'مدة الجولة يجب أن تكون بين يوم و 93 يوماً.' };

  const lines = parseTourLines(payload, dateFrom, dateTo);
  if (!lines.length) return { ok: false, error: 'اختر عميلاً واحداً على الأقل للجولة.' };

  const rep = await safeQuery(`SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1`, [
    salesRepId,
  ]);
  if (!rep[0]) return { ok: false, error: 'المندوب غير موجود أو غير نشط.' };

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
    await conn.commit();
    return {
      ok: true,
      id: tourId,
      message: `تم حفظ الجولة (مسودة) · ${lines.length} عميل · ${dateFrom} → ${dateTo}`,
    };
  } catch (e) {
    await conn.rollback();
    console.error('saveTour', e.message);
    return { ok: false, error: 'تعذر حفظ الجولة. تأكد من تشغيل ترحيل قاعدة البيانات 262.' };
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

/** تفاصيل الطباعة: صف لكل يوم + عميل */
async function getTourPrintRows(id) {
  const tour = await getTour(id);
  if (!tour) return null;
  const rows = [];
  for (const ln of tour.lines || []) {
    const days = daysBetween(String(ln.date_from).slice(0, 10), String(ln.date_to).slice(0, 10));
    for (const day of days) {
      rows.push({
        visit_date: day,
        customer_code: ln.customer_code || '',
        customer_name: ln.customer_name || '',
        region_name: ln.region_name || '',
        address_name: ln.address_name || '',
      });
    }
  }
  rows.sort((a, b) => {
    if (a.visit_date !== b.visit_date) return a.visit_date < b.visit_date ? -1 : 1;
    return String(a.customer_name).localeCompare(String(b.customer_name), 'ar');
  });
  return { tour, rows };
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
  ensureTourSchema,
  // aliases
  listRoutes,
  getRoute,
  saveRoute,
  deleteRoute,
  listActiveCustomers,
};
