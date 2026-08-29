-- صلاحيات أزرار ترحيل/إلغاء ترحيل Oracle لطلب شراء العميل + حذف زيارة المندوب
INSERT IGNORE INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES
('action_post_customer_order_oracle', 'ترحيل طلب شراء عميل إلى Oracle', 'screen', 9010),
('action_unpost_customer_order_oracle', 'إلغاء ترحيل Oracle لطلب شراء عميل', 'screen', 9011),
('action_delete_sales_rep_visit', 'حذف زيارة من تقرير الزيارات', 'screen', 9012);

INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
SELECT g.id, s.id, 1
FROM sys_group g
INNER JOIN sys_screen s ON s.code IN (
  'action_post_customer_order_oracle',
  'action_unpost_customer_order_oracle',
  'action_delete_sales_rep_visit'
)
WHERE g.code = 'ADMINS';
