@echo off
REM تشغيل واجهة Hypex Node — مطلوب مع Apache حتى يعمل http://localhost/hypex
cd /d "%~dp0..\hypex-node"
echo.
echo  Hypex Node starting...
echo  Public URL:  http://localhost/hypex
echo  Internal:    http://127.0.0.1:3000/hypex
echo.
echo  للتطوير (تحديث تلقائي بعد الحفظ):
echo    deploy\start-hypex-node-watch.cmd
echo    أو:  npm run dev
echo.
node src/server.js
