'use strict';

/**
 * استيراد ملفي التسعير:
 *   سعر البيع.xlsx  → default_sale = Unit Price (قطعة غير شامل)
 *   سعر الجملة.xlsx → default_wholesale = Unit Price (قطعة غير شامل)
 * سعر الكرتونة غير الشامل = سعر القطعة × التعبئة
 *
 *   node cli/import_sale_wholesale_books.js
 */

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const masters = require('../src/inventory/mastersService');
const db = require('../src/db');

const ITEMS_DIR = path.join(__dirname, '..', '..', 'items');
const SALE_FILE = path.join(ITEMS_DIR, 'سعر البيع.xlsx');
const WHOLESALE_FILE = path.join(ITEMS_DIR, 'سعر الجملة.xlsx');
const SQL_OUT = path.join(ITEMS_DIR, 'import-on-server.sql');

function findPhp() {
  const candidates = [process.env.PHP_BIN, 'C:\\xampp\\php\\php.exe', 'php'].filter(Boolean);
  for (const bin of candidates) {
    const r = spawnSync(bin, ['-v'], { encoding: 'utf8' });
    if (r.status === 0) return bin;
  }
  return null;
}

function loadRows(xlsxPath) {
  const php = findPhp();
  if (!php) throw new Error('php غير موجود');
  const script = path.join(__dirname, 'inv_items_xlsx_to_json.php');
  const r = spawnSync(php, [script, xlsxPath], { encoding: 'utf8', maxBuffer: 80 * 1024 * 1024 });
  if (r.status !== 0) throw new Error((r.stderr || r.stdout || 'فشل قراءة Excel').trim());
  const raw = String(r.stdout || '');
  const start = raw.indexOf('{');
  const data = JSON.parse(start >= 0 ? raw.slice(start) : '{}');
  if (!data.ok || !Array.isArray(data.rows)) throw new Error('صيغة Excel غير متوقعة');
  return data.rows;
}

function normHeader(h) {
  return String(h || '')
    .replace(/^\uFEFF/, '')
    .trim()
    .toLowerCase()
    .replace(/[\s\-_\.]+/g, '');
}

function mapColumns(header) {
  const map = {};
  header.forEach((raw, i) => {
    const h = normHeader(raw);
    if (!h) return;
    if (h === 'itemnum' || h === 'itemno' || h === 'sku' || h.includes('itemnum')) map.sku = i;
    else if (h === 'barcode' || h.includes('barcode')) map.barcode = i;
    else if (h === 'category' || h.includes('category')) map.category = i;
    else if (h.includes('descriptionarab')) map.name_ar = i;
    else if (h.includes('descriptionenglish') || h.includes('descriptioneng')) map.name_en = i;
    else if (h === 'pack') map.pack = i;
  });
  header.forEach((raw, i) => {
    const t = String(raw || '')
      .replace(/^\uFEFF/, '')
      .trim()
      .toLowerCase();
    if (t === 'unit price') map.unit_price = i;
  });
  return map;
}

function cell(row, idx) {
  if (idx == null || idx < 0) return '';
  return String(row[idx] ?? '').trim();
}

function num(val) {
  const n = Number(String(val || '0').replace(/,/g, '').replace(/\s/g, ''));
  return Number.isFinite(n) ? n : 0;
}

function barcodeDigits(v) {
  return String(v || '').replace(/\D/g, '');
}

function sqlStr(v) {
  if (v == null || v === '') return 'NULL';
  return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
}

