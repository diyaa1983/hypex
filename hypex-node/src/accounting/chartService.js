'use strict';

const db = require('../db');

const TYPE_LABELS = {
  asset: 'أصول',
  liability: 'خصوم',
  equity: 'حقوق ملكية',
  revenue: 'إيرادات',
  expense: 'مصروفات',
};

const TYPE_TONE = {
  asset: 'asset',
  liability: 'liability',
  equity: 'equity',
  revenue: 'revenue',
  expense: 'expense',
};

async function q(sql, params = []) {
  return db.query(sql, params);
}

function typeLabel(t) {
  return TYPE_LABELS[t] || t || '—';
}

function codeDigits(code) {
  return String(code || '').replace(/\D/g, '');
}

function formatCode(code) {
  const d = codeDigits(code);
  if (!d) return '';
  const t = d.replace(/^0+/, '');
  return t === '' ? '0' : t;
}

async function loadAll(activeOnly = false) {
  const where = activeOnly ? 'WHERE is_active = 1' : '';
  return q(
    `SELECT id, code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order
     FROM acc_account ${where}
     ORDER BY sort_order ASC, code ASC, id ASC`
  );
}

async function getAccount(id) {
  const n = Number(id);
  if (!Number.isFinite(n) || n < 1) return null;
  const rows = await q(`SELECT * FROM acc_account WHERE id = ? LIMIT 1`, [n]);
  return rows[0] || null;
}

async function codeExists(code) {
  const rows = await q(`SELECT id FROM acc_account WHERE code = ? LIMIT 1`, [code]);
  return !!rows[0];
}

async function nextCode(parentId) {
  const pid = parentId != null && Number(parentId) > 0 ? Number(parentId) : null;
  if (!pid) {
    const roots = await q(`SELECT code FROM acc_account WHERE parent_id IS NULL`);
    let max = 0;
    for (const r of roots) {
      const c = String(r.code || '').trim();
      if (c && /^\d+$/.test(c)) max = Math.max(max, Number(c));
    }
    for (let n = max + 1; n <= max + 999; n++) {
      const code = String(n);
      if (!(await codeExists(code))) return code;
    }
    throw new Error('تعذر توليد رقم حساب رئيسي فريد.');
  }
  const parent = await getAccount(pid);
  if (!parent) throw new Error('الحساب الأب غير موجود.');
  const parentDigits = codeDigits(parent.code).replace(/^0+/, '') || codeDigits(parent.code);
  if (!parentDigits) throw new Error('رقم الحساب الأب غير صالح.');
  const kids = await q(`SELECT code FROM acc_account WHERE parent_id = ?`, [pid]);
  let maxSuffix = 0;
  const prefixLen = parentDigits.length;
  for (const r of kids) {
    let digits = codeDigits(r.code).replace(/^0+/, '');
    if (!digits) digits = codeDigits(r.code);
    if (digits.startsWith(parentDigits)) {
      const suffix = digits.slice(prefixLen);
      if (suffix.length === 3 && /^\d+$/.test(suffix)) {
        maxSuffix = Math.max(maxSuffix, Number(suffix));
      }
    }
  }
  for (let s = maxSuffix + 1; s <= 999; s++) {
    const candidate = parentDigits + String(s).padStart(3, '0');
    if (!(await codeExists(candidate))) return candidate;
  }
  throw new Error('تعذر توليد رقم حساب فرعي فريد.');
}

async function nextSortOrder(parentId) {
  const pid = parentId != null && Number(parentId) > 0 ? Number(parentId) : null;
  if (!pid) {
    const rows = await q(
      `SELECT IFNULL(MAX(sort_order), 0) AS m FROM acc_account WHERE parent_id IS NULL`
    );
    return Number(rows[0]?.m || 0) + 10;
  }
  const rows = await q(
    `SELECT IFNULL(MAX(sort_order), 0) AS m FROM acc_account WHERE parent_id = ?`,
    [pid]
  );
  return Number(rows[0]?.m || 0) + 10;
}

