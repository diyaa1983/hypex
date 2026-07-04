@echo off
chcp 65001 >nul
setlocal

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

cd /d "%~dp0.."
"%PHP%" "%~dp0zk_sync_agent.php" %*
set "ERR=%ERRORLEVEL%"

if %ERR% NEQ 0 (
    echo.
    echo [خطأ] فشلت المزامنة — رمز %ERR%
    pause
)

exit /b %ERR%
