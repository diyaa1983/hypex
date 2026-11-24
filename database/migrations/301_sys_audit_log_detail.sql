-- توسيع حقول التفاصيل في سجل حركات التعديل
ALTER TABLE sys_audit_log MODIFY COLUMN summary TEXT NULL;
ALTER TABLE sys_audit_log MODIFY COLUMN entity_ref VARCHAR(120) NULL;
