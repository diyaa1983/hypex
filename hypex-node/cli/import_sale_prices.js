'use strict';

/**
 * استيراد/تحديث سعر البيع من Excel:
 * Item Num, Barcode, Category, Description English, Description Arab, Pack, Unit Price
 *
 * - Unit Price → default_sale على أقل وحدة (قطعة)
 * - المواد الموجودة: يُحدَّث سعر البيع فقط (حتى بعد حركات/قفل البطاقة)
 * - المواد الجديدة: تُنشأ مثل استيراد الجملة مع سعر البيع
 *
 * Usage:
 *   node cli/import_sale_prices.js "C:\\xampp\\htdocs\\hypex\\uploads\\K.A Promo (2).xlsx"
 *   node cli/import_sale_prices.js "path.xlsx" --create
 *   (بدون --create: تحديث فقط؛ مع --create: إنشاء المواد الناقصة)
 */

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const masters = require('../src/inventory/mastersService');
const db = require('../src/db');
const companyDecimals = require('../src/lib/companyDecimals');

const DEFAULT_PATHS = [
  path.join(__dirname, '..', '..', 'uploads', 'K.A Promo (2).xlsx'),
  path.join(__dirname, '..', '..', 'uploads', 'KA_Promo_2.xlsx'),
  'C:\\xampp\\htdocs\\hypex\\uploads\\K.A Promo (2).xlsx',
  'C:\\xampp\\htdocs\\Hypex\\uploads\\K.A Promo (2).xlsx',
  'C:\\xampp\\htdocs\\hypex\\uploads\\KA_Promo_2.xlsx',
];

function resolvePath() {
  const arg = process.argv[2];
  if (arg && !arg.startsWith('--') && fs.existsSync(arg)) return path.resolve(arg);
  for (const p of DEFAULT_PATHS) {
    if (fs.existsSync(p)) return p;
  }
  return null;
}

function hasFlag(name) {
  return process.argv.slice(2).some((a) => a === name || a === `--${name.replace(/^--/, '')}`);
}

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
  if (!php) throw new Error('php غير موجود (جرّب C:\\xampp\\php\\php.exe)');
  const script = path.join(__dirname, 'inv_items_xlsx_to_json.php');
  const r = spawnSync(php, [script, xlsxPath], {
    encoding: 'utf8',
    maxBuffer: 80 * 1024 * 1024,
  });
  if (r.status !== 0) {
    throw new Error((r.stderr || r.stdout || 'فشل قراءة Excel').trim());
  }
  const data = JSON.parse(r.stdout || '{}');
  if (!data.ok || !Array.isArray(data.rows)) {
    throw new Error('صيغة قراءة Excel غير متوقعة');
  }
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
    if (
      h === 'itemnum' ||
      h === 'itemno' ||
      h === 'itemnumber' ||
      h === 'sku' ||
      h === 'رقمالمادة' ||
      h.includes('itemnum')
    ) {
      map.sku = map.sku ?? i;
    } else if (h === 'barcode' || h.includes('barcode') || h === 'باركود') {
      map.barcode = map.barcode ?? i;
    } else if (h === 'category' || h.includes('category') || h === 'فئة' || h === 'تصنيف') {
      map.category = map.category ?? i;
    } else if (
      h === 'descriptionarab' ||
      h === 'descriptionarabic' ||
      h.includes('descriptionarab') ||
      h === 'اسمعربي'
    ) {
      map.name_ar = map.name_ar ?? i;
    } else if (
      h === 'descriptionenglish' ||
      h === 'descriptioneng' ||
      h.includes('descriptionenglish') ||
      h === 'اسمانجليزي'
    ) {
      map.name_en = map.name_en ?? i;
    } else if (h === 'pack' || h === 'التعبئة' || h === 'تعبئة') {
      map.pack = map.pack ?? i;
    }
  });
  header.forEach((raw, i) => {
    const t = String(raw || '')
      .replace(/^\uFEFF/, '')
      .trim()
      .toLowerCase();
    if (t === 'unit price') map.unit_price = i;
  });
  if (map.unit_price == null) {
    header.forEach((raw, i) => {
      const h = normHeader(raw);
      if (h === 'unitprice' || h === 'saleprice' || h === 'سعرالبيع' || h === 'سعرالوحدة') {
        map.unit_price = map.unit_price ?? i;
      }
    });
  }
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
  const any = await db.query(
    `SELECT id FROM inv_warehouse WHERE is_active = 1 ORDER BY id LIMIT 1`
  );
  if (any[0]?.id) return Number(any[0].id);
  const fallback = await db.query(`SELECT id FROM inv_warehouse ORDER BY id LIMIT 1`);
  if (fallback[0]?.id) return Number(fallback[0].id);
  throw new Error('لا يوجد مستودع في النظام.');
}

