@echo off
chcp 65001 >nul
setlocal EnableExtensions
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%LOCALAPPDATA%\npm;%PATH%"
cd /d "%~dp0..\hypex-node"

echo.
echo === Hypex Node — تشغيل محلي ===
echo المجلد: %cd%
echo.

where node >nul 2>nul
if errorlevel 1 (
  echo [خطأ] Node.js غير موجود. ثبّت من https://nodejs.org
  goto :fail
)

where pm2 >nul 2>nul
if errorlevel 1 (
  echo [خطأ] pm2 غير مثبت.
  echo شغّل مرة واحدة: deploy\pm2-install-service.cmd  (Run as administrator)
  goto :fail
)

if not exist "src\server.js" (
  echo [خطأ] src\server.js غير موجود في %cd%
  goto :fail
)

if not exist "node_modules" (
  echo [1] تثبيت الاعتماديات...
  call npm install --omit=dev
  if errorlevel 1 goto :fail
)

if not exist ".env" (
  echo [تحذير] ملف .env غير موجود — انسخ .env.example إلى .env وعدّل DB_*
)

echo [1] فحص حالة PM2...
pm2 describe hypex-node 2>nul | findstr /I /C:"status" | findstr /I /C:"online" >nul 2>nul
if not errorlevel 1 (
  echo      العملية مسجّلة online — فحص المنفذ 3000...
  call :health
  if not errorlevel 1 (
    echo.
    echo [OK] hypex-node يعمل بالفعل ويستجيب.
    goto :ok
  )
  echo      لا يستجيب على المنفذ — إعادة تشغيل...
  pm2 delete hypex-node 2>nul
)

echo [2] تشغيل hypex-node...
if exist ecosystem.config.cjs (
  pm2 start ecosystem.config.cjs
) else (
  pm2 start src\server.js --name hypex-node --cwd "%cd%"
)
if errorlevel 1 (
  echo [خطأ] فشل pm2 start
  goto :fail
)

pm2 save --force >nul 2>nul
timeout /t 2 /nobreak >nul

call :health
if errorlevel 1 (
  echo.
  echo [خطأ] العملية بدأت لكن لا تستجيب على http://127.0.0.1:3000
  echo اعرض السجلات:
  echo   pm2 logs hypex-node --lines 50
  echo.
  pm2 logs hypex-node --lines 25 --nostream
  goto :fail
)

:ok
pm2 status
echo.
echo ========== جاهز ==========
echo  الرابط:  http://localhost/hypex
echo  داخلي:   http://127.0.0.1:3000/hypex
echo.
echo  تأكد أن Apache/XAMPP يعمل إن استخدمت /hypex عبر المنفذ 80
echo.
pause
exit /b 0

:fail
echo.
echo ========== فشل التشغيل ==========
echo  تحقق: Node + pm2 + .env + npm install + XAMPP MySQL
echo  logs: pm2 logs hypex-node
echo.
pause
exit /b 1

:health
REM يرجع 0 إذا المنفذ يستجيب
powershell -NoProfile -Command "try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:3000/hypex' -UseBasicParsing -TimeoutSec 4; if ($r.StatusCode -ge 200 -and $r.StatusCode -lt 500) { exit 0 } else { exit 1 } } catch { exit 1 }"
exit /b %ERRORLEVEL%
