@echo off
chcp 65001 >nul
setlocal

cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0zk_sync_agent.ps1" %*
set "ERR=%ERRORLEVEL%"

if %ERR% NEQ 0 (
    echo.
    echo [خطأ] فشلت المزامنة — رمز %ERR%
    pause
)

exit /b %ERR%
