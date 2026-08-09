# Hypex — واجهة واحدة على Node.js

الرابط العام (XAMPP): **http://localhost/hypex**  
نفس قاعدة البيانات والشاشات — الواجهة Node خلف Apache.

## التشغيل اليومي

1. شغّل **Apache + MySQL** من XAMPP  
2. شغّل Node:

```bat
deploy\start-hypex-node.cmd
```

أو:

```bash
cd hypex-node
npm start
```

3. افتح المتصفح: **http://localhost/hypex**

بدون الخطوة 2 يظهر رسالة «واجهة Node غير متاحة».

## كيف يعمل

| الطبقة | الدور |
|--------|--------|
| `http://localhost/hypex` | Apache → `node-front.php` → Node |
| `hypex-node` (منفذ 3000) | التطبيق الفعلي |
| `APP_BASE_PATH=/hypex` | المسارات الداخلية تطابق الرابط القديم |
| PHP المتبقي | `index.php?r=...` للشاشات غير المحوّلة + ترحيل CLI |

## إعداد `.env`

انسخ من `.env.example` وعدّل MySQL إن لزم:

- `APP_BASE_PATH=/hypex`
- `PHP_BASE_URL=http://127.0.0.1/hypex`
- `PORT=3000`

## تطوير مباشر بدون Apache

افتح `http://127.0.0.1:3000/hypex` (مع نفس `APP_BASE_PATH`).

## الأقسام

مبيعات · مشتريات · عملاء · موردين · مندوبين · مستودعات · محاسبة · موظفين · نظام · هاتف
