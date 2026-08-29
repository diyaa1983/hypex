@echo off
REM إنشاء اختصار سطح المكتب: يفتح هايبكس كتطبيق بدون شريط Not secure / الرابط
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0create-desktop-shortcut.ps1"
echo.
echo افتح الاختصار «هايبكس» من سطح المكتب.
echo لا تفتح النظام من شريط عنوان المتصفح.
pause
