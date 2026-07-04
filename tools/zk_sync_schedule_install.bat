@echo off
chcp 65001 >nul
setlocal

cd /d "%~dp0"
set "TASK_NAME=Manager ZKT Attendance Sync"
set "TOOLS_DIR=%~dp0"
set "RUN_CMD=cmd /c cd /d \"%TOOLS_DIR%\" ^&^& \"%TOOLS_DIR%zk_sync_run.bat\" /quiet"

echo ========================================
echo   جدولة المزامنة كل 10 دقائق
echo ========================================
echo.
echo المهمة: %TASK_NAME%
echo المسار: %TOOLS_DIR%
echo.

net session >nul 2>&1
if errorlevel 1 (
    echo [!] شغّل هذا الملف: Run as administrator
    echo     كليك يمين على zk_sync_schedule_install.bat
    pause
    exit /b 1
)

schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>&1

schtasks /Create /TN "%TASK_NAME%" /TR "%RUN_CMD%" /SC MINUTE /MO 10 /RU "%USERNAME%" /RL HIGHEST /F
if errorlevel 1 (
    echo [X] فشل إنشاء المهمة المجدولة
    pause
    exit /b 1
)

echo [OK] تمت الجدولة بنجاح — كل 10 دقائق
echo.
echo للتحقق: Task Scheduler ^> %TASK_NAME%
echo السجل:   %TOOLS_DIR%zk_sync.log
echo.
echo لتشغيل فوري الآن:
schtasks /Run /TN "%TASK_NAME%"
echo.
pause
exit /b 0
