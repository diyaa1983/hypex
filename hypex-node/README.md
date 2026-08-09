# Hypex — واجهة واحدة على Node.js

الرابط العام (XAMPP): **http://localhost/hypex**

## لماذا نعيد تشغيل Node؟

Node يحمّل ملفات JavaScript **مرة واحدة** عند البدء. التعديل على الملفات على القرص لا يدخل الذاكرة إلا بعد إعادة التشغيل.

**الحل أثناء التطوير:** وضع المراقبة (`--watch`) يعيد التشغيل تلقائياً عند الحفظ.

## التشغيل

1. Apache + MySQL من XAMPP  
2. Node:

| الوضع | الأمر |
|--------|--------|
| عادي (إنتاج) | `deploy\start-hypex-node.cmd` أو `npm start` |
| **تلقائي بعد التعديل** | `deploy\start-hypex-node-watch.cmd` أو `npm run dev` |

ثم: **http://localhost/hypex**

### pm2 على السيرفر (تشغيل دائم)

```bat
cd c:\xampp\htdocs\hypex\hypex-node
pm2 start src/server.js --name hypex-node
pm2 save
```

بعد نسخ ملفات جديدة: `pm2 restart hypex-node`

أثناء التطوير فقط (مراقبة ملفات):

```bat
pm2 start src/server.js --name hypex-node --watch --ignore-watch="node_modules"
```

### ملاحظات

- ملفات **CSS/JS في المتصفح** (`public/…`): غالباً يكفي **Ctrl+F5** بدون إعادة تشغيل Node.
- ملفات **PHP** (تقارير Oracle عبر CLI): تُقرأ عند كل طلب — لا تحتاج إعادة تشغيل Node عادة.
- ملفات **`src/*.js`**: تحتاج إعادة تشغيل Node أو وضع `watch` / `pm2 restart`.

## إعداد `.env`

انسخ من `.env.example`:

- `APP_BASE_PATH=/hypex`
- `PHP_BASE_URL=http://127.0.0.1/hypex`
- `DB_*` = نفس MySQL
- `PORT=3000`
