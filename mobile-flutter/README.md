# Hypex — تطبيق موبايل أصلي (Flutter)

تطبيق Flutter مستقل (ليس WebView) يتصل بسيرفر النظام الحالي عبر واجهات JSON تحت `/api/`،
ويستخدم **جلسة الكوكيز** نفسها التي تستخدمها واجهة `/m/`.

**وضع Offline:** من بلاطة «تحديث البيانات» يُحمَّل الكتالوج محلياً، ثم يعمل التطبيق بدون إنترنت، وعند عودة الاتصال تُرحَّل العمليات المعلّقة تلقائياً.

يدعم iOS و Android، وواجهة عربية RTL بألوان النظام (`#0572CE`).

## الشاشات

- إعداد السيرفر + تسجيل الدخول (مع تذكّر الدخول).
- الرئيسية: مربّعات حسب صلاحيات المستخدم (نفس مربّعات `/m/`).
- فواتير المبيعات: قائمة + عرض + إنشاء + ترحيل (مع التقاط GPS عند الترحيل).
- سندات القبض: قائمة + إنشاء (نقدي/شيك).
- مرتجعات المبيعات: قائمة + إنشاء (اختيار العميل ثم الفاتورة ثم الكميات).
- كشف الحساب (عميل/مورد) خلال فترة.
- عهدة المندوب: تحميل/إرجاع + قائمة العهدات + رصيد العهدة.
- GPS: مواقع الفواتير + مواقع المستخدمين + إرسال موقعي الآن.

## المتطلبات

1. **Flutter SDK** (نسخة 3.3 أو أحدث): https://docs.flutter.dev/get-started/install
   - Android: Android Studio + Android SDK.
   - iOS: جهاز Mac + Xcode (والرفع إلى App Store يتطلب اشتراك Apple Developer).
2. سيرفر النظام يعمل على العنوان المعتمد (مثل `http://176.29.176.192/hypex`).

## الإعداد لأول مرة

بعد تثبيت Flutter SDK وإضافته إلى `PATH`:

**Windows (Android):**

```powershell
cd mobile-flutter
powershell -ExecutionPolicy Bypass -File tool\setup.ps1
```

**Mac/Linux:**

```bash
cd mobile-flutter
bash tool/setup.sh
```

يقوم السكربت بـ: توليد مجلدات المنصّات عبر `flutter create .` (لا يستبدل `AndroidManifest.xml` المُعدّ مسبقاً)، ثم `flutter pub get`، وعلى Mac يحقن أذونات الموقع في `Info.plist`.

## التشغيل والبناء

```bash
flutter run                     # تشغيل على جهاز/محاكي متصل
flutter build apk --release     # ملف APK (Android) — يظهر في build/app/outputs/flutter-apk/
flutter build appbundle         # AAB لمتجر Google Play
flutter build ipa               # iOS (على Mac؛ الرفع للمتجر يتطلب اشتراك Apple)
```

عند أول تشغيل: أدخل عنوان السيرفر (افتراضياً `http://176.29.176.192/hypex`) ثم سجّل الدخول
بحساب ضمن مجموعة **هاتف (MOBILE)** في النظام.

## الأذونات

- **Android**: مضبوطة في `android/app/src/main/AndroidManifest.xml` (إنترنت + موقع).
- **iOS**: مفاتيح `NSLocationWhenInUseUsageDescription` تُضاف تلقائياً عبر `tool/patch_ios_permissions.sh`
  على Mac، أو أضفها يدوياً في `ios/Runner/Info.plist`.

## معرّف التطبيق والأيقونة

- **applicationId / Bundle ID** الافتراضي بعد الإعداد: `com.gppjo.biodev.nammaMobile`.
  لتغييره إلى `com.gppjo.biodev.mobile`: عدّل `android/app/build.gradle` (Android)
  و`ios/Runner.xcodeproj` عبر Xcode (iOS).
- **الأيقونة**: ضع الشعار في `assets/app_icon.png` ثم استخدم حزمة `flutter_launcher_icons`
  أو استبدل ملفات `android/app/src/main/res/mipmap-*` و`ios/Runner/Assets.xcassets`.

## واجهات السيرفر المستخدمة

أُضيفت واجهات JSON خفيفة تعتمد الجلسة الحالية (بدون نظام توكن):

- `api/mobile_session.php` — دخول/حالة الجلسة/خروج + رمز CSRF.
- `api/mobile_home.php` — مربّعات الرئيسية حسب الصلاحية + بيانات الشركة.
- `api/mobile_parties.php` — قائمة العملاء/الموردين (للاختيار).
- `api/mobile_invoice_meta.php` — المستودعات والافتراضيات لإنشاء الفاتورة.

وبقية الشاشات تستخدم الواجهات الموجودة أصلاً (`api/sales_invoices_list.php`،
`api/mobile_party_statement.php`، `api/mobile_rep_*`، `api/*_gps_*` ...).

## ملاحظات

- التطبيق أونلاين فقط (لا وضع Offline).
- الحفظ/الترحيل يعتمد نفس منطق `/m/` وصلاحياته تماماً — لا تغيير على قاعدة البيانات.
- إذا انتهت الجلسة (401) يعود التطبيق تلقائياً لشاشة الدخول.
