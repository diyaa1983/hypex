#!/usr/bin/env bash
# إعداد مشروع iOS للبناء — شغّل على Mac فقط.
# الاشتراك المدفوع غير مطلوب للتجربة على Simulator/جهازك.
# الاشتراك مطلوب فقط لاحقاً عند Archive → App Store Connect.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> npm install"
npm install

echo "==> cap sync ios"
npx cap sync ios

echo "==> pod install"
cd ios/App
pod install
cd "$ROOT"

echo ""
echo "جاهز للبناء التجريبي (بدون اشتراك مدفوع):"
echo "  1) npx cap open ios"
echo "  2) Signing → Team (Apple ID مجاني يكفي للتجربة)"
echo "  3) Product → Run"
echo ""
echo "لاحقاً بعد الاشتراك في Apple Developer Program:"
echo "  Product → Archive → Distribute → App Store Connect"
echo ""
echo "راجع: BUILD_NOW.md و app-store/APP_STORE_CHECKLIST.md"
echo "Privacy URL: https://www.biodev.gppjo.com/m/privacy.php"
