#!/usr/bin/env bash
# حقن مفاتيح أذونات الموقع في ios/Runner/Info.plist بعد flutter create.
# يُشغَّل على Mac بعد إنشاء مجلد iOS. آمن للتشغيل المتكرر.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLIST="$ROOT/ios/Runner/Info.plist"

if [[ ! -f "$PLIST" ]]; then
  echo "لم يُعثر على $PLIST — شغّل flutter create أولاً." >&2
  exit 1
fi

add_string() {
  local key="$1"; local value="$2"
  if /usr/libexec/PlistBuddy -c "Print :$key" "$PLIST" >/dev/null 2>&1; then
    /usr/libexec/PlistBuddy -c "Set :$key $value" "$PLIST"
  else
    /usr/libexec/PlistBuddy -c "Add :$key string $value" "$PLIST"
  fi
}

add_string "NSLocationWhenInUseUsageDescription" "يُستخدم موقعك لتسجيل موقع الفواتير وتتبّع المندوب أثناء العمل."
add_string "NSLocationAlwaysAndWhenInUseUsageDescription" "يُستخدم موقعك لتسجيل موقع الفواتير وتتبّع المندوب أثناء العمل."

echo "تم ضبط أذونات الموقع في Info.plist"
