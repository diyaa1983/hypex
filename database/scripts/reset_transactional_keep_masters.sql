-- تفريغ بيانات التشغيل والبدء من جديد
-- يُبقى: العملاء، المواد (والتصنيفات/الوحدات/المستودعات)، شجرة الحسابات، إعدادات النظام
-- نفّذ من phpMyAdmin على قاعدة namma_erp (أو اسم قاعدتك) بعد نسخة احتياطية

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM acc_journal_line;
DELETE FROM acc_journal_entry;
DELETE FROM fin_credit_note_line;
DELETE FROM fin_credit_note;
DELETE FROM fin_debit_note_line;
DELETE FROM fin_debit_note;
DELETE FROM fin_voucher;
DELETE FROM inv_stock_move;
DELETE FROM crm_customer_ledger;
DELETE FROM crm_supplier_ledger;
DELETE FROM sal_return_line;
DELETE FROM sal_return;
DELETE FROM sal_delivery_line;
DELETE FROM sal_delivery;
DELETE FROM sal_invoice_line;
DELETE FROM sal_invoice;
DELETE FROM pur_return_line;
DELETE FROM pur_return;
DELETE FROM pur_invoice_line;
DELETE FROM pur_invoice;

UPDATE crm_customer SET sales_rep_id = NULL WHERE sales_rep_id IS NOT NULL;
DELETE FROM crm_sales_rep;
DELETE FROM crm_supplier;

SET FOREIGN_KEY_CHECKS = 1;
