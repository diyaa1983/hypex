'use strict';

const db = require('../db');
const { todayIso } = require('../lib/html');

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function parseRange(fromIn, toIn) {
  let from = String(fromIn || '').trim();
  let to = String(toIn || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(from)) from = monthStart();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(to)) to = todayIso();
  if (from > to) [from, to] = [to, from];
  return { from, to };
}

function codeDigits(code) {
  return String(code || '').replace(/\D/g, '');
}

function emptySections() {
  return {
    sales_revenue: [],
    sales_returns: [],
    other_revenue: [],
    cogs: [],
    purchases: [],
    purchase_returns: [],
    salaries: [],
    general_admin: [],
    other_operating: [],
  };
}

async function tableOk() {
  try {
    await db.query(`SELECT id FROM acc_journal_entry LIMIT 1`);
    await db.query(`SELECT id FROM acc_journal_line LIMIT 1`);
    await db.query(`SELECT id FROM acc_account LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

async function postingByAccount() {
  const map = new Map();
  try {
    const rows = await db.query(
      `SELECT account_id, rule_code FROM acc_posting_setting WHERE account_id IS NOT NULL`
    );
    for (const r of rows) {
      const aid = Number(r.account_id || 0);
      if (aid > 0) map.set(aid, String(r.rule_code || ''));
    }
  } catch {
    /* no posting table */
  }
  return map;
}

async function inventoryAccountId() {
  try {
    const rows = await db.query(
      `SELECT account_id FROM acc_posting_setting WHERE rule_code = 'inventory' AND account_id IS NOT NULL LIMIT 1`
    );
    return Number(rows[0]?.account_id || 0);
  } catch {
    return 0;
  }
}

function classifyByCode(accountType, digits, usesInventory) {
  if (accountType === 'revenue') {
    if (digits.startsWith('42')) return 'sales_returns';
    if (digits.startsWith('41')) return 'sales_revenue';
    return 'other_revenue';
  }
  if (accountType === 'expense') {
    if (usesInventory) {
      if (digits.startsWith('51') || digits.startsWith('55')) return 'ignore';
    }
    if (digits.startsWith('54')) return 'cogs';
    if (digits.startsWith('55')) return 'purchase_returns';
    if (digits.startsWith('51')) return 'purchases';
    if (digits === '6001') return 'purchases';
    if (digits.startsWith('52')) return 'salaries';
    if (digits.startsWith('53')) return 'general_admin';
    return 'other_operating';
  }
  return 'ignore';
}

function classifyAccount(acc, postingMap, usesInventory) {
  const id = Number(acc.id || 0);
  const type = String(acc.account_type || '');
  const digits = codeDigits(acc.code);
  if (id > 0 && postingMap.has(id)) {
    const rule = postingMap.get(id);
    if (usesInventory && (rule === 'purchases' || rule === 'purchase_returns')) return 'ignore';
    switch (rule) {
      case 'sales_revenue':
        return 'sales_revenue';
      case 'sales_returns':
        return 'sales_returns';
      case 'cogs':
        return 'cogs';
      case 'purchases':
        return 'purchases';
      case 'purchase_returns':
        return 'purchase_returns';
      case 'salaries_expense':
        return 'salaries';
      case 'misc_expense':
        return 'general_admin';
      default:
        return classifyByCode(type, digits, usesInventory);
    }
  }
  return classifyByCode(type, digits, usesInventory);
}

function sumSigned(rows) {
  return Math.round(rows.reduce((s, r) => s + Number(r.signed || 0), 0) * 1e6) / 1e6;
}

function summaryRows(totals) {
  const totalRevenue = Number(totals.total_revenue || 0);
  const totalPurchases = Number(totals.total_purchases || 0);
  const usesInventory = !!totals.uses_inventory;
  const totalCogs = Number(totals.total_cogs || 0);
  const grossProfit = Number(totals.gross_profit || 0);
  const totalOperating = Number(totals.total_operating || 0);
  const netIncome = Number(totals.net_income || 0);
  const isProfit = !!totals.net_is_profit;

  const rows = [
    {
      line_no: 1,
      label: 'إجمالي الإيرادات',
      amount: totalRevenue,
      style: 'normal',
      deduction: false,
    },
  ];
  let lineNo = 1;
  if (totalPurchases > 0.0005) {
    lineNo++;
    rows.push({
      line_no: lineNo,
      label: usesInventory ? 'المشتريات (تُسجّل في المخزون)' : 'المشتريات',
      amount: totalPurchases,
      style: 'normal',
      deduction: false,
      info_only: usesInventory,
    });
  }
  lineNo++;
  rows.push({
    line_no: lineNo,
    label: 'تكلفة المبيعات',
    amount: totalCogs,
    style: 'normal',
    deduction: true,
  });
  lineNo++;
  rows.push({
    line_no: lineNo,
    label: 'مجمل الربح',
    amount: grossProfit,
    style: 'subtotal',
    deduction: false,
  });
  lineNo++;
  rows.push({
    line_no: lineNo,
    label: 'المصروفات التشغيلية',
    amount: totalOperating,
    style: 'normal',
    deduction: true,
  });
  lineNo++;
  rows.push({
    line_no: lineNo,
    label: isProfit ? 'صافي الربح' : 'صافي الخسارة',
    amount: netIncome,
    style: 'total',
    deduction: false,
    is_profit: isProfit,
  });
  return rows;
}

async function companyName() {
  try {
    const rows = await db.query(
      `SELECT company_name_ar FROM sys_company_settings WHERE id = 1 LIMIT 1`
    );
    const n = String(rows[0]?.company_name_ar || '').trim();
    return n && n !== 'اسم الشركة' ? n : 'Hypex';
  } catch {
    return 'Hypex';
  }
}

async function invPurchaseAmounts(from, to, invId) {
  if (invId < 1) {
    return { uses_inventory: false, purchases: 0, purchase_returns: 0, code: '', name: '' };
  }
  let acc = { code: '', name_ar: 'المخزون' };
  try {
    const rows = await db.query(`SELECT code, name_ar FROM acc_account WHERE id = ? LIMIT 1`, [invId]);
    if (rows[0]) acc = rows[0];
  } catch {
    /* ignore */
  }
  let purchases = 0;
  let purchaseReturns = 0;
  try {
    const rows = await db.query(
      `SELECT e.ref_type,
              COALESCE(SUM(l.debit), 0) AS sum_debit,
              COALESCE(SUM(l.credit), 0) AS sum_credit
       FROM acc_journal_line l
       INNER JOIN acc_journal_entry e ON e.id = l.journal_id
       WHERE l.account_id = ?
         AND e.status = 'posted'
         AND e.entry_date >= ?
         AND e.entry_date <= ?
       GROUP BY e.ref_type`,
      [invId, from, to]
    );
    for (const r of rows) {
      const ref = String(r.ref_type || '');
      if (ref === 'purchase_invoice') purchases += Number(r.sum_debit || 0);
      if (ref === 'purchase_return') purchaseReturns += Number(r.sum_credit || 0);
    }
  } catch {
    /* ignore */
  }
  return {
    uses_inventory: true,
    purchases: Math.round(purchases * 1e6) / 1e6,
    purchase_returns: Math.round(purchaseReturns * 1e6) / 1e6,
    inventory_account_id: invId,
    inventory_code: String(acc.code || ''),
    inventory_name_ar: String(acc.name_ar || 'المخزون'),
  };
}

/**
 * تقرير الأرباح والخسائر الشامل — منطق مطابق لـ PHP.
 */
async function comprehensivePl(fromIn, toIn) {
  const { from, to } = parseRange(fromIn, toIn);
  const company = await companyName();
  const sections = emptySections();

  if (!(await tableOk())) {
    const totals = {
      total_revenue: 0,
      total_purchases: 0,
      uses_inventory: false,
      total_cogs: 0,
      gross_profit: 0,
      total_operating: 0,
      net_income: 0,
      net_is_profit: true,
    };
    return {
      date_from: from,
      date_to: to,
      company,
      totals,
      summary_rows: summaryRows(totals),
      has_activity: false,
    };
  }

  const invId = await inventoryAccountId();
  const usesInventory = invId > 0;
  const postingMap = await postingByAccount();

  const accounts = await db.query(
    `SELECT id, code, name_ar, account_type
     FROM acc_account
     WHERE is_active = 1 AND is_leaf = 1 AND account_type IN ('revenue', 'expense')
     ORDER BY code ASC, id ASC`
  );

  let movementMap = new Map();
  try {
    const moves = await db.query(
      `SELECT l.account_id,
              COALESCE(SUM(l.debit), 0) AS sum_debit,
              COALESCE(SUM(l.credit), 0) AS sum_credit
       FROM acc_journal_line l
       INNER JOIN acc_journal_entry e ON e.id = l.journal_id
       WHERE e.status = 'posted'
         AND e.entry_date >= ?
         AND e.entry_date <= ?
       GROUP BY l.account_id`,
      [from, to]
    );
    for (const m of moves) {
      movementMap.set(Number(m.account_id), {
        sum_debit: Number(m.sum_debit || 0),
        sum_credit: Number(m.sum_credit || 0),
      });
    }
  } catch (e) {
    console.error('pl movement aggregate', e.message);
  }

  for (const acc of accounts) {
    const section = classifyAccount(acc, postingMap, usesInventory);
    if (section === 'ignore' || !sections[section]) continue;
    const period = movementMap.get(Number(acc.id)) || { sum_debit: 0, sum_credit: 0 };
    if (Math.abs(period.sum_debit) < 1e-6 && Math.abs(period.sum_credit) < 1e-6) continue;

    const type = String(acc.account_type || '');
    let signed = 0;
    if (type === 'revenue') {
      signed = Math.round((period.sum_credit - period.sum_debit) * 1e6) / 1e6;
    } else if (type === 'expense') {
      signed = Math.round((period.sum_debit - period.sum_credit) * 1e6) / 1e6;
    } else {
      continue;
    }
    if (Math.abs(signed) < 1e-6) continue;

    const isDeduction = section === 'sales_returns' || section === 'purchase_returns';
    sections[section].push({
      line_no: sections[section].length + 1,
      id: Number(acc.id),
      code: String(acc.code || ''),
      name_ar: String(acc.name_ar || ''),
      account_type: type,
      section,
      amount: Math.abs(signed),
      signed,
      is_deduction: isDeduction,
    });
  }

  // مشتريات من حركة المخزون عند غياب حساب مشتريات منفصل
  if (usesInventory) {
    const info = await invPurchaseAmounts(from, to, invId);
    const existingPurch = sections.purchases.reduce((s, r) => s + Math.abs(Number(r.amount || 0)), 0);
    if (existingPurch < 0.0005 && info.purchases > 0.0005) {
      sections.purchases.push({
        id: invId,
        code: info.inventory_code,
        name_ar: 'مشتريات — فواتير شراء (المخزون)',
        amount: info.purchases,
        signed: info.purchases,
        is_deduction: false,
        is_synthetic: true,
        inventory_info: true,
      });
    }
    const existingRet = sections.purchase_returns.reduce(
      (s, r) => s + Math.abs(Number(r.amount || 0)),
      0
    );
    if (existingRet < 0.0005 && info.purchase_returns > 0.0005) {
      const amt = info.purchase_returns;
      sections.purchase_returns.push({
        id: invId,
        code: info.inventory_code,
        name_ar: 'مردودات مشتريات (المخزون)',
        amount: amt,
        signed: -amt,
        is_deduction: true,
        is_synthetic: true,
        inventory_info: true,
      });
    }
  }

  const grossSales = sumSigned(sections.sales_revenue);
  const salesReturnsSigned = sumSigned(sections.sales_returns);
  const otherRevenueSigned = sumSigned(sections.other_revenue);
  const netSales = Math.round((grossSales + salesReturnsSigned) * 1e6) / 1e6;

  const cogsSigned = sumSigned(sections.cogs);
  const purchasesSigned = sumSigned(sections.purchases);
  const purchaseReturnsSigned = sumSigned(sections.purchase_returns);

  let totalCogs;
  let purchasesDisplay;
  if (usesInventory) {
    totalCogs = Math.round((cogsSigned + purchaseReturnsSigned) * 1e6) / 1e6;
    purchasesDisplay = Math.round(Math.abs(purchasesSigned) * 1e6) / 1e6;
  } else {
    totalCogs = Math.round((cogsSigned + purchasesSigned + purchaseReturnsSigned) * 1e6) / 1e6;
    purchasesDisplay = Math.round(Math.abs(purchasesSigned) * 1e6) / 1e6;
  }

  const grossProfit = Math.round((netSales + otherRevenueSigned - totalCogs) * 1e6) / 1e6;
  const salariesSigned = sumSigned(sections.salaries);
  const generalSigned = sumSigned(sections.general_admin);
  const otherOpSigned = sumSigned(sections.other_operating);
  const totalOperating = Math.round((salariesSigned + generalSigned + otherOpSigned) * 1e6) / 1e6;
  const operatingIncome = Math.round((grossProfit - totalOperating) * 1e6) / 1e6;
  const netIncome = operatingIncome;

  const totals = {
    total_revenue: Math.round((netSales + otherRevenueSigned) * 1e6) / 1e6,
    total_purchases: purchasesDisplay,
    uses_inventory: usesInventory,
    total_cogs: totalCogs,
    gross_profit: grossProfit,
    total_operating: totalOperating,
    net_income: netIncome,
    net_is_profit: netIncome >= 0,
  };

  const hasActivity =
    Math.abs(totals.total_revenue) > 1e-6 ||
    Math.abs(totals.total_cogs) > 1e-6 ||
    Math.abs(totals.total_operating) > 1e-6;

  return {
    date_from: from,
    date_to: to,
    company,
    totals,
    summary_rows: summaryRows({
      total_revenue: totals.total_revenue,
      total_purchases: totals.total_purchases,
      uses_inventory: usesInventory,
      total_cogs: totals.total_cogs,
      gross_profit: totals.gross_profit,
      total_operating: totals.total_operating,
      net_income: totals.net_income,
      net_is_profit: totals.net_is_profit,
    }),
    has_activity: hasActivity,
  };
}

module.exports = { comprehensivePl, parseRange };
