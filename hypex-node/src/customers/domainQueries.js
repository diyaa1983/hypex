'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('customers query', e.message);
    return [];
  }
}

const repNamesSub = `(SELECT GROUP_CONCAT(r2.name_ar ORDER BY csr2.sort_order, r2.name_ar SEPARATOR '، ')
                FROM crm_customer_sales_rep csr2
                INNER JOIN crm_sales_rep r2 ON r2.id = csr2.sales_rep_id
                WHERE csr2.customer_id = c.id)`;

function customerListWhere({
  q = '',
  activeOnly = true,
  regionId = 0,
  salesRepId = 0,
  oraclePendingOnly = false,
} = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('c.is_active = 1');
  if (oraclePendingOnly) {
    where.push(`(
      (c.oracle_key IS NULL OR c.oracle_key = '')
      OR c.oracle_pending = 1
      OR c.code LIKE 'P-%'
    )`);
  }
  if (regionId > 0) {
    where.push('c.region_id = ?');
    params.push(regionId);
  }
  if (salesRepId > 0) {
    where.push(`(
      c.sales_rep_id = ?
      OR EXISTS (
        SELECT 1 FROM crm_customer_sales_rep csr
        WHERE csr.customer_id = c.id AND csr.sales_rep_id = ?
      )
    )`);
    params.push(salesRepId, salesRepId);
  }
  if (q) {
    const like = `%${q}%`;
    where.push(`(
      c.name_ar LIKE ? OR c.code LIKE ? OR IFNULL(c.phone,'') LIKE ?
      OR IFNULL(c.email,'') LIKE ? OR IFNULL(c.tax_number,'') LIKE ?
      OR IFNULL(rg.name_ar,'') LIKE ? OR IFNULL(r.name_ar,'') LIKE ?
      OR IFNULL(${repNamesSub},'') LIKE ?
    )`);
    params.push(like, like, like, like, like, like, like, like);
  }
  return { where, params };
}

const CUSTOMER_LIST_FROM = `FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
     LEFT JOIN crm_region rg ON rg.id = c.region_id
     LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id`;

async function countCustomers(opts = {}) {
  const { where, params } = customerListWhere(opts);
  const rows = await safeQuery(
    `SELECT COUNT(*) AS c ${CUSTOMER_LIST_FROM}
     WHERE ${where.join(' AND ')}`,
    params
  );
  return Number((rows && rows[0] && rows[0].c) || 0);
}

async function listCustomers({
  q = '',
  activeOnly = true,
  regionId = 0,
  salesRepId = 0,
  oraclePendingOnly = false,
  limit = 10000,
} = {}) {
  const { where, params } = customerListWhere({
    q,
    activeOnly,
    regionId,
    salesRepId,
    oraclePendingOnly,
  });
  const cap = Math.min(20000, Math.max(1, Number(limit) || 10000));
  return safeQuery(
    `SELECT c.id, c.code, c.name_ar, c.phone, c.email, c.tax_number, c.is_active,
            c.oracle_key, c.oracle_pending, c.payment_period, c.region_id,
            rg.name_ar AS region_name,
            ra.name_ar AS region_address_name,
            COALESCE(${repNamesSub}, r.name_ar) AS sales_rep_name
     ${CUSTOMER_LIST_FROM}
     WHERE ${where.join(' AND ')}
     ORDER BY c.id DESC
     LIMIT ${cap}`,
    params
  );
}

async function listRegions({ q = '', activeOnly = true, limit = 200 } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('r.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(r.name_ar LIKE ? OR IFNULL(r.code,'') LIKE ?
        OR EXISTS (
          SELECT 1 FROM crm_region_address a
          WHERE a.region_id = r.id AND a.name_ar LIKE ?
        ))`
    );
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT r.id, r.code, r.name_ar, r.is_active, r.sort_order,
            (SELECT COUNT(*) FROM crm_customer c WHERE c.region_id = r.id) AS customer_count,
            (SELECT COUNT(*) FROM crm_region_address a WHERE a.region_id = r.id) AS address_count
     FROM crm_region r
     WHERE ${where.join(' AND ')}
     ORDER BY r.sort_order ASC, r.name_ar ASC, r.id ASC
     LIMIT ${Math.min(300, limit)}`,
    params
  );
}

async function listRegionAddresses(regionId) {
  if (!regionId) return [];
  return safeQuery(
    `SELECT a.id, a.name_ar, a.is_active, a.sort_order,
            (SELECT COUNT(*) FROM crm_customer c WHERE c.region_address_id = a.id) AS customer_count
     FROM crm_region_address a
     WHERE a.region_id = ?
     ORDER BY a.sort_order ASC, a.name_ar ASC`,
    [regionId]
  );
}

async function regionOptions() {
  return safeQuery(
    `SELECT id, code, name_ar FROM crm_region WHERE is_active = 1 ORDER BY sort_order, name_ar`
  );
}

async function salesRepOptions() {
  return safeQuery(
    `SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar`
  );
}

async function reportCustomers({ activeOnly = false } = {}) {
  const where = activeOnly ? 'WHERE c.is_active = 1' : '';
  return safeQuery(
    `SELECT c.code AS customer_code, c.name_ar AS customer_name,
            COALESCE(c.phone,'') AS phone, COALESCE(c.email,'') AS email,
            COALESCE(c.tax_number,'') AS tax_number, COALESCE(c.address_ar,'') AS address_ar,
            COALESCE(NULLIF(TRIM(r.name_ar), ''), '') AS sales_rep_name, c.is_active,
            COALESCE(NULLIF(TRIM(rg.name_ar), ''), '') AS region_name,
            COALESCE(NULLIF(TRIM(ra.name_ar), ''), '') AS region_address_name
     FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
     LEFT JOIN crm_region rg ON rg.id = c.region_id
     LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id
     ${where}
     ORDER BY c.name_ar ASC, c.code ASC
     LIMIT 2000`
  );
}

