@echo off
setlocal
chcp 65001 >nul
REM إزالة الإقلاع التلقائي + إيقاف العملية
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%PATH%"

echo إزالة مهمة Windows HypexNode...
schtasks /Delete /TN "HypexNode" /F >nul 2>nul
schtasks /Delete /TN "HypexNodePM2" /F >nul 2>nul

set "STARTUP_DIR=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
if exist "%STARTUP_DIR%\HypexNode.cmd" del /f /q "%STARTUP_DIR%\HypexNode.cmd" >nul 2>nul

where pm2 >nul 2>nul
if not errorlevel 1 (
  pm2 delete hypex-node 2>nul
  pm2 save --force 2>nul
)

echo تم. لن يعمل Hypex Node تلقائياً عند الإقلاع.
pause
endlocal
