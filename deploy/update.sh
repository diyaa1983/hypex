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

# واجهة Node (hypex-node) — تثبيت الحزم وإعادة التشغيل عبر pm2 إن وُجد
if [[ -d hypex-node ]]; then
  echo "==> تحديث hypex-node"
  if [[ ! -f hypex-node/.env ]]; then
    if [[ -f hypex-node/.env.example ]]; then
      cp hypex-node/.env.example hypex-node/.env
      echo "تحذير: أُنشئ hypex-node/.env من المثال — عدّل DB و PHP_BASE_URL و SESSION_SECRET."
    else
      echo "تحذير: hypex-node/.env غير موجود."
    fi
  fi
  if command -v npm >/dev/null 2>&1; then
    (
      cd hypex-node
      if [[ -f package-lock.json ]]; then
        npm ci --omit=dev
      else
        npm install --omit=dev
      fi
    )
    if command -v pm2 >/dev/null 2>&1; then
      if pm2 describe hypex-node >/dev/null 2>&1; then
        pm2 restart hypex-node --update-env
      else
        pm2 start hypex-node/src/server.js --name hypex-node --cwd "$ROOT/hypex-node"
        pm2 save 2>/dev/null || true
      fi
      echo "    Node يعمل عبر pm2 (hypex-node)."
    else
      echo "تحذير: pm2 غير مثبّت — ثبّته (npm i -g pm2) أو شغّل: cd hypex-node && npm start"
    fi
  else
    echo "تحذير: npm/Node غير موجود على السيرفر — ثبّت Node 18+ ثم أعد: bash deploy/update.sh"
  fi
fi

echo "==> تم التحديث بنجاح."
echo "    PHP:  http://STATIC_IP/"
echo "    Node: http://STATIC_IP:3000/  (أو عبر reverse proxy إن ضبطت)"
