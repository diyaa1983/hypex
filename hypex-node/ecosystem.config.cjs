/**
 * PM2 — Hypex Node (إنتاج / خدمة Windows)
 *
 * - بدون watch: لا إعادة تشغيل عند حفظ الملفات (خفّض الحمل والاضطراب)
 * - autorestart: يعيد التشغيل فقط إذا تعطل العملية
 * - التعديلات على src تتطلب: pm2 restart hypex-node
 * - public/css و public/js تُقرأ مباشرة (Ctrl+F5) بدون restart
 *
 * للتطوير مع إعادة تحميل تلقائية: npm run pm2:dev
 */
module.exports = {
  apps: [
    {
      name: 'hypex-node',
      script: 'src/server.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      watch: false,
      max_memory_restart: '350M',
      autorestart: true,
      max_restarts: 20,
      min_uptime: '10s',
      restart_delay: 2000,
      kill_timeout: 5000,
      env: {
        NODE_ENV: 'production',
        PHP_BIN: 'C:\\xampp\\php\\php.exe',
        PHP_INI: 'C:\\xampp\\php\\php.ini',
      },
    },
  ],
};
