@echo off
setlocal
cd /d "%~dp0"

if not exist "config.local.json" (
  copy /Y "config.json" "config.local.json" >nul
)

copy /Y "config.local.json" "config.json" >nul

if not exist "node_modules\electron\dist\electron.exe" (
  call npm.cmd install --no-fund --no-audit
)

start "Manager Desktop Local" /D "%CD%" "%CD%\node_modules\electron\dist\electron.exe" .
endlocal
