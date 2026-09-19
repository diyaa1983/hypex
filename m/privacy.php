<?php
declare(strict_types=1);

/**
 * سياسة الخصوصية لتطبيق الهاتف — مطلوبة لـ App Store Connect (رابط عام HTTPS).
 * لا تتطلب تسجيل دخول.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/company_settings.php');

$companyName = 'النماء الحيوية للصناعات الزراعية والبيطرية';
try {
    $settings = company_settings();
    $name = trim((string) ($settings['company_name_ar'] ?? ''));
    if ($name !== '') {
        $companyName = $name;
    }
} catch (Throwable $e) {
    // ignore
}

$updated = '2026-07-24';
$privacyUrl = app_url('m/privacy.php');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="index,follow">
    <title>سياسة الخصوصية — تطبيق الهاتف</title>
    <style>
        :root { --bg:#f4f6f8; --card:#fff; --text:#1a2332; --muted:#5b6b7c; --accent:#0572ce; }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            background: var(--bg); color: var(--text); line-height: 1.75;
        }
        .wrap { max-width: 42rem; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }
        .card {
            background: var(--card); border-radius: 12px; padding: 1.5rem 1.35rem;
            box-shadow: 0 1px 3px rgba(16,24,40,.08);
        }
        h1 { margin: 0 0 .35rem; font-size: 1.35rem; color: var(--accent); }
        .meta { margin: 0 0 1.25rem; color: var(--muted); font-size: .9rem; }
        h2 { margin: 1.35rem 0 .5rem; font-size: 1.05rem; }
        p, li { margin: 0 0 .65rem; }
        ul { padding-inline-start: 1.25rem; }
        a { color: var(--accent); }
        .foot { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e6ebf0; color: var(--muted); font-size: .88rem; }
    </style>
</head>
<body>
<main class="wrap">
    <article class="card">
        <h1>سياسة الخصوصية</h1>
        <p class="meta">تطبيق الهاتف — <?= esc($companyName) ?><br>آخر تحديث: <?= esc($updated) ?></p>

        <p>
            توضّح هذه السياسة كيف يتعامل تطبيق الهاتف («التطبيق») مع بياناتك عند استخدامه للوصول إلى نظام
            <?= esc($companyName) ?> عبر السيرفر الآمن.
        </p>

        <h2>1. ما هو التطبيق؟</h2>
        <p>
            التطبيق غلاف أصلي (iOS/Android) يعرض واجهة الموبايل الموجودة على السيرفر
            (<code dir="ltr">/m/</code>). لا يخزّن التطبيق بيانات الأعمال محلياً بشكل دائم؛
            العمل يتم عبر اتصال HTTPS بالسيرفر.
        </p>

        <h2>2. البيانات التي قد تُجمع</h2>
        <ul>
            <li><strong>بيانات الدخول:</strong> اسم المستخدم وكلمة المرور لإثبات الهوية على السيرفر.</li>
            <li><strong>بيانات العمل:</strong> الفواتير، سندات القبض، العهدة، وغيرها حسب صلاحيات حسابك — تُحفظ على السيرفر.</li>
            <li><strong>الموقع الجغرافي (GPS):</strong> عند استخدام ميزات تتطلب الموقع (مثل تسجيل موقع المندوب مع المستندات)، وبموافقتك عبر إذن النظام.</li>
            <li><strong>عنوان السيرفر:</strong> قد يُحفظ محلياً على الجهاز لتسهيل إعادة الاتصال.</li>
            <li><strong>سجلات تقنية:</strong> مثل عنوان IP ووقت الطلب لأغراض الأمان والاستقرار على السيرفر.</li>
        </ul>

        <h2>3. كيف نستخدم البيانات؟</h2>
        <ul>
            <li>تشغيل النظام المحاسبي/التشغيلي للمستخدمين المخوّلين فقط.</li>
            <li>تسجيل مواقع العمل عند تفعيل الميزات التي تتطلب ذلك.</li>
            <li>حماية الحساب ومنع الاستخدام غير المصرّح به.</li>
        </ul>
        <p>لا نبيع بياناتك الشخصية لأطراف ثالثة لأغراض تسويقية.</p>

        <h2>4. المشاركة مع أطراف أخرى</h2>
        <p>
            قد تُعالج البيانات عبر مزوّد الاستضافة أو خدمات البنية التحتية اللازمة لتشغيل السيرفر،
            ضمن حدود تشغيل الخدمة والأمان. لا تُشارك مع أطراف غير مرتبطة بالتشغيل إلا إذا فرض القانون ذلك.
        </p>

        <h2>5. الأمان</h2>
        <p>
            يتم الاتصال عبر HTTPS. الوصول للتطبيق يتطلب حساباً ضمن مجموعة «هاتف» في النظام.
            أنت مسؤول عن الحفاظ على سرية كلمة المرور.
        </p>

        <h2>6. الاحتفاظ بالبيانات</h2>
        <p>
            تُحفظ بيانات الأعمال على السيرفر وفق سياسات الشركة والنسخ الاحتياطي.
            يمكنك طلب حذف أو تعديل بيانات حسابك عبر إدارة النظام داخل الشركة.
        </p>

        <h2>7. أذونات الجهاز</h2>
        <ul>
            <li><strong>الموقع:</strong> لتسجيل موقع المندوب مع المستندات عند الحاجة.</li>
            <li><strong>الشبكة:</strong> للاتصال بالسيرفر.</li>
        </ul>

        <h2>8. خصوصية الأطفال</h2>
        <p>التطبيق مخصّص للاستخدام المهني داخل الشركة وليس موجّهاً للأطفال دون 13 عاماً.</p>

        <h2>9. التعديلات</h2>
        <p>قد نحدّث هذه السياسة عند تغيّر الميزات أو المتطلبات القانونية. يُعرض تاريخ آخر تحديث أعلاه.</p>

        <h2>10. التواصل</h2>
        <p>للاستفسارات حول الخصوصية، تواصل مع إدارة <?= esc($companyName) ?> داخل الشركة.</p>

        <p class="foot" dir="ltr">
            Privacy Policy URL: <a href="<?= esc($privacyUrl) ?>"><?= esc($privacyUrl) ?></a>
        </p>
    </article>
</main>
</body>
</html>
