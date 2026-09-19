-- إضافة عملة الشركة (للتفقيط وعرض المبالغ)

ALTER TABLE sys_company_settings
    ADD COLUMN currency_code VARCHAR(8) NOT NULL DEFAULT 'SAR' AFTER rows_per_page;
