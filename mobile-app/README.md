# تطبيق الهاتف (Capacitor — iOS + Android)

غلاف أصلي لواجهة الموبايل على السيرفر:

`https://www.biodev.gppjo.com/m/login.php`

**ابدأ بـ iOS:** راجع [`README-IOS.md`](README-IOS.md) للخطوات التفصيلية على Mac.

---

## iPhone (iOS) — جاهز في المستودع

مجلد `ios/` مُنشأ. على **Mac**:

```bash
cd mobile-app
npm install
npx cap sync ios
cd ios/App && pod install && cd ../..
npx cap open ios
```

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
