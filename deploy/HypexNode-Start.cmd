@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul 2>nul
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%LOCALAPPDATA%\npm;%PATH%"
cd /d "%~dp0..\hypex-node"

echo.
echo === Hypex Node - Start ===
echo Folder: %cd%
echo.

where node >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Node.js not found. Install from https://nodejs.org
  goto :fail
)

where pm2 >nul 2>nul
if errorlevel 1 (
  echo [ERROR] pm2 not found.
  echo Run once as Admin: deploy\pm2-install-service.cmd
  goto :fail
)

if not exist "src\server.js" (
  echo [ERROR] src\server.js missing in %cd%
  goto :fail
)

if not exist "node_modules" (
  echo [1] npm install...
  call npm install --omit=dev
  if errorlevel 1 goto :fail
)

if not exist ".env" (
  echo [WARN] .env missing - copy from .env.example
)

echo [1] Checking PM2...
set "NEED_START=1"
pm2 describe hypex-node 2>nul | findstr /I /C:"status" | findstr /I /C:"online" >nul 2>nul
if not errorlevel 1 (
  call :health
  if not errorlevel 1 (
    echo [OK] hypex-node already online and healthy.
    set "NEED_START=0"
    goto :ok
  )
  echo [WARN] online but not answering - restarting...
  pm2 delete hypex-node 2>nul
)

if "%NEED_START%"=="1" (
  echo [2] Starting hypex-node...
  if exist ecosystem.config.cjs (
    pm2 start ecosystem.config.cjs
  ) else (
    pm2 start src\server.js --name hypex-node --cwd "%cd%"
  )
  if errorlevel 1 (
    echo [ERROR] pm2 start failed
    goto :fail
  )
  pm2 save --force >nul 2>nul
  timeout /t 3 /nobreak >nul
)

call :health
if errorlevel 1 (
  echo.
  echo [ERROR] Process up but http://127.0.0.1:3000 not answering
  echo Try: pm2 logs hypex-node --lines 40
  echo.
  pm2 logs hypex-node --lines 20 --nostream
  goto :fail
)

:ok
pm2 status
echo.
echo ========== READY ==========
echo  Browser :  http://localhost/hypex
echo  Direct  :  http://127.0.0.1:3000/hypex
echo  Public  :  http://YOUR-PUBLIC-IP/hypex
echo.
echo  Need XAMPP Apache+MySQL running for /hypex on port 80
echo.
pause
exit /b 0

:fail
echo.
echo ========== START FAILED ==========
echo  Check: Node, pm2, .env, npm install, XAMPP MySQL
echo  Logs : pm2 logs hypex-node
echo  Status: pm2 status
echo.
pause
exit /b 1

:health
REM Healthy if Node responds on port 3000 (0=ok, 1=fail)
node -e "const h=require('http');const u='http://127.0.0.1:3000/hypex';const t=setTimeout(()=>process.exit(1),5000);h.get(u,r=>{clearTimeout(t);process.exit(r.statusCode&&r.statusCode<500?0:1);}).on('error',()=>{clearTimeout(t);process.exit(1);});"
if errorlevel 1 (
  exit /b 1
)
exit /b 0
