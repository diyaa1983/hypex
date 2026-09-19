'use strict';

/**
 * تصدير المواد المستوردة (SKU من Excel) كـ SQL جاهز للسيرفر.
 * Usage: node cli/export_wholesale_items_sql.js [xlsx] [out.sql]
 */

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const db = require('../src/db');

const DEFAULT_XLSX = path.join(__dirname, '..', '..', 'uploads', 'Retail_Whol_Price_2.xlsx');
const DEFAULT_OUT = path.join(__dirname, '..', '..', 'uploads', 'wholesale_items_import.sql');

function findPhp() {
  for (const bin of [process.env.PHP_BIN, 'C:\\xampp\\php\\php.exe', 'php'].filter(Boolean)) {
    if (spawnSync(bin, ['-v'], { encoding: 'utf8' }).status === 0) return bin;
  }
  return null;
}

function loadRows(xlsxPath) {
  const php = findPhp();
  if (!php) throw new Error('php missing');
  const script = path.join(__dirname, 'inv_items_xlsx_to_json.php');
  const r = spawnSync(php, [script, xlsxPath], { encoding: 'utf8', maxBuffer: 80 * 1024 * 1024 });
  if (r.status !== 0) throw new Error((r.stderr || r.stdout || 'xlsx fail').trim());
  return JSON.parse(r.stdout).rows || [];
}

