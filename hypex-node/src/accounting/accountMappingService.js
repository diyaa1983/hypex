'use strict';

const db = require('../db');

const HIDDEN_RULES = new Set([
  'hr_social_insurance_employee',
  'hr_social_insurance_employer',
  'salaries_expense',
  'hr_payroll_accrual',
]);

const LEGACY_REJECT = new Set(['11', '12', '13', '15', '112']);

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('account mapping', e.message);
    return [];
  }
}

function codeDigits(code) {
  return String(code || '').replace(/\D/g, '');
}

function canonicalDigits(code) {
  const d = codeDigits(code);
  if (!d) return '';
  const t = d.replace(/^0+/, '');
  return t === '' ? '0' : t;
}

async function tableReady() {
  try {
    await q(`SELECT rule_code FROM acc_posting_setting LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

/**
 * @returns {Promise<Array<{rule_code:string,label_ar:string,hint_ar:string,account_id:number|null,sort_order:number,account_code?:string,account_name?:string}>>}
 */
async function listSettings() {
  if (!(await tableReady())) return [];
  const rows = await safe(
    `SELECT ps.rule_code, ps.label_ar, ps.hint_ar, ps.account_id, ps.sort_order,
            a.code AS account_code, a.name_ar AS account_name, a.is_leaf AS account_is_leaf
     FROM acc_posting_setting ps
     LEFT JOIN acc_account a ON a.id = ps.account_id
     ORDER BY ps.sort_order ASC, ps.rule_code ASC`
  );
  return rows
    .filter((r) => !HIDDEN_RULES.has(String(r.rule_code || '')))
    .map((r) => ({
      rule_code: String(r.rule_code || ''),
      label_ar: String(r.label_ar || ''),
      hint_ar: r.hint_ar != null ? String(r.hint_ar) : '',
      account_id: r.account_id != null ? Number(r.account_id) : null,
      sort_order: Number(r.sort_order || 0),
      account_code: r.account_code != null ? String(r.account_code) : '',
      account_name: r.account_name != null ? String(r.account_name) : '',
    }));
}

async function listLeafAccounts() {
  return safe(
    `SELECT id, code, name_ar, parent_id, is_leaf, is_active
     FROM acc_account
     WHERE is_active = 1 AND is_leaf = 1
     ORDER BY code ASC, id ASC
     LIMIT 5000`
  );
}

async function getAccount(id) {
  const n = Number(id);
  if (n < 1) return null;
  const rows = await safe(
    `SELECT id, code, name_ar, parent_id, is_leaf, is_active FROM acc_account WHERE id = ? LIMIT 1`,
    [n]
  );
  return rows[0] || null;
}

async function isValidMappingTarget(accountId) {
  const acc = await getAccount(accountId);
  if (!acc || Number(acc.is_active) !== 1 || Number(acc.is_leaf) !== 1) return false;
  const digits = canonicalDigits(acc.code);
  if (!digits) return false;
  if (LEGACY_REJECT.has(digits)) return false;
  if (digits.length > 3) return true;
  const parentId = Number(acc.parent_id || 0);
  if (parentId < 1) return false;
  const parent = await getAccount(parentId);
  if (!parent || Number(parent.is_active) !== 1) return false;
  const parentDigits = canonicalDigits(parent.code);
  if (LEGACY_REJECT.has(parentDigits)) return false;
  return ['1', '2', '3', '4', '5'].includes(parentDigits);
}

/**
 * @param {Record<string, string|number>} map rule_code → account id or empty
 */
async function saveMappings(map) {
  if (!(await tableReady())) {
    return { ok: false, error: 'جدول ربط الحسابات غير موجود. نفّذ ترحيل 032_acc_gl_posting.sql.' };
  }
  const settings = await listSettings();
  if (!settings.length) {
    return { ok: false, error: 'لا توجد قواعد ربط معرفة.' };
  }

  try {
    for (const meta of settings) {
      const code = meta.rule_code;
      const raw = map[code];
      let accId =
        raw === '' || raw == null || raw === undefined ? null : Number(raw) || null;
      if (accId !== null && accId < 1) accId = null;

      if (accId !== null) {
        const acc = await getAccount(accId);
        if (!acc || Number(acc.is_leaf) !== 1) {
          return {
            ok: false,
            error:
              'الحساب المختار لـ «' +
              (meta.label_ar || code) +
              '» يجب أن يكون حساباً نهائياً في الشجرة.',
          };
        }
        if (!(await isValidMappingTarget(accId))) {
          return {
            ok: false,
            error:
              'الحساب المختار لـ «' +
              (meta.label_ar || code) +
              '» قديم أو غير معتمد. اختر حساباً من الشجرة الهرمية.',
          };
        }
      }

      await q(`UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?`, [
        accId,
        code,
      ]);
    }
    return {
      ok: true,
      message: 'تم حفظ ربط الحسابات. الترحيلات الجديدة ستستخدم هذه الإعدادات.',
    };
  } catch (e) {
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

function requiredCodes(settings) {
  const codes = ['ar_customers', 'ap_suppliers', 'cash', 'sales_revenue'];
  const inv = settings.find((s) => s.rule_code === 'inventory');
  if (!inv || !inv.account_id) codes.push('purchases');
  return new Set(codes);
}

module.exports = {
  listSettings,
  listLeafAccounts,
  saveMappings,
  requiredCodes,
  tableReady,
  HIDDEN_RULES,
};
