# دليل سريع: نشر Hypex عبر GitHub + Static IP
# (تفاصيل كاملة في رسالة المساعد — هذا ملخص للسيرفر)

# المتطلبات: Apache أو Nginx + PHP 8.1+ (pdo_mysql, mbstring, openssl, gd, zip) + MySQL 8
# المنافذ العامة: 80 (و 443 إن وُجد SSL)
# MySQL يبقى على 127.0.0.1 فقط — لا تفتح 3306 للإنترنت

# المرة الأولى على السيرفر:
#   git clone https://github.com/YOUR_USER/YOUR_REPO.git /var/www/hypex
#   cd /var/www/hypex
#   bash deploy/first-install.sh
#   # عدّل config/database.local.php و config/app.local.php
#   # استورد قاعدة البيانات الفارغة
#   # DocumentRoot = /var/www/hypex

# كل تحديث بعد التعديل محلياً:
#   (محلي)  git add -A && git commit -m "..." && git push origin main
#   (سيرفر) cd /var/www/hypex && bash deploy/update.sh
#
# واجهة Node (hypex-node) على السيرفر (مرة أولى):
#   1) ثبّت Node.js 18+ و npm  و  npm i -g pm2
#   2) أنشئ/عدّل hypex-node/.env  (من .env.example):
#        DB_* = نفس MySQL النظام
#        PHP_BASE_URL = http://STATIC_IP  (أو مسار PHP العام)
#        SESSION_SECRET = سلسلة عشوائية قوية
#        PORT=3000
#   3) افتح Firewall للمنفذ 3000 (أو اضبط reverse proxy على Apache/Nginx)
#   4) bash deploy/update.sh   ← يثبت npm ويعيد تشغيل pm2
#   5) افتح: http://STATIC_IP:3000/
#
# ملاحظة: .env و node_modules لا يُرفعان إلى GitHub — يُبنيان على السيرفر فقط.
#
# آخر تحقق من الربط مع GitHub: 2026-08-03 (push test OK)
