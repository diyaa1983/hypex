/**
 * PM2 — Hypex Node (إنتاج / خدمة Windows)
 *
 * - watch على src فقط: حفظ ملف الخادم يعيد التشغيل وحده بعد ثانيتين
 * - public/css و public/js تُقرأ مباشرة (Ctrl+F5) بدون إعادة تشغيل
 * - autorestart: يعيد التشغيل إذا تعطلت العملية
 * - الإقلاع مع Windows: deploy\pm2-install-service.cmd (مرة واحدة كمسؤول)
 */
module.exports = {
  apps: [
    {
      name: 'hypex-node',
      script: 'src/server.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      watch: ['src'],
      ignore_watch: ['node_modules', 'public', 'logs', '.git', '*.log', '**/*.md'],
      watch_delay: 2500,
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
