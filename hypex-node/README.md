# Hypex — واجهة واحدة على Node.js

الرابط: **http://localhost/hypex**

## جلسة لا تُفقد عند إعادة التشغيل

الجلسات مخزّنة في **MySQL** (جدول `hypex_node_sessions`) وليس في ذاكرة Node.
بعد `pm2 restart` أو تحديث الكود **المستخدم لا يحتاج إعادة تسجيل الدخول** (طالما الكوكي لم تنتهِ و`SESSION_SECRET` لم يتغيّر).

## تشغيل ثابت كخدمة (مستحسن — مرة واحدة)

شغّل **كـ Administrator**:

```bat
deploy\pm2-install-service.cmd
```

ماذا يفعل؟
1. يثبت **PM2**
2. يشغّل `hypex-node` في الخلفية
3. **watch** على مجلد `src/` → أي تعديل في كود السيرفر = reload تلقائي (بدون start/stop يدوي)
4. يسجّل مهمة Windows **HypexNodePM2** لتعيد الخدمة بعد إعادة تشغيل الجهاز

لا حاجة لإبقاء نافذة CMD مفتوحة، ولا لتشغيل يدوي كل مرة.

| | |
|--|--|
| حالة | `pm2 status` |
| سجلات | `pm2 logs hypex-node` |
| إيقاف مؤقت | `pm2 stop hypex-node` |
| تشغيل بعد الإيقاف | `pm2 start hypex-node` |

## أنواع الملفات — ماذا تحتاج؟

| التعديل | ماذا تفعل؟ |
|---------|------------|
| `public/css` أو `public/js` | **Ctrl+F5** فقط — بدون أي restart |
| `src/*.js` | **لا شيء** — PM2 watch يعيد التحميل وحده |
| `.env` | `pm2 restart hypex-node` مرة واحدة |
| PHP | لا شيء |

**ملاحظة:** بعد reload التلقائي **الجلسات لا تُفقد** (مخزّنة في MySQL).

## تطوير محلي بدون PM2

```bat
deploy\start-hypex-node-watch.cmd
```

أو `npm run dev` — يحتاج نافذة مفتوحة.

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
SESSION_SECRET=لا-تغيّره-عشوائياً-بعد-الإنتاج
SESSION_MAX_AGE_HOURS=12
```

**مهم:** لا تغيّر `SESSION_SECRET` بعد تسجيل دخول المستخدمين وإلا تُلغى كل الجلسات.
