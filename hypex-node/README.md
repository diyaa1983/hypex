# Hypex — واجهة واحدة على Node.js

الرابط: **http://localhost/hypex**

## مثل XAMPP — يعمل مع بدء النظام

Node ليس جزءاً من أباتشي XAMPP؛ لذلك يُشغَّل ك**خدمة مستقلة** تقلع تلقائياً.

### مرة واحدة (مستحسن)

كليك يمين على:

```text
deploy\pm2-install-service.cmd
```

→ **Run as administrator**

ماذا يفعل؟
1. يثبت PM2
2. يشغّل `hypex-node` في الخلفية **بدون مراقبة الملفات** (حمل منخفض)
3. يسجّل مهمة Windows **HypexNode** لتعمل بعد تسجيل الدخول / الإقلاع

لا حاجة لإبقاء نافذة CMD مفتوحة.

### أزرار يومية (مثل لوحة XAMPP)

| | الملف |
|--|--|
| تشغيل | `deploy\HypexNode-Start.cmd` |
| إيقاف | `deploy\HypexNode-Stop.cmd` |
| حالة | `deploy\HypexNode-Status.cmd` |
| إلغاء الإقلاع التلقائي | `deploy\uninstall-hypex-service.cmd` |

| | |
|--|--|
| حالة | `pm2 status` |
| سجلات | `pm2 logs hypex-node` |
| إعادة تشغيل بعد تعديل `src/` | `pm2 restart hypex-node` |

## لماذا كان يبدو «عبئاً»؟

سابقاً كان **watch** مفعّلاً: كل حفظ في `src/` يعيد تشغيل Node → استهلاك CPU و148+ إعادة تشغيل.
الوضع الإنتاجي الآن: **watch مغلق** — العملية ثابتة مثل Apache.

## جلسة لا تُفقد

الجلسات في **MySQL** (`hypex_node_sessions`).
بعد `pm2 restart` لا يلزم تسجيل دخول من جديد (طالما `SESSION_SECRET` ثابت).

## أنواع الملفات

| التعديل | ماذا تفعل؟ |
|---------|------------|
| `public/css` أو `public/js` | **Ctrl+F5** فقط |
| `src/*.js` | `pm2 restart hypex-node` |
| `.env` | `pm2 restart hypex-node` |
| PHP | لا شيء |

## تطوير (اختياري — ليس خدمة)

```bat
deploy\start-hypex-node-watch.cmd
```

أو: `npm run dev` / `npm run pm2:dev` — يعيد التحميل عند الحفظ (لجلسة تطوير فقط).

## إعداد `.env`

```env
PORT=3000
APP_BASE_PATH=/hypex
PHP_BASE_URL=http://127.0.0.1/hypex
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=...
DB_USER=...
DB_PASS=...
SESSION_SECRET=لا-تغيّره-بعد-الإنتاج
SESSION_MAX_AGE_HOURS=12
```
