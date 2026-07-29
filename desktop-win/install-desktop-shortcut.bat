@echo off
REM إنشاء اختصار على سطح المكتب لفتح النظام بغلاف ويندوز (تأكيد الخروج عند X)
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0create-desktop-shortcut.ps1"
pause
