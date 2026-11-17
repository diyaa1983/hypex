@echo off
REM انسخ إصلاحات جذر Apache إلى C:\xampp\htdocs على سيرفر 176.x
REM يمنع 404 عند فتح الشاشات بروابط بدون /hypex أو /n/...

set "SRC=%~dp0"
set "DST=C:\xampp\htdocs"

if not exist "%DST%" (
  echo [ERROR] Not found: %DST%
  echo Edit DST in this script if XAMPP is elsewhere.
  pause
  exit /b 1
)

copy /Y "%SRC%.htaccess" "%DST%\.htaccess"
copy /Y "%SRC%hypex-root-redirect.php" "%DST%\hypex-root-redirect.php"

echo.
echo [OK] Root redirects installed.
echo Test: http://SERVER/sales/orders  should 302 to /hypex/sales/orders
echo Then restart Node if you also updated Hypex app files:
echo   deploy\HypexNode-Start.cmd
echo.
pause
