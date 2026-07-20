# تطبيق الهاتف — iOS (Capacitor)

غلاف iPhone/iPad لواجهة الموبايل على السيرفر:

`https://www.biodev.gppjo.com/m/login.php`

---

## المتطلبات (Mac فقط للبناء والتشغيل)

- **Mac** مع macOS 13+
- **Xcode** 15+ (من App Store)
- **CocoaPods**: `sudo gem install cocoapods`
- **Node.js** 20+
- **Apple ID** (مجاني للتجربة على جهازك)
- **Apple Developer Program** (~99$/سنة) للنشر على App Store / TestFlight

> مجلد `ios/` جاهز في المستودع. البناء النهائي يتم على Mac.

---

## الإعداد على Mac (أول مرة)

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
2. **Signing & Capabilities** → اختر Team (Apple ID)
3. Bundle ID: `com.gppjo.biodev.mobile`
4. وصّل iPhone أو اختر Simulator
5. **Product → Run** (▶)

---

## تحديث بعد تعديل `www/`

```bash
cd mobile-app
npx cap sync ios
```

ثم أعد Build من Xcode.

---

## الاستخدام

1. أول فتح → شاشة إعداد السيرفر (الافتراضي: `https://www.biodev.gppjo.com`)
2. **فحص الاتصال** → **اتصال** → `/m/login.php`
3. من الدخول: **تغيير عنوان السيرفر**

---

## النشر

- **TestFlight**: Archive → Distribute → App Store Connect
- **App Store**: نفس المسار بعد مراجعة Apple

---

## Android (لاحقاً)

```bash
npx cap sync android
npx cap open android
```

راجع أيضاً `README.md` في نفس المجلد.
