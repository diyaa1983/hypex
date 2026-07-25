#!/usr/bin/env bash
# إعداد مشروع Flutter على Mac/Linux (Android)، أو على Mac لبناء iOS.
# المتطلبات: تثبيت Flutter SDK — https://docs.flutter.dev/get-started/install
# التشغيل:  bash tool/setup.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v flutter >/dev/null 2>&1; then
  echo "لم يُعثر على flutter في PATH. ثبّت Flutter SDK أولاً." >&2
  exit 1
fi

echo "==> إنشاء مجلدات المنصّات (لن يستبدل AndroidManifest.xml الموجود)"
flutter create . --org com.gppjo.biodev --project-name namma_mobile --platforms=android,ios

echo "==> جلب الحزم"
flutter pub get

# حقن أذونات الموقع في iOS Info.plist (على Mac)
if [[ "$(uname)" == "Darwin" ]]; then
  echo "==> ضبط أذونات الموقع في iOS Info.plist"
  bash tool/patch_ios_permissions.sh || true
fi

echo ""
echo "تم الإعداد. أوامر مفيدة:"
echo "  flutter run"
echo "  flutter build apk --release        # Android"
echo "  flutter build ipa                  # iOS (يتطلب Xcode واشتراك Apple للرفع)"
