@echo off
setlocal EnableExtensions
chcp 65001 >nul
REM =============================================================================
REM  تثبيت Hypex Node كخدمة دائمة على Windows
REM  - يعمل دائماً (حتى بعد إعادة تشغيل الجهاز)
REM  - عند تعديل ملفات src/ يعيد التحميل تلقائياً (بدون إيقاف يدوي)
REM  - الجلسات تبقى في MySQL — المستخدمون لا يُطردون
REM =============================================================================

cd /d "%~dp0..\hypex-node"
if not exist "src\server.js" (
  echo ERROR: src\server.js not found in %cd%
  pause
  exit /b 1
)

where node >nul 2>nul
if errorlevel 1 (
  echo ERROR: Node.js غير مثبت. ثبّت Node 18+ ثم أعد المحاولة.
  pause
  exit /b 1
)

echo [1/5] تثبيت pm2...
call npm install -g pm2
if errorlevel 1 (
  echo ERROR: فشل تثبيت pm2
  pause
  exit /b 1
)

echo [2/5] تثبيت اعتماديات المشروع...
call npm install --omit=dev

echo [3/5] تشغيل hypex-node (مع watch على src/)...
pm2 delete hypex-node 2>nul
pm2 start ecosystem.config.cjs
if errorlevel 1 (
  echo ERROR: فشل pm2 start
  pause
  exit /b 1
)

echo [4/5] حفظ قائمة العمليات...
pm2 save

echo [5/5] تسجيل مهمة Windows للتشغيل عند الإقلاع...
set "TASK_NAME=HypexNodePM2"
set "RESURRECT=%~dp0pm2-resurrect.cmd"

schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>nul
schtasks /Create /TN "%TASK_NAME%" /TR "\"%RESURRECT%\"" /SC ONSTART /RU SYSTEM /RL HIGHEST /F
if errorlevel 1 (
  echo.
  echo تحذير: تعذر إنشاء مهمة ONSTART ^(SYSTEM^). جرّب عند تسجيل الدخول...
  schtasks /Create /TN "%TASK_NAME%" /TR "\"%RESURRECT%\"" /SC ONLOGON /RL HIGHEST /F
  if errorlevel 1 (
    echo ERROR: فشل تسجيل Scheduled Task — شغّل هذا الملف كـ Administrator
    pause
    exit /b 1
  )
)

echo.
echo ========== الحالة ==========
pm2 status
echo.
echo تمت التهيئة بنجاح.
echo.
echo  الرابط:  http://localhost/hypex
echo.
echo  تعديلات src/*.js     → إعادة تحميل تلقائية (watch)
echo  تعديلات public/css,js → Ctrl+F5 فقط
echo  تعديلات .env         → نفّذ: pm2 restart hypex-node
echo.
echo  الحالة:  pm2 status
echo  السجلات: pm2 logs hypex-node
echo  إيقاف:   pm2 stop hypex-node
echo  تشغيل:   pm2 start hypex-node
echo.
pause
endlocal
