@echo off
chcp 65001 >nul
setlocal

echo ========================================
echo   تثبيت Access Database Engine
echo ========================================
echo.
echo 1) سيفتح رابط التحميل الرسمي من Microsoft
echo 2) اختر: AccessDatabaseEngine.exe (64-bit) في الغالب
echo 3) اضغط Install
echo.
echo اذا ظهر تعارض مع Office 32-bit استخدم:
echo    AccessDatabaseEngine.exe /quiet
echo    من CMD كمسؤول (بعد تنزيل الملف)
echo.
pause

start "" "https://www.microsoft.com/en-us/download/details.aspx?id=54920"

echo.
echo بعد التثبيت شغّل: zk_sync_test.bat للتحقق
pause
