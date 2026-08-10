@echo off
REM يستعاد Hypex Node بعد إقلاع / تسجيل دخول Windows
setlocal EnableExtensions
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%LOCALAPPDATA%\npm;%PATH%"
set "LOG=%TEMP%\hypex-node-autostart.log"

echo ==== %DATE% %TIME% ====>> "%LOG%"

REM انتظر تهيئة الشبكة وMySQL/XAMPP قليلاً
timeout /t 12 /nobreak >nul

where node >nul 2>nul
if errorlevel 1 (
  echo ERROR: node not found>> "%LOG%"
  exit /b 1
)

where pm2 >nul 2>nul
if errorlevel 1 (
  where npm >nul 2>nul
  if not errorlevel 1 call npm install -g pm2 >>"%LOG%" 2>&1
)

where pm2 >nul 2>nul
if errorlevel 1 (
  echo ERROR: pm2 not found>> "%LOG%"
  exit /b 1
)

REM إن كان يعمل مسبقاً — لا تُعد التشغيل
pm2 describe hypex-node 2>nul | findstr /I "status.*online" >nul 2>nul
if not errorlevel 1 (
  echo already online>> "%LOG%"
  exit /b 0
)

pm2 resurrect >>"%LOG%" 2>&1
pm2 describe hypex-node 2>nul | findstr /I "status.*online" >nul 2>nul
if not errorlevel 1 (
  echo resurrect ok>> "%LOG%"
  exit /b 0
)

cd /d "%~dp0..\hypex-node"
if exist ecosystem.config.cjs (
  pm2 delete hypex-node 2>nul
  pm2 start ecosystem.config.cjs >>"%LOG%" 2>&1
  pm2 save --force >>"%LOG%" 2>&1
  echo started from ecosystem>> "%LOG%"
) else (
  echo ERROR: no ecosystem>> "%LOG%"
  exit /b 1
)
endlocal
