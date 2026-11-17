@echo off
chcp 65001 >nul
REM تثبيت إعادة توجيه جذر Apache على سيرفر Hypex (يمنع 404/صفحة فارغة بدون /hypex)
set "SRC=%~dp0"
set "DST=C:\xampp\htdocs"
set "APP=C:\xampp\htdocs\Hypex"

if not exist "%DST%" (
  echo [ERROR] Not found: %DST%
  pause
  exit /b 1
)

copy /Y "%SRC%.htaccess" "%DST%\.htaccess"
copy /Y "%SRC%hypex-root-redirect.php" "%DST%\hypex-root-redirect.php"

REM انسخ أيضاً بركسي التحويل المحدّث إن وُجد في المشروع
if exist "%APP%\node-front.php" (
  echo [OK] node-front.php already in %APP%
) else if exist "%SRC%..\..\node-front.php" (
  copy /Y "%SRC%..\..\node-front.php" "%APP%\node-front.php"
)

echo.
echo [OK] Root redirects installed.
echo Test:
echo   http://SERVER/           → should go to /hypex/
echo   http://SERVER/sales/...  → should go to /hypex/sales/...
echo Then restart Node: deploy\HypexNode-Start.cmd
echo.
pause
