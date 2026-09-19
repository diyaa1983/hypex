-- شيكات صادرة آجلة: ربط حساب الالتزام حتى صرف الشيك من البنك
INSERT INTO acc_posting_setting (rule_code, label_ar, hint_ar, sort_order)
SELECT
  'outgoing_deferred_checks',
  'الشيكات الآجلة (صادرة)',
  'سند صرف بشيك: دائن عند ترحيل السند (بدل البنك). عند صرف الشيك من سجل الشيكات الصادرة يُمدَّن هذا الحساب ويُخصم البنك',
  14
WHERE NOT EXISTS (
  SELECT 1 FROM acc_posting_setting WHERE rule_code = 'outgoing_deferred_checks'
);

UPDATE acc_posting_setting
SET
  label_ar = 'الشيكات الآجلة (صادرة)',
  hint_ar = 'سند صرف بشيك: دائن عند ترحيل السند (بدل البنك). عند صرف الشيك من سجل الشيكات الصادرة يُمدَّن هذا الحساب ويُخصم البنك',
  sort_order = 14
WHERE rule_code = 'outgoing_deferred_checks';

UPDATE acc_posting_setting
SET hint_ar = 'سندات بالتحويل البنكي أو النقد عبر البنك — شيكات الصرف الصادرة تستخدم «الشيكات الآجلة» حتى الصرف'
WHERE rule_code = 'bank';
