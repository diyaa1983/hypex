@echo off
setlocal
REM تشغيل Hypex Node كخدمة دائمة عبر pm2
cd /d "%~dp0..\hypex-node"
if not exist "src\server.js" (
  echo ERROR: src\server.js not found in %cd%
  pause
  exit /b 1
)

where pm2 >nul 2>nul
if errorlevel 1 (
  echo Installing pm2 globally...
  call npm install -g pm2
  if errorlevel 1 (
    echo ERROR: npm install -g pm2 failed
    pause
    exit /b 1
  )
)

echo Installing dependencies...
call npm install --omit=dev

echo.
echo Stopping old instance if any...
pm2 delete hypex-node 2>nul

echo Starting hypex-node...
pm2 start "%cd%\src\server.js" --name hypex-node --cwd "%cd%"
if errorlevel 1 (
  echo ERROR: pm2 start failed
  pause
  exit /b 1
)

pm2 save
echo.
echo ========== status ==========
pm2 status
echo.
echo OK - open http://localhost/hypex
echo Logs: pm2 logs hypex-node
echo Restart later: pm2 restart hypex-node
echo.
pause
endlocal
