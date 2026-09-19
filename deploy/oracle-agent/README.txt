Oracle Sync Agent — مزامنة تلقائية لعملاء Oracle
================================================

1) من الشاشة: تكامل Oracle — العملاء
   - فعّل «المزامنة التلقائية» واحفظ (الفاصل = 5 دقائق)
   - استخدم «مزامنة يدوية الآن» في أي وقت

2) ثبّت مهمة Windows مرة واحدة (PowerShell كمسؤول):
   cd C:\xampp\htdocs\Hypex\deploy\oracle-agent
   .\install-customers-sync-task.ps1

3) السكربت الذي يُنفَّذ كل 5 دقائق:
   C:\xampp\php\php.exe C:\xampp\htdocs\Hypex\tools\oracle_customers_auto_sync_run.php

بديل (حلقة PowerShell مستمرة):
   عدّل agent.config.json إن وُجد (interval_seconds: 300)
   ثم شغّل oracle_sync_agent.ps1

ملاحظات:
- يحترم auto_sync.enabled في config\oracle.local.php
- --force يتجاهل الفاصل الزمني
- لا ترفع ملفات الإعداد التي فيها كلمات مرور أو token