async function resolveTax16Id() {
  const exact = await db.query(
    `SELECT id FROM sys_tax_rate
     WHERE is_active = 1 AND ABS(rate_percent - 16) < 0.001
     ORDER BY id LIMIT 1`
  );
  if (exact[0]?.id) return Number(exact[0].id);
  const inactive = await db.query(
    `SELECT id FROM sys_tax_rate WHERE ABS(rate_percent - 16) < 0.001 ORDER BY id LIMIT 1`
  );
  if (inactive[0]?.id) {
    await db.query(`UPDATE sys_tax_rate SET is_active = 1 WHERE id = ?`, [inactive[0].id]);
    return Number(inactive[0].id);
  }
  try {
    const [result] = await db.getPool().execute(
      `INSERT INTO sys_tax_rate (name_ar, rate_percent, sort_order, is_active) VALUES ('16%', 16, 1, 1)`
    );
    return Number(result.insertId);
  } catch {
    const [result] = await db.getPool().execute(
      `INSERT INTO sys_tax_rate (name_ar, rate_percent, is_active) VALUES ('16%', 16, 1)`
    );
    return Number(result.insertId);
  }
}

async function resolveCategoryId(name, cache) {
  const key = name.trim().toLowerCase();
  if (!key) return null;
  if (cache.has(key)) return cache.get(key);
  const existing = await db.query(
    `SELECT id FROM inv_item_category WHERE LOWER(TRIM(name_ar)) = ? OR LOWER(TRIM(code)) = ? LIMIT 1`,
    [key, key]
  );
  if (existing[0]?.id) {
    const id = Number(existing[0].id);
    cache.set(key, id);
    return id;
  }
  const r = await masters.saveCategory({ name_ar: name.trim() });
  if (!r.ok) throw new Error(r.error || `تعذر إنشاء فئة: ${name}`);
  cache.set(key, Number(r.id));
  return Number(r.id);
}

async function findItemIdBySkuOrBarcode(sku, barcode) {
  if (sku) {
    const bySku = await db.query(`SELECT id FROM inv_item WHERE sku = ? LIMIT 1`, [sku]);
    if (bySku[0]?.id) return Number(bySku[0].id);
  }
  if (barcode) {
    const byBc = await db.query(`SELECT id FROM inv_item WHERE barcode = ? LIMIT 1`, [barcode]);
    if (byBc[0]?.id) return Number(byBc[0].id);
  }
  return 0;
}

async function updateSalePrice(itemId, sale) {
  await db.query(`UPDATE inv_item SET default_sale = ? WHERE id = ?`, [sale, itemId]);
}

