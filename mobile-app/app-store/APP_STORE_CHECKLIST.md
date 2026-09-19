# قائمة نشر App Store — Capacitor `/m/`

## A) حساب Apple Developer (مرة واحدة)

1. سجّل في [Apple Developer Program](https://developer.apple.com/programs/) (~99$/سنة).
2. انتظر تفعيل العضوية.
3. افتح [Certificates, Identifiers & Profiles](https://developer.apple.com/account/resources/identifiers/list).
4. أنشئ **App ID** من نوع App:
   - Description: `Namma Mobile`
   - Bundle ID (Explicit): `com.gppjo.biodev.mobile`
5. فعّل القدرات المطلوبة فقط (لا حاجة لـ Push الآن ما لم تُضف لاحقاً).

## B) App Store Connect — إنشاء التطبيق

1. [App Store Connect → My Apps → +](https://appstoreconnect.apple.com/apps)
2. New App:
   - Platforms: iOS
   - Name: `النماء — هاتف`
   - Primary Language: Arabic
   - Bundle ID: اختر `com.gppjo.biodev.mobile`
   - SKU: `namma-mobile-ios`
3. انسخ محتوى [`listing-ar.md`](listing-ar.md) إلى حقول الوصف والكلمات والروابط.
4. Privacy Policy URL: `https://www.biodev.gppjo.com/m/privacy.php`
5. ارفع لقطات الشاشة (انظر [`screenshots/README.md`](screenshots/README.md)).
6. عبّئ **App Privacy**.

## C) البناء على Mac

شغّل من مجلد المشروع:

```bash
cd mobile-app
chmod +x scripts/ios-release.sh
./scripts/ios-release.sh
```

أو يدوياً كما في [`../README-IOS.md`](../README-IOS.md).

في Xcode:

1. افتح `ios/App/App.xcworkspace`
2. Signing & Capabilities → Team (حساب المطور)
3. Scheme: **Any iOS Device (arm64)** (ليس Simulator)
4. Product → **Archive**
5. Distribute App → **App Store Connect** → Upload

## D) TestFlight ثم المراجعة

1. انتظر معالجة البناء في App Store Connect (دقائق إلى ساعة).
2. TestFlight → أضف مختبرين داخليين → ثبّت واختبر الدخول والشاشات.
3. App Store → الإصدار 1.0 → اختر البناء → **Add for Review**.
4. أجب على Export Compliance (HTTPS فقط / Exempt).
5. Submit for Review.

## E) بعد الموافقة

- الحالة: Ready for Sale
- التطبيق يظهر على App Store للتنزيل
- تحديثات واجهة `/m/` على السيرفر تظهر دون إعادة رفع التطبيق (ما عدا تغييرات الغلاف/الأيقونة/الأذونات)