function parseBook(rows) {
  const map = mapColumns(rows[0] || []);
  const needed = ['sku', 'category', 'name_ar', 'pack', 'unit_price'];
  const missing = needed.filter((k) => map[k] == null);
  if (missing.length) {
    throw new Error('أعمدة ناقصة: ' + missing.join(', ') + ' | ' + JSON.stringify(rows[0]));
  }
  const list = [];
  for (let i = 1; i < rows.length; i++) {
    const row = rows[i];
    if (!Array.isArray(row) || row.every((c) => String(c ?? '').trim() === '')) continue;
    const sku = cell(row, map.sku);
    const barcode = barcodeDigits(cell(row, map.barcode));
    if (!sku && !barcode) continue;
    list.push({
      item_num: sku,
      barcode,
      category: cell(row, map.category),
      name_ar: cell(row, map.name_ar),
      name_en: map.name_en != null ? cell(row, map.name_en) : '',
      pack: Math.max(1, Math.round(num(cell(row, map.pack))) || 1),
      unit_price: num(cell(row, map.unit_price)),
    });
  }
  return list;
}

function assignSkus(rows) {
  const counts = new Map();
  rows.forEach((row) => counts.set(row.item_num, (counts.get(row.item_num) || 0) + 1));
  const seen = new Map();
  return rows.map((row) => {
    const n = (seen.get(row.item_num) || 0) + 1;
    seen.set(row.item_num, n);
    const shared = (counts.get(row.item_num) || 0) > 1;
    const sku = !shared || n === 1 ? row.item_num : row.item_num + '-' + n;
    return { ...row, sku };
  });
}

async function ensureUnit(code, nameAr) {
  const rows = await db.query(
    `SELECT id FROM inv_unit WHERE code = ? OR name_ar = ? ORDER BY id LIMIT 1`,
    [code, nameAr]
  );
  if (rows[0]?.id) return Number(rows[0].id);
  const r = await masters.saveUnit({ code, name_ar: nameAr, is_active: 1 });
  if (!r.ok) throw new Error(r.error || `تعذر إنشاء وحدة ${code}`);
  return Number(r.id);
}

async function resolveDefaultWarehouseId() {
  const preferred = await db.query(
    `SELECT id FROM inv_warehouse
     WHERE is_active = 1 AND (UPPER(code) = 'MAIN' OR name_ar LIKE '%رئيسي%')
     ORDER BY id LIMIT 1`
  );
  if (preferred[0]?.id) return Number(preferred[0].id);
  const any = await db.query(`SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY id LIMIT 1`);
  if (any[0]?.id) return Number(any[0].id);
  const fallback = await db.query(`SELECT id FROM inv_warehouse ORDER BY id LIMIT 1`);
  if (fallback[0]?.id) return Number(fallback[0].id);
  throw new Error('لا يوجد مستودع. أنشئ مستودعاً ثم أعد الاستيراد.');
}

async function resolveTax16Id() {
  const exact = await db.query(
    `SELECT id FROM sys_tax_rate WHERE is_active = 1 AND ABS(rate_percent - 16) < 0.001 ORDER BY id LIMIT 1`
  );
  if (exact[0]?.id) return Number(exact[0].id);
  const inactive = await db.query(
    `SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY id LIMIT 1`
  );
  if (inactive[0]?.id) {
    await db.query(`UPDATE sys_tax_rate SET is_active = 1 WHERE id = ?`, [inactive[0].id]);
    return Number(inactive[0].id);
  }
  const [result] = await db.getPool().execute(
    `INSERT INTO sys_tax_rate (name_ar, rate_percent, is_active) VALUES ('16%', 16, 1)`
  );
  return Number(result.insertId);
}

async function resolveCategoryId(name, cache) {
  const key = name.trim().toLowerCase();
  if (!key) return null;
  if (cache.has(key)) return cache.get(key);
  const existing = await db.query(
    `SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = ? LIMIT 1`,
    [key]
  );
  if (existing[0]?.id) {
    cache.set(key, Number(existing[0].id));
    return Number(existing[0].id);
  }
  const r = await masters.saveCategory({ name_ar: name.trim() });
  if (!r.ok) throw new Error(r.error || `تعذر إنشاء فئة: ${name}`);
  cache.set(key, Number(r.id));
  return Number(r.id);
}

