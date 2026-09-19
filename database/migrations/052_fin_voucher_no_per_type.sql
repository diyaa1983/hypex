-- رقم السند فريد ضمن النوع (قبض / صرف) وليس عالمياً — يسمح بـ 001-2026 للقبض و001-2026 للصرف

ALTER TABLE fin_voucher DROP INDEX uq_fin_voucher_no;
ALTER TABLE fin_voucher ADD UNIQUE KEY uq_fin_voucher_type_no (voucher_type, voucher_no);
