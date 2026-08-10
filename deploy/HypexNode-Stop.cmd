@echo off
chcp 65001 >nul
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%PATH%"
where pm2 >nul 2>nul
if errorlevel 1 (
  echo pm2 غير مثبت.
  exit /b 1
)
pm2 stop hypex-node
echo تم الإيقاف. لإعادة التشغيل: deploy\HypexNode-Start.cmd
