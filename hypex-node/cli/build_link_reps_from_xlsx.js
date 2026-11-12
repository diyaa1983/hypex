/**
 * يُنشئ items/link-reps-regions-customers.sql من New folder/المندوبين.xlsx
 * مصدر المندوب: ورقة Sheet1 (رقم عميل + اسم مندوب) — نفس ما تُحسبه في الإكسل.
 * المنطقة: من جولة المندوبين ثم ورقة 1 للعميل.
 * Usage: node hypex-node/cli/build_link_reps_from_xlsx.js
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const XLSX_DIR = path.join(__dirname, '../../New folder');
const OUT_SQL = path.join(__dirname, '../../items/link-reps-regions-customers.sql');

const xlsx = fs.readdirSync(XLSX_DIR).find((f) => f.endsWith('.xlsx') && !f.startsWith('~$'));
if (!xlsx) {
  console.error('Missing المندوبين.xlsx in New folder');
  process.exit(1);
}

const tmp = path.join(XLSX_DIR, '_build_unz');
fs.rmSync(tmp, { recursive: true, force: true });
fs.mkdirSync(tmp);
const zip = path.join(tmp, 'b.zip');
fs.copyFileSync(path.join(XLSX_DIR, xlsx), zip);
execSync(
  `powershell -NoProfile -Command "Expand-Archive -LiteralPath '${zip}' -DestinationPath '${path.join(tmp, 'u')}' -Force"`
);
const root = path.join(tmp, 'u', 'xl');
const ssXml = fs.readFileSync(path.join(root, 'sharedStrings.xml'), 'utf8');
const strings = [];
let m;
const re = /<si>([\s\S]*?)<\/si>/g;
while ((m = re.exec(ssXml))) {
  strings.push(
    [...m[1].matchAll(/<t[^>]*>([\s\S]*?)<\/t>/g)]
      .map((x) =>
        x[1]
          .replace(/&amp;/g, '&')
          .replace(/&lt;/g, '<')
          .replace(/&gt;/g, '>')
          .replace(/&quot;/g, '"')
      )
      .join('')
  );
}
function colIndex(ref) {
  let n = 0;
  for (const ch of String(ref || '').replace(/\d/g, '')) n = n * 26 + (ch.charCodeAt(0) - 64);
  return n - 1;
}
function allRows(file) {
  const xml = fs.readFileSync(path.join(root, 'worksheets', file), 'utf8');
  const rows = [];
  const rowRe = /<row[^>]*>([\s\S]*?)<\/row>/g;
  let rm;
  while ((rm = rowRe.exec(xml))) {
    const cells = [];
    const cellRe = /<c([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g;
    let cm;
    while ((cm = cellRe.exec(rm[1]))) {
      const ref = (cm[1].match(/r="([^"]+)"/) || [])[1] || '';
      const t = (cm[1].match(/t="([^"]+)"/) || [])[1] || '';
      const body = cm[2] || '';
      const v = (body.match(/<v>([\s\S]*?)<\/v>/) || [])[1] || '';
      const idx = colIndex(ref);
      if (idx >= 0) {
        let val = v;
        if (t === 's') val = strings[Number(v)] || '';
        cells[idx] = val;
      }
    }
    rows.push(cells);
  }
  return rows;
}

const nameToCode = {
  شريف: '40',
  فادي: '43',
  سامي: '43',
  'محمد حسن': '30',
  'جاد الحاج': '38',
  ركان: '41',
  'سامر ابو جاموس': '37',
  'سامي عريقات': '3',
  'علي حياصات': '12',
  وجدي: '36',
  إبراهيم: '39',
  ابراهيم: '39',
};
const codeToName = {
  '3': 'سامي عريقات',
  '12': 'علي حياصات',
  '30': 'محمد حسن',
  '36': 'وجدي',
  '37': 'سامر ابو جاموس',
  '38': 'جاد الحاج',
  '39': 'إبراهيم',
  '40': 'شريف',
  '41': 'ركان',
  '43': 'فادي',
};

const listRows = allRows('sheet3.xml').slice(1);
const tourRows = allRows('sheet2.xml').slice(1);
const extraRows = allRows('sheet1.xml').slice(1);

const regionByCust = {};
function noteRegion(rows, idx) {
  rows.forEach((r) => {
    const cust = String(r[0] || '').trim();
    const region = String(r[3] || '').trim();
    if (!/^\d{5,}$/.test(cust) || !region) return;
    if (!regionByCust[cust]) regionByCust[cust] = region;
  });
}
noteRegion(tourRows, 3);
noteRegion(extraRows, 3);

const byCust = {};
const dupRep = [];
listRows.forEach((r) => {
  const cust = String(r[0] || '').trim();
  const repName = String(r[2] || '').trim();
  if (!/^\d{5,}$/.test(cust) || !repName || repName === '#N/A') return;
  const repCode = nameToCode[repName] || '';
  if (!codeToName[repCode]) return;
  if (byCust[cust] && byCust[cust].repCode !== repCode) {
    dupRep.push({ cust, was: byCust[cust].repCode, now: repCode });
  }
  byCust[cust] = { repCode, region: regionByCust[cust] || '' };
});

const customers = Object.entries(byCust).map(([cust_code, v]) => ({
  cust_code,
  rep_code: v.repCode,
  region_name: v.region,
}));

const regionsByRep = {};
customers.forEach((c) => {
  if (!c.region_name) return;
  if (!regionsByRep[c.rep_code]) regionsByRep[c.rep_code] = new Set();
  regionsByRep[c.rep_code].add(c.region_name);
});
const regionCode = {
  'عمان الغربية': 'RG-WEST',
  'شمال عمان': 'RG-NAMMAN',
  'وسط عمان': 'RG-CENTRAL',
  الشمال: 'RG-NORTH',
  الزرقاء: 'RG-ZARQA',
  'عمان الشرقية': 'RG-EAST',
  الجنوب: 'RG-SOUTH',
};

const q = (s) => "'" + String(s).replace(/'/g, "''") + "'";
const excelCount = {};
customers.forEach((c) => {
  excelCount[c.rep_code] = (excelCount[c.rep_code] || 0) + 1;
});

const lines = [];
lines.push('-- ربط المندوبين من ملف المندوبين.xlsx — مصدر العدد: ورقة Sheet1 (عميل + مندوب)');
lines.push('-- نفّذه على قاعدة السيرفر (hypex) من Workbench. يلغي الربط القديم ثم يعيده من الملف.');
lines.push(`-- عملاء في الملف: ${customers.length} | تعارض اسم مندوب لنفس العميل في Sheet1: ${dupRep.length}`);
Object.keys(codeToName)
  .sort((a, b) => Number(a) - Number(b))
  .forEach((code) => {
    lines.push(`-- مندوب ${code} ${codeToName[code]}: ${excelCount[code] || 0} عميل في الإكسل`);
  });
lines.push('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;');
lines.push('');
lines.push('CREATE TABLE IF NOT EXISTS crm_sales_rep_region (');
lines.push('  sales_rep_id INT UNSIGNED NOT NULL,');
lines.push('  region_id INT UNSIGNED NOT NULL,');
lines.push('  sort_order INT NOT NULL DEFAULT 0,');
lines.push('  PRIMARY KEY (sales_rep_id, region_id),');
lines.push('  KEY idx_csrr_region (region_id)');
lines.push(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
lines.push('');
for (const [name, code] of Object.entries(regionCode)) {
  lines.push(
    `INSERT INTO crm_region (code, name_ar, sort_order, is_active)
SELECT ${q(code)}, ${q(name)}, 0, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM crm_region WHERE name_ar = ${q(name)});`
  );
}
lines.push('');
for (const [code, name] of Object.entries(codeToName)) {
  lines.push(
    `INSERT INTO crm_sales_rep (code, name_ar, is_active)
SELECT ${q(code)}, ${q(name)}, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM crm_sales_rep WHERE code = ${q(code)});`
  );
  lines.push(`UPDATE crm_sales_rep SET name_ar = ${q(name)}, is_active = 1 WHERE code = ${q(code)};`);
}
lines.push('');
lines.push('DROP TEMPORARY TABLE IF EXISTS tmp_rep_cust;');
lines.push('CREATE TEMPORARY TABLE tmp_rep_cust (');
lines.push('  cust_code VARCHAR(40) NOT NULL,');
lines.push('  rep_code VARCHAR(40) NOT NULL,');
lines.push('  region_name VARCHAR(120) NULL,');
lines.push('  PRIMARY KEY (cust_code)');
lines.push(') ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;');
for (let i = 0; i < customers.length; i += 80) {
  const chunk = customers.slice(i, i + 80);
  lines.push('INSERT INTO tmp_rep_cust (cust_code, rep_code, region_name) VALUES');
  lines.push(
    chunk
      .map((c) => `  (${q(c.cust_code)}, ${q(c.rep_code)}, ${c.region_name ? q(c.region_name) : 'NULL'})`)
      .join(',\n') + ';'
  );
}
lines.push('');
lines.push('DELETE FROM crm_sales_rep_region WHERE sales_rep_id > 0;');
let sort = 0;
for (const [repCode, regions] of Object.entries(regionsByRep)) {
  for (const region of regions) {
    lines.push(
      `INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)
SELECT r.id, g.id, ${sort}
FROM crm_sales_rep r
JOIN crm_region g ON g.name_ar = ${q(region)}
WHERE r.code = ${q(repCode)};`
    );
    sort += 1;
  }
}
lines.push('');
lines.push('UPDATE crm_customer SET sales_rep_id = NULL, region_id = NULL WHERE id > 0;');
lines.push('DELETE FROM crm_customer_sales_rep WHERE customer_id > 0;');
lines.push('');
lines.push(`UPDATE crm_customer c
JOIN tmp_rep_cust t ON (
  TRIM(c.code) = t.cust_code
  OR TRIM(IFNULL(c.oracle_key, '')) = t.cust_code
  OR REGEXP_REPLACE(TRIM(c.code), '[^0-9]', '') = t.cust_code
)
JOIN crm_sales_rep r ON r.code = t.rep_code
LEFT JOIN crm_region g ON g.name_ar = t.region_name
SET c.sales_rep_id = r.id,
    c.region_id = g.id
WHERE c.id > 0;`);
lines.push('');
lines.push(`INSERT INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order)
SELECT c.id, r.id, 0
FROM crm_customer c
JOIN tmp_rep_cust t ON (
  TRIM(c.code) = t.cust_code
  OR TRIM(IFNULL(c.oracle_key, '')) = t.cust_code
  OR REGEXP_REPLACE(TRIM(c.code), '[^0-9]', '') = t.cust_code
)
JOIN crm_sales_rep r ON r.code = t.rep_code
GROUP BY c.id, r.id;`);
lines.push('');
lines.push('-- مقارنة أعداد الإكسل (tmp) مع ما رُبط فعلياً في النظام');
lines.push(`SELECT r.code AS rep_code, r.name_ar AS rep_name,
       SUM(CASE WHEN t.cust_code IS NOT NULL THEN 1 ELSE 0 END) AS excel_rows,
       COUNT(DISTINCT c.id) AS linked_customers
FROM crm_sales_rep r
LEFT JOIN tmp_rep_cust t ON t.rep_code = r.code
LEFT JOIN crm_customer c ON c.sales_rep_id = r.id
WHERE r.code IN (${Object.keys(codeToName).map(q).join(', ')})
GROUP BY r.id, r.code, r.name_ar
ORDER BY CAST(r.code AS UNSIGNED);`);
lines.push('');
lines.push(`SELECT t.cust_code, t.rep_code, 'غير موجود في crm_customer' AS note
FROM tmp_rep_cust t
WHERE NOT EXISTS (
  SELECT 1 FROM crm_customer c
  WHERE TRIM(c.code) = t.cust_code
     OR TRIM(IFNULL(c.oracle_key, '')) = t.cust_code
     OR REGEXP_REPLACE(TRIM(c.code), '[^0-9]', '') = t.cust_code
)
LIMIT 50;`);
lines.push('');
lines.push('DROP TEMPORARY TABLE IF EXISTS tmp_rep_cust;');

fs.writeFileSync(OUT_SQL, lines.join('\n') + '\n', 'utf8');
fs.rmSync(tmp, { recursive: true, force: true });
console.log('Wrote', OUT_SQL, 'customers', customers.length, 'dupRep', dupRep.length);
console.log('Excel counts:', excelCount);
