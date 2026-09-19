-- =============================================================================
-- تفريغ النظام لإنتاج نسخة قالب فارغة (Template)
-- =============================================================================
-- يُبقي: الشاشات، المجموعات والصلاحيات، سجل الترحيلات، شجرة الحسابات،
--        ربط الحسابات، أنواع حركات المخزون، إعدادات ضريبة/رواتب أساسية، مستخدم admin.
-- يمسح: كل بيانات التشغيل والأطراف والمواد والموظفين والمستندات والسجلات.
--
-- تنبيه: نفّذ نسخة احتياطية أولاً.
-- الاستخدام:
--   mysql -h localhost -u root -p hypex < database/scripts/factory_empty_template.sql
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_SAFE_UPDATES = 0;

-- ---------------------------------------------------------------------------
-- 1) مستندات وقيود وتشغيل
-- ---------------------------------------------------------------------------
TRUNCATE TABLE acc_journal_line;
TRUNCATE TABLE acc_journal_entry;
TRUNCATE TABLE fin_credit_note_line;
TRUNCATE TABLE fin_credit_note;
TRUNCATE TABLE fin_debit_note_line;
TRUNCATE TABLE fin_debit_note;
TRUNCATE TABLE fin_voucher_check;
TRUNCATE TABLE fin_voucher_document;
TRUNCATE TABLE fin_check_due_email_log;
TRUNCATE TABLE fin_private_out_check;
TRUNCATE TABLE fin_voucher;
TRUNCATE TABLE inv_stock_move;
TRUNCATE TABLE inv_wh_move_line;
TRUNCATE TABLE inv_wh_move;
TRUNCATE TABLE inv_stocktake_line;
TRUNCATE TABLE inv_stocktake_doc;
TRUNCATE TABLE inv_price_adj_doc;
TRUNCATE TABLE inv_item_sale_price_adj;
TRUNCATE TABLE crm_customer_ledger;
TRUNCATE TABLE crm_supplier_ledger;
TRUNCATE TABLE crm_customer_sales_rep;
TRUNCATE TABLE sal_return_line;
TRUNCATE TABLE sal_return;
TRUNCATE TABLE sal_delivery_line;
TRUNCATE TABLE sal_delivery;
TRUNCATE TABLE sal_invoice_line;
TRUNCATE TABLE sal_invoice;
TRUNCATE TABLE sal_customer_order_line;
TRUNCATE TABLE sal_customer_order;
TRUNCATE TABLE sal_rep_route_line;
TRUNCATE TABLE sal_rep_route;
TRUNCATE TABLE pur_return_line;
TRUNCATE TABLE pur_return;
TRUNCATE TABLE pur_invoice_line;
TRUNCATE TABLE pur_invoice;
TRUNCATE TABLE pur_order_line;
TRUNCATE TABLE pur_order;
TRUNCATE TABLE doc_number_pool;

-- ---------------------------------------------------------------------------
-- 2) أطراف + مناديب
-- ---------------------------------------------------------------------------
TRUNCATE TABLE crm_customer;
TRUNCATE TABLE crm_supplier;
TRUNCATE TABLE crm_sales_rep;

-- ---------------------------------------------------------------------------
-- 3) مخزون: مواد، تصنيفات، وحدات، مستودعات (ثم بذرة مستودع افتراضي)
-- ---------------------------------------------------------------------------
TRUNCATE TABLE inv_item_unit;
TRUNCATE TABLE inv_item;
TRUNCATE TABLE inv_item_category;
TRUNCATE TABLE inv_unit;
TRUNCATE TABLE sys_group_warehouse;
TRUNCATE TABLE inv_warehouse;

INSERT INTO inv_warehouse (id, code, name_ar, is_active)
SELECT 1, 'MAIN', 'المستودع الرئيسي', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_warehouse LIMIT 1);

INSERT INTO inv_unit (code, name_ar, is_active)
SELECT 'PCS', 'قطعة', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inv_unit LIMIT 1);

-- ---------------------------------------------------------------------------
-- 4) موارد بشرية / حضور
-- ---------------------------------------------------------------------------
TRUNCATE TABLE hr_salary_advance_deduction;
TRUNCATE TABLE hr_employee_advance;
TRUNCATE TABLE hr_salary_line;
TRUNCATE TABLE hr_salary;
TRUNCATE TABLE hr_social_security;
TRUNCATE TABLE hr_employee_monthly_payroll_line;
TRUNCATE TABLE hr_employee_salary_line;
TRUNCATE TABLE hr_employee_overtime;
TRUNCATE TABLE hr_employee_leave;
TRUNCATE TABLE hr_employee_leave_balance;
TRUNCATE TABLE hr_employee_departure;
TRUNCATE TABLE hr_att_punch;
TRUNCATE TABLE hr_att_employee_weekly_day;
TRUNCATE TABLE hr_att_employee_weekly;
TRUNCATE TABLE hr_att_employee_default_shift;
TRUNCATE TABLE hr_att_employee_map;
TRUNCATE TABLE hr_employee;
TRUNCATE TABLE hr_department;
TRUNCATE TABLE hr_job_title;
TRUNCATE TABLE hr_nationality;
TRUNCATE TABLE hr_salary_bank;
TRUNCATE TABLE hr_att_shift;

