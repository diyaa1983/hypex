'use strict';

/** كتالوج المحاسبة — مطابق nav_menu */
const accountingCatalog = [
  {
    group: 'finance',
    title: 'العمليات المالية',
    items: [
      { r: 'account_mapping', label: 'ربط الحسابات', icon: '🔗', path: '/accounting/account-mapping', kind: 'list' },
      { r: 'cash_receipt', label: 'سند قبض', icon: '⬆', path: '/accounting/receipts/entry', kind: 'list' },
      { r: 'cash_receipts_list', label: 'ترحيل سندات القبض', icon: '📋', path: '/accounting/receipts', kind: 'list' },
      { r: 'cash_payment', label: 'سند صرف', icon: '⬇', path: '/accounting/payments/entry', kind: 'list' },
      { r: 'cash_payments_list', label: 'ترحيل سندات الصرف', icon: '📋', path: '/accounting/payments', kind: 'list' },
      { r: 'fin_employee_advances', label: 'السلف', icon: '💰', path: '/accounting/advances', kind: 'list' },
      { r: 'journal_voucher', label: 'سند قيد', icon: '⚖', path: '/accounting/journal-voucher', kind: 'list' },
      { r: 'debit_notes', label: 'إشعار مدين', icon: 'Ⓓ', path: '/accounting/debit-notes/entry', kind: 'bridge' },
      { r: 'debit_notes', label: 'قائمة إشعارات مدينة', icon: '📋', path: '/accounting/debit-notes', kind: 'list' },
      { r: 'credit_notes', label: 'إشعار دائن', icon: 'Ⓒ', path: '/accounting/credit-notes/entry', kind: 'bridge' },
      { r: 'credit_notes', label: 'قائمة إشعارات دائنة', icon: '📋', path: '/accounting/credit-notes', kind: 'list' },
    ],
  },
  {
    group: 'ops',
    title: 'المحاسبة',
    items: [
      { r: 'chart_of_accounts', label: 'شجرة الحسابات', icon: '🌳', path: '/accounting/chart', kind: 'list' },
      { r: 'acc_opening_balance', label: 'الأرصدة الافتتاحية', icon: '📥', path: '/accounting/opening-balance', kind: 'list' },
      { r: 'journal_entries', label: 'القيود المحاسبية', icon: '⚖', path: '/accounting/journals', kind: 'list' },
      { r: 'fin_checks', label: 'الشيكات الواردة', icon: '📝', path: '/accounting/checks-in', kind: 'list' },
      { r: 'fin_outgoing_checks', label: 'سجل الشيكات الصادرة', icon: '📤', path: '/accounting/checks-out', kind: 'list' },
      { r: 'fin_private_out_checks', label: 'شيكات خاصة', icon: '📋', path: '/accounting/checks-private', kind: 'list' },
      { r: 'report_general_ledger', label: 'دفتر الأستاذ العام', icon: '📖', path: '/accounting/general-ledger', kind: 'report' },
      { r: 'acc_period_close', label: 'إغلاق الأشهر المحاسبية', icon: '🔒', path: '/accounting/period-close', kind: 'list' },
      { r: 'acc_year_close', label: 'إقفال السنة المالية', icon: '📅', path: '/accounting/year-close', kind: 'list' },
    ],
  },
  {
    group: 'reports',
    title: 'تقارير المحاسبة',
    items: [
      { r: 'report_vouchers', label: 'تقرير سندات القبض / الصرف', icon: '📒', path: '/accounting/reports/vouchers', kind: 'report' },
      { r: 'report_cancelled_vouchers', label: 'قائمة السندات الملغاة', icon: '🚫', path: '/accounting/reports/cancelled-vouchers', kind: 'report' },
      { r: 'report_chart_of_accounts', label: 'طباعة شجرة الحسابات', icon: '🌳', path: '/accounting/reports/chart', kind: 'report' },
      { r: 'report_receivables', label: 'كشف ذمم العملاء', icon: '📒', path: '/accounting/reports/receivables', kind: 'report' },
      { r: 'report_receivables_aging', label: 'أعمار الذمم', icon: '📊', path: '/accounting/reports/receivables-aging', kind: 'report' },
      { r: 'report_incoming_checks', label: 'تقرير الشيكات الواردة', icon: '📒', path: '/accounting/reports/checks-in', kind: 'report' },
      { r: 'report_outgoing_checks', label: 'تقرير الشيكات الصادرة', icon: '📒', path: '/accounting/reports/checks-out', kind: 'report' },
      { r: 'report_supplier_payables', label: 'كشف ذمم الموردين', icon: '📒', path: '/accounting/reports/payables', kind: 'report' },
      { r: 'report_party_statement', label: 'كشف حساب مورد - عميل', icon: '📋', path: '/accounting/reports/party-statement', kind: 'report' },
      { r: 'report_oracle_customer_statement', label: 'كشف حساب تفصيلي Oracle', icon: '📒', path: '/accounting/reports/oracle-statement', kind: 'report' },
      { r: 'report_oracle_sales_invoice', label: 'فاتورة بيع Oracle', icon: '🧾', path: '/accounting/reports/oracle-sales-invoice', kind: 'report' },
      { r: 'report_account_statement', label: 'كشف حساب', icon: '📋', path: '/accounting/reports/account-statement', kind: 'report' },
      { r: 'report_trial_balance', label: 'ميزان المراجعة', icon: '⚖', path: '/accounting/reports/trial-balance', kind: 'report' },
      { r: 'report_trial_balance_detailed', label: 'ميزان مراجعة تفصيلي', icon: '⚖', path: '/accounting/reports/trial-balance-detailed', kind: 'report' },
      { r: 'report_journal', label: 'تقرير القيود', icon: '📒', path: '/accounting/reports/journal', kind: 'report' },
      { r: 'report_income_statement_comprehensive', label: 'الأرباح والخسائر', path: '/accounting/reports/pl', icon: '📊', kind: 'report' },
      { r: 'report_income_statement', label: 'قائمة الدخل', icon: '📈', path: '/accounting/reports/income', kind: 'report' },
      { r: 'report_balance_sheet', label: 'الميزانية العمومية', icon: '🏛', path: '/accounting/reports/balance-sheet', kind: 'report' },
    ],
  },
  {
    group: 'vat',
    title: 'تقارير الضريبة',
    items: [
      { r: 'report_tax_declaration', label: 'الإقرار الضريبي', icon: '📋', path: '/accounting/tax/declaration', kind: 'report' },
      { r: 'report_tax_ar3', label: 'تقرير الضريبة (أر/3)', icon: '📄', path: '/accounting/tax/ar3', kind: 'report' },
      { r: 'report_vat_net_payable', label: 'أمانات ضريبة مبيعات', icon: '🇯🇴', path: '/accounting/tax/vat-net', kind: 'report' },
      { r: 'report_invoice_tax', label: 'ضريبة فواتير البيع', icon: '🧾', path: '/accounting/tax/sales', kind: 'report' },
      { r: 'report_invoice_tax_purchase', label: 'ضريبة فواتير الشراء', icon: '📥', path: '/accounting/tax/purchases', kind: 'report' },
      { r: 'report_vat_return_tax', label: 'ضريبة مردود البيع', icon: '↩', path: '/accounting/tax/sales-return', kind: 'report' },
      { r: 'report_vat_return_tax_purchase', label: 'ضريبة مردود الشراء', icon: '↩', path: '/accounting/tax/purchase-return', kind: 'report' },
    ],
  },
];

function flatAccountingItems() {
  return accountingCatalog.flatMap((g) => g.items.map((it) => ({ ...it, group: g.group })));
}

module.exports = { accountingCatalog, flatAccountingItems };
