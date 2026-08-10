@echo off
chcp 65001 >nul
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%PATH%"
cd /d "%~dp0..\hypex-node"

where pm2 >nul 2>nul
if errorlevel 1 (
  echo pm2 غير مثبت. شغّل deploy\pm2-install-service.cmd مرة واحدة.
  pause
  exit /b 1
)

pm2 describe hypex-node 2>nul | findstr /I "online" >nul 2>nul
if not errorlevel 1 (
  echo hypex-node يعمل بالفعل.
  pm2 status
  exit /b 0
)

if exist ecosystem.config.cjs (
  pm2 start ecosystem.config.cjs
) else (
  pm2 start src\server.js --name hypex-node
)
pm2 save --force
pm2 status
echo.
echo تم التشغيل — http://localhost/hypex
