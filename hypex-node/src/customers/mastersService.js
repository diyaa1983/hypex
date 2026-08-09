'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('customers masters', e.message);
    throw e;
  }
}

async function getCustomer(id) {
  const rows = await safeQuery(`SELECT * FROM crm_customer WHERE id = ? LIMIT 1`, [Number(id)]);
  if (!rows[0]) return null;
  const c = rows[0];
  let repIds = [];
  try {
    const reps = await safeQuery(
      `SELECT sales_rep_id FROM crm_customer_sales_rep WHERE customer_id = ? ORDER BY sort_order, sales_rep_id`,
      [c.id]
    );
    repIds = reps.map((r) => Number(r.sales_rep_id));
  } catch {
    if (c.sales_rep_id) repIds = [Number(c.sales_rep_id)];
  }
  return { ...c, rep_ids: repIds };
}

async function nextCustomerCode() {
  const m = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM crm_customer`);
  let base = Number(m[0]?.m || 0) + 1;
  for (let i = 0; i < 100; i++) {
    const code = 'C-' + String(base + i).padStart(5, '0');
    const dup = await safeQuery(`SELECT id FROM crm_customer WHERE code = ? LIMIT 1`, [code]);
    if (!dup[0]) return code;
  }
  return 'C-' + Date.now().toString(36);
}

function nullIfEmpty(v) {
  const s = String(v || '').trim();
  return s === '' ? null : s;
}

async function saveCustomer(payload) {
  const id = Number(payload.id || 0);
  const name = String(payload.name_ar || '').trim();
  if (!name) return { ok: false, error: 'اسم العميل مطلوب.' };

  const phone = nullIfEmpty(payload.phone);
  const email = nullIfEmpty(payload.email);
  const tax = nullIfEmpty(payload.tax_number);
  const addr = nullIfEmpty(payload.address_ar);
  const regionId = Number(payload.region_id || 0) || null;
  const regionAddressId = Number(payload.region_address_id || 0) || null;

  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return { ok: false, error: 'البريد الإلكتروني غير صالح.' };
  }

  let repIds = [];
  if (Array.isArray(payload.rep_ids)) {
    repIds = payload.rep_ids.map(Number).filter((n) => n > 0);
  } else if (payload.rep_ids != null) {
    repIds = [Number(payload.rep_ids)].filter((n) => n > 0);
  } else if (payload.sales_rep_id) {
    repIds = [Number(payload.sales_rep_id)].filter((n) => n > 0);
  }

  if (id > 0) {
    const cur = await getCustomer(id);
    if (!cur) return { ok: false, error: 'العميل غير موجود.' };
    const oracleLocked = String(cur.oracle_key || '').trim() !== '';
    const finalName = oracleLocked ? String(cur.name_ar || '') : name;

    try {
      await safeQuery(
        `UPDATE crm_customer SET name_ar=?, phone=?, email=?, tax_number=?, address_ar=?,
         region_id=?, region_address_id=?, sales_rep_id=? WHERE id=?`,
        [
          finalName,
          phone,
          email,
          tax,
          addr,
          regionId,
          regionAddressId,
          repIds[0] || null,
          id,
        ]
      );
    } catch {
      await safeQuery(
        `UPDATE crm_customer SET name_ar=?, phone=?, email=?, tax_number=?, address_ar=?, sales_rep_id=? WHERE id=?`,
        [finalName, phone, email, tax, addr, repIds[0] || null, id]
      );
    }
    await saveCustomerReps(id, repIds);
    return {
      ok: true,
      id,
      message: oracleLocked
        ? 'تم تحديث بيانات العميل (الاسم من Oracle غير قابل للتعديل).'
        : 'تم تحديث بيانات العميل.',
    };
  }

  const code = (await nextCustomerCode());
  try {
    const [result] = await db.getPool().execute(
      `INSERT INTO crm_customer
       (code, name_ar, phone, email, tax_number, address_ar, region_id, region_address_id, sales_rep_id, is_active)
       VALUES (?,?,?,?,?,?,?,?,?,1)`,
      [code, name, phone, email, tax, addr, regionId, regionAddressId, repIds[0] || null]
    );
    const newId = Number(result.insertId);
    await saveCustomerReps(newId, repIds);
    return { ok: true, id: newId, message: `تم إضافة العميل. الرمز: ${code}` };
  } catch {
    const [result] = await db.getPool().execute(
      `INSERT INTO crm_customer
       (code, name_ar, phone, email, tax_number, address_ar, sales_rep_id, is_active)
       VALUES (?,?,?,?,?,?,?,1)`,
      [code, name, phone, email, tax, addr, repIds[0] || null]
    );
    const newId = Number(result.insertId);
    await saveCustomerReps(newId, repIds);
    return { ok: true, id: newId, message: `تم إضافة العميل. الرمز: ${code}` };
  }
}

async function saveCustomerReps(customerId, repIds) {
  try {
    await safeQuery(`DELETE FROM crm_customer_sales_rep WHERE customer_id = ?`, [customerId]);
    let i = 0;
    for (const rid of repIds) {
      await safeQuery(
        `INSERT INTO crm_customer_sales_rep (customer_id, sales_rep_id, sort_order) VALUES (?,?,?)`,
        [customerId, rid, i++]
      );
    }
  } catch {
    /* table optional */
  }
}

async function getRegion(id) {
  const rows = await safeQuery(`SELECT * FROM crm_region WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function nextRegionCode() {
  const m = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM crm_region`);
  return 'R' + String(Number(m[0]?.m || 0) + 1).padStart(3, '0');
}

async function saveRegion(payload) {
  const id = Number(payload.id || 0);
  const name = String(payload.name_ar || '').trim();
  if (!name) return { ok: false, error: 'اسم المنطقة مطلوب.' };
  let code = String(payload.code || '').trim();
  const sortOrder = Number(payload.sort_order || 0) || 0;
  const isActive =
    payload.is_active === '1' ||
    payload.is_active === 1 ||
    payload.is_active === true ||
    payload.is_active === 'on'
      ? 1
      : id > 0
        ? 0
        : 1;

  if (id > 0) {
    if (!code) {
      const cur = await getRegion(id);
      code = cur?.code || (await nextRegionCode());
    }
    await safeQuery(`UPDATE crm_region SET code=?, name_ar=?, sort_order=?, is_active=? WHERE id=?`, [
      code,
      name,
      sortOrder,
      isActive,
      id,
    ]);
    return { ok: true, id, message: 'تم تحديث المنطقة.' };
  }

  if (!code) code = await nextRegionCode();
  const dup = await safeQuery(`SELECT id FROM crm_region WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) code = code + '_' + Date.now().toString(36).slice(-4);

  const [result] = await db.getPool().execute(
    `INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES (?,?,?,1)`,
    [code, name, sortOrder]
  );
  return { ok: true, id: Number(result.insertId), message: 'تم إضافة المنطقة.' };
}

async function saveRegionAddress(payload) {
  const regionId = Number(payload.region_id || 0);
  const name = String(payload.name_ar || '').trim();
  if (regionId < 1) return { ok: false, error: 'اختر المنطقة.' };
  if (!name) return { ok: false, error: 'اسم العنوان مطلوب.' };
  const sortOrder = Number(payload.sort_order || 0) || 0;
  const [result] = await db.getPool().execute(
    `INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active) VALUES (?,?,?,1)`,
    [regionId, name, sortOrder]
  );
  return { ok: true, id: Number(result.insertId), message: 'تم إضافة العنوان.' };
}

async function listAddressesForRegion(regionId) {
  if (!regionId) return [];
  return safeQuery(
    `SELECT id, name_ar FROM crm_region_address WHERE region_id = ? AND is_active = 1 ORDER BY sort_order, name_ar`,
    [Number(regionId)]
  );
}

module.exports = {
  getCustomer,
  saveCustomer,
  nextCustomerCode,
  getRegion,
  saveRegion,
  saveRegionAddress,
  listAddressesForRegion,
  nextRegionCode,
};
