# TestFlight ثم Submit for Review

بعد رفع الـ Archive من Xcode إلى App Store Connect:

## 1) انتظار المعالجة

- App Store Connect → نشاطك → البناء يظهر خلال دقائق إلى ~1 ساعة.
- إن فشل المعالجة، راجع رسالة البريد من Apple.

## 2) TestFlight

1. TestFlight → iOS Builds → اختر البناء.
2. أجب على Export Compliance إن طُلب (التطبيق يستخدم HTTPS فقط — Exempt / `ITSAppUsesNonExemptEncryption=false`).
3. Internal Testing → أضف نفسك ومختبري الشركة.
4. ثبّت من تطبيق TestFlight على iPhone واختبر:
   - إعداد السيرفر / اتصال
   - تسجيل الدخول
   - الصفحة الرئيسية
   - فاتورة / قبض / GPS (إن متاح)

## 3) إعداد صفحة المتجر

راجع [`listing-ar.md`](../app-store/listing-ar.md):

- الوصف والكلمات المفتاحية
- Privacy Policy: `https://www.biodev.gppjo.com/m/privacy.php`
- لقطات الشاشة من [`screenshots/`](../app-store/screenshots/)
- حساب تجريبي في Review Notes

## 4) Submit for Review

1. App Store → iOS App Version (1.0)
2. اختر البناء من TestFlight
3. احفظ → **Add for Review** → **Submit to App Review**
4. المدة المعتادة للمراجعة: يوم إلى عدة أيام

## 5) بعد الموافقة

- الحالة: Ready for Sale (أو اختر تاريخ إطلاق يدوي)
- رابط المتجر يظهر في App Store Connect → App Information
- حدّث Build number في Xcode عند كل رفع لاحق (`CURRENT_PROJECT_VERSION`)

## استكشاف أخطاء شائعة

| المشكلة | الحل |
|---------|------|
| Missing Compliance | أجب Exempt encryption في TestFlight |
| Invalid Icon | تأكد أن 1024 بدون ألفا (الملف الحالي RGB) |
| Login failed للمراجع | ضع حساب مجموعة «هاتف» صالح في Review Notes |
| Guideline 4.2 (Minimum Functionality) | وضّح أن التطبيق غلاف لنظام أعمال مؤسسي مع حساب تجريبي |
| Privacy Policy URL | تأكد أن `/m/privacy.php` يعمل على HTTPS العام |
