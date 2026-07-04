@echo off
chcp 65001 >nul
setlocal

cd /d "%~dp0"

echo ========================================
echo   فحص إعداد مزامنة ZKT
echo ========================================
echo.

if not exist "%~dp0zk_sync.local.php" (
    echo [X] zk_sync.local.php غير موجود
    echo     انسخ zk_sync.local.example.php الى zk_sync.local.php
    goto :fail
) else (
    echo [OK] zk_sync.local.php
)

if not exist "C:\zktdata\att2000.mdb" (
    echo [!] C:\zktdata\att2000.mdb غير موجود — تحقق من mdb_path في الإعداد
) else (
    echo [OK] att2000.mdb
)

if not exist "%~dp0zk_sync_agent.ps1" (
    echo [X] zk_sync_agent.ps1 غير موجود
    goto :fail
) else (
    echo [OK] zk_sync_agent.ps1
)

echo.
echo فحص Access Engine...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "try { $c = New-Object -ComObject ADODB.Connection; 'ACE/Jet COM available' } catch { 'ACE/Jet NOT installed — شغّل zk_sync_install_ace.bat' }"

echo.
echo تجربة مزامنة...
call "%~dp0zk_sync_run.bat"
set ERR=%ERRORLEVEL%

if %ERR% NEQ 0 goto :fail

echo.
echo ========================================
echo   كل شيء جاهز — يمكنك جدولة zk_sync_schedule_install.bat
echo ========================================
pause
exit /b 0

:fail
echo.
echo ========================================
echo   يوجد مشكلة — راجع zk_sync.log
echo ========================================
pause
exit /b 1
