'use strict';

/**
 * شاشة العروض — رأس (فترة) + بنود (كمية إضافية أو خصم %)
 */
const db = require('../db');
const { neighbors, findIdByNo } = require('../lib/docBrowse');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('salesOffers', e.message);
    throw e;
  }
}

async function tableExists(name) {
  try {
    await db.query(`SELECT 1 FROM \`${name}\` LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

async function ensureSchema() {
  if (!(await tableExists('sal_offer'))) {
    await db.query(`
      CREATE TABLE IF NOT EXISTS sal_offer (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_no VARCHAR(20) NOT NULL,
        name_ar VARCHAR(200) NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        notes VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sal_offer_no (offer_no),
        KEY idx_sal_offer_dates (date_from, date_to),
        KEY idx_sal_offer_active (is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
  }
  if (!(await tableExists('sal_offer_line'))) {
    await db.query(`
      CREATE TABLE IF NOT EXISTS sal_offer_line (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_id BIGINT UNSIGNED NOT NULL,
        line_no INT UNSIGNED NOT NULL DEFAULT 1,
        item_id INT UNSIGNED NOT NULL,
        offer_type ENUM('bonus','discount_pct') NOT NULL DEFAULT 'bonus',
        trigger_qty DECIMAL(18,3) NOT NULL DEFAULT 1,
        bonus_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
        discount_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_sal_offer_line_item (offer_id, item_id),
        KEY idx_sal_offer_line_item (item_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
  }
  if (!(await tableExists('sal_offer_application'))) {
    await db.query(`
      CREATE TABLE IF NOT EXISTS sal_offer_application (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        offer_id BIGINT UNSIGNED NOT NULL,
        offer_line_id BIGINT UNSIGNED NULL,
        item_id INT UNSIGNED NOT NULL,
        doc_type ENUM('invoice','order') NOT NULL,
        doc_id BIGINT UNSIGNED NOT NULL,
        doc_no VARCHAR(40) NULL,
        doc_date DATE NOT NULL,
        qty DECIMAL(18,3) NOT NULL DEFAULT 0,
        bonus_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
        discount_pct DECIMAL(8,3) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_soa_offer (offer_id),
        KEY idx_soa_doc (doc_type, doc_id),
        KEY idx_soa_date (doc_date),
        KEY idx_soa_item (item_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `);
  }

  // شاشات الصلاحيات
  try {
    await db.query(
      `INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
       SELECT 'sales_offers', 'شاشة العرض', 'screen', 155 FROM DUAL
       WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'sales_offers')`
    );
    await db.query(
      `INSERT INTO sys_screen (code, name_ar, screen_type, sort_order)
       SELECT 'report_sales_offers', 'تقرير العروض', 'report', 208 FROM DUAL
       WHERE NOT EXISTS (SELECT 1 FROM sys_screen WHERE code = 'report_sales_offers')`
    );
  } catch {
    /* ignore */
  }
}

function todayIso() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function r3(n) {
  const x = Number(n);
  if (!Number.isFinite(x)) return 0;
  return Math.round(x * 1000) / 1000;
}

async function nextOfferNo() {
  const rows = await safeQuery(
    `SELECT offer_no FROM sal_offer ORDER BY id DESC LIMIT 20`
  );
  let max = 0;
  for (const r of rows || []) {
    const m = String(r.offer_no || '').match(/(\d+)/);
    if (m) max = Math.max(max, Number(m[1]) || 0);
  }
  return 'OF-' + String(max + 1).padStart(5, '0');
}

async function listOffers({ q = '' } = {}) {
  await ensureSchema();
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (o.offer_no LIKE ? OR o.name_ar LIKE ? OR IFNULL(o.notes,'') LIKE ?)`;
    const like = `%${q}%`;
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT o.id, o.offer_no, o.name_ar, o.date_from, o.date_to, o.is_active, o.notes, o.created_at,
            (SELECT COUNT(*) FROM sal_offer_line l WHERE l.offer_id = o.id) AS lines_count
     FROM sal_offer o
     WHERE ${where}
     ORDER BY o.id DESC
     LIMIT 300`,
    params
  );
}

async function getOffer(id) {
  await ensureSchema();
  const oid = Number(id);
  if (!oid) return null;
  const docs = await safeQuery(`SELECT * FROM sal_offer WHERE id = ? LIMIT 1`, [oid]);
  const doc = docs[0];
  if (!doc) return null;
  const lines = await safeQuery(
    `SELECT l.*,
            COALESCE(NULLIF(TRIM(i.barcode),''), i.sku) AS item_code,
            i.name_ar AS item_name
     FROM sal_offer_line l
     INNER JOIN inv_item i ON i.id = l.item_id
     WHERE l.offer_id = ?
     ORDER BY l.line_no, l.id`,
    [oid]
  );
  return { ...doc, lines: lines || [] };
}

async function saveOffer(payload, userId) {
  await ensureSchema();
  const id = Number(payload.id || 0);
  const nameAr = String(payload.name_ar || '').trim();
  const dateFrom = String(payload.date_from || '').slice(0, 10);
  const dateTo = String(payload.date_to || '').slice(0, 10);
  const notes = String(payload.notes || '').trim() || null;
  const isActive = payload.is_active === 0 || payload.is_active === '0' || payload.is_active === false ? 0 : 1;

  if (!nameAr) return { ok: false, error: 'اسم العرض مطلوب.' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dateFrom) || !/^\d{4}-\d{2}-\d{2}$/.test(dateTo)) {
    return { ok: false, error: 'تاريخ بداية ونهاية العرض مطلوبان.' };
  }
  if (dateTo < dateFrom) return { ok: false, error: 'تاريخ النهاية يجب أن يكون بعد البداية أو مساوياً له.' };

  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];
  const lines = [];
  const seen = new Set();
  for (const ln of rawLines) {
    const itemId = Number(ln.item_id || 0);
    if (!itemId) continue;
    if (seen.has(itemId)) continue;
    seen.add(itemId);
    const offerType = String(ln.offer_type || '') === 'discount_pct' ? 'discount_pct' : 'bonus';
    const triggerQty = r3(ln.trigger_qty);
    if (!(triggerQty > 0)) {
      return { ok: false, error: 'كمية العرض يجب أن تكون أكبر من صفر لكل مادة.' };
    }
    const bonusQty = offerType === 'bonus' ? r3(ln.bonus_qty) : 0;
    const discountPct = offerType === 'discount_pct' ? r3(ln.discount_pct) : 0;
    if (offerType === 'bonus' && !(bonusQty > 0)) {
      return { ok: false, error: 'الكمية الإضافية للعرض يجب أن تكون أكبر من صفر.' };
    }
    if (offerType === 'discount_pct' && !(discountPct > 0 && discountPct <= 100)) {
      return { ok: false, error: 'نسبة الخصم يجب أن تكون بين 0 و 100.' };
    }
    lines.push({
      item_id: itemId,
      offer_type: offerType,
      trigger_qty: triggerQty,
      bonus_qty: bonusQty,
      discount_pct: discountPct,
    });
  }
  if (!lines.length) return { ok: false, error: 'أضف مادة واحدةً على الأقل للعرض.' };

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    let offerId = id;
    if (offerId > 0) {
      const [exist] = await conn.execute(`SELECT id FROM sal_offer WHERE id = ? FOR UPDATE`, [offerId]);
      if (!exist[0]) {
        await conn.rollback();
        return { ok: false, error: 'العرض غير موجود.' };
      }
      await conn.execute(
        `UPDATE sal_offer
         SET name_ar=?, date_from=?, date_to=?, is_active=?, notes=?, updated_at=NOW()
         WHERE id=?`,
        [nameAr, dateFrom, dateTo, isActive, notes, offerId]
      );
      await conn.execute(`DELETE FROM sal_offer_line WHERE offer_id = ?`, [offerId]);
    } else {
      const offerNo = await nextOfferNo();
      const [ins] = await conn.execute(
        `INSERT INTO sal_offer (offer_no, name_ar, date_from, date_to, is_active, notes, created_by)
         VALUES (?,?,?,?,?,?,?)`,
        [offerNo, nameAr, dateFrom, dateTo, isActive, notes, userId || null]
      );
      offerId = Number(ins.insertId);
    }

    let n = 0;
    for (const ln of lines) {
      n += 1;
      await conn.execute(
        `INSERT INTO sal_offer_line
           (offer_id, line_no, item_id, offer_type, trigger_qty, bonus_qty, discount_pct)
         VALUES (?,?,?,?,?,?,?)`,
        [offerId, n, ln.item_id, ln.offer_type, ln.trigger_qty, ln.bonus_qty, ln.discount_pct]
      );
    }
    await conn.commit();
    return { ok: true, id: offerId, message: 'تم حفظ العرض.' };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    console.error('saveOffer', e);
    return { ok: false, error: e.message || 'تعذّر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function toggleOffer(id) {
  await ensureSchema();
  const oid = Number(id);
  if (!oid) return { ok: false, error: 'معرّف غير صالح.' };
  await safeQuery(`UPDATE sal_offer SET is_active = 1 - is_active, updated_at=NOW() WHERE id = ?`, [oid]);
  return { ok: true, message: 'تم تحديث حالة العرض.' };
}

/**
 * عروض نشطة لتاريخ مستند — مفهرسة حسب item_id
 * عند تعدد عروض لنفس المادة يُفضَّل الأحدث date_from ثم أكبر id
 */
async function activeOfferMapForDate(docDate) {
  await ensureSchema();
  const d = String(docDate || todayIso()).slice(0, 10);
  const rows = await safeQuery(
    `SELECT o.id AS offer_id, o.offer_no, o.name_ar AS offer_name,
            l.id AS offer_line_id, l.item_id, l.offer_type, l.trigger_qty, l.bonus_qty, l.discount_pct,
            o.date_from
     FROM sal_offer o
     INNER JOIN sal_offer_line l ON l.offer_id = o.id
     WHERE o.is_active = 1
       AND o.date_from <= ?
       AND o.date_to >= ?
     ORDER BY o.date_from DESC, o.id DESC, l.id ASC`,
    [d, d]
  );
  const map = new Map();
  for (const r of rows || []) {
    const itemId = Number(r.item_id);
    if (!itemId || map.has(itemId)) continue;
    map.set(itemId, {
      offer_id: Number(r.offer_id),
      offer_line_id: Number(r.offer_line_id),
      offer_no: r.offer_no,
      offer_name: r.offer_name,
      offer_type: String(r.offer_type) === 'discount_pct' ? 'discount_pct' : 'bonus',
      trigger_qty: r3(r.trigger_qty),
      bonus_qty: r3(r.bonus_qty),
      discount_pct: r3(r.discount_pct),
    });
  }
  return map;
}

function computeOfferEffect(qty, offer) {
  const q = r3(qty);
  const out = { applied: false, bonus_qty: 0, discount_pct: 0, offer: null };
  if (!offer || !(q > 0)) return out;
  const t = r3(offer.trigger_qty);
  if (!(t > 0) || q < t) return out;
  if (offer.offer_type === 'bonus') {
    const b = r3(offer.bonus_qty);
    if (!(b > 0)) return out;
    out.applied = true;
    out.bonus_qty = Math.floor(q / t) * b;
    out.offer = offer;
    return out;
  }
  const p = r3(offer.discount_pct);
  if (!(p > 0)) return out;
  out.applied = true;
  out.discount_pct = p;
  out.offer = offer;
  return out;
}

async function logApplications(apps) {
  if (!apps || !apps.length) return;
  await ensureSchema();
  for (const a of apps) {
    try {
      await safeQuery(
        `INSERT INTO sal_offer_application
           (offer_id, offer_line_id, item_id, doc_type, doc_id, doc_no, doc_date, qty, bonus_qty, discount_pct)
         VALUES (?,?,?,?,?,?,?,?,?,?)`,
        [
          a.offer_id,
          a.offer_line_id || null,
          a.item_id,
          a.doc_type,
          a.doc_id,
          a.doc_no || null,
          a.doc_date,
          r3(a.qty),
          r3(a.bonus_qty),
          r3(a.discount_pct),
        ]
      );
    } catch (e) {
      console.error('logApplications', e.message);
    }
  }
}

async function clearApplications(docType, docId) {
  await ensureSchema();
  await safeQuery(`DELETE FROM sal_offer_application WHERE doc_type = ? AND doc_id = ?`, [
    docType,
    Number(docId) || 0,
  ]);
}

async function reportOffers({ from = '', to = '', q = '' } = {}) {
  await ensureSchema();
  const params = [];
  const where = ['1=1'];
  if (from) {
    where.push('a.doc_date >= ?');
    params.push(from);
  }
  if (to) {
    where.push('a.doc_date <= ?');
    params.push(to);
  }
  if (q) {
    where.push(
      `(IFNULL(o.offer_no,'') LIKE ? OR IFNULL(o.name_ar,'') LIKE ? OR IFNULL(i.name_ar,'') LIKE ? OR IFNULL(i.barcode,'') LIKE ? OR IFNULL(a.doc_no,'') LIKE ?)`
    );
    const like = `%${q}%`;
    params.push(like, like, like, like, like);
  }
  return safeQuery(
    `SELECT a.id, a.doc_type, a.doc_id, a.doc_no, a.doc_date, a.qty, a.bonus_qty, a.discount_pct, a.created_at,
            o.offer_no, o.name_ar AS offer_name,
            COALESCE(NULLIF(TRIM(i.barcode),''), i.sku) AS item_code,
            i.name_ar AS item_name
     FROM sal_offer_application a
     LEFT JOIN sal_offer o ON o.id = a.offer_id
     LEFT JOIN inv_item i ON i.id = a.item_id
     WHERE ${where.join(' AND ')}
     ORDER BY a.doc_date DESC, a.id DESC
     LIMIT 1000`,
    params
  );
}

async function reportOfferDefinitions({ q = '' } = {}) {
  await ensureSchema();
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (o.offer_no LIKE ? OR o.name_ar LIKE ? OR i.name_ar LIKE ? OR i.barcode LIKE ?)`;
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }
  return safeQuery(
    `SELECT o.offer_no, o.name_ar, o.date_from, o.date_to, o.is_active,
            COALESCE(NULLIF(TRIM(i.barcode),''), i.sku) AS item_code,
            i.name_ar AS item_name,
            l.offer_type, l.trigger_qty, l.bonus_qty, l.discount_pct
     FROM sal_offer o
     INNER JOIN sal_offer_line l ON l.offer_id = o.id
     INNER JOIN inv_item i ON i.id = l.item_id
     WHERE ${where}
     ORDER BY o.date_from DESC, o.id DESC, l.line_no ASC
     LIMIT 1000`,
    params
  );
}

module.exports = {
  ensureSchema,
  todayIso,
  listOffers,
  getOffer,
  saveOffer,
  toggleOffer,
  activeOfferMapForDate,
  computeOfferEffect,
  logApplications,
  clearApplications,
  reportOffers,
  reportOfferDefinitions,
  browseNeighbors,
  findOfferIdByNo,
};

async function browseNeighbors(id) {
  await ensureSchema();
  return neighbors('sal_offer', id);
}

async function findOfferIdByNo(no) {
  await ensureSchema();
  return findIdByNo('sal_offer', 'offer_no', no);
}
