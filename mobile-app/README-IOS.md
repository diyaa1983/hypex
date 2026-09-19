# تطبيق الهاتف — iOS (Capacitor) → App Store

غلاف iPhone/iPad لواجهة الموبايل على السيرفر:

`https://www.biodev.gppjo.com/m/login.php`

**سياسة الخصوصية (مطلوبة للمتجر):**  
`https://www.biodev.gppjo.com/m/privacy.php`

**دليل النشر الكامل:** [`app-store/APP_STORE_CHECKLIST.md`](app-store/APP_STORE_CHECKLIST.md)  
**نصوص المتجر:** [`app-store/listing-ar.md`](app-store/listing-ar.md)

---

## المتطلبات (Mac فقط للبناء والتشغيل)

- **Mac** مع macOS 13+
- **Xcode** 15+ (من App Store)
- **CocoaPods**: `sudo gem install cocoapods`
- **Node.js** 20+
- **Apple Developer Program** (~99$/سنة) للنشر على App Store / TestFlight

> مجلد `ios/` جاهز في المستودع. البناء النهائي يتم على Mac.

---

## الإعداد على Mac (أول مرة أو تحديث)

### سكربت سريع

```bash
cd mobile-app
chmod +x scripts/ios-release.sh
./scripts/ios-release.sh
npx cap open ios
```

### يدوياً

```bash
cd mobile-app
npm install
npx cap sync ios
cd ios/App
pod install
cd ../..
npx cap open ios
```

في **Xcode**:

1. افتح `ios/App/App.xcworkspace` (وليس `.xcodeproj`)
2. **Signing & Capabilities** → اختر Team (Apple Developer)
3. Bundle ID: `com.gppjo.biodev.mobile` (مثبّت في المشروع)
4. Version `1.0` / Build `1` (أو زِد Build عند كل رفع جديد)
5. وصّل iPhone أو اختر Simulator للتجربة
6. **Product → Run** (▶)

---

## النشر على App Store

1. أكمل إعداد التطبيق في App Store Connect (راجع `app-store/APP_STORE_CHECKLIST.md`).
2. في Xcode اختر **Any iOS Device (arm64)**.
3. **Product → Archive**.
4. **Distribute App → App Store Connect → Upload**.
5. (اختياري سطر أوامر بعد الأرشفة):

```bash
xcodebuild -exportArchive \
  -archivePath ~/Library/Developer/Xcode/Archives/.../App.xcarchive \
  -exportOptionsPlist app-store/ExportOptions.plist \
  -exportPath ./build/ios-export
```

6. في App Store Connect: TestFlight → اختبار → Submit for Review.

---

## تحديث بعد تعديل `www/`

```bash
cd mobile-app
npx cap sync ios
```

ثم أعد Build / Archive من Xcode.

---

## الاستخدام

1. أول فتح → شاشة إعداد السيرفر (الافتراضي: `https://www.biodev.gppjo.com`)
2. **فحص الاتصال** → **اتصال** → `/m/login.php`
3. من الدخول: **تغيير عنوان السيرفر** عند الحاجة

---

## Android (لاحقاً)

```bash
npx cap sync android
npx cap open android
```

راجع أيضاً `README.md` في نفس المجلد.
