@echo off
REM مزامنة عملاء Oracle — للتشغيل من Task Scheduler كل 5 دقائق
cd /d "C:\xampp\htdocs\Hypex"
"C:\xampp\php\php.exe" -c "C:\xampp\php\php.ini" "C:\xampp\htdocs\Hypex\tools\oracle_customers_auto_sync_run.php" %*
