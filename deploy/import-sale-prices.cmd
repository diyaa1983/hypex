@echo off
chcp 65001 >nul
setlocal EnableExtensions
REM استيراد سعر البيع من Excel (Unit Price ^> default_sale)
REM الملف الافتراضي: uploads\K.A Promo (2).xlsx

set "ROOT=%~dp0.."
if exist "C:\xampp\htdocs\hypex\hypex-node\cli\import_sale_prices.js" set "ROOT=C:\xampp\htdocs\hypex"
if exist "C:\xampp\htdocs\Hypex\hypex-node\cli\import_sale_prices.js" set "ROOT=C:\xampp\htdocs\Hypex"

set "NODE_DIR=%ROOT%\hypex-node"
set "CLI=%NODE_DIR%\cli\import_sale_prices.js"
set "XLSX=%ROOT%\uploads\K.A Promo (2).xlsx"

if not "%~1"=="" set "XLSX=%~1"

if not exist "%CLI%" (
  echo [ERROR] script missing:
  echo   %CLI%
  echo Run: git pull origin main
  exit /b 1
)

if not exist "%XLSX%" (
  echo [ERROR] Excel file missing:
  echo   %XLSX%
  echo Usage: import-sale-prices.cmd "C:\full\path\file.xlsx"
  exit /b 1
)

echo ROOT: %ROOT%
echo FILE: %XLSX%
echo.
pushd "%NODE_DIR%" || exit /b 1
node "%CLI%" "%XLSX%"
set "ERR=%ERRORLEVEL%"
popd
echo.
if not "%ERR%"=="0" (
  echo Import failed. Code: %ERR%
  exit /b %ERR%
)
echo Done.
exit /b 0