async function hasChildren(id) {
  const rows = await q(`SELECT id FROM acc_account WHERE parent_id = ? LIMIT 1`, [id]);
  return !!rows[0];
}

async function deleteCheck(id) {
  const n = Number(id);
  if (!Number.isFinite(n) || n < 1) {
    return { can_delete: false, message: 'معرّف غير صالح.' };
  }
  if (await hasChildren(n)) {
    return {
      can_delete: false,
      message: 'لا يمكن الحذف: للحساب حسابات فرعية. احذف الفروع أولاً.',
    };
  }
  try {
    const j = await q(
      `SELECT COUNT(*) AS c FROM acc_journal_line WHERE account_id = ?`,
      [n]
    );
    if (Number(j[0]?.c || 0) > 0) {
      return {
        can_delete: false,
        message: 'لا يمكن حذف الحساب: يوجد عليه حركات. يمكنك إيقاف الحساب بدلاً من الحذف.',
      };
    }
  } catch {
    /* جدول قد لا يوجد */
  }
  try {
    const v = await q(
      `SELECT COUNT(*) AS c FROM fin_voucher WHERE cash_account_id = ?`,
      [n]
    );
    if (Number(v[0]?.c || 0) > 0) {
      return {
        can_delete: false,
        message: 'لا يمكن حذف الحساب: يوجد عليه حركات (سندات قبض أو صرف).',
      };
    }
  } catch {
    /* ignore */
  }
  try {
    const p = await q(
      `SELECT COUNT(*) AS c FROM acc_posting_setting WHERE account_id = ?`,
      [n]
    );
    if (Number(p[0]?.c || 0) > 0) {
      return {
        can_delete: false,
        message:
          'لا يمكن حذف الحساب لأنه مربوط في «ربط الحسابات». غيّر الربط أولاً أو أوقف الحساب.',
      };
    }
  } catch {
    /* ignore */
  }
  return { can_delete: true, message: '' };
}

async function saveAccount(payload) {
  const id = Number(payload.id || 0) || 0;
  const name = String(payload.name_ar || '').trim();
  let parentId = Number(payload.parent_id || 0) || null;
  if (parentId != null && parentId < 1) parentId = null;
  let isLeaf =
    payload.is_leaf === '1' || payload.is_leaf === 1 || payload.is_leaf === true ? 1 : 0;
  const isActive =
    payload.is_active === undefined ||
    payload.is_active === null ||
    payload.is_active === '' ||
    payload.is_active === '1' ||
    payload.is_active === 1 ||
    payload.is_active === true
      ? 1
      : 0;
  let accountType = String(payload.account_type || '').trim();

  if (!name) return { ok: false, error: 'اسم الحساب مطلوب.' };
  if (!TYPE_LABELS[accountType] && id < 1) {
    return { ok: false, error: 'نوع الحساب غير صالح.' };
  }

  if (id > 0) {
    const cur = await getAccount(id);
    if (!cur) return { ok: false, error: 'الحساب غير موجود.' };
    if ((await hasChildren(id)) && isLeaf) {
      return {
        ok: false,
        error: 'لا يمكن جعل الحساب «نهائي» طالما له حسابات فرعية.',
      };
    }
    await q(`UPDATE acc_account SET name_ar = ?, is_leaf = ?, is_active = ? WHERE id = ?`, [
      name,
      isLeaf,
      isActive,
      id,
    ]);
    return { ok: true, id, message: 'تم تحديث الحساب.' };
  }

  if (parentId) {
    const parent = await getAccount(parentId);
    if (!parent) return { ok: false, error: 'الحساب الأب غير موجود.' };
    accountType = String(parent.account_type);
    await q(`UPDATE acc_account SET is_leaf = 0 WHERE id = ? AND is_leaf = 1`, [parentId]);
  }
  if (!TYPE_LABELS[accountType]) {
    return { ok: false, error: 'نوع الحساب غير صالح.' };
  }

  let code;
  try {
    code = await nextCode(parentId);
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر توليد رقم الحساب.' };
  }
  const sortOrder = await nextSortOrder(parentId);
  try {
    const r = await q(
      `INSERT INTO acc_account (code, name_ar, parent_id, account_type, is_leaf, is_active, sort_order)
       VALUES (?,?,?,?,?,?,?)`,
      [code, name, parentId, accountType, isLeaf, isActive, sortOrder]
    );
    const newId = Number(r.insertId || 0);
    try {
      await q(
        `INSERT IGNORE INTO sys_dashboard_account (account_id, is_visible) VALUES (?, 0)`,
        [newId]
      );
    } catch {
      /* optional */
    }
    return { ok: true, id: newId, message: 'تم إضافة الحساب.' };
  } catch (e) {
    if (String(e.message || '').includes('Duplicate') || e.errno === 1062) {
      return {
        ok: false,
        error: 'رقم الحساب «' + code + '» مستخدم مسبقاً. حدّث الصفحة وحاول مرة أخرى.',
      };
    }
    return { ok: false, error: 'تعذر الحفظ: ' + (e.message || '') };
  }
}

