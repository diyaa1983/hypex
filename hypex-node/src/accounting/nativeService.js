'use strict';

const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const { todayIso } = require('../lib/html');

function hypexRoot() {
  return path.resolve(__dirname, '..', '..', '..');
}

function phpBin() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  for (const c of ['C:\\xampp\\php\\php.exe', 'C:\\xampp\\php\\php', 'php']) {
    if (c === 'php' || fs.existsSync(c)) return c;
  }
  return 'php';
}

function phpArgs(script, action, userId) {
  const args = [];
  const ini = process.env.PHP_INI || 'C:\\xampp\\php\\php.ini';
  if (fs.existsSync(ini)) {
    args.push('-c', ini);
  }
  args.push(script, action, String(userId || 0));
  return args;
}

function run(action, userId, payload = {}) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'acc_native_run.php');
    if (!fs.existsSync(script)) {
      return resolve({ ok: false, error: 'سكربت CLI غير موجود' });
    }
    const child = spawn(phpBin(), phpArgs(script, action, userId), {
      cwd: hypexRoot(),
      windowsHide: true,
    });
    let out = '';
    let err = '';
    child.stdout.on('data', (d) => {
      out += String(d);
    });
    child.stderr.on('data', (d) => {
      err += String(d);
    });
    child.on('error', (e) => resolve({ ok: false, error: e.message }));
    child.on('close', () => {
      const line = out
        .split(/\r?\n/)
        .map((s) => s.trim())
        .filter(Boolean)
        .pop();
      if (!line) {
        return resolve({
          ok: false,
          error: err.trim() || 'لا استجابة من PHP',
        });
      }
      try {
        resolve(JSON.parse(line));
      } catch {
        resolve({ ok: false, error: line.slice(0, 300) });
      }
    });
    child.stdin.write(JSON.stringify(payload || {}));
    child.stdin.end();
  });
}

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function yearStart() {
  return `${new Date().getFullYear()}-01-01`;
}

function range(from, to) {
  const { parseDateToIso } = require('../lib/html');
  let f = parseDateToIso(String(from || '').trim(), yearStart());
  let t = parseDateToIso(String(to || '').trim(), todayIso());
  if (f > t) [f, t] = [t, f];
  return { from: f, to: t };
}

module.exports = { run, range, monthStart, yearStart, todayIso };