function esc(v) {
  if (v === null || v === undefined) return 'NULL';
  return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

async function main() {
  const xlsx = process.argv[2] || DEFAULT_XLSX;
  const outFile = process.argv[3] || DEFAULT_OUT;
  if (!fs.existsSync(xlsx)) throw new Error('xlsx missing: ' + xlsx);

  const rows = loadRows(xlsx);
  const skus = [];
  for (let i = 1; i < rows.length; i++) {
    const sku = String(rows[i][0] ?? '').trim();
    if (sku) skus.push(sku);
  }
  if (!skus.length) throw new Error('no skus');

  const placeholders = skus.map(() => '?').join(',');
  const items = await db.query(
    `SELECT * FROM inv_item WHERE sku IN (${placeholders}) ORDER BY id`,
    skus
  );
  const ids = items.map((r) => Number(r.id));
  if (!ids.length) throw new Error('no items in local DB for excel skus');

  const catIds = [...new Set(items.map((r) => Number(r.category_id || 0)).filter(Boolean))];
  let cats = [];
  if (catIds.length) {
    cats = await db.query(
      `SELECT * FROM inv_item_category WHERE id IN (${catIds.map(() => '?').join(',')})`,
      catIds
    );
  }

  const units = await db.query(`SELECT * FROM inv_item_unit WHERE item_id IN (${ids.map(() => '?').join(',')})`, ids);

  // also PCS/BOX unit masters by code
  const unitMasters = await db.query(
    `SELECT * FROM inv_unit WHERE code IN ('PCS','BOX') OR name_ar IN ('قطعة','كرتون','كرتونة')`
  );

  const lines = [];
  lines.push('-- Hypex wholesale items export — apply on SERVER MySQL');
  lines.push('-- Generated: ' + new Date().toISOString());
  lines.push('SET NAMES utf8mb4;');
  lines.push('SET FOREIGN_KEY_CHECKS=0;');
  lines.push('');

  lines.push('-- Units PCS / BOX');
  for (const u of unitMasters) {
    lines.push(
      `INSERT INTO inv_unit (code, name_ar, is_active) VALUES (${esc(u.code)}, ${esc(u.name_ar)}, ${Number(u.is_active) ? 1 : 0})
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), is_active=VALUES(is_active);`
    );
  }
  lines.push('');

  lines.push('-- Categories by name (server may have different ids)');
  for (const c of cats) {
    lines.push(
      `INSERT INTO inv_item_category (code, name_ar, is_active)
SELECT ${esc(c.code)}, ${esc(c.name_ar)}, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_item_category WHERE TRIM(name_ar)=${esc(String(c.name_ar).trim())});`
    );
  }
  lines.push('');

  lines.push('-- Items (match by sku)');
  const itemCols = Object.keys(items[0] || {}).filter((k) => k !== 'id' && k !== 'created_at');
  // remove category_id from raw insert; set via name join later
  const writeCols = itemCols.filter((c) => c !== 'category_id' && c !== 'unit_id');

  for (const it of items) {
    const catName = cats.find((c) => Number(c.id) === Number(it.category_id))?.name_ar || '';
    const sets = writeCols.map((c) => `\`${c}\`=${esc(it[c])}`).join(', ');
    lines.push(
      `INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
VALUES (${esc(it.sku)}, ${esc(it.name_ar)}, ${esc(it.unit_name || 'قطعة')}, ${esc(it.default_cost)}, ${esc(it.default_sale)}, 1, 1)
ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar), unit_name=VALUES(unit_name);`
    );
    // update extended fields + category + pcs unit
    lines.push(
      `UPDATE inv_item i
LEFT JOIN inv_item_category c ON TRIM(c.name_ar)=${esc(String(catName).trim())}
LEFT JOIN inv_unit pcs ON pcs.code='PCS' OR pcs.name_ar='قطعة'
SET i.barcode=${esc(it.barcode)},
    i.name_en=${esc(it.name_en)},
    i.default_wholesale=${esc(it.default_wholesale)},
    i.default_sale=${esc(it.default_sale)},
    i.default_cost=${esc(it.default_cost)},
    i.category_id=c.id,
    i.unit_id=pcs.id,
    i.unit_name=COALESCE(pcs.name_ar,'قطعة'),
    i.is_active=1,
    i.track_inventory=1
WHERE i.sku=${esc(it.sku)};`
    );
  }
  lines.push('');

  lines.push('-- Item units: base PCS + BOX pack if any');
  // group packs by local item sku
  const itemById = new Map(items.map((i) => [Number(i.id), i]));
  const unitById = new Map();
  // get unit names for factors
  const unitIds = [...new Set(units.map((u) => Number(u.unit_id)))];
  let unitRows = [];
  if (unitIds.length) {
    unitRows = await db.query(
      `SELECT id, code, name_ar FROM inv_unit WHERE id IN (${unitIds.map(() => '?').join(',')})`,
      unitIds
    );
  }
  const umap = new Map(unitRows.map((u) => [Number(u.id), u]));

  for (const it of items) {
    const sku = it.sku;
    const itsUnits = units.filter((u) => Number(u.item_id) === Number(it.id));
    lines.push(`-- units for ${sku}`);
    lines.push(
      `DELETE iu FROM inv_item_unit iu INNER JOIN inv_item i ON i.id=iu.item_id WHERE i.sku=${esc(sku)};`
    );
    for (const u of itsUnits) {
      const meta = umap.get(Number(u.unit_id));
      const code = meta?.code || 'PCS';
      const isBase = Number(u.is_base) === 1 ? 1 : 0;
      const isDef = Number(u.is_default_issue) === 1 ? 1 : 0;
      const factor = u.factor_to_base;
      lines.push(
        `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
SELECT i.id, u.id, ${esc(factor)}, ${isBase}, ${isDef}
FROM inv_item i
CROSS JOIN inv_unit u
WHERE i.sku=${esc(sku)} AND (u.code=${esc(code)} OR u.name_ar=${esc(meta?.name_ar || '')})
LIMIT 1;`
      );
    }
  }

  lines.push('');
  lines.push('SET FOREIGN_KEY_CHECKS=1;');
  lines.push('-- done');

  fs.writeFileSync(outFile, lines.join('\n'), 'utf8');
  console.log(JSON.stringify({ ok: true, items: items.length, cats: cats.length, out: outFile }, null, 2));
  await db.getPool().end();
}

main().catch(async (e) => {
  console.error(JSON.stringify({ ok: false, error: e.message }, null, 2));
  try {
    await db.getPool().end();
  } catch {
    /* */
  }
  process.exit(1);
});
