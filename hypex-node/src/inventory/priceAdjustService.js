'use strict';

/**
 * وثائق تعديل أسعار البيع/الجملة
 * save = مسودة · post = اعتماد على بطاقة المادة
 */
const db = require('../db');
const companyDecimals = require('../lib/companyDecimals');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('priceAdjust', e.message);
    throw e;
  }
}

async function colExists(table, column) {
  try {
    await db.query(`SELECT \`${column}\` FROM \`${table}\` LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

async function ensureSchema() {
  // doc table
  try {
    await db.query(`SELECT id FROM inv_price_adj_doc LIMIT 1`);
  } catch {
    await db.query(
      `CREATE TABLE IF NOT EXISTS inv_price_adj_doc (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        adj_no VARCHAR(20) NOT NULL,
        adj_date DATE NOT NULL,
        status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
        notes VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        posted_at DATETIME NULL,
        posted_by INT UNSIGNED NULL,
        UNIQUE KEY uq_inv_price_adj_doc_no (adj_no),
        KEY idx_inv_price_adj_doc_status (status),
        KEY idx_inv_price_adj_doc_date (adj_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
    );
  }

  // line table (legacy single-row too)
  try {
    await db.query(`SELECT id FROM inv_item_sale_price_adj LIMIT 1`);
  } catch {
    await db.query(
      `CREATE TABLE IF NOT EXISTS inv_item_sale_price_adj (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id BIGINT UNSIGNED NULL,
        line_no INT UNSIGNED NOT NULL DEFAULT 1,
        item_id INT UNSIGNED NOT NULL,
        old_sale_price DECIMAL(18,6) NOT NULL DEFAULT 0,
        new_sale_price DECIMAL(18,6) NOT NULL DEFAULT 0,
        old_wholesale DECIMAL(18,6) NOT NULL DEFAULT 0,
        new_wholesale DECIMAL(18,6) NOT NULL DEFAULT 0,
        tax_rate_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
        status ENUM('draft','posted') NOT NULL DEFAULT 'draft',
        notes VARCHAR(255) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        posted_at DATETIME NULL,
        KEY idx_iispa_item (item_id),
        KEY idx_iispa_status (status),
        KEY idx_iispa_doc (doc_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
    );
  }

  const alters = [
    ['inv_item_sale_price_adj', 'doc_id', 'BIGINT UNSIGNED NULL'],
    ['inv_item_sale_price_adj', 'line_no', 'INT UNSIGNED NOT NULL DEFAULT 1'],
    ['inv_item_sale_price_adj', 'old_wholesale', 'DECIMAL(18,6) NOT NULL DEFAULT 0'],
    ['inv_item_sale_price_adj', 'new_wholesale', 'DECIMAL(18,6) NOT NULL DEFAULT 0'],
    ['inv_price_adj_doc', 'posted_by', 'INT UNSIGNED NULL'],
  ];
  for (const [t, c, def] of alters) {
    if (!(await colExists(t, c))) {
      try {
        await db.query(`ALTER TABLE \`${t}\` ADD COLUMN \`${c}\` ${def}`);
      } catch (e) {
        /* race */
      }
    }
  }
  // default_wholesale on items
  if (!(await colExists('inv_item', 'default_wholesale'))) {
    try {
      await db.query(
        `ALTER TABLE inv_item ADD COLUMN default_wholesale DECIMAL(18,6) NOT NULL DEFAULT 0`
      );
    } catch {
      /* */
    }
  }
}

function r6(n) {
  return companyDecimals.roundUnit(n);
}

function todayIso() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

async function nextAdjNo() {
  try {
    const rows = await safeQuery(
      `SELECT COALESCE(MAX(CAST(adj_no AS UNSIGNED)), 0) AS m FROM inv_price_adj_doc
       WHERE adj_no REGEXP '^[0-9]+$'`
    );
    return String(Number(rows[0]?.m || 0) + 1).padStart(6, '0');
  } catch {
    const rows = await safeQuery(`SELECT COALESCE(MAX(id), 0) AS m FROM inv_price_adj_doc`);
    return String(Number(rows[0]?.m || 0) + 1).padStart(6, '0');
  }
}

