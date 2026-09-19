@echo off
setlocal EnableExtensions
chcp 65001 >nul
REM =============================================================================
REM  تثبيت Hypex Node ليقلع مع Windows (مثل Apache/MySQL في XAMPP)
REM
REM  - بدون watch: لا حمل من مراقبة الملفات
REM  - يعمل في الخلفية دائماً
REM  - بعد إعادة تشغيل الجهاز يعمل تلقائياً
REM
REM  شغّل هذا الملف مرة واحدة: كليك يمين → Run as administrator
REM =============================================================================

cd /d "%~dp0..\hypex-node"
if not exist "src\server.js" (
  echo ERROR: src\server.js not found in %cd%
  pause
  exit /b 1
)

where node >nul 2>nul
if errorlevel 1 (
  echo ERROR: Node.js غير مثبت. ثبّت Node 18+ من https://nodejs.org ثم أعد المحاولة.
  pause
  exit /b 1
)

echo.
echo === تثبيت Hypex Node كخدمة تلقائية ===
echo المجلد: %cd%
echo.

echo [1/6] تثبيت pm2 عالمياً...
call npm install -g pm2
if errorlevel 1 (
  echo ERROR: فشل تثبيت pm2 — شغّل كـ Administrator
  pause
  exit /b 1
)

echo [2/6] تثبيت اعتماديات المشروع...
call npm install --omit=dev
if errorlevel 1 (
  echo ERROR: فشل npm install
  pause
  exit /b 1
)

echo [3/6] إيقاف أي نسخة قديمة...
pm2 delete hypex-node 2>nul

echo [4/6] تشغيل hypex-node (إنتاج — بدون watch)...
pm2 start ecosystem.config.cjs
if errorlevel 1 (
  echo ERROR: فشل pm2 start
  pause
  exit /b 1
)
pm2 save --force

echo [5/6] تسجيل الإقلاع التلقائي مع Windows...
set "TASK_NAME=HypexNode"
set "RESURRECT=%~dp0pm2-resurrect.cmd"
set "STARTUP_CMD=%~dp0start-hypex-autostart.cmd"

REM مهمة عند تسجيل الدخول (موثوقة مع PM2 لكل مستخدم)
schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>nul
schtasks /Delete /TN "HypexNodePM2" /F >nul 2>nul

schtasks /Create /TN "%TASK_NAME%" /TR "cmd /c \"\"%STARTUP_CMD%\"\"" /SC ONLOGON /RL HIGHEST /F
if errorlevel 1 (
  echo.
  echo تحذير: فشل إنشاء المهمة بدون صلاحيات أعلى. جرّب ONSTART كـ SYSTEM...
  schtasks /Create /TN "%TASK_NAME%" /TR "cmd /c \"\"%STARTUP_CMD%\"\"" /SC ONSTART /RU SYSTEM /RL HIGHEST /DELAY 0001:00 /F
  if errorlevel 1 (
    echo ERROR: فشل تسجيل Scheduled Task.
    echo شغّل هذا الملف: كليك يمين → Run as administrator
    pause
    exit /b 1
  )
)

REM اختصار في Startup folder (احتياطي إضافي عند دخول المستخدم)
set "STARTUP_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
if exist "%STARTUP_DIR%" (
  (
    echo @echo off
    echo call "%STARTUP_CMD%"
  ) > "%STARTUP_DIR%\HypexNode.cmd"
)

echo [6/6] التحقق...
pm2 status
schtasks /Query /TN "%TASK_NAME%" /FO LIST 2>nul | findstr /I "TaskName Status"

echo.
echo ========== تم ==========
echo.
echo  Hypex Node يعمل الآن مثل خدمة XAMPP:
echo    - يقلع مع تسجيل الدخول / الإقلاع
echo    - بدون مراقبة ملفات (load منخفض)
echo    - الرابط: http://localhost/hypex
echo.
echo  أوامر يومية:
echo    حالة   :  pm2 status
echo    سجلات  :  pm2 logs hypex-node
echo    إيقاف  :  deploy\HypexNode-Stop.cmd
echo    تشغيل  :  deploy\HypexNode-Start.cmd
echo    إزالة  :  deploy\uninstall-hypex-service.cmd
echo.
echo  بعد تعديل كود src\ :  pm2 restart hypex-node
echo  بعد public/css,js  :  Ctrl+F5 فقط
echo.
pause
endlocal
