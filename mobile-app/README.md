# تطبيق الهاتف (Capacitor — iOS + Android)

غلاف أصلي لواجهة الموبايل على السيرفر:

`https://www.biodev.gppjo.com/m/login.php`

**نشر App Store:** راجع [`app-store/APP_STORE_CHECKLIST.md`](app-store/APP_STORE_CHECKLIST.md)  
**iOS بالتفصيل:** [`README-IOS.md`](README-IOS.md)  
**سياسة الخصوصية:** `https://www.biodev.gppjo.com/m/privacy.php`

---

## iPhone (iOS) — جاهز في المستودع

مجلد `ios/` مُنشأ. على **Mac**:

```bash
cd mobile-app
chmod +x scripts/ios-release.sh
./scripts/ios-release.sh
npx cap open ios
```

ثم Archive → Upload → TestFlight → Submit for Review.

---

## Android (APK)

```bash
cd mobile-app
npm install
npx cap sync android
npx cap open android
```

---

## الاستخدام

1. شاشة إعداد السيرفر (افتراضي: `https://www.biodev.gppjo.com`)
2. **اتصال** → `/m/login.php`
3. **تغيير عنوان السيرفر** من شاشة الدخول

---

## ملاحظات

- الواجهة من السيرفر (online).
- GPS: `@capacitor/geolocation` + `assets/js/geo.js`
- بعد تعديل `www/`: `npx cap sync ios` أو `android`
- Bundle ID: `com.gppjo.biodev.mobile`
