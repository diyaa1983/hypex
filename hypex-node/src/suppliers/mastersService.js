'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('suppliers masters', e.message);
    throw e;
  }
}

async function getSupplier(id) {
  const rows = await safeQuery(`SELECT * FROM crm_supplier WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function nextSupplierCode() {
  const m = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM crm_supplier`);
  return 'S-' + String(Number(m[0]?.m || 0) + 1).padStart(5, '0');
}

function nullIfEmpty(v) {
  const s = String(v || '').trim();
  return s === '' ? null : s;
}

async function saveSupplier(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '').trim();
  const name = String(payload.name_ar || '').trim();
  const phone = nullIfEmpty(payload.phone);
  const email = nullIfEmpty(payload.email);
  const tax = nullIfEmpty(payload.tax_number);
  const addr = nullIfEmpty(payload.address_ar);

  if (!name) return { ok: false, error: 'اسم المورد مطلوب.' };
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return { ok: false, error: 'البريد الإلكتروني غير صالح.' };
  }
  if (!code) code = await nextSupplierCode();

  const dup = id
    ? await safeQuery(`SELECT id FROM crm_supplier WHERE code = ? AND id <> ? LIMIT 1`, [code, id])
    : await safeQuery(`SELECT id FROM crm_supplier WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) return { ok: false, error: 'رمز المورد مستخدم مسبقاً.' };

  if (id > 0) {
    await safeQuery(
      `UPDATE crm_supplier SET code=?, name_ar=?, phone=?, email=?, tax_number=?, address_ar=? WHERE id=?`,
      [code, name, phone, email, tax, addr, id]
    );
    return { ok: true, id, message: 'تم تحديث بيانات المورد.' };
  }

  const [result] = await db.getPool().execute(
    `INSERT INTO crm_supplier (code, name_ar, phone, email, tax_number, address_ar, is_active)
     VALUES (?,?,?,?,?,?,1)`,
    [code, name, phone, email, tax, addr]
  );
  return { ok: true, id: Number(result.insertId), message: 'تم إضافة المورد.' };
}

module.exports = { getSupplier, saveSupplier, nextSupplierCode };
