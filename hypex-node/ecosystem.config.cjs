/**
 * PM2 — Hypex Node
 *
 * - watch: يعيد التشغيل تلقائياً عند حفظ ملفات src/
 * - الجلسات في MySQL → لا يُفقد تسجيل الدخول بعد reload
 * - public/css و public/js تُقرأ مباشرة (بدون restart)
 */
module.exports = {
  apps: [
    {
      name: 'hypex-node',
      script: 'src/server.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      // إعادة تحميل تلقائية عند تعديل كود السيرفر
      watch: ['src'],
      ignore_watch: [
        'node_modules',
        'public',
        'logs',
        '.env',
        '*.log',
        '**/*.md',
      ],
      watch_delay: 1000,
      max_memory_restart: '400M',
      // لو تعطّل Node لأي سبب — أعد تشغيله
      autorestart: true,
      max_restarts: 50,
      min_uptime: '5s',
      env: {
        NODE_ENV: 'production',
      },
    },
  ],
};