async function deleteAccount(id) {
  const n = Number(id);
  const chk = await deleteCheck(n);
  if (!chk.can_delete) return { ok: false, error: chk.message };
  const row = await getAccount(n);
  if (!row) return { ok: false, error: 'الحساب غير موجود.' };
  try {
    await q(`DELETE FROM acc_account WHERE id = ?`, [n]);
    return { ok: true, message: 'تم حذف الحساب.' };
  } catch (e) {
    const msg = String(e.message || '');
    if (msg.includes('foreign key') || msg.includes('1451') || e.errno === 1451) {
      return {
        ok: false,
        error: 'لا يمكن حذف الحساب: يوجد عليه حركات أو سجلات مرتبطة.',
      };
    }
    return { ok: false, error: 'تعذر الحذف: ' + msg };
  }
}

function buildTree(rows) {
  const byParent = new Map();
  for (const r of rows) {
    const pid = r.parent_id == null ? 0 : Number(r.parent_id);
    if (!byParent.has(pid)) byParent.set(pid, []);
    byParent.get(pid).push(r);
  }
  for (const list of byParent.values()) {
    list.sort((a, b) => {
      const sa = Number(a.sort_order || 0) - Number(b.sort_order || 0);
      if (sa !== 0) return sa;
      return String(a.code || '').localeCompare(String(b.code || ''), undefined, {
        numeric: true,
      });
    });
  }
  function walk(parentId, depth) {
    const kids = byParent.get(parentId) || [];
    return kids.map((r) => ({
      ...r,
      depth,
      children: walk(Number(r.id), depth + 1),
    }));
  }
  return walk(0, 0);
}

function flattenTree(nodes, out = []) {
  for (const n of nodes) {
    out.push(n);
    if (n.children && n.children.length) flattenTree(n.children, out);
  }
  return out;
}

async function treePageData({ q = '', activeOnly = false } = {}) {
  let rows = await loadAll(activeOnly);
  if (q) {
    const like = String(q).trim().toLowerCase();
    const matchIds = new Set();
    const byId = new Map(rows.map((r) => [Number(r.id), r]));
    for (const r of rows) {
      const hay = (
        String(r.code || '') +
        ' ' +
        String(r.name_ar || '') +
        ' ' +
        formatCode(r.code)
      ).toLowerCase();
      if (hay.includes(like)) {
        let cur = r;
        while (cur) {
          matchIds.add(Number(cur.id));
          cur = cur.parent_id ? byId.get(Number(cur.parent_id)) : null;
        }
      }
    }
    rows = rows.filter((r) => matchIds.has(Number(r.id)));
  }
  const tree = buildTree(rows);
  const flat = flattenTree(tree);
  return { tree, flat, count: rows.length };
}

module.exports = {
  TYPE_LABELS,
  TYPE_TONE,
  typeLabel,
  formatCode,
  codeDigits,
  loadAll,
  getAccount,
  nextCode,
  hasChildren,
  deleteCheck,
  saveAccount,
  deleteAccount,
  buildTree,
  treePageData,
};
