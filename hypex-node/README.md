# Hypex — واجهة واحدة على Node.js

الرابط: **http://localhost/hypex**

## جلسة لا تُفقد عند إعادة التشغيل

الجلسات مخزّنة في **MySQL** (جدول `hypex_node_sessions`) وليس في ذاكرة Node.
بعد `pm2 restart` أو تحديث الكود **المستخدم لا يحتاج إعادة تسجيل الدخول** (طالما الكوكي لم تنتهِ و`SESSION_SECRET` لم يتغيّر).

## تشغيل ثابت على السيرفر (مستحسن)

مرة واحدة:

```bat
deploy\pm2-start-hypex.cmd
```

أو:

```bat
cd c:\xampp\htdocs\hypex\hypex-node
npm install -g pm2
npm install
pm2 start src/server.js --name hypex-node
pm2 save
pm2 startup
```

بعد رفع ملفات جديدة:

```bat
pm2 restart hypex-node
```

| | |
|--|--|
| حالة | `pm2 status` |
| سجلات | `pm2 logs hypex-node` |
| إيقاف | `pm2 stop hypex-node` |

لا حاجة لإبقاء نافذة CMD مفتوحة.

## أنواع الملفات — ماذا تحتاج؟

| التعديل | ماذا تفعل؟ |
|---------|------------|
| `public/css` أو `public/js` | **Ctrl+F5** في المتصفح فقط |
| PHP (تقارير Oracle…) | لا شيء — تُقرأ فوراً |
| `src/*.js` أو `.env` | `pm2 restart hypex-node` (بدون logout للمستخدمين) |

## تطوير محلي (مراقبة ملفات)

```bat
deploy\start-hypex-node-watch.cmd
```

أو `npm run dev` — يعيد التشغيل تلقائياً عند الحفظ، **والجلسة تبقى** بفضل MySQL.

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