async function main() {
  const allowCreate = hasFlag('--create') || hasFlag('create');
  const xlsxPath = resolvePath();
  if (!xlsxPath) {
    console.error(
      JSON.stringify(
        {
          ok: false,
          error: 'ملف Excel غير موجود. مرّر المسار: node cli/import_sale_prices.js "C:\\\\path\\\\file.xlsx"',
          tried: DEFAULT_PATHS,
        },
        null,
        2
      )
    );
    process.exit(1);
  }

  await companyDecimals.load(true);

  console.error('Reading:', xlsxPath);
  console.error('Mode:', allowCreate ? 'update + create' : 'update sale only');
  const rows = loadRows(xlsxPath);
  if (rows.length < 2) {
    console.error(JSON.stringify({ ok: false, error: 'الملف فارغ' }, null, 2));
    process.exit(1);
  }

  const map = mapColumns(rows[0]);
  const needed = ['sku', 'unit_price'];
  const missing = needed.filter((k) => map[k] == null);
  if (missing.length) {
    console.error(
      JSON.stringify(
        { ok: false, error: 'أعمدة ناقصة: ' + missing.join(', '), header: rows[0], map },
        null,
        2
      )
    );
    process.exit(1);
  }

  const pcsId = await ensureUnit('PCS', 'قطعة');
  const boxId = await ensureUnit('BOX', 'كرتونة');
  let warehouseId = null;
  let taxRateId = null;
  if (allowCreate) {
    warehouseId = await resolveDefaultWarehouseId();
    taxRateId = await resolveTax16Id();
  }

  const catCache = new Map();
  const stats = {
    path: xlsxPath,
    mode: allowCreate ? 'create_and_update' : 'update_sale_only',
    unit_dp: companyDecimals.unitPlaces(),
    total_data_rows: 0,
    updated: 0,
    created: 0,
    skipped: 0,
    not_found: 0,
    zero_price: 0,
    errors: [],
    warnings: [],
    columns: map,
  };

  for (let i = 1; i < rows.length; i++) {
    const row = rows[i];
    if (!Array.isArray(row) || row.every((c) => String(c ?? '').trim() === '')) continue;

    const sku = cell(row, map.sku);
    const barcode = map.barcode != null ? barcodeDigits(cell(row, map.barcode)) : '';
    const catName = map.category != null ? cell(row, map.category) : '';
    let nameAr = map.name_ar != null ? cell(row, map.name_ar) : '';
    const nameEn = map.name_en != null ? cell(row, map.name_en) : '';
    const pack = Math.max(1, Math.round(num(map.pack != null ? cell(row, map.pack) : 1)) || 1);
    const saleRaw = num(cell(row, map.unit_price));
    const sale = companyDecimals.roundUnit(saleRaw);

    if (!sku && !barcode) {
      stats.skipped++;
      continue;
    }
    stats.total_data_rows++;

    if (!sku) {
      stats.skipped++;
      stats.errors.push(`صف ${i + 1}: رقم مادة فارغ`);
      continue;
    }

    try {
      const existingId = await findItemIdBySkuOrBarcode(sku, barcode);

      if (existingId) {
        await updateSalePrice(existingId, sale);
        stats.updated++;
        if (!(sale > 0)) stats.zero_price++;
        continue;
      }

      if (!allowCreate) {
        stats.not_found++;
        stats.warnings.push(`صف ${i + 1} (${sku}): مادة غير موجودة — لم تُحدَّث`);
        continue;
      }

      if (!nameAr) nameAr = nameEn || sku || barcode;
      if (!nameAr) {
        stats.skipped++;
        stats.warnings.push(`صف ${i + 1}: بدون اسم — لا إنشاء`);
        continue;
      }

      const categoryId = catName ? await resolveCategoryId(catName, catCache) : null;
      if (barcode) {
        const chk = await db.query(`SELECT id, sku FROM inv_item WHERE barcode = ? LIMIT 1`, [barcode]);
        if (chk[0] && String(chk[0].sku) !== sku) {
          stats.errors.push(`صف ${i + 1}: الباركود ${barcode} مستخدم لمادة ${chk[0].sku}`);
          stats.skipped++;
          continue;
        }
      }

      const payload = {
        id: 0,
        sku,
        barcode: barcode || '',
        name_ar: nameAr,
        name_en: nameEn || nameAr,
        category_id: categoryId || '',
        unit_id: pcsId,
        default_warehouse_id: warehouseId,
        tax_rate_id: taxRateId,
        default_cost: 0,
        default_sale: sale,
        default_wholesale: 0,
        is_active: 1,
      };
      if (pack > 1) {
        payload.pack_unit_id = [boxId];
        payload.pack_factor = [pack];
      }

      const res = await masters.saveItem(payload);
      if (!res.ok) {
        stats.errors.push(`صف ${i + 1} (${sku}): ${res.error || 'فشل الإنشاء'}`);
        stats.skipped++;
        continue;
      }
      stats.created++;
      if (!(sale > 0)) stats.zero_price++;
    } catch (e) {
      stats.errors.push(`صف ${i + 1} (${sku}): ${e.message || e}`);
      stats.skipped++;
    }
  }

  console.log(
    JSON.stringify(
      {
        ok: true,
        message: 'اكتمل تحديث أسعار البيع',
        stats,
      },
      null,
      2
    )
  );
  await db.getPool().end();
}

main().catch(async (e) => {
  console.error(JSON.stringify({ ok: false, error: e.message || String(e) }, null, 2));
  try {
    await db.getPool().end();
  } catch {
    /* */
  }
  process.exit(1);
});
