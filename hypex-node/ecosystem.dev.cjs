/**
 * PM2 — وضع التطوير فقط (نافذة/جلسة عمل)
 * لا تستخدمه كمهمة إقلاع Windows
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
      ignore_watch: ['node_modules', 'public', 'logs', '.env', '*.log', '**/*.md'],
      watch_delay: 1500,
      max_memory_restart: '400M',
      autorestart: true,
      env: {
        NODE_ENV: 'development',
      },
    },
  ],
};
