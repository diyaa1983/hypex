'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const SCRIPT = path.join(__dirname, '..', '..', 'cli', 'oracle_customers_sync_run.php');

function phpCandidates() {
  const xamppPhp = 'C:\\xampp\\php\\php.exe';
  const xamppIni = 'C:\\xampp\\php\\php.ini';
  const list = [];
  if (process.env.HYPEX_PHP_BIN) {
    list.push({ bin: process.env.HYPEX_PHP_BIN, ini: process.env.HYPEX_PHP_INI || '' });
  }
  if (process.env.PHP_BIN) {
    list.push({ bin: process.env.PHP_BIN, ini: process.env.PHP_INI || '' });
  }
  if (fs.existsSync(xamppPhp)) {
    list.push({ bin: xamppPhp, ini: fs.existsSync(xamppIni) ? xamppIni : '' });
  }
  list.push({ bin: 'php', ini: fs.existsSync(xamppIni) ? xamppIni : '' });
  return list;
}

/**
 * @param {string} action
 * @param {Record<string, unknown>} [extra]
 * @returns {Record<string, unknown>}
 */
function runOracleAction(action, extra = {}) {
  const scriptArgs = [SCRIPT, '--action=' + action];
  let payloadPath = '';

  const needsPayload =
    action === 'save_config' ||
    action === 'sync' ||
    (extra && Object.keys(extra).length > 0);

  if (needsPayload && Object.keys(extra).length) {
    payloadPath = path.join(os.tmpdir(), 'hypex-oracle-' + process.pid + '-' + Date.now() + '.json');
    fs.writeFileSync(payloadPath, JSON.stringify(extra), 'utf8');
    scriptArgs.push('--payload-file=' + payloadPath);
  }

  let lastErr = 'تعذّر تشغيل عامل Oracle';
  try {
    for (const cand of phpCandidates()) {
      const args = [];
      if (cand.ini) {
        args.push('-c', cand.ini);
      }
      args.push(...scriptArgs);
      const r = spawnSync(cand.bin, args, {
        encoding: 'utf8',
        timeout: 180000,
        windowsHide: true,
        maxBuffer: 20 * 1024 * 1024,
        env: Object.assign({}, process.env, cand.ini ? { PHPRC: path.dirname(cand.ini) } : {}),
      });
      if (r.error && r.error.code === 'ENOENT') {
        lastErr = 'PHP غير موجود: ' + cand.bin;
        continue;
      }
      const raw = String(r.stdout || '').trim();
      if (!raw) {
        lastErr = String(r.stderr || r.error || 'لا مخرجات من عامل Oracle') || lastErr;
        continue;
      }
      try {
        return JSON.parse(raw);
      } catch {
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
          try {
            return JSON.parse(raw.slice(start, end + 1));
          } catch {
            /* fall through */
          }
        }
        lastErr = raw.slice(0, 800) || String(r.stderr || 'تعذّر قراءة JSON');
      }
    }
  } finally {
    if (payloadPath) {
      try {
        fs.unlinkSync(payloadPath);
      } catch {
        /* */
      }
    }
  }
  return { ok: false, message: lastErr, action };
}

module.exports = { runOracleAction };
