@echo off
chcp 65001 >nul
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%PATH%"
where pm2 >nul 2>nul
if errorlevel 1 (
  echo pm2 غير مثبت.
  exit /b 1
)
echo === Hypex Node ===
pm2 status
echo.
echo مهمة Windows:
schtasks /Query /TN "HypexNode" /FO LIST 2>nul
if errorlevel 1 echo  (لا توجد مهمة HypexNode — ثبّت عبر pm2-install-service.cmd)
echo.
echo الرابط: http://localhost/hypex
pause