function buildServerSql(items) {
  const cats = [...new Set(items.map((it) => it.category).filter(Boolean))];
  const lines = [];
  lines.push('USE hypex;');
  lines.push('SET SQL_SAFE_UPDATES = 0;');
  lines.push('');
  lines.push(
    `INSERT INTO inv_unit (code, name_ar, is_active)
SELECT 'PCS', 'قطعة', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة'));`
  );
  lines.push(
    `INSERT INTO inv_unit (code, name_ar, is_active)
SELECT 'BOX', 'كرتونة', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_unit WHERE code = 'BOX' OR name_ar IN ('كرتونة','كرتون'));`
  );
  lines.push('');
  cats.forEach((name, idx) => {
    const code = 'C' + String(idx + 1);
    lines.push(
      `INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT ${sqlStr(code)}, ${sqlStr(name)}, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER(${sqlStr(name)}));`
    );
  });
  lines.push('');
  for (const it of items) {
    const catExpr = it.category
      ? `(SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = LOWER(${sqlStr(it.category)}) LIMIT 1)`
      : 'NULL';
    lines.push(
      `INSERT INTO inv_item (sku, barcode, name_ar, name_en, unit_name, unit_id, category_id, default_warehouse_id, default_cost, default_sale, default_wholesale, tax_rate_id, track_inventory, is_active)
SELECT ${sqlStr(it.sku)}, ${sqlStr(it.barcode || null)}, ${sqlStr(it.name_ar)}, ${sqlStr(it.name_en || it.name_ar)}, 'قطعة',
  (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1),
  ${catExpr},
  (SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY CASE WHEN UPPER(code) = 'MAIN' THEN 0 ELSE 1 END, id LIMIT 1),
  0, ${Number(it.sale).toFixed(6)}, ${Number(it.wholesale).toFixed(6)},
  (SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY is_active DESC, id LIMIT 1),
  1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item WHERE sku = ${sqlStr(it.sku)});`
    );
    lines.push(
      `UPDATE inv_item SET
  barcode = ${sqlStr(it.barcode || null)},
  name_ar = ${sqlStr(it.name_ar)},
  name_en = ${sqlStr(it.name_en || it.name_ar)},
  category_id = ${catExpr},
  default_sale = ${Number(it.sale).toFixed(6)},
  default_wholesale = ${Number(it.wholesale).toFixed(6)},
  unit_name = 'قطعة',
  unit_id = (SELECT id FROM (SELECT id FROM inv_unit WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة') ORDER BY id LIMIT 1) pcs),
  is_active = 1
WHERE sku = ${sqlStr(it.sku)};`
    );
    lines.push(`DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id = iu.item_id WHERE i.sku = ${sqlStr(it.sku)};`);
    lines.push(
      `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, 1, 1, ${it.pack > 1 ? 0 : 1}
FROM inv_item i
JOIN inv_unit u ON u.code = 'PCS' OR u.name_ar IN ('قطعة','حبة')
WHERE i.sku = ${sqlStr(it.sku)}
ORDER BY u.id
LIMIT 1;`
    );
    if (it.pack > 1) {
      lines.push(
        `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, ${it.pack}, 0, 1
FROM inv_item i
JOIN inv_unit u ON u.code = 'BOX' OR u.name_ar IN ('كرتونة','كرتون')
WHERE i.sku = ${sqlStr(it.sku)}
ORDER BY u.id
LIMIT 1;`
      );
    }
    lines.push('');
  }
  lines.push('SET SQL_SAFE_UPDATES = 1;');
  lines.push('');
  return lines.join('\n');
}

