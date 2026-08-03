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
