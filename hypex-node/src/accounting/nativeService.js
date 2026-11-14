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

function sanitizePhpCliError(raw) {
  let s = String(raw || '').replace(/\s+/g, ' ').trim();
  if (!s) return 'تعذر إكمال الطلب من PHP';
  if (/Allowed memory size/i.test(s)) {
    return 'نفدت ذاكرة PHP أثناء التقرير. قلّل الفلاتر (مندوب/سنة/شهر) ثم أعد المحاولة.';
  }
  if (/Maximum execution time/i.test(s)) {
    return 'انتهت مهلة تنفيذ التقرير. اختر مندوباً أو سنة محددة ثم أعد المحاولة.';
  }
  if (/<!doctype html|<html[\s>]|تعذر إكمال الطلب|حدث خطأ داخلي|cPanel/i.test(s)) {
    return 'تعذر إكمال التقرير على الخادم. قلّل الفلاتر أو أعد المحاولة.';
  }
  if (s.length > 280) s = s.slice(0, 280) + '…';
  return s;
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
      const lines = out
        .split(/\r?\n/)
        .map((s) => s.trim())
        .filter(Boolean);
      // آخر سطر JSON صالح (يتجاوز أي HTML طرأ قبل إصلاح CLI)
      for (let i = lines.length - 1; i >= 0; i -= 1) {
        const line = lines[i];
        if (line.charAt(0) !== '{' && line.charAt(0) !== '[') continue;
        try {
          return resolve(JSON.parse(line));
        } catch {
          /* continue */
        }
      }
      const fallback = lines[lines.length - 1] || err.trim() || 'لا استجابة من PHP';
      resolve({ ok: false, error: sanitizePhpCliError(fallback) });
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
