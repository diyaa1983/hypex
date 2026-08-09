@echo off
REM تثبيت/تحديث وتشغيل Hypex Node كخدمة دائمة (pm2) — بدون إغلاق نافذة
cd /d "%~dp0..\hypex-node"

where pm2 >nul 2>nul
if errorlevel 1 (
  echo Installing pm2 globally...
  call npm install -g pm2
)

echo Installing dependencies...
call npm install --omit=dev

echo Starting / restarting hypex-node via pm2...
pm2 describe hypex-node >nul 2>nul
if errorlevel 1 (
  pm2 start src/server.js --name hypex-node --cwd "%cd%"
) else (
  pm2 restart hypex-node --update-env
)
pm2 save

echo.
echo  OK — Node يعمل في الخلفية ^
echo  الرابط: http://localhost/hypex
echo  حالة: pm2 status
echo  سجلات: pm2 logs hypex-node
echo.
pause
