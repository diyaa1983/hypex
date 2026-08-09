'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('suppliers query', e.message);
    return [];
  }
}

async function listSuppliers({ q = '', activeOnly = true } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('s.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(s.name_ar LIKE ? OR IFNULL(s.code,'') LIKE ? OR IFNULL(s.phone,'') LIKE ? OR IFNULL(s.tax_number,'') LIKE ? OR IFNULL(s.email,'') LIKE ?)`
    );
    params.push(like, like, like, like, like);
  }
  return safeQuery(
    `SELECT s.id, s.code, s.name_ar, s.phone, s.email, s.tax_number, s.address_ar, s.is_active,
            COALESCE((
              SELECT SUM(l.credit) - SUM(l.debit) FROM crm_supplier_ledger l WHERE l.supplier_id = s.id
            ), 0) AS balance
     FROM crm_supplier s
     WHERE ${where.join(' AND ')}
     ORDER BY s.name_ar ASC
     LIMIT 500`,
    params
  );
}

module.exports = { listSuppliers };
