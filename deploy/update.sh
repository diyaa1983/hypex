#!/usr/bin/env bash
# =============================================================================
# تحديث النظام من GitHub على السيرفر
# الاستخدام (من مجلد المشروع على السيرفر):
#   bash deploy/update.sh
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

BRANCH="${DEPLOY_BRANCH:-main}"
REMOTE="${DEPLOY_REMOTE:-origin}"

echo "==> المسار: $ROOT"
echo "==> جلب التحديثات: $REMOTE/$BRANCH"

# حماية ملفات الإعدادات المحلية (لا تُرفع إلى Git)
if [[ ! -f config/database.local.php ]]; then
  echo "تحذير: config/database.local.php غير موجود — أنشئه قبل تشغيل النظام."
fi
if [[ ! -f config/app.local.php ]]; then
  echo "تحذير: config/app.local.php غير موجود — انسخ من app.local.example.php."
fi

# لا تلمّس الملفات المحلية أثناء السحب
git fetch "$REMOTE" "$BRANCH"
git merge --ff-only "$REMOTE/$BRANCH"

# صلاحيات المجلدات القابلة للكتابة
mkdir -p logs uploads uploads/logos data/zk_cache
chmod -R u+rwX,g+rwX logs uploads data 2>/dev/null || true

# إن وُجد Composer على السيرفر (اختياري — المشروع يضم vendor غالباً)
if command -v composer >/dev/null 2>&1 && [[ -f composer.json ]]; then
  composer install --no-dev --optimize-autoloader 2>/dev/null || true
fi

echo "==> تم التحديث بنجاح."
echo "    افتح النظام عبر: http://STATIC_IP/"