async function main() {
  if (!fs.existsSync(SALE_FILE) || !fs.existsSync(WHOLESALE_FILE)) {
    throw new Error('الملفان غير موجودين في مجلد items');
  }
  const sale = assignSkus(parseBook(loadRows(SALE_FILE)));
  const wholesale = parseBook(loadRows(WHOLESALE_FILE));
  const wholesaleByBarcode = new Map();
  wholesale.forEach((row) => {
    if (row.barcode) wholesaleByBarcode.set(row.barcode, row);
  });
  await db.query('DELETE FROM inv_item_unit');
  await db.query('DELETE FROM inv_item');

  const pcsId = await ensureUnit('PCS', 'قطعة');
  const boxId = await ensureUnit('BOX', 'كرتونة');
  const warehouseId = await resolveDefaultWarehouseId();
  const taxRateId = await resolveTax16Id();
  const catCache = new Map();

  const stats = { total: sale.length, created: 0, updated: 0, skipped: 0, errors: [] };
  const exported = [];

  for (const s of sale) {
    const sku = s.sku;
    const w = s.barcode ? wholesaleByBarcode.get(s.barcode) : null;
    const nameAr = s.name_ar || (w && w.name_ar) || sku;
    const nameEn = s.name_en || (w && w.name_en) || '';
    const category = s.category || (w && w.category) || '';
    const barcode = s.barcode || (w && w.barcode) || '';
    const pack = s.pack || (w && w.pack) || 1;
    const salePrice = s.unit_price || 0;
    const wholesalePrice = w ? w.unit_price : 0;

    try {
      const categoryId = category ? await resolveCategoryId(category, catCache) : null;
      const existing = await db.query(`SELECT id FROM inv_item WHERE sku = ? LIMIT 1`, [sku]);
      const existingId = existing[0] ? Number(existing[0].id) : 0;
      if (barcode) {
        const chk = await db.query(`SELECT id, sku FROM inv_item WHERE barcode = ? LIMIT 1`, [barcode]);
        if (chk[0] && String(chk[0].sku) !== sku) {
          stats.errors.push(`${sku}: الباركود مستخدم لمادة ${chk[0].sku}`);
          stats.skipped++;
          continue;
        }
      }
      const payload = {
        id: existingId || 0,
        sku,
        barcode,
        name_ar: nameAr,
        name_en: nameEn || nameAr,
        category_id: categoryId || '',
        unit_id: pcsId,
        default_warehouse_id: warehouseId,
        tax_rate_id: taxRateId,
        default_cost: 0,
        default_sale: salePrice,
        default_wholesale: wholesalePrice,
        is_active: 1,
      };
      if (pack > 1) {
        payload.pack_unit_id = [boxId];
        payload.pack_factor = [pack];
      }
      const res = await masters.saveItem(payload);
      if (!res.ok) {
        stats.errors.push(`${sku}: ${res.error || 'فشل'}`);
        stats.skipped++;
        continue;
      }
      if (existingId) stats.updated++;
      else stats.created++;
      const saved = await db.query(
        `SELECT default_sale, default_wholesale FROM inv_item WHERE sku = ? LIMIT 1`,
        [sku]
      );
      exported.push({
        sku,
        barcode,
        name_ar: nameAr,
        name_en: nameEn || nameAr,
        category,
        pack,
        sale: Number(saved[0]?.default_sale || 0),
        wholesale: Number(saved[0]?.default_wholesale || 0),
      });
    } catch (e) {
      stats.errors.push(`${sku}: ${e.message || e}`);
      stats.skipped++;
    }
  }

  fs.writeFileSync(SQL_OUT, buildServerSql(exported), 'utf8');
  const catCount = await db.query(`SELECT COUNT(*) AS c FROM inv_item_category`);
  const itemCount = await db.query(`SELECT COUNT(*) AS c FROM inv_item`);
  console.log(
    JSON.stringify(
      {
        ok: true,
        sale_rows: sale.length,
        wholesale_rows: wholesale.length,
        categories: Number(catCount[0].c),
        items: Number(itemCount[0].c),
        sql: SQL_OUT,
        stats,
      },
      null,
      2
    )
  );
  await db.getPool().end();
}

main().catch(async (e) => {
  console.error(e.message || e);
  try {
    await db.getPool().end();
  } catch {
    /* */
  }
  process.exit(1);
});
