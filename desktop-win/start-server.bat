@echo off
setlocal
cd /d "%~dp0"

REM Use server URL config (copy example first if missing)
if not exist "config.server.json" (
  if exist "config.server.example.json" (
    copy /Y "config.server.example.json" "config.server.json" >nul
    echo Created config.server.json — edit appUrl to your real server URL, then run again.
    notepad "config.server.json"
    pause
    exit /b 1
  )
  echo Missing config.server.json
  pause
  exit /b 1
)

copy /Y "config.server.json" "config.json" >nul

if not exist "node_modules\electron\dist\electron.exe" (
  echo Electron is missing. Running npm.cmd install...
  call npm.cmd install --no-fund --no-audit
  if errorlevel 1 (
    echo Install failed.
    pause
    exit /b 1
  )
)

start "Manager Desktop Server" /D "%CD%" "%CD%\node_modules\electron\dist\electron.exe" .
endlocal