async function getItemPrices(itemId) {
  const id = Number(itemId);
  if (!id) return null;
  const hasWh = await colExists('inv_item', 'default_wholesale');
  const rows = await safeQuery(
    hasWh
      ? `SELECT id, sku, barcode, name_ar, COALESCE(default_sale,0) AS default_sale,
                COALESCE(default_wholesale,0) AS default_wholesale, is_active
         FROM inv_item WHERE id = ? LIMIT 1`
      : `SELECT id, sku, barcode, name_ar, COALESCE(default_sale,0) AS default_sale,
                0 AS default_wholesale, is_active
         FROM inv_item WHERE id = ? LIMIT 1`,
    [id]
  );
  const r = rows[0];
  if (!r || !Number(r.is_active)) return null;
  const code = String(r.barcode || '').trim() || String(r.sku || '');
  return {
    id: Number(r.id),
    code,
    sku: r.sku,
    barcode: r.barcode,
    name_ar: r.name_ar,
    sale_price: r6(r.default_sale),
    wholesale_price: r6(r.default_wholesale),
  };
}

async function listDocs({ q = '', limit = 80 } = {}) {
  await ensureSchema();
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (d.adj_no LIKE ? OR IFNULL(d.notes,'') LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT d.id, d.adj_no, d.adj_date, d.status, d.notes, d.created_at, d.posted_at,
            u.full_name_ar AS created_by_name,
            (SELECT COUNT(*) FROM inv_item_sale_price_adj l WHERE l.doc_id = d.id) AS line_count
     FROM inv_price_adj_doc d
     LEFT JOIN sys_user u ON u.id = d.created_by
     WHERE ${where}
     ORDER BY d.id DESC
     LIMIT ${Math.min(200, Number(limit) || 80)}`,
    params
  );
}

async function getDoc(id) {
  await ensureSchema();
  const docId = Number(id);
  if (!docId) return null;
  const docs = await safeQuery(
    `SELECT d.*, u.full_name_ar AS created_by_name, p.full_name_ar AS posted_by_name
     FROM inv_price_adj_doc d
     LEFT JOIN sys_user u ON u.id = d.created_by
     LEFT JOIN sys_user p ON p.id = d.posted_by
     WHERE d.id = ? LIMIT 1`,
    [docId]
  );
  const d = docs[0];
  if (!d) return null;

  const hasWh = await colExists('inv_item_sale_price_adj', 'old_wholesale');
  let lines;
  try {
    lines = await safeQuery(
      hasWh
        ? `SELECT l.*,
                  COALESCE(NULLIF(TRIM(i.barcode),''), i.sku) AS item_code,
                  i.name_ar AS item_name
           FROM inv_item_sale_price_adj l
           INNER JOIN inv_item i ON i.id = l.item_id
           WHERE l.doc_id = ?
           ORDER BY l.line_no, l.id`
        : `SELECT l.*, 0 AS old_wholesale, 0 AS new_wholesale,
                  COALESCE(NULLIF(TRIM(i.barcode),''), i.sku) AS item_code,
                  i.name_ar AS item_name
           FROM inv_item_sale_price_adj l
           INNER JOIN inv_item i ON i.id = l.item_id
           WHERE l.doc_id = ?
           ORDER BY l.line_no, l.id`,
      [docId]
    );
  } catch {
    lines = [];
  }

  return {
    id: Number(d.id),
    adj_no: d.adj_no,
    adj_date: String(d.adj_date).slice(0, 10),
    status: d.status,
    is_posted: String(d.status) === 'posted',
    notes: d.notes || '',
    created_at: d.created_at,
    posted_at: d.posted_at,
    created_by_name: d.created_by_name || '',
    posted_by_name: d.posted_by_name || d.created_by_name || '',
    lines: (lines || []).map((ln) => ({
      id: Number(ln.id),
      line_no: Number(ln.line_no || 0),
      item_id: Number(ln.item_id),
      item_code: ln.item_code || '',
      item_name: ln.item_name || '',
      old_sale_price: r6(ln.old_sale_price),
      new_sale_price: r6(ln.new_sale_price),
      old_wholesale: r6(ln.old_wholesale),
      new_wholesale: r6(ln.new_wholesale),
    })),
  };
}

/**
 * @param {object} payload
 * @param {number} userId
 */
async function saveDoc(payload, userId) {
  await ensureSchema();
  // التاريخ دائماً تاريخ اليوم — لا يقبل تعديل من العميل
  const adjDate = todayIso();
  const notes = String(payload.notes || '').trim() || null;
  let docId = Number(payload.id || 0);
  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];
  const hasWhCol = await colExists('inv_item_sale_price_adj', 'old_wholesale');
  const hasItemWh = await colExists('inv_item', 'default_wholesale');

  const seen = new Set();
  const lines = [];
  for (const ln of rawLines) {
    const itemId = Number(ln.item_id || 0);
    if (!itemId || seen.has(itemId)) continue;
    const item = await getItemPrices(itemId);
    if (!item) continue;
    let newSale = r6(ln.new_sale_price);
    let newWh = r6(ln.new_wholesale);
    // إذا تُرك فارغاً/undefined احتفظ بالحالي
    if (ln.new_sale_price === '' || ln.new_sale_price == null) newSale = item.sale_price;
    if (ln.new_wholesale === '' || ln.new_wholesale == null) newWh = item.wholesale_price;
    if (newSale < 0 || newWh < 0) {
      return { ok: false, error: 'الأسعار يجب أن تكون ≥ 0.' };
    }
    const saleChanged = Math.abs(newSale - item.sale_price) >= 0.000001;
    const whChanged = Math.abs(newWh - item.wholesale_price) >= 0.000001;
    if (!saleChanged && !whChanged) {
      return {
        ok: false,
        error: `لم يتغيّر أي سعر للمادة «${item.name_ar}». أدخل سعراً جديداً للبيع أو الجملة.`,
      };
    }
    seen.add(itemId);
    lines.push({
      item_id: itemId,
      old_sale: item.sale_price,
      new_sale: newSale,
      old_wh: item.wholesale_price,
      new_wh: newWh,
    });
  }
  if (!lines.length) {
    return { ok: false, error: 'أضف مادة واحدة على الأقل مع سعر جديد.' };
  }

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    if (docId > 0) {
      const [rows] = await conn.execute(
        `SELECT id, status, adj_no FROM inv_price_adj_doc WHERE id = ? FOR UPDATE`,
        [docId]
      );
      if (!rows[0]) {
        await conn.rollback();
        return { ok: false, error: 'الحركة غير موجودة.' };
      }
      if (String(rows[0].status) === 'posted') {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل حركة مرحّلة.' };
      }
      await conn.execute(
        `UPDATE inv_price_adj_doc SET adj_date=?, notes=? WHERE id=? AND status='draft'`,
        [adjDate, notes, docId]
      );
      await conn.execute(`DELETE FROM inv_item_sale_price_adj WHERE doc_id = ?`, [docId]);
    } else {
      const adjNo = await nextAdjNo();
      const [ins] = await conn.execute(
        `INSERT INTO inv_price_adj_doc (adj_no, adj_date, status, notes, created_by)
         VALUES (?,?, 'draft', ?, ?)`,
        [adjNo, adjDate, notes, userId || null]
      );
      docId = Number(ins.insertId);
    }

    let lineNo = 0;
    for (const ln of lines) {
      lineNo++;
      if (hasWhCol) {
        await conn.execute(
          `INSERT INTO inv_item_sale_price_adj
           (doc_id, line_no, item_id, old_sale_price, new_sale_price, old_wholesale, new_wholesale,
            tax_rate_percent, status, created_by)
           VALUES (?,?,?,?,?,?,?,0,'draft',?)`,
          [
            docId,
            lineNo,
            ln.item_id,
            ln.old_sale,
            ln.new_sale,
            ln.old_wh,
            ln.new_wh,
            userId || null,
          ]
        );
      } else {
        await conn.execute(
          `INSERT INTO inv_item_sale_price_adj
           (doc_id, line_no, item_id, old_sale_price, new_sale_price, tax_rate_percent, status, created_by)
           VALUES (?,?,?,?,?,0,'draft',?)`,
          [docId, lineNo, ln.item_id, ln.old_sale, ln.new_sale, userId || null]
        );
      }
    }

    await conn.commit();
    return { ok: true, id: docId, message: 'تم حفظ مسودة تعديل الأسعار.' };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* */
    }
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function postDoc(id, userId) {
  await ensureSchema();
  const docId = Number(id);
  if (!docId) return { ok: false, error: 'معرّف غير صالح.' };
  const hasItemWh = await colExists('inv_item', 'default_wholesale');
  const hasWhCol = await colExists('inv_item_sale_price_adj', 'old_wholesale');
  const hasPostedBy = await colExists('inv_price_adj_doc', 'posted_by');

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [docs] = await conn.execute(
      `SELECT id, status FROM inv_price_adj_doc WHERE id = ? FOR UPDATE`,
      [docId]
    );
    if (!docs[0]) {
      await conn.rollback();
      return { ok: false, error: 'الحركة غير موجودة.' };
    }
    if (String(docs[0].status) === 'posted') {
      await conn.rollback();
      return { ok: false, error: 'هذه الحركة مرحّلة مسبقاً.' };
    }

    const [lines] = await conn.execute(
      hasWhCol
        ? `SELECT item_id, new_sale_price, new_wholesale FROM inv_item_sale_price_adj WHERE doc_id = ?`
        : `SELECT item_id, new_sale_price, 0 AS new_wholesale FROM inv_item_sale_price_adj WHERE doc_id = ?`,
      [docId]
    );
    if (!lines.length) {
      await conn.rollback();
      return { ok: false, error: 'لا توجد بنود للترحيل.' };
    }

    for (const ln of lines) {
      if (hasItemWh) {
        await conn.execute(
          `UPDATE inv_item SET default_sale = ?, default_wholesale = ? WHERE id = ?`,
          [r6(ln.new_sale_price), r6(ln.new_wholesale), ln.item_id]
        );
      } else {
        await conn.execute(`UPDATE inv_item SET default_sale = ? WHERE id = ?`, [
          r6(ln.new_sale_price),
          ln.item_id,
        ]);
      }
    }

    await conn.execute(
      `UPDATE inv_item_sale_price_adj SET status='posted', posted_at=NOW() WHERE doc_id=?`,
      [docId]
    );
    if (hasPostedBy) {
      await conn.execute(
        `UPDATE inv_price_adj_doc SET status='posted', posted_at=NOW(), posted_by=? WHERE id=? AND status='draft'`,
        [userId || null, docId]
      );
    } else {
      await conn.execute(
        `UPDATE inv_price_adj_doc SET status='posted', posted_at=NOW() WHERE id=? AND status='draft'`,
        [docId]
      );
    }

    await conn.commit();
    return { ok: true, id: docId, message: 'تم ترحيل الأسعار وتحديث بطاقات المواد.' };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* */
    }
    return { ok: false, error: e.message || 'تعذر الترحيل.' };
  } finally {
    conn.release();
  }
}

/**
 * تقرير الأسعار المعدّلة (المرحّلة فقط) — تفصيلي
 */
async function reportAdjustments({ from = '', to = '', q = '', limit = 500 } = {}) {
  await ensureSchema();
  const where = [`l.status = 'posted'`];
  const params = [];
  if (from) {
    where.push(`DATE(COALESCE(l.posted_at, d.posted_at, l.created_at)) >= ?`);
    params.push(from);
  }
  if (to) {
    where.push(`DATE(COALESCE(l.posted_at, d.posted_at, l.created_at)) <= ?`);
    params.push(to);
  }
  if (q) {
    where.push(
      `(IFNULL(d.adj_no,'') LIKE ? OR IFNULL(i.name_ar,'') LIKE ? OR IFNULL(i.barcode,'') LIKE ? OR IFNULL(i.sku,'') LIKE ?)`
    );
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }

  const hasWh = await colExists('inv_item_sale_price_adj', 'old_wholesale');
  const whCols = hasWh
    ? ', l.old_wholesale, l.new_wholesale'
    : ', 0 AS old_wholesale, 0 AS new_wholesale';

  return safeQuery(
    `SELECT l.id, l.item_id, l.old_sale_price, l.new_sale_price${whCols},
            l.posted_at, l.created_at,
            d.adj_no, d.adj_date, d.posted_at AS doc_posted_at,
            COALESCE(NULLIF(TRIM(i.barcode),''), i.sku) AS item_code,
            i.name_ar AS item_name,
            COALESCE(p.full_name_ar, c.full_name_ar, '') AS employee_name,
            COALESCE(p.username, c.username, '') AS employee_user
     FROM inv_item_sale_price_adj l
     LEFT JOIN inv_price_adj_doc d ON d.id = l.doc_id
     INNER JOIN inv_item i ON i.id = l.item_id
     LEFT JOIN sys_user p ON p.id = d.posted_by
     LEFT JOIN sys_user c ON c.id = COALESCE(d.created_by, l.created_by)
     WHERE ${where.join(' AND ')}
     ORDER BY COALESCE(l.posted_at, d.posted_at, l.created_at) DESC, l.id DESC
     LIMIT ${Math.min(1000, Number(limit) || 500)}`,
    params
  );
}

module.exports = {
  ensureSchema,
  todayIso,
  getItemPrices,
  listDocs,
  getDoc,
  saveDoc,
  postDoc,
  reportAdjustments,
};
