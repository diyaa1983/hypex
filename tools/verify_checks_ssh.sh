#!/usr/bin/env bash
# فحص رفع وتطبيق ترحيلات الشيكات عبر SSH
# الاستخدام:
#   cd /path/to/manager
#   bash tools/verify_checks_ssh.sh

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 1

echo "=== مسار المشروع ==="
echo "$ROOT"
echo

FILES=(
  "database/migrations/162_fin_checks_manage.sql"
  "database/migrations/172_fin_check_endorse.sql"
  "database/migrations/173_fin_check_action_undo.sql"
  "database/migrations/175_fin_check_endorse_supplier_ledger.sql"
  "includes/fin_checks_manage.php"
  "includes/crm_party_statement.php"
  "includes/acc_journal_party.php"
  "includes/acc_gl.php"
  "modules/finance/checks.php"
  "assets/css/fin-checks.css"
  "assets/js/fin-checks.js"
  "tools/verify_checks_schema.php"
)

MISSING=0
echo "=== فحص الملفات ==="
for f in "${FILES[@]}"; do
  if [[ -r "$ROOT/$f" ]]; then
    echo "[OK] $f"
  else
    echo "[MISSING] $f"
    MISSING=1
  fi
done
echo

PHP_BIN=""
for candidate in php php8.3 php8.2 php8.1 php8.0 php7.4; do
  if command -v "$candidate" >/dev/null 2>&1; then
    if "$candidate" -m 2>/dev/null | grep -qi pdo_mysql; then
      PHP_BIN="$candidate"
      break
    fi
  fi
done

if [[ -n "$PHP_BIN" && -f "$ROOT/tools/verify_checks_schema.php" ]]; then
  echo "=== فحص قاعدة البيانات (PHP: $PHP_BIN) ==="
  "$PHP_BIN" "$ROOT/tools/verify_checks_schema.php"
  EXIT=$?
  exit $((MISSING + EXIT))
fi

echo "=== PHP مع pdo_mysql غير متوفر — فحص mysql CLI ==="
if ! command -v mysql >/dev/null 2>&1; then
  echo "تعذر الفحص التلقائي لقاعدة البيانات."
  echo "ثبّت php-cli أو mysql-client، أو نفّذ الاستعلامات يدوياً (انظر التعليمات)."
  exit "$MISSING"
fi

# قراءة إعدادات DB من config/database.php (بسيط)
DB_HOST=$(php -r "echo (require '$ROOT/config/database.php')['host'];")
DB_NAME=$(php -r "echo (require '$ROOT/config/database.php')['name'];")
DB_USER=$(php -r "echo (require '$ROOT/config/database.php')['user'];")
DB_PASS=$(php -r "echo (require '$ROOT/config/database.php')['pass'];")

MYSQL=(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -s)

check_col() {
  local table="$1" col="$2"
  if "${MYSQL[@]}" -e \
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'" \
    | grep -qx 1; then
    echo "[OK] $table.$col"
  else
    echo "[FAIL] $table.$col"
    DB_FAIL=1
  fi
}

DB_FAIL=0
check_col fin_voucher_check lifecycle_status
check_col fin_voucher_check endorsed_party_type
check_col fin_voucher_check endorsed_party_id
check_col fin_voucher_check endorse_notes
check_col fin_voucher_check action_undo_at
check_col fin_voucher_check undone_action

LS_TYPE=$("${MYSQL[@]}" -e \
  "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fin_voucher_check' AND COLUMN_NAME='lifecycle_status'")
if echo "$LS_TYPE" | grep -qi endorsed; then
  echo "[OK] lifecycle_status contains endorsed"
else
  echo "[FAIL] lifecycle_status contains endorsed (got: $LS_TYPE)"
  DB_FAIL=1
fi

TXN_TYPE=$("${MYSQL[@]}" -e \
  "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_supplier_ledger' AND COLUMN_NAME='txn_type'")
if echo "$TXN_TYPE" | grep -qi check_endorse; then
  echo "[OK] crm_supplier_ledger.txn_type contains check_endorse"
else
  echo "[FAIL] crm_supplier_ledger.txn_type contains check_endorse (got: $TXN_TYPE)"
  DB_FAIL=1
fi

if [[ "$DB_FAIL" -eq 0 && "$MISSING" -eq 0 ]]; then
  echo
  echo "كل شيء جاهز."
  exit 0
fi

echo
echo "إذا فشلت قاعدة البيانات: افتح شاشة الشيكات مرة واحدة أو نفّذ migrations 172/173/175 يدوياً."
exit 1
