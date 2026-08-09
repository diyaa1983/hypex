@echo off
REM تشغيل Hypex Node مع إعادة تحميل تلقائية عند تعديل الملفات
cd /d "%~dp0..\hypex-node"
echo.
echo  Hypex Node (watch mode) — يتحدّث تلقائياً بعد حفظ الملفات
echo  Public URL:  http://localhost/hypex
echo  أوقف: Ctrl+C
echo.
node --watch src/server.js
