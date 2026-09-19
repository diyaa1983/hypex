@echo off
chcp 65001 >nul
setlocal EnableExtensions
title Hypex — فتح جدار الحماية (HTTP 80)

net session >nul 2>&1
if errorlevel 1 (
  echo.
  echo [ERROR] هذا السكربت يحتاج صلاحيات المسؤول (Run as Administrator).
  echo انقر يميناً → Run as administrator
  echo.
  pause
  exit /b 1
)

echo.
echo ============================================
echo   فتح منفذ 80 (Apache) من أي شبكة
echo ============================================
echo.

REM قواعد واضحة لـ Hypex / Apache
netsh advfirewall firewall delete rule name="Hypex Apache HTTP 80" >nul 2>&1
netsh advfirewall firewall add rule name="Hypex Apache HTTP 80" dir=in action=allow protocol=TCP localport=80 profile=any enable=yes
if errorlevel 1 (
  echo [ERROR] فشل إضافة قاعدة المنفذ 80
) else (
  echo [OK] Rule: Hypex Apache HTTP 80  (TCP 80 inbound / Any profile)
)

REM قاعدة اختيارية للمنفذ 3000 — مقفلة افتراضياً (لا تُفتح للإنترنت)
REM netsh advfirewall firewall add rule name="Hypex Node 3000 LOCAL" dir=in action=allow protocol=TCP localport=3000 profile=private enable=yes remoteip=localsubnet

echo.
echo [INFO] تأكد أيضاً من:
echo   1) Apache يعمل (XAMPP Control → Apache Start)
echo   2) hypex-node يعمل: deploy\HypexNode-Start.cmd
echo   3) Port Forward على الراوتر → IP هذا الجهاز المنفذ 80
echo   4) الاختبار من بيانات الموبايل: http://YOUR_PUBLIC_IP/hypex
echo.
echo لعرض التشخيص: deploy\check-external-access.cmd
echo.
pause
endlocal
