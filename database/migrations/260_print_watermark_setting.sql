-- علامة مائية عند الطباعة
-- إن وُجد العمود مسبقًا سيظهر خطأ يمكن تجاهله؛ Node يضيفه تلقائياً أيضاً.
ALTER TABLE sys_company_settings
  ADD COLUMN print_watermark_enabled TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1=إظهار علامة الشعار المائية عند الطباعة';
