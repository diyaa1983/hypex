@echo off
REM يستعيد عمليات PM2 المحفوظة بعد إقلاع Windows (تُستدعى من Task Scheduler)
setlocal
set "PATH=%ProgramFiles%\nodejs;%ProgramFiles(x86)%\nodejs;%APPDATA%\npm;%PATH%"

REM انتظر حتى يصبح Node/npm جاهزين
timeout /t 8 /nobreak >nul

where pm2 >nul 2>nul
if errorlevel 1 (
  where npm >nul 2>nul
  if not errorlevel 1 call npm install -g pm2 >nul 2>nul
)

where pm2 >nul 2>nul
if errorlevel 1 exit /b 1

pm2 resurrect
if errorlevel 1 (
  cd /d "%~dp0..\hypex-node"
  if exist ecosystem.config.cjs pm2 start ecosystem.config.cjs
  pm2 save
)
endlocal