async function reportCustomersByRep({ activeOnly = false, salesRepId = 0 } = {}) {
  const params = [];
  const activeSql = activeOnly ? ' AND c.is_active = 1' : '';
  let repSql = '';
  if (salesRepId > 0) {
    repSql = ' AND COALESCE(r.id, 0) = ?';
    params.push(salesRepId);
  }
  return safeQuery(
    `SELECT
        c.id AS customer_id,
        c.code AS customer_code,
        c.name_ar AS customer_name,
        COALESCE(c.phone,'') AS phone,
        COALESCE(c.email,'') AS email,
        COALESCE(c.tax_number,'') AS tax_number,
        COALESCE(c.address_ar,'') AS address_ar,
        c.is_active,
        COALESCE(r.id, 0) AS rep_id,
        COALESCE(NULLIF(TRIM(r.name_ar), ''), '— بدون مندوب —') AS rep_name,
        COALESCE(r.code, '') AS rep_code,
        COALESCE(NULLIF(TRIM(rg.name_ar), ''), '') AS region_name,
        COALESCE(NULLIF(TRIM(ra.name_ar), ''), '') AS region_address_name
     FROM crm_customer c
     LEFT JOIN (
         SELECT customer_id, MIN(sales_rep_id) AS sales_rep_id
         FROM (
           SELECT customer_id, sales_rep_id FROM crm_customer_sales_rep
           UNION ALL
           SELECT id AS customer_id, sales_rep_id
           FROM crm_customer
           WHERE sales_rep_id IS NOT NULL AND sales_rep_id > 0
         ) u
         GROUP BY customer_id
     ) map ON map.customer_id = c.id
     LEFT JOIN crm_sales_rep r ON r.id = COALESCE(map.sales_rep_id, c.sales_rep_id)
     LEFT JOIN crm_region rg ON rg.id = c.region_id
     LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id
     WHERE 1=1${activeSql}${repSql}
     ORDER BY
       rep_id ASC,
       rep_name ASC,
       region_name ASC,
       region_address_name ASC,
       customer_name ASC,
       customer_code ASC
     LIMIT 3000`,
    params
  );
}

async function oracleLinkedCount() {
  const rows = await safeQuery(
    `SELECT COUNT(*) AS c FROM crm_customer
     WHERE oracle_key IS NOT NULL AND oracle_key <> '' AND code LIKE '112%'`
  );
  return Number(rows[0]?.c || 0);
}

/**
 * تقرير العناوين والمنطقة: كل منطقة + عناوينها + المندوب/المندوبين المربوطين
 * (على مستوى العنوان، وإلا تغطية المنطقة كاحتياطي).
 */
async function reportRegionAddresses({ activeOnly = false, regionId = 0 } = {}) {
  const params = [];
  const where = ['1=1'];
  if (activeOnly) {
    where.push('rg.is_active = 1');
    where.push('(a.id IS NULL OR a.is_active = 1)');
  }
  if (regionId > 0) {
    where.push('rg.id = ?');
    params.push(regionId);
  }
  return safeQuery(
    `SELECT
        rg.id AS region_id,
        COALESCE(rg.code, '') AS region_code,
        rg.name_ar AS region_name,
        rg.is_active AS region_active,
        COALESCE(a.id, 0) AS address_id,
        COALESCE(a.name_ar, '') AS address_name,
        COALESCE(a.is_active, 0) AS address_active,
        COALESCE(
          NULLIF(TRIM((
            SELECT GROUP_CONCAT(DISTINCT sr.name_ar ORDER BY sr.name_ar SEPARATOR '، ')
            FROM crm_sales_rep_region_address sra
            INNER JOIN crm_sales_rep sr ON sr.id = sra.sales_rep_id AND sr.is_active = 1
            WHERE sra.region_address_id = a.id
          )), ''),
          NULLIF(TRIM((
            SELECT GROUP_CONCAT(DISTINCT sr.name_ar ORDER BY sr.name_ar SEPARATOR '، ')
            FROM crm_sales_rep_region srr
            INNER JOIN crm_sales_rep sr ON sr.id = srr.sales_rep_id AND sr.is_active = 1
            WHERE srr.region_id = rg.id
          )), ''),
          ''
        ) AS sales_rep_name,
        CASE
          WHEN a.id IS NOT NULL THEN (
            SELECT COUNT(*) FROM crm_customer c WHERE c.region_address_id = a.id
          )
          ELSE (
            SELECT COUNT(*) FROM crm_customer c
            WHERE c.region_id = rg.id
              AND (c.region_address_id IS NULL OR c.region_address_id = 0)
          )
        END AS customer_count
     FROM crm_region rg
     LEFT JOIN crm_region_address a ON a.region_id = rg.id
     WHERE ${where.join(' AND ')}
     ORDER BY
       COALESCE(rg.sort_order, 0) ASC,
       rg.name_ar ASC,
       COALESCE(a.sort_order, 0) ASC,
       a.name_ar ASC,
       rg.id ASC`,
    params
  );
}

module.exports = {
  listCustomers,
  countCustomers,
  listRegions,
  listRegionAddresses,
  regionOptions,
  salesRepOptions,
  reportCustomers,
  reportCustomersByRep,
  reportRegionAddresses,
  oracleLinkedCount,
};
