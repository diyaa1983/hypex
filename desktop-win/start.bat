@echo off
setlocal
cd /d "%~dp0"

if not exist "node_modules\electron\dist\electron.exe" (
  echo Electron is missing. Running npm.cmd install...
  call npm.cmd install --no-fund --no-audit
  if errorlevel 1 (
    echo Install failed.
    pause
    exit /b 1
  )
)

if not exist "package.json" (
  echo Missing package.json in %CD%
  pause
  exit /b 1
)

if not exist "main.js" (
  echo Missing main.js in %CD%
  pause
  exit /b 1
)

REM Use "." so the path has no trailing backslash quote bug.
start "Manager Desktop" /D "%CD%" "%CD%\node_modules\electron\dist\electron.exe" .
endlocal
