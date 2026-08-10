@echo off
chcp 65001 >nul
setlocal
REM استيراد سعر البيع من Excel (عمود Unit Price → default_sale)
REM المسار الافتراضي: uploads\K.A Promo (2).xlsx

set ROOT=%~dp0..
if exist "C:\xampp\htdocs\hypex\hypex-node\cli\import_sale_prices.js" set ROOT=C:\xampp\htdocs\hypex
if exist "C:\xampp\htdocs\Hypex\hypex-node\cli\import_sale_prices.js" set ROOT=C:\xampp\htdocs\Hypex

set NODE_DIR=%ROOT%\hypex-node
set CLI=%NODE_DIR%\cli\import_sale_prices.js
set XLSX=%ROOT%\uploads\K.A Promo (2).xlsx

if not "%~1"=="" set XLSX=%~1

if not exist "%CLI%" (
  echo [خطأ] السكربت غير موجود: %CLI%
  echo انسخ hypex-node\cli\import_sale_prices.js إلى السيرفر.
  exit /b 1
)

if not exist "%XLSX%" (
  echo [خطأ] ملف Excel غير موجود:
  echo   %XLSX%
  echo مرّر المسار: import-sale-prices.cmd "C:\path\file.xlsx"
  exit /b 1
)

echo المجلد: %NODE_DIR%
echo الملف:  %XLSX%
echo.
cd /d "%NODE_DIR%"

REM تحديث سعر البيع فقط للمواد الموجودة (الافتراضي)
REM لإنشاء مواد ناقصة أضف --create في السطر التالي
node "%CLI%" "%XLSX%"
set ERR=%ERRORLEVEL%
echo.
if %ERR% neq 0 (
  echo فشل الاستيراد. كود: %ERR%
  exit /b %ERR%
)
echo تم.
exit /b 0
