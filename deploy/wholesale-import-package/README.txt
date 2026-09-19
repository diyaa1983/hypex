انسخ محتويات هذه الحزمة إلى جذر مشروع Hypex على السيرفر (نفس المسارات):

  hypex-node\cli\import_wholesale_items.js
  hypex-node\cli\inv_items_xlsx_to_json.php
  includes\xlsx_simple_reader.php
  deploy\import-wholesale-items.cmd

ثم على السيرفر (PowerShell):

  Copy-Item -LiteralPath "C:\xampp\htdocs\Hypex\uploads\Retail & Whol Price (2).xlsx" -Destination "C:\xampp\htdocs\Hypex\uploads\Retail_Whol_Price_2.xlsx" -Force
  cd C:\xampp\htdocs\Hypex\deploy
  .\import-wholesale-items.cmd

تحقق سريع:
  Test-Path C:\xampp\htdocs\Hypex\hypex-node\cli\import_wholesale_items.js
يجب أن يظهر True