-- ZKT / بصمات (إن وُجدت)
TRUNCATE TABLE checkexact;
TRUNCATE TABLE checkinout;
TRUNCATE TABLE departments;
TRUNCATE TABLE excnotes;
TRUNCATE TABLE holidays;
TRUNCATE TABLE leaveclass;
TRUNCATE TABLE leaveclass1;
TRUNCATE TABLE num_run;
TRUNCATE TABLE num_run_deil;
TRUNCATE TABLE schclass;
TRUNCATE TABLE securitydetails;
TRUNCATE TABLE shift;
TRUNCATE TABLE template;
TRUNCATE TABLE user_of_run;
TRUNCATE TABLE user_speday;
TRUNCATE TABLE user_temp_sch;
TRUNCATE TABLE userinfo;
TRUNCATE TABLE attparam;

-- ---------------------------------------------------------------------------
-- 5) فترات محاسبية / سنوات مالية (تبدأ من جديد)
-- ---------------------------------------------------------------------------
TRUNCATE TABLE acc_accounting_period;
TRUNCATE TABLE acc_fiscal_year;

-- ---------------------------------------------------------------------------
-- 6) سجلات نظام وتشغيل مؤقتة / ترخيص / جلسات
-- ---------------------------------------------------------------------------
TRUNCATE TABLE sys_audit_log;
TRUNCATE TABLE sys_error_log;
TRUNCATE TABLE sys_user_favorite;
TRUNCATE TABLE sys_user_location;
TRUNCATE TABLE sys_user_location_track;
TRUNCATE TABLE sys_user_open_session;
TRUNCATE TABLE sys_user_password_reset;
TRUNCATE TABLE sys_dashboard_account;
TRUNCATE TABLE sys_license_activation_log;
TRUNCATE TABLE sys_license;

-- إعدادات نسخ احتياطي (صف واحد غالباً — إعادة ضبط بسيطة إن وُجدت أعمدة)
-- لا نحذف بنية الجدول؛ نُفرّغ الصفوف
TRUNCATE TABLE sys_backup_settings;

-- ---------------------------------------------------------------------------
-- 7) المستخدمون: الإبقاء على admin فقط
-- ---------------------------------------------------------------------------
DELETE FROM sys_user_group WHERE user_id NOT IN (
  SELECT id FROM (SELECT id FROM sys_user WHERE username = 'admin' LIMIT 1) t
);
DELETE FROM sys_user WHERE username <> 'admin';

-- ضمان وجود admin مرتبط بمجموعة ADMINS
INSERT INTO sys_user (username, password_hash, full_name_ar, email, is_active)
SELECT 'admin',
       '$2y$10$qI5ocJ2TWKNwF0o9JD54JOoJVbNrUFuQvF97YO5i1HExZfdas/fbi',
       'مدير النظام',
       'admin@local.test',
       1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM sys_user WHERE username = 'admin' LIMIT 1);

INSERT IGNORE INTO sys_user_group (user_id, group_id)
SELECT u.id, g.id
FROM sys_user u
INNER JOIN sys_group g ON g.code = 'ADMINS'
WHERE u.username = 'admin';

-- ---------------------------------------------------------------------------
-- 8) إعدادات الشركة / الفاتورة الإلكترونية → قيم قالب عامة
-- ---------------------------------------------------------------------------
UPDATE sys_company_settings
SET company_name_ar = 'اسم الشركة',
    logo_path = NULL,
    address_ar = NULL,
    phone = NULL,
    email = NULL,
    smtp_host = NULL,
    smtp_username = NULL,
    smtp_password = NULL,
    smtp_from_email = NULL,
    smtp_from_name = NULL,
    wa_phone_id = NULL,
    wa_access_token = NULL,
    wa_bridge_url = NULL,
    wa_bridge_token = NULL,
    check_email_enabled = 0,
    check_email_recipients = NULL,
    out_check_email_enabled = 0,
    out_check_email_recipients = NULL,
    login_recaptcha_enabled = 0,
    login_recaptcha_site_key = NULL,
    login_recaptcha_secret_key = NULL,
    document_archive_dir = NULL,
    gps_google_maps_api_key = ''
WHERE id = 1;

UPDATE sys_user
SET license_no = NULL
WHERE username = 'admin';

UPDATE sys_einvoice_settings
SET company_name = '',
    trade_name = NULL,
    vat_no = NULL,
    gst_no = NULL,
    company_email = NULL,
    company_phone = NULL,
    address = NULL,
    city = NULL,
    client_id = NULL,
    secret_key = NULL,
    admin_email = NULL,
    notes = NULL
WHERE id = 1;

-- ---------------------------------------------------------------------------
-- 9) تنظيف meta التشغيلية فقط (الإبقاء على علامات الترحيل/التهيئة)
-- ---------------------------------------------------------------------------
DELETE FROM acc_system_meta
WHERE meta_key IN (
  'check_due_email_last_ts'
);

SET FOREIGN_KEY_CHECKS = 1;
SET SQL_SAFE_UPDATES = 1;

-- تحقق سريع
SELECT 'customers' AS entity, COUNT(*) AS cnt FROM crm_customer
UNION ALL SELECT 'items', COUNT(*) FROM inv_item
UNION ALL SELECT 'sal_invoice', COUNT(*) FROM sal_invoice
UNION ALL SELECT 'employees', COUNT(*) FROM hr_employee
UNION ALL SELECT 'journal', COUNT(*) FROM acc_journal_entry
UNION ALL SELECT 'users', COUNT(*) FROM sys_user
UNION ALL SELECT 'accounts_kept', COUNT(*) FROM acc_account
UNION ALL SELECT 'screens_kept', COUNT(*) FROM sys_screen;
