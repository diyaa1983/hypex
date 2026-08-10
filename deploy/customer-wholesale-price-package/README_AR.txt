تسعير العميل (بيع / جملة) — نشر على السيرفر
===========================================

السبب: المحلي يعمل لأن الملفات محدّثة و PM2 يقرأها.
السيرفر لا يظهر القسم لأن ملف menuRoutes.js (أو العملية) ما زال قديماً.

1) انسخ محتويات هذه الحزمة فوق مشروع hypex على السيرفر
   (المسارات داخل الحزمة نفسها: hypex-node\... و modules\...)
   مثال المسار الشائع:
   C:\xampp\htdocs\hypex\

2) افتح CMD كمسؤول على السيرفر وتحقق أن الملف الجديد وصل:
   findstr /C:"use_wholesale_price" C:\xampp\htdocs\hypex\hypex-node\src\customers\menuRoutes.js
   findstr /C:"سعر البيع / الجملة" C:\xampp\htdocs\hypex\hypex-node\src\customers\menuRoutes.js

   إذا لم يظهر نص — النسخ لم يصل للمسار الصحيح.

3) تحقق من مسار تشغيل PM2 (يجب أن يطابق المشروع):
   pm2 describe hypex-node
   (انظر: exec cwd و script path)

4) أعد التشغيل بدون نقطة في الاسم:
   pm2 restart hypex-node
   pm2 save

5) في المتصفح على السيرفر: Ctrl+F5
   ثم: /customers/ID أو قائمة العملاء → تعريف العميل
   يجب أن يظهر قسم أخضر: «سعر البيع / الجملة»

6) العمود في MySQL يُنشأ تلقائياً عند أول حفظ/فتح عميل من Node.
   أو نفّذ مرة واحدة (phpMyAdmin / mysql):
   انظر: database\migrations\268_crm_customer_use_wholesale_price.sql

ملفات الحزمة (11):
- hypex-node\src\customers\menuRoutes.js          ← واجهة العميل (الأهم للعرض)
- hypex-node\src\customers\mastersService.js      ← حفظ الخيار
- hypex-node\src\lib\itemPricing.js
- hypex-node\src\sales\invoicesService.js
- hypex-node\src\sales\invoicesRoutes.js
- hypex-node\src\sales\customerOrdersService.js
- hypex-node\src\sales\customerOrdersRoutes.js
- hypex-node\public\js\sales-invoice.js
- hypex-node\public\js\customer-order.js
- modules\master\customers.php
- database\migrations\268_crm_customer_use_wholesale_price.sql
