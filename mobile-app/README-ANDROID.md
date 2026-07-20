# تطبيق Android — طريقتان

## 1) PWA (الأسرع — بدون Android Studio)

1. حدّث السيرفر من GitHub
2. على **Android** افتح **Chrome**:
   `https://www.biodev.gppjo.com/m/login.php`
3. سيظهر زر **«تثبيت التطبيق»** أو من القائمة ⋮ → **تثبيت التطبيق**
4. افتح من أيقونة الشاشة الرئيسية

---

## 2) APK (تطبيق أصلي — Capacitor)

### المتطلبات على Windows

- Node.js 20+
- Android Studio + JDK 17

### البناء

```bash
cd mobile-app
npm install
npx cap sync android
npx cap open android
```

من Android Studio:

1. **Build → Build Bundle(s) / APK(s) → Build APK(s)**
2. ملف APK في: `android/app/build/outputs/apk/debug/`

### أول تشغيل

- العنوان الافتراضي: `https://www.biodev.gppjo.com`
- يفتح `/m/login.php` تلقائياً

### توزيع APK

- انسخ ملف APK للهاتف وثبّته (فعّل «مصادر غير معروفة» إن لزم)
- أو انشر على Google Play (يحتاج حساب مطوّر)
