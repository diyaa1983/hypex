Oracle Sync Agent — مزامنة تلقائية حسب ملف إعداد
================================================

1) انسخ المجلد deploy\oracle-agent إلى السيرفر، مثلاً:
   C:\xampp\htdocs\system\deploy\oracle-agent\

2) عدّل agent.config.json:
   - interval_seconds: 60 = كل دقيقة | 300 = 5 دقائق | 3600 = ساعة
   - token: نفس sync_token في config\oracle.local.php
   - php_exe / sync_script: مسارات XAMPP على السيرفر
   - entities: "customers" (أو "customers,accounts" لاحقاً)
   - enabled: true/false لإيقاف المزامنة دون حذف المهمة

3) تشغيل يدوي (حلقة مستمرة):
   PowerShell:
   cd C:\xampp\htdocs\system\deploy\oracle-agent
   .\oracle_sync_agent.ps1

4) تشغيل مرة واحدة (لـ Task Scheduler كل دقيقة):
   .\oracle_sync_agent.ps1 -Once

5) سجلّات:
   storage\logs\oracle-agent-YYYYMMDD.log

ملاحظات أمان:
- لا ترفع agent.config.json إلى Git إن فيه token حقيقي.
- لا تجعل interval أقل من 15 ثانية (السكربت يفرض حداً أدنى 15).
- كل دقيقة قد تكون ثقيلة إن كان عدد العملاء كبيراً؛ ابدأ بـ 300.
