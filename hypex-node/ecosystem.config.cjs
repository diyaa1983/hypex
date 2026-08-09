module.exports = {
  apps: [
    {
      name: 'hypex-node',
      script: 'src/server.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      watch: ['src'],
      ignore_watch: ['node_modules', 'public', '.env', '*.log', 'logs'],
      watch_delay: 800,
      max_memory_restart: '400M',
      env: {
        NODE_ENV: 'production',
      },
    },
  ],
};
