-- آخر مستخدم عدّل القيد المحاسبي

ALTER TABLE acc_journal_entry
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by;

ALTER TABLE acc_journal_entry
    ADD CONSTRAINT fk_aje_updated_by FOREIGN KEY (updated_by) REFERENCES sys_user(id) ON DELETE SET NULL;
