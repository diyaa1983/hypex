#!/usr/bin/env bash
# =============================================================================
# التثبيت الأول على سيرفر Linux (Static IP)
# نفّذ مرة واحدة بعد clone من GitHub.
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> مجلد التطبيق: $ROOT"

# 1) ملفات الإعداد المحلية (لا تُرفع إلى Git)
if [[ ! -f config/database.local.php ]]; then
  cp config/database.local.example.php config/database.local.php
  echo "أُنشئ config/database.local.php — عدّل بيانات MySQL."
fi
if [[ ! -f config/app.local.php ]]; then
  cp config/app.local.example.php config/app.local.php
  echo "أُنشئ config/app.local.php — راجع APP_URL_BASE."
fi

# 2) مجلدات الكتابة
mkdir -p logs uploads uploads/logos data/zk_cache
chmod -R u+rwX,g+rwX logs uploads data 2>/dev/null || true

# 3) تحقق PHP
if command -v php >/dev/null 2>&1; then
  php -v | head -n 1
  php -m | grep -E 'pdo_mysql|mbstring|openssl|gd|curl|zip' || true
else
  echo "تحذير: php غير موجود في PATH."
fi

echo ""
echo "الخطوات التالية يدوياً:"
echo "  1) عدّل config/database.local.php (host/name/user/pass)"
echo "  2) أنشئ قاعدة MySQL ثم استورد النسخة الفارغة (mysqldump من جهازك)"
echo "  3) وجّه DocumentRoot Apache/Nginx إلى: $ROOT"
echo "  4) افتح Firewall للمنفذين 80 و 443 (و 3000 إن شغّلت Node بدون proxy)"
echo "  5) ثبّت Node 18+ و pm2 إن أردت واجهة hypex-node"
echo "  6) انسخ hypex-node/.env.example → hypex-node/.env وعدّل DB/PHP_BASE_URL/SESSION_SECRET"
echo "  7) من الجهاز المحلي: git push  →  على السيرفر: bash deploy/update.sh"
echo ""
echo "بعد الإعداد:"
echo "  PHP:  http://YOUR_STATIC_IP/"
echo "  Node: http://YOUR_STATIC_IP:3000/"
