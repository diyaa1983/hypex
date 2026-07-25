# البناء الآن — الاشتراك لاحقاً

يمكنك **بناء وتشغيل** التطبيق قبل الاشتراك في Apple Developer Program.
الاشتراك (~99$/سنة) تحتاجه فقط عند **الرفع إلى App Store**.

## على هذا الجهاز (Windows) — تم تجهيزه

- `npm install` ✓
- `npx cap sync ios` ✓
- مشروع Xcode جاهز في `ios/`
- سياسة الخصوصية والأيقونة ونصوص المتجر جاهزة في `app-store/`

> لا يمكن إنتاج ملف iOS النهائي (IPA/Archive) من Windows — يلزم Mac + Xcode.

---

## على Mac — بناء تجريبي بدون اشتراك مدفوع

يكفي **Apple ID مجاني** لتشغيل التطبيق على جهازك أو Simulator:

```bash
cd mobile-app
chmod +x scripts/ios-release.sh
./scripts/ios-release.sh
npx cap open ios
```

في Xcode:

1. Signing & Capabilities → Team = Apple ID الخاص بك (مجاني)
2. اختر Simulator أو iPhone موصول بكابل
3. Product → **Run** (▶)

ملاحظات مع الحساب المجاني:
- يعمل للتجربة على جهازك
- التوقيع ينتهي تقريباً كل 7 أيام ويحتاج إعادة تثبيت
- **لا يظهر زر الرفع إلى App Store** حتى تشترك في البرنامج المدفوع

---

## لاحقاً — بعد الاشتراك

1. Apple Developer Program
2. نفس المشروع + نفس Bundle ID: `com.gppjo.biodev.mobile`
3. Product → **Archive** → Upload → TestFlight → Submit

راجع: `app-store/APP_STORE_CHECKLIST.md` و `app-store/TESTFLIGHT_SUBMIT.md`

---

## بديل فوري على Windows: بناء Android (اختياري)

إن أردت ملف APK للتجربة الآن من Windows (ليس App Store):

```bash
cd mobile-app
npm install
npx cap sync android
npx cap open android
```

ثم Build APK من Android Studio.
