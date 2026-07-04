@echo off
chcp 65001 >nul
setlocal

cd /d "%~dp0"

REM Prefer PowerShell agent (no PHP/XAMPP required)
if exist "%~dp0zk_sync_agent.ps1" (
    if /I "%~1"=="/quiet" (
        powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0zk_sync_agent.ps1" -Quiet
    ) else (
        powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0zk_sync_agent.ps1" %*
    )
    set "ERR=%ERRORLEVEL%"
    goto :done
)

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

"%PHP%" "%~dp0zk_sync_agent.php" %*
set "ERR=%ERRORLEVEL%"

:done
if %ERR% NEQ 0 (
    if /I not "%~1"=="/quiet" (
        echo.
        echo [خطأ] فشلت المزامنة — رمز %ERR%
        pause
    )
)

exit /b %ERR%
