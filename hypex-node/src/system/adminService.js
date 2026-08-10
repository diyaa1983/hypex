'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const db = require('../db');
const config = require('../config');
const permissionsNav = require('./permissionsNav');

const WIN_ACTIVE_SEC = 1800;
const MOB_ACTIVE_SEC = 180;
const PHONE_APP_ROOT = path.resolve(__dirname, '..', '..', '..'); // htdocs/Hypex

async function q(sql, params = []) {
  return db.query(sql, params);
}

/* ── groups ── */
async function listGroups() {
  return q(
    `SELECT g.id, g.code, g.name_ar, g.description,
            (SELECT COUNT(*) FROM sys_user_group ug WHERE ug.group_id = g.id) AS user_count,
            (SELECT COUNT(*) FROM sys_group_permission gp WHERE gp.group_id = g.id AND gp.allowed = 1) AS perm_count
     FROM sys_group g
     ORDER BY g.id ASC`
  );
}

async function getGroup(id) {
  const rows = await q(
    `SELECT id, code, name_ar, description FROM sys_group WHERE id = ? LIMIT 1`,
    [Number(id)]
  );
  if (!rows[0]) return null;
  const [mc] = await q(`SELECT COUNT(*) AS c FROM sys_user_group WHERE group_id = ?`, [id]);
  const [pc] = await q(
    `SELECT COUNT(*) AS c FROM sys_group_permission WHERE group_id = ? AND allowed = 1`,
    [id]
  );
  return {
    ...rows[0],
    user_count: Number(mc?.c || 0),
    perm_count: Number(pc?.c || 0),
  };
}

async function saveGroup(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '')
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9_]/g, '');
  const nameAr = String(payload.name_ar || '').trim();
  const description = String(payload.description || '').trim();

  if (code.length < 2) {
    return { ok: false, error: 'رمز المجموعة مطلوب (حرفان على الأقل، إنجليزي/أرقام/_).' };
  }
  if (!nameAr) return { ok: false, error: 'اسم المجموعة مطلوب.' };

  if (id > 0) {
    const cur = await getGroup(id);
    if (!cur) return { ok: false, error: 'المجموعة غير موجودة.' };
    if (cur.code === 'ADMINS' && code !== 'ADMINS') {
      return { ok: false, error: 'لا يمكن تغيير رمز مجموعة مديري النظام.' };
    }
    if (cur.code === 'ADMINS') code = 'ADMINS';
  }

  const dup = await q(`SELECT id FROM sys_group WHERE code = ? AND id <> ? LIMIT 1`, [code, id]);
  if (dup[0]) return { ok: false, error: 'رمز المجموعة مستخدم مسبقاً.' };

  try {
    if (id < 1) {
      const pool = db.getPool();
      const [ins] = await pool.execute(
        `INSERT INTO sys_group (code, name_ar, description) VALUES (?,?,?)`,
        [code, nameAr, description || null]
      );
      return {
        ok: true,
        id: Number(ins.insertId),
        message: 'تمت إضافة المجموعة. يمكنك ضبط صلاحيات الشاشات والتقارير.',
      };
    }
    await q(`UPDATE sys_group SET code=?, name_ar=?, description=? WHERE id=?`, [
      code,
      nameAr,
      description || null,
      id,
    ]);
    return { ok: true, id, message: 'تم حفظ بيانات المجموعة.' };
  } catch (e) {
    console.error('saveGroup', e.message);
    return { ok: false, error: 'تعذر حفظ المجموعة.' };
  }
}

/* ── permissions ── */
async function listPermissionsMatrix(groupId = 0) {
  const groups = await listGroups();
  const screens = await q(
    `SELECT id, code, name_ar, screen_type, sort_order FROM sys_screen ORDER BY sort_order, id`
  );
  let allowed = new Set();
  if (groupId > 0) {
    const rows = await q(
      `SELECT screen_id FROM sys_group_permission WHERE group_id = ? AND allowed = 1`,
      [groupId]
    );
    allowed = new Set(rows.map((r) => Number(r.screen_id)));
  }
  const gMeta = groups.find((g) => Number(g.id) === Number(groupId));
  const isMobile = String(gMeta?.code || '').toUpperCase() === 'MOBILE';
  const tree = permissionsNav.buildPermissionPanels(screens, { isMobile });
  return { groups, screens, allowed, isMobile, ...tree };
}

async function savePermissions(groupId, screenIds) {
  const gid = Number(groupId);
  if (gid < 1) return { ok: false, error: 'مجموعة غير صالحة.' };
  const g = await getGroup(gid);
  if (!g) return { ok: false, error: 'المجموعة غير موجودة.' };

  const selected = new Set(
    [].concat(screenIds || [])
      .map(Number)
      .filter((n) => n > 0)
  );
  const isMobile = String(g.code || '').toUpperCase() === 'MOBILE';

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    if (isMobile) {
      await conn.execute(
        `DELETE gp FROM sys_group_permission gp
         INNER JOIN sys_screen s ON s.id = gp.screen_id
         WHERE gp.group_id = ? AND s.code LIKE 'm_%'`,
        [gid]
      );
      const [mobileScreens] = await conn.execute(
        `SELECT id FROM sys_screen WHERE code LIKE 'm_%'`
      );
      for (const s of mobileScreens) {
        if (selected.has(Number(s.id))) {
          await conn.execute(
            `INSERT INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?,?,1)`,
            [gid, s.id]
          );
        }
      }
    } else {
      await conn.execute(`DELETE FROM sys_group_permission WHERE group_id = ?`, [gid]);
      const [all] = await conn.execute(`SELECT id FROM sys_screen`);
      for (const s of all) {
        if (selected.has(Number(s.id))) {
          await conn.execute(
            `INSERT INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?,?,1)`,
            [gid, s.id]
          );
        }
      }
    }
    await conn.commit();
    return { ok: true, message: 'تم حفظ الصلاحيات.' };
  } catch (e) {
    await conn.rollback();
    console.error('savePermissions', e.message);
    return { ok: false, error: 'تعذر حفظ الصلاحيات.' };
  } finally {
    conn.release();
  }
}

/* ── sessions ── */
async function listActiveSessions({ q: search = '', clientType = '' } = {}) {
  const winCutoff = new Date(Date.now() - WIN_ACTIVE_SEC * 1000)
    .toISOString()
    .slice(0, 19)
    .replace('T', ' ');
  const mobCutoff = new Date(Date.now() - MOB_ACTIVE_SEC * 1000)
    .toISOString()
    .slice(0, 19)
    .replace('T', ' ');

  const where = [
    's.revoked_at IS NULL',
    `((s.client_type = 'windows' AND s.last_seen_at >= ?) OR (s.client_type = 'mobile' AND s.last_seen_at >= ?))`,
  ];
  const params = [winCutoff, mobCutoff];

  if (clientType === 'windows' || clientType === 'mobile') {
    where.push('s.client_type = ?');
    params.push(clientType);
  }
  if (search) {
    const like = `%${search}%`;
    where.push(
      `(u.username LIKE ? OR IFNULL(u.full_name_ar,'') LIKE ? OR IFNULL(s.ip_address,'') LIKE ? OR IFNULL(s.client_label,'') LIKE ?)`
    );
    params.push(like, like, like, like);
  }

  return q(
    `SELECT s.id, s.user_id, s.client_type, s.client_label, s.ip_address,
            s.location_text, s.login_at, s.last_seen_at, s.latitude, s.longitude,
            u.username, u.full_name_ar
     FROM sys_user_open_session s
     INNER JOIN sys_user u ON u.id = s.user_id
     WHERE ${where.join(' AND ')}
     ORDER BY s.last_seen_at DESC, s.id DESC
     LIMIT 500`,
    params
  );
}

async function killSession(sessionId, revokedBy) {
  const id = Number(sessionId);
  if (id < 1) return { ok: false, error: 'معرّف الجلسة غير صالح.' };
  const rows = await q(
    `SELECT id, revoked_at FROM sys_user_open_session WHERE id = ? LIMIT 1`,
    [id]
  );
  if (!rows[0]) return { ok: false, error: 'الجلسة غير موجودة.' };
  if (rows[0].revoked_at) return { ok: true, message: 'الجلسة منتهية مسبقاً.' };
  await q(
    `UPDATE sys_user_open_session SET revoked_at = NOW(), revoked_by = ?, last_seen_at = NOW() WHERE id = ?`,
    [revokedBy || null, id]
  );
  return { ok: true, message: 'تم إنهاء الجلسة. سيُفصل المستخدم عند الطلب التالي.' };
}

/* ── company settings ── */
const CRYPTO = require('crypto');

const CURRENCY_CATALOG = {
  SAR: { code: 'SAR', name_ar: 'ريال سعودي', symbol: 'ر.س' },
  YER: { code: 'YER', name_ar: 'ريال يمني', symbol: 'ر.ي' },
  AED: { code: 'AED', name_ar: 'درهم إماراتي', symbol: 'د.إ' },
  KWD: { code: 'KWD', name_ar: 'دينار كويتي', symbol: 'د.ك' },
  JOD: { code: 'JOD', name_ar: 'دينار أردني', symbol: 'د.أ' },
  IQD: { code: 'IQD', name_ar: 'دينار عراقي', symbol: 'د.ع' },
  BHD: { code: 'BHD', name_ar: 'دينار بحريني', symbol: 'د.ب' },
  OMR: { code: 'OMR', name_ar: 'ريال عماني', symbol: 'ر.ع' },
  QAR: { code: 'QAR', name_ar: 'ريال قطري', symbol: 'ر.ق' },
  EGP: { code: 'EGP', name_ar: 'جنيه مصري', symbol: 'ج.م' },
  SDG: { code: 'SDG', name_ar: 'جنيه سوداني', symbol: 'ج.س' },
  LYD: { code: 'LYD', name_ar: 'دينار ليبي', symbol: 'د.ل' },
  DZD: { code: 'DZD', name_ar: 'دينار جزائري', symbol: 'د.ج' },
  MAD: { code: 'MAD', name_ar: 'درهم مغربي', symbol: 'د.م' },
  TND: { code: 'TND', name_ar: 'دينار تونسي', symbol: 'د.ت' },
  SYP: { code: 'SYP', name_ar: 'ليرة سورية', symbol: 'ل.س' },
  LBP: { code: 'LBP', name_ar: 'ليرة لبنانية', symbol: 'ل.ل' },
  USD: { code: 'USD', name_ar: 'دولار أمريكي', symbol: '$' },
  EUR: { code: 'EUR', name_ar: 'يورو', symbol: '€' },
  GBP: { code: 'GBP', name_ar: 'جنيه إسترليني', symbol: '£' },
  TRY: { code: 'TRY', name_ar: 'ليرة تركية', symbol: '₺' },
};

function currencyCatalogList() {
  return Object.values(CURRENCY_CATALOG);
}

function archiveRecommendedDir() {
  const parent = path.dirname(PHONE_APP_ROOT);
  return path.join(parent, 'manager_documents');
}

function parseEmailList(raw) {
  return String(raw || '')
    .split(/[\s,;]+/)
    .map((s) => s.trim())
    .filter((s) => s && s.includes('@'));
}

function isValidEmail(s) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s || '').trim());
}

async function getCompanySettings() {
  await ensurePrintWatermarkColumn();
  const rows = await q(`SELECT * FROM sys_company_settings WHERE id = 1 LIMIT 1`);
  return rows[0] || null;
}

/** عمود إعداد العلامة المائية عند الطباعة */
async function ensurePrintWatermarkColumn() {
  try {
    await q(`SELECT print_watermark_enabled FROM sys_company_settings LIMIT 1`);
  } catch {
    try {
      await q(
        `ALTER TABLE sys_company_settings
         ADD COLUMN print_watermark_enabled TINYINT(1) NOT NULL DEFAULT 1
         COMMENT 'إظهار علامة الشعار المائية عند الطباعة'`
      );
    } catch {
      /* ignore race / privilege */
    }
  }
}

async function listActiveTaxRates() {
  return q(
    `SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id`
  );
}

/**
 * @param {object} payload
 * @param {{ buffer?: Buffer, mimetype?: string, size?: number }|null} logoFile
 */
async function saveCompanySettings(payload, logoFile) {
  payload = payload && typeof payload === 'object' ? payload : {};
  const payloadKeys = Object.keys(payload);
  if (payloadKeys.length === 0) {
    return {
      ok: false,
      error:
        'لم تصل بيانات النموذج إلى الخادم (body فارغ). تأكد أن الخدمة Node تستقبل multipart، ثم أعد الحفظ.',
    };
  }

  await q(
    `INSERT IGNORE INTO sys_company_settings (id, company_name_ar, tax_rate_percent, decimal_places)
     VALUES (1, 'Hypex', 16.000, 3)`
  ).catch(() => {});

  const row = (await getCompanySettings()) || {};
  const has = (k) => Object.prototype.hasOwnProperty.call(payload, k);

  let name = has('company_name_ar')
    ? String(payload.company_name_ar || '').trim()
    : String(row.company_name_ar || '').trim();
  if (!name && row.company_name_ar) name = String(row.company_name_ar);
  if (!name) return { ok: false, error: 'اسم الشركة مطلوب.' };

  const clampDec = (v, fallback) => {
    const fb = Number.isFinite(Number(fallback)) ? Math.floor(Number(fallback)) : 2;
    if (v === undefined || v === null || v === '') return Math.max(0, Math.min(6, fb));
    const n = Number(v);
    if (!Number.isFinite(n)) return Math.max(0, Math.min(6, fb));
    return Math.max(0, Math.min(6, Math.floor(n)));
  };

  const taxRates = await listActiveTaxRates();
  let taxF = null;
  if (taxRates.length) {
    if (has('default_tax_rate_id')) {
      const taxId = Number(payload.default_tax_rate_id || 0);
      if (taxId > 0) {
        const hit = taxRates.find((t) => Number(t.id) === taxId);
        if (hit) taxF = Number(hit.rate_percent);
      }
    }
    if (taxF == null && row.tax_rate_percent != null) {
      taxF = Number(row.tax_rate_percent);
    }
    if (taxF == null || !Number.isFinite(taxF)) taxF = Number(taxRates[0].rate_percent);
  } else if (has('tax_rate_percent')) {
    taxF = Number(String(payload.tax_rate_percent || '0').replace(',', '.'));
  } else {
    taxF = Number(row.tax_rate_percent ?? 0);
  }
  if (!Number.isFinite(taxF) || taxF < 0 || taxF > 100) {
    return { ok: false, error: 'نسبة الضريبة غير منطقية (0–100).' };
  }

  const dec = clampDec(
    has('decimal_places') ? payload.decimal_places : row.decimal_places,
    row.decimal_places ?? 3
  );
  const unitDec = clampDec(
    has('invoice_unit_price_decimal_places')
      ? payload.invoice_unit_price_decimal_places
      : row.invoice_unit_price_decimal_places,
    row.invoice_unit_price_decimal_places ?? 3
  );
  const printDec = clampDec(
    has('invoice_print_decimal_places')
      ? payload.invoice_print_decimal_places
      : row.invoice_print_decimal_places,
    row.invoice_print_decimal_places ?? dec
  );
  const printUnitDec = clampDec(
    has('invoice_print_unit_price_decimal_places')
      ? payload.invoice_print_unit_price_decimal_places
      : row.invoice_print_unit_price_decimal_places,
    row.invoice_print_unit_price_decimal_places ?? unitDec
  );

  let rowsPerPage = has('rows_per_page')
    ? Number(payload.rows_per_page || 15) || 15
    : Number(row.rows_per_page || 15) || 15;
  if (![10, 15, 20].includes(rowsPerPage)) rowsPerPage = 15;

  let uiTheme = String(
    has('ui_theme') ? payload.ui_theme : row.ui_theme || 'basic'
  ).toLowerCase();
  if (uiTheme === 'modern') uiTheme = 'basic';
  if (!['classic', 'basic'].includes(uiTheme)) uiTheme = 'basic';
  let uiLang = String(has('ui_lang') ? payload.ui_lang : row.ui_lang || 'ar').toLowerCase();
  if (!['ar', 'en'].includes(uiLang)) uiLang = 'ar';

  let currency = String(
    has('currency_code') ? payload.currency_code : row.currency_code || 'JOD'
  )
    .toUpperCase()
    .trim();
  if (!CURRENCY_CATALOG[currency]) currency = 'JOD';

  const addr = has('address_ar')
    ? String(payload.address_ar || '').trim()
    : String(row.address_ar || '').trim();
  const phone = has('phone')
    ? String(payload.phone || '').trim()
    : String(row.phone || '').trim();
  const email = has('email')
    ? String(payload.email || '').trim()
    : String(row.email || '').trim();

  const smtpHost = has('smtp_host')
    ? String(payload.smtp_host || '').trim()
    : String(row.smtp_host || '').trim();
  let smtpPort = has('smtp_port')
    ? Number(payload.smtp_port || 587) || 587
    : Number(row.smtp_port || 587) || 587;
  if (smtpPort < 1 || smtpPort > 65535) smtpPort = 587;
  let smtpSecure = String(
    has('smtp_secure') ? payload.smtp_secure : row.smtp_secure || 'tls'
  ).toLowerCase();
  if (!['tls', 'ssl', 'none'].includes(smtpSecure)) smtpSecure = 'tls';
  const smtpUser = has('smtp_username')
    ? String(payload.smtp_username || '').trim()
    : String(row.smtp_username || '').trim();
  let smtpPass = has('smtp_password') ? String(payload.smtp_password || '') : '';
  if (!smtpPass && row.smtp_password) smtpPass = String(row.smtp_password);
  const smtpFromEmail = has('smtp_from_email')
    ? String(payload.smtp_from_email || '').trim()
    : String(row.smtp_from_email || '').trim();
  const smtpFromName = has('smtp_from_name')
    ? String(payload.smtp_from_name || '').trim()
    : String(row.smtp_from_name || '').trim();

  // النموذج الكامل يرسل company_name_ar دائماً — عندها checkbox الغائب = غير مفعّل
  const formComplete = has('company_name_ar') || has('decimal_places') || has('currency_code');

  const printWatermarkEnabled = formComplete
    ? !!(
        payload.print_watermark_enabled === '1' ||
        payload.print_watermark_enabled === 'on' ||
        payload.print_watermark_enabled === true
      )
    : row.print_watermark_enabled == null
      ? true
      : Number(row.print_watermark_enabled) === 1;

  let loginRecaptchaEnabled = formComplete
    ? !!(
        payload.login_recaptcha_enabled === '1' ||
        payload.login_recaptcha_enabled === 'on' ||
        payload.login_recaptcha_enabled === true
      )
    : Number(row.login_recaptcha_enabled) === 1;
  const loginRecaptchaSiteKey = has('login_recaptcha_site_key')
    ? String(payload.login_recaptcha_site_key || '').trim()
    : String(row.login_recaptcha_site_key || '').trim();
  let loginRecaptchaSecret = has('login_recaptcha_secret_key')
    ? String(payload.login_recaptcha_secret_key || '')
    : '';
  if (!loginRecaptchaSecret && row.login_recaptcha_secret_key) {
    loginRecaptchaSecret = String(row.login_recaptcha_secret_key);
  }
  if (loginRecaptchaSiteKey && loginRecaptchaSecret) loginRecaptchaEnabled = true;
  if (loginRecaptchaEnabled && !loginRecaptchaSiteKey) {
    return { ok: false, error: 'reCAPTCHA: أدخل Site Key من Google.' };
  }
  if (loginRecaptchaEnabled && !loginRecaptchaSecret) {
    return { ok: false, error: 'reCAPTCHA: أدخل Secret Key من Google (مطلوب في أول حفظ).' };
  }

  const checkEmailEnabled = formComplete
    ? !!(
        payload.check_email_enabled === '1' ||
        payload.check_email_enabled === 'on' ||
        payload.check_email_enabled === true
      )
    : Number(row.check_email_enabled) === 1;
  let checkEmailDays = has('check_email_days_before')
    ? Number(payload.check_email_days_before || 5) || 5
    : Number(row.check_email_days_before || 5) || 5;
  checkEmailDays = Math.max(1, Math.min(60, Math.floor(checkEmailDays)));
  const checkEmailOnDue = formComplete
    ? !!(
        payload.check_email_on_due_day === '1' ||
        payload.check_email_on_due_day === 'on' ||
        payload.check_email_on_due_day === true
      )
    : row.check_email_on_due_day == null || Number(row.check_email_on_due_day) === 1;
  const checkEmailRecipients = has('check_email_recipients')
    ? String(payload.check_email_recipients || '').trim()
    : String(row.check_email_recipients || '').trim();

  const outCheckEmailEnabled = formComplete
    ? !!(
        payload.out_check_email_enabled === '1' ||
        payload.out_check_email_enabled === 'on' ||
        payload.out_check_email_enabled === true
      )
    : Number(row.out_check_email_enabled) === 1;
  let outCheckEmailDays = has('out_check_email_days_before')
    ? Number(payload.out_check_email_days_before || 5) || 5
    : Number(row.out_check_email_days_before || 5) || 5;
  outCheckEmailDays = Math.max(1, Math.min(60, Math.floor(outCheckEmailDays)));
  const outCheckEmailOnDue = formComplete
    ? !!(
        payload.out_check_email_on_due_day === '1' ||
        payload.out_check_email_on_due_day === 'on' ||
        payload.out_check_email_on_due_day === true
      )
    : row.out_check_email_on_due_day == null || Number(row.out_check_email_on_due_day) === 1;
  const outCheckEmailRecipients = has('out_check_email_recipients')
    ? String(payload.out_check_email_recipients || '').trim()
    : String(row.out_check_email_recipients || '').trim();

  const checkHasRcpt =
    parseEmailList(checkEmailRecipients).length > 0 || (email && isValidEmail(email));
  const outCheckHasRcpt =
    parseEmailList(outCheckEmailRecipients).length > 0 || (email && isValidEmail(email));
  const smtpReady =
    (smtpHost && (smtpFromEmail || (email && isValidEmail(email)))) ||
    (row.smtp_host && (row.smtp_from_email || row.email));

  if (checkEmailEnabled && !checkHasRcpt) {
    return {
      ok: false,
      error:
        'فعّلت تنبيهات الشيكات الواردة: أدخل بريداً واحداً على الأقل في قائمة المستلمين، أو بريداً صالحاً في حقل «البريد» الرئيسي.',
    };
  }
  if (checkEmailEnabled && !smtpReady) {
    return {
      ok: false,
      error: 'فعّلت تنبيهات الشيكات الواردة: أكمل إعدادات SMTP (الخادم والبريد المرسل) أولاً.',
    };
  }
  if (outCheckEmailEnabled && !outCheckHasRcpt) {
    return {
      ok: false,
      error:
        'فعّلت تنبيهات الشيكات الصادرة: أدخل بريداً واحداً على الأقل في قائمة المستلمين، أو بريداً صالحاً في حقل «البريد» الرئيسي.',
    };
  }
  if (outCheckEmailEnabled && !smtpReady) {
    return {
      ok: false,
      error: 'فعّلت تنبيهات الشيكات الصادرة: أكمل إعدادات SMTP (الخادم والبريد المرسل) أولاً.',
    };
  }

  let documentArchiveDir = has('document_archive_dir')
    ? String(payload.document_archive_dir || '').trim()
    : String(row.document_archive_dir || '').trim();
  let documentArchiveMaxMb = has('document_archive_max_mb')
    ? Number(payload.document_archive_max_mb || 10) || 10
    : Number(row.document_archive_max_mb || 10) || 10;
  documentArchiveMaxMb = Math.max(1, Math.min(100, Math.floor(documentArchiveMaxMb)));
  if (documentArchiveDir) {
    try {
      const abs = path.resolve(documentArchiveDir);
      fs.mkdirSync(abs, { recursive: true });
      documentArchiveDir = abs;
    } catch (e) {
      return { ok: false, error: 'مسار أرشيف المستندات غير صالح: ' + (e.message || '') };
    }
  }

  const waPhoneId = has('wa_phone_id')
    ? String(payload.wa_phone_id || '').trim()
    : String(row.wa_phone_id || '').trim();
  let waApiVersion = has('wa_api_version')
    ? String(payload.wa_api_version || 'v20.0').trim() || 'v20.0'
    : String(row.wa_api_version || 'v20.0').trim() || 'v20.0';
  let waToken = has('wa_access_token') ? String(payload.wa_access_token || '') : '';
  if (!waToken && row.wa_access_token) waToken = String(row.wa_access_token);
  const waCountryRaw = has('wa_default_country')
    ? String(payload.wa_default_country || '')
    : String(row.wa_default_country || '');
  const waCountry = waCountryRaw.replace(/\D+/g, '') || null;

  let logoPath = row.logo_path ? String(row.logo_path) : null;
  let logoNote = '';
  if (logoFile && logoFile.buffer && logoFile.buffer.length) {
    const mimeMap = {
      'image/jpeg': 'jpg',
      'image/png': 'png',
      'image/webp': 'webp',
    };
    const ext = mimeMap[logoFile.mimetype];
    if (!ext) {
      logoNote = ' الشعار لم يُحدَّث (استخدم PNG أو JPG أو WebP).';
    } else if (logoFile.size > 2_000_000) {
      logoNote = ' الشعار كبير جدًا (الحد 2 ميجابايت) ولم يُحفظ.';
    } else {
      const dir = path.join(PHONE_APP_ROOT, 'uploads', 'logos');
      try {
        fs.mkdirSync(dir, { recursive: true });
        const fname = 'logo_' + CRYPTO.randomBytes(8).toString('hex') + '.' + ext;
        const dest = path.join(dir, fname);
        fs.writeFileSync(dest, logoFile.buffer);
        if (logoPath) {
          const oldAbs = path.join(PHONE_APP_ROOT, logoPath.replace(/^\//, ''));
          try {
            if (fs.existsSync(oldAbs)) fs.unlinkSync(oldAbs);
          } catch {
            /* ignore */
          }
        }
        logoPath = 'uploads/logos/' + fname;
      } catch (e) {
        return { ok: false, error: 'تعذر حفظ الشعار: ' + (e.message || '') };
      }
    }
  }

  const params = [
    name,
    taxF.toFixed(3),
    dec,
    unitDec,
    printDec,
    printUnitDec,
    rowsPerPage,
    uiTheme,
    uiLang,
    currency,
    addr || null,
    phone || null,
    email || null,
    logoPath || null,
    smtpHost || null,
    smtpPort,
    smtpSecure,
    smtpUser || null,
    smtpPass || null,
    smtpFromEmail || null,
    smtpFromName || null,
    'cloud',
    waPhoneId || null,
    waToken || null,
    waApiVersion,
    waCountry,
    null,
    null,
    checkEmailEnabled ? 1 : 0,
    checkEmailDays,
    checkEmailOnDue ? 1 : 0,
    checkEmailRecipients || null,
    outCheckEmailEnabled ? 1 : 0,
    outCheckEmailDays,
    outCheckEmailOnDue ? 1 : 0,
    outCheckEmailRecipients || null,
    loginRecaptchaEnabled ? 1 : 0,
    loginRecaptchaSiteKey || null,
    loginRecaptchaSecret || null,
    documentArchiveDir || null,
    documentArchiveMaxMb,
  ];

  const updateSql = `UPDATE sys_company_settings SET
         company_name_ar = ?, tax_rate_percent = ?, decimal_places = ?,
         invoice_unit_price_decimal_places = ?, invoice_print_decimal_places = ?,
         invoice_print_unit_price_decimal_places = ?, rows_per_page = ?,
         ui_theme = ?, ui_lang = ?, currency_code = ?,
         address_ar = ?, phone = ?, email = ?, logo_path = ?,
         smtp_host = ?, smtp_port = ?, smtp_secure = ?, smtp_username = ?, smtp_password = ?,
         smtp_from_email = ?, smtp_from_name = ?,
         wa_provider = ?, wa_phone_id = ?, wa_access_token = ?, wa_api_version = ?,
         wa_default_country = ?, wa_bridge_url = ?, wa_bridge_token = ?,
         check_email_enabled = ?, check_email_days_before = ?, check_email_on_due_day = ?,
         check_email_recipients = ?,
         out_check_email_enabled = ?, out_check_email_days_before = ?, out_check_email_on_due_day = ?,
         out_check_email_recipients = ?,
         login_recaptcha_enabled = ?, login_recaptcha_site_key = ?, login_recaptcha_secret_key = ?,
         document_archive_dir = ?, document_archive_max_mb = ?,
         updated_at = NOW()
       WHERE id = 1`;

  try {
    let result = await q(updateSql, params);
    let affected = Number(result && result.affectedRows != null ? result.affectedRows : -1);
    if (affected === 0) {
      await q(
        `INSERT IGNORE INTO sys_company_settings (id, company_name_ar, tax_rate_percent, decimal_places)
         VALUES (1, ?, ?, ?)`,
        [name, taxF.toFixed(3), dec]
      );
      result = await q(updateSql, params);
      affected = Number(result && result.affectedRows != null ? result.affectedRows : 1);
    }
    // تحقّق قراءة سريعة
    const after = await getCompanySettings();
    if (!after) {
      return { ok: false, error: 'تم التحديث لكن تعذر قراءة صف الإعدادات (id=1).' };
    }
    if (String(after.company_name_ar || '') !== name) {
      return {
        ok: false,
        error:
          'قاعدة البيانات لم تعكس الاسم بعد الحفظ. تحقق من صلاحيات مستخدم MySQL على sys_company_settings.',
      };
    }
    try {
      const { invalidatePrintBrand } = require('../lib/printBrand');
      invalidatePrintBrand({
        company_name_ar: after.company_name_ar,
        logo_path: after.logo_path,
        print_watermark_enabled: after.print_watermark_enabled,
      });
    } catch {
      /* ignore */
    }
    try {
      await ensurePrintWatermarkColumn();
      await q(`UPDATE sys_company_settings SET print_watermark_enabled = ? WHERE id = 1`, [
        printWatermarkEnabled ? 1 : 0,
      ]);
      const { invalidatePrintBrand } = require('../lib/printBrand');
      invalidatePrintBrand({
        company_name_ar: after.company_name_ar,
        logo_path: after.logo_path,
        print_watermark_enabled: printWatermarkEnabled ? 1 : 0,
      });
    } catch {
      /* عمود قديم غير موجود */
    }
    return {
      ok: true,
      message: 'تم حفظ الإعدادات.' + (logoNote ? ' —' + logoNote : ''),
    };
  } catch (e) {
    console.error('saveCompanySettings', e.message);
    const msg = String(e.message || '');
    if (/document_archive|Unknown column/i.test(msg)) {
      try {
        // أعمدة الأرشيف قد تكون غير موجودة — احفظ بدونها
        const sqlCore = `UPDATE sys_company_settings SET
           company_name_ar = ?, tax_rate_percent = ?, decimal_places = ?,
           invoice_unit_price_decimal_places = ?, invoice_print_decimal_places = ?,
           invoice_print_unit_price_decimal_places = ?, rows_per_page = ?,
           ui_theme = ?, ui_lang = ?, currency_code = ?,
           address_ar = ?, phone = ?, email = ?, logo_path = ?,
           smtp_host = ?, smtp_port = ?, smtp_secure = ?, smtp_username = ?, smtp_password = ?,
           smtp_from_email = ?, smtp_from_name = ?,
           wa_provider = ?, wa_phone_id = ?, wa_access_token = ?, wa_api_version = ?,
           wa_default_country = ?, wa_bridge_url = ?, wa_bridge_token = ?,
           check_email_enabled = ?, check_email_days_before = ?, check_email_on_due_day = ?,
           check_email_recipients = ?,
           out_check_email_enabled = ?, out_check_email_days_before = ?, out_check_email_on_due_day = ?,
           out_check_email_recipients = ?,
           login_recaptcha_enabled = ?, login_recaptcha_site_key = ?, login_recaptcha_secret_key = ?,
           updated_at = NOW()
         WHERE id = 1`;
        const coreParams = params.slice(0, -2);
        await q(sqlCore, coreParams);
        return {
          ok: true,
          message:
            'تم حفظ الإعدادات (بدون مسار الأرشيف — عمود document_archive غير موجود في قاعدتك).' +
            logoNote,
        };
      } catch (e2) {
        return { ok: false, error: 'تعذر حفظ الإعدادات: ' + e2.message };
      }
    }
    return { ok: false, error: 'تعذر حفظ الإعدادات: ' + e.message };
  }
}

/* ── dashboard accounts ── */
async function syncDashboardAccounts() {
  try {
    await q(
      `INSERT IGNORE INTO sys_dashboard_account (account_id, is_visible)
       SELECT a.id, 0 FROM acc_account a WHERE a.is_active = 1 AND a.is_leaf = 1`
    );
  } catch (e) {
    console.error('syncDashboardAccounts', e.message);
  }
}

async function listDashboardAccountsFull() {
  await syncDashboardAccounts();
  try {
    return await q(
      `SELECT a.id, a.code, a.name_ar, a.account_type,
              COALESCE(d.is_visible, 0) AS is_visible
       FROM acc_account a
       LEFT JOIN sys_dashboard_account d ON d.account_id = a.id
       WHERE a.is_active = 1 AND a.is_leaf = 1
       ORDER BY a.code ASC, a.id ASC`
    );
  } catch {
    return [];
  }
}

async function saveDashboardVisibility(visibleIds) {
  await syncDashboardAccounts();
  const allowed = new Set(
    [].concat(visibleIds || [])
      .map(Number)
      .filter((n) => n > 0)
  );
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    await conn.execute(`UPDATE sys_dashboard_account SET is_visible = 0`);
    for (const id of allowed) {
      await conn.execute(
        `INSERT INTO sys_dashboard_account (account_id, is_visible, updated_at)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE is_visible = 1, updated_at = NOW()`,
        [id]
      );
    }
    await conn.commit();
    return { ok: true, message: 'تم حفظ حسابات الشاشة الرئيسية.' };
  } catch (e) {
    await conn.rollback();
    console.error('saveDashboardVisibility', e.message);
    return { ok: false, error: 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

/* ── tax rates ── */
async function listTaxRates() {
  return q(
    `SELECT id, name_ar, rate_percent, sort_order, is_active FROM sys_tax_rate ORDER BY sort_order ASC, id ASC`
  );
}

async function addTaxRate(payload) {
  const name = String(payload.name_ar || '').trim();
  const rate = Number(String(payload.rate_percent || '0').replace(',', '.'));
  const sort = Number(payload.sort_order || 10) || 10;
  if (!name) return { ok: false, error: 'اسم المعدّل مطلوب.' };
  if (!Number.isFinite(rate) || rate < 0 || rate > 100) {
    return { ok: false, error: 'نسبة الضريبة يجب أن تكون بين 0 و 100.' };
  }
  try {
    await q(
      `INSERT INTO sys_tax_rate (name_ar, rate_percent, sort_order, is_active) VALUES (?,?,?,1)`,
      [name, Number(rate.toFixed(3)), sort]
    );
    return { ok: true, message: 'تمت إضافة معدّل الضريبة.' };
  } catch {
    return { ok: false, error: 'تعذر الإضافة. قد يكون الاسم مكرراً.' };
  }
}

async function saveTaxRatesAll(ratesMap) {
  const updates = [];
  for (const [idStr, row] of Object.entries(ratesMap || {})) {
    const id = Number(idStr);
    if (id < 1 || !row || typeof row !== 'object') continue;
    const name = String(row.name_ar || '').trim();
    const rate = Number(String(row.rate_percent || '0').replace(',', '.'));
    const sort = Number(row.sort_order || 0) || 0;
    const active =
      row.is_active === '1' || row.is_active === 1 || row.is_active === 'on' || row.is_active === true
        ? 1
        : 0;
    if (!name) return { ok: false, error: 'اسم المعدّل مطلوب.' };
    if (!Number.isFinite(rate) || rate < 0 || rate > 100) {
      return { ok: false, error: `نسبة الضريبة غير صالحة («${name}»).` };
    }
    updates.push({ id, name, rate: Number(rate.toFixed(3)), sort, active });
  }
  if (!updates.length) return { ok: false, error: 'لا توجد بيانات للحفظ.' };
  if (!updates.some((u) => u.active === 1)) {
    return { ok: false, error: 'يجب أن يبقى معدّل ضريبة واحد على الأقل نشطاً.' };
  }
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    for (const u of updates) {
      await conn.execute(
        `UPDATE sys_tax_rate SET name_ar=?, rate_percent=?, sort_order=?, is_active=? WHERE id=?`,
        [u.name, u.rate, u.sort, u.active, u.id]
      );
    }
    await conn.commit();
    return { ok: true, message: 'تم حفظ التعديلات.' };
  } catch (e) {
    await conn.rollback();
    return { ok: false, error: 'تعذر الحفظ. قد يكون الاسم مكرراً.' };
  } finally {
    conn.release();
  }
}

async function deleteTaxRate(id) {
  const rid = Number(id);
  if (rid < 1) return { ok: false, error: 'معرّف غير صالح.' };
  const [cnt] = await q(`SELECT COUNT(*) AS c FROM sys_tax_rate`);
  if (Number(cnt?.c || 0) <= 1) return { ok: false, error: 'لا يمكن حذف آخر معدّل ضريبة.' };
  try {
    await q(`DELETE FROM sys_tax_rate WHERE id = ?`, [rid]);
    return { ok: true, message: 'تم حذف المعدّل.' };
  } catch {
    return { ok: false, error: 'تعذر الحذف.' };
  }
}

/* ── backup ── */
function backupRecommendedDir() {
  const parent = path.dirname(PHONE_APP_ROOT);
  return path.join(parent, 'manager_backups');
}

function todayFolderName() {
  const d = new Date();
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  return `${dd}-${mm}-${yyyy}`;
}

async function getBackupSettings() {
  try {
    const rows = await q(`SELECT * FROM sys_backup_settings WHERE id = 1 LIMIT 1`);
    return rows[0] || { backup_dir: '', last_backup_at: null, last_backup_path: '' };
  } catch {
    return { backup_dir: '', last_backup_at: null, last_backup_path: '' };
  }
}

async function saveBackupDir(dir, userId) {
  const bare = String(dir || '').trim();
  if (!bare) return { ok: false, error: 'مسار المجلد مطلوب.' };
  const abs = path.resolve(bare);
  try {
    fs.mkdirSync(abs, { recursive: true });
  } catch (e) {
    return { ok: false, error: 'تعذر إنشاء/الوصول للمجلد: ' + (e.message || '') };
  }
  try {
    await q(
      `INSERT INTO sys_backup_settings (id, backup_dir, updated_by, updated_at)
       VALUES (1, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE backup_dir = VALUES(backup_dir), updated_by = VALUES(updated_by), updated_at = NOW()`,
      [abs, userId || null]
    );
    return { ok: true, message: 'تم حفظ مجلد النسخ الاحتياطي.', path: abs };
  } catch (e) {
    return { ok: false, error: 'تعذر الحفظ في قاعدة البيانات: ' + e.message };
  }
}

function runMysqldump(outFile) {
  return new Promise((resolve) => {
    const args = [
      `-h${config.db.host}`,
      `-P${config.db.port}`,
      `-u${config.db.user}`,
      `--default-character-set=utf8mb4`,
      `--single-transaction`,
      `--routines`,
      `--triggers`,
      config.db.database,
    ];
    if (config.db.password) args.splice(3, 0, `-p${config.db.password}`);
    const out = fs.createWriteStream(outFile);
    const proc = spawn('mysqldump', args, { windowsHide: true });
    let err = '';
    proc.stdout.pipe(out);
    proc.stderr.on('data', (d) => {
      err += d.toString();
    });
    proc.on('error', () => resolve(false));
    proc.on('close', (code) => {
      out.end();
      resolve(code === 0 && fs.existsSync(outFile) && fs.statSync(outFile).size > 0);
    });
  });
}

async function dumpViaNode(outFile) {
  const tables = await q(`SHOW TABLES`);
  const key = `Tables_in_${config.db.database}`;
  const names = tables.map((r) => r[key] || Object.values(r)[0]).filter(Boolean);
  const stream = fs.createWriteStream(outFile, { encoding: 'utf8' });
  stream.write(`-- Hypex Node backup ${new Date().toISOString()}\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n`);
  for (const t of names) {
    try {
      const [createRows] = await db.getPool().query(`SHOW CREATE TABLE \`${t}\``);
      const createSql = createRows[0]['Create Table'] || createRows[0]['Create View'];
      if (!createSql) continue;
      stream.write(`DROP TABLE IF EXISTS \`${t}\`;\n${createSql};\n\n`);
      const [rows] = await db.getPool().query(`SELECT * FROM \`${t}\``);
      if (!rows.length) continue;
      for (const row of rows) {
        const cols = Object.keys(row);
        const vals = cols.map((c) => {
          const v = row[c];
          if (v === null || v === undefined) return 'NULL';
          if (Buffer.isBuffer(v)) return `X'${v.toString('hex')}'`;
          if (v instanceof Date) return `'${v.toISOString().slice(0, 19).replace('T', ' ')}'`;
          if (typeof v === 'number') return String(v);
          return `'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
        });
        stream.write(
          `INSERT INTO \`${t}\` (\`${cols.join('`,`')}\`) VALUES (${vals.join(',')});\n`
        );
      }
      stream.write('\n');
    } catch (e) {
      stream.write(`-- skip ${t}: ${e.message}\n`);
    }
  }
  stream.write('SET FOREIGN_KEY_CHECKS=1;\n');
  await new Promise((resolve) => stream.end(resolve));
  return fs.existsSync(outFile) && fs.statSync(outFile).size > 0;
}

function zipAppFiles(zipFile) {
  return new Promise((resolve) => {
    const root = PHONE_APP_ROOT;
    const excludes = [
      'hypex-node/node_modules',
      'node_modules',
      '.git',
      'backups',
      'manager_backups',
    ];
    // Windows tar (bsdtar)
    const args = ['-a', '-c', '-f', zipFile, '-C', root];
    // include common folders; if too heavy ok
    for (const ex of excludes) {
      args.push(`--exclude=${ex}`);
    }
    args.push('.');
    const proc = spawn('tar', args, { windowsHide: true, shell: false });
    proc.on('error', () => resolve(false));
    proc.on('close', (code) => {
      resolve(code === 0 && fs.existsSync(zipFile) && fs.statSync(zipFile).size > 0);
    });
  });
}

async function runBackup(userId) {
  const settings = await getBackupSettings();
  let root = String(settings.backup_dir || '').trim();
  if (!root) {
    return { ok: false, error: 'حدّد مجلد النسخ الاحتياطي أولاً ثم احفظ المسار.' };
  }
  root = path.resolve(root);
  try {
    fs.mkdirSync(root, { recursive: true });
  } catch (e) {
    return { ok: false, error: 'تعذر الوصول لمجلد النسخ: ' + e.message };
  }
  const dateFolder = todayFolderName();
  const targetDir = path.join(root, dateFolder);
  try {
    fs.mkdirSync(targetDir, { recursive: true });
  } catch (e) {
    return { ok: false, error: 'تعذر إنشاء مجلد اليوم: ' + e.message };
  }
  const dbFile = path.join(targetDir, 'database.sql');
  const zipFile = path.join(targetDir, 'system_files.zip');
  let dumpOk = await runMysqldump(dbFile);
  if (!dumpOk) dumpOk = await dumpViaNode(dbFile);
  if (!dumpOk) return { ok: false, error: 'تعذر أخذ نسخة قاعدة البيانات.' };

  await zipAppFiles(zipFile);
  fs.writeFileSync(
    path.join(targetDir, 'README.txt'),
    `Hypex backup\r\nfolder: ${dateFolder}\r\ncreated: ${new Date().toISOString()}\r\n`,
    'utf8'
  );
  await q(
    `UPDATE sys_backup_settings SET last_backup_at = NOW(), last_backup_path = ?, updated_by = ? WHERE id = 1`,
    [targetDir, userId || null]
  );
  return {
    ok: true,
    message: `تم إنشاء النسخة الاحتياطية في المجلد ${dateFolder}.`,
    path: targetDir,
  };
}

function listRecentBackups(settings) {
  const root = String(settings.backup_dir || '').trim();
  if (!root || !fs.existsSync(root)) return [];
  try {
    return fs
      .readdirSync(root, { withFileTypes: true })
      .filter((d) => d.isDirectory())
      .map((d) => {
        const full = path.join(root, d.name);
        const st = fs.statSync(full);
        return { name: d.name, path: full, mtime: st.mtime };
      })
      .sort((a, b) => b.mtime - a.mtime)
      .slice(0, 8);
  } catch {
    return [];
  }
}

const ACCOUNT_TYPE_AR = {
  asset: 'أصول',
  liability: 'خصوم',
  equity: 'حقوق ملكية',
  revenue: 'إيرادات',
  expense: 'مصروفات',
};

/* ── e-invoice / JoFotara ── */
const INVOICE_CASH_OPTS = {
  '011': 'فاتورة مبيعات عامة نقدية محلية (011)',
  '1111': 'فاتورة مبيعات عامة نقدية محلية (1111)',
  '012': 'فاتورة مبيعات عامة نقدية تصدير (012)',
  '112': 'فاتورة مبيعات عامة نقدية تصدير (112)',
  '212': 'فاتورة مبيعات عامة نقدية تصدير (212)',
};
const INVOICE_DEBIT_OPTS = {
  '021': 'فاتورة مبيعات عامة ذمم محلية (021)',
  '121': 'فاتورة مبيعات عامة ذمم محلية (121)',
  '022': 'فاتورة مبيعات عامة ذمم تصدير (022)',
  '122': 'فاتورة مبيعات عامة ذمم تصدير (122)',
  '222': 'فاتورة مبيعات عامة ذمم تصدير (222)',
};
const DEFAULT_JOFOTARA_URL = 'https://backend.jofotara.gov.jo/core/invoices/';

function einvoiceExtCfg() {
  // نفس افتراضيات config/einvoice.php — تُغيَّر عبر env
  return {
    admin: {
      host: process.env.EINV_ADMIN_HOST || config.db.host,
      port: Number(process.env.EINV_ADMIN_PORT || config.db.port) || 3306,
      database: process.env.EINV_ADMIN_DB || 'admin',
      user: process.env.EINV_ADMIN_USER || config.db.user,
      password: process.env.EINV_ADMIN_PASS != null ? process.env.EINV_ADMIN_PASS : config.db.password,
      prefix: process.env.EINV_ADMIN_PREFIX || 'glx_',
    },
    galaxy: {
      host: process.env.EINV_GALAXY_HOST || config.db.host,
      port: Number(process.env.EINV_GALAXY_PORT || config.db.port) || 3306,
      database: process.env.EINV_GALAXY_DB || 'galaxy',
      user: process.env.EINV_GALAXY_USER || config.db.user,
      password: process.env.EINV_GALAXY_PASS != null ? process.env.EINV_GALAXY_PASS : config.db.password,
      prefix: process.env.EINV_GALAXY_PREFIX || 'glx_',
    },
  };
}

async function getEinvoiceSettings() {
  try {
    const rows = await q(`SELECT * FROM sys_einvoice_settings WHERE id = 1 LIMIT 1`);
    if (rows[0]) return rows[0];
    await q(`INSERT IGNORE INTO sys_einvoice_settings (id, taxes_type, invoice_cash, invoice_debit, jofotara_api_url)
             VALUES (1, 2, '011', '021', ?)`, [DEFAULT_JOFOTARA_URL]);
    const again = await q(`SELECT * FROM sys_einvoice_settings WHERE id = 1 LIMIT 1`);
    return again[0] || { id: 1, taxes_type: 2, invoice_cash: '011', invoice_debit: '021', jofotara_api_url: DEFAULT_JOFOTARA_URL };
  } catch (e) {
    console.error('getEinvoiceSettings', e.message);
    return { id: 1 };
  }
}

async function saveEinvoiceSettings(payload) {
  const companyName = String(payload.company_name || '').trim();
  const vatNo = String(payload.vat_no || '').trim();
  const gstNo = String(payload.gst_no || '').trim();
  const clientId = String(payload.client_id || '').trim();
  const secretKey = String(payload.secret_key || '').trim();
  if (!companyName) return { ok: false, error: 'اسم الشركة مطلوب.' };
  if (!vatNo || !gstNo) return { ok: false, error: 'الرقم الضريبي ورقم GST مطلوبان.' };
  if (!clientId || !secretKey) return { ok: false, error: 'Client ID و Secret Key مطلوبان.' };

  const taxesType = Number(payload.taxes_type) === 1 ? 1 : 2;
  const cash = String(payload.invoice_cash || '011').trim() || '011';
  const debit = String(payload.invoice_debit || '021').trim() || '021';
  let apiUrl = String(payload.jofotara_api_url || DEFAULT_JOFOTARA_URL).trim();
  if (!apiUrl) apiUrl = DEFAULT_JOFOTARA_URL;

  try {
    await getEinvoiceSettings();
    await q(
      `UPDATE sys_einvoice_settings SET
         company_name=?, trade_name=?, vat_no=?, gst_no=?,
         company_email=?, company_phone=?, address=?, city=?,
         taxes_type=?, invoice_cash=?, invoice_debit=?,
         client_id=?, secret_key=?, admin_email=?, jofotara_api_url=?, notes=?,
         updated_at=NOW()
       WHERE id = 1`,
      [
        companyName,
        String(payload.trade_name || '').trim() || null,
        vatNo || null,
        gstNo || null,
        String(payload.company_email || '').trim() || null,
        String(payload.company_phone || '').trim() || null,
        String(payload.address || '').trim() || null,
        String(payload.city || '').trim() || null,
        taxesType,
        cash,
        debit,
        clientId || null,
        secretKey || null,
        String(payload.admin_email || '').trim() || null,
        apiUrl,
        String(payload.notes || '').trim() || null,
      ]
    );
    return { ok: true, message: 'تم حفظ إعدادات الفوترة بنجاح.' };
  } catch (e) {
    console.error('saveEinvoiceSettings', e.message);
    return { ok: false, error: 'تعذر الحفظ: ' + e.message };
  }
}

async function testEinvoiceConnection() {
  const settings = await getEinvoiceSettings();
  const clientId = String(settings.client_id || '').trim();
  const secretKey = String(settings.secret_key || '').trim();
  let apiUrl = String(settings.jofotara_api_url || DEFAULT_JOFOTARA_URL).trim();
  if (!apiUrl.endsWith('/')) apiUrl += '/';

  const out = {
    ok: false,
    level: 'error',
    title: 'فشل الاختبار',
    message: '',
    http_code: 0,
    raw: null,
    url: apiUrl,
  };
  if (!clientId || !secretKey) {
    out.title = 'بيانات اعتماد ناقصة';
    out.message = 'Client ID و Secret Key مطلوبان لإجراء الاختبار. عبّئهما في الإعدادات أولاً.';
    return out;
  }
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 45000);
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Client-Id': clientId,
        'Secret-Key': secretKey,
      },
      body: JSON.stringify({ invoice: '' }),
      signal: controller.signal,
    });
    clearTimeout(timer);
    const text = await res.text();
    out.http_code = res.status;
    out.raw = text.slice(0, 800);
    const body = text.toLowerCase();

    if (res.status === 200 || res.status === 201) {
      out.ok = true;
      out.level = 'success';
      out.title = 'الاتصال ناجح';
      out.message = 'تم الوصول إلى JoFotara وبيانات الاعتماد مقبولة.';
    } else if (res.status === 400) {
      // غالباً بنية فاتورة ناقصة → الاعتماد غالباً سليم
      out.ok = true;
      out.level = 'success';
      out.title = 'الاتصال صحيح';
      out.message =
        'الخادم ردّ بخطأ في بنية الفاتورة (متوقع بالاختبار الفارغ). هذا يعني أن الرابط والاعتماد يعملان.';
    } else if (res.status === 401 || res.status === 403) {
      out.title = 'اعتماد مرفوض';
      out.message = 'Client ID أو Secret Key غير صحيحين (HTTP ' + res.status + ').';
    } else if (res.status === 404 || res.status >= 500) {
      out.title = 'مشكلة في الخدمة/الرابط';
      out.message = 'HTTP ' + res.status + ' — تحقق من رابط API أو حالة الخدمة.';
    } else {
      out.title = 'رد غير متوقع';
      out.message = 'HTTP ' + res.status + (body ? ': ' + text.slice(0, 200) : '');
    }
  } catch (e) {
    out.title = 'تعذّر الوصول إلى الخادم';
    out.message = e.name === 'AbortError' ? 'انتهت مهلة الاتصال.' : String(e.message || e);
  }
  return out;
}

async function queryExternalSettings(kind) {
  const mysql = require('mysql2/promise');
  const all = einvoiceExtCfg();
  const cfg = all[kind];
  if (!cfg) return { ok: false, error: 'مصدر غير معروف.' };
  let conn;
  try {
    conn = await mysql.createConnection({
      host: cfg.host,
      port: cfg.port,
      user: cfg.user,
      password: cfg.password,
      database: cfg.database,
      charset: 'utf8mb4',
    });
    const pfx = cfg.prefix || 'glx_';
    const [rows] = await conn.execute(
      `SELECT client_id, secret_key, invoice_cash, invoice_debit, taxes_type, admin_email
       FROM \`${pfx}settings\` WHERE id = 1 LIMIT 1`
    );
    let biller = null;
    try {
      const [b] = await conn.execute(
        `SELECT name, company, vat_no, gst_no, email, phone, address, city
         FROM \`${pfx}companies\` WHERE group_name = 'biller' ORDER BY id ASC LIMIT 1`
      );
      biller = b[0] || null;
    } catch {
      /* optional */
    }
    return { ok: true, settings: rows[0] || null, biller };
  } catch (e) {
    return {
      ok: false,
      error:
        (kind === 'admin' ? 'تعذر الاتصال بقاعدة admin' : 'تعذر الاتصال بقاعدة Galaxy') +
        ': ' +
        e.message,
    };
  } finally {
    if (conn) await conn.end().catch(() => {});
  }
}

async function importEinvoiceFromAdmin() {
  const ext = await queryExternalSettings('admin');
  if (!ext.ok) return { ok: false, error: ext.error };
  if (!ext.settings) return { ok: false, error: 'لم تُعثر على إعدادات في admin.' };
  const cur = await getEinvoiceSettings();
  const s = ext.settings;
  const b = ext.biller || {};
  const data = {
    ...cur,
    client_id: s.client_id || cur.client_id,
    secret_key: s.secret_key || cur.secret_key,
    invoice_cash: s.invoice_cash || cur.invoice_cash || '011',
    invoice_debit: s.invoice_debit || cur.invoice_debit || '021',
    taxes_type: s.taxes_type != null ? s.taxes_type : cur.taxes_type,
    admin_email: s.admin_email || cur.admin_email,
  };
  if (b.name) data.company_name = b.name;
  if (b.company) data.trade_name = b.company;
  if (b.vat_no) data.vat_no = b.vat_no;
  if (b.gst_no) data.gst_no = b.gst_no;
  if (b.email) data.company_email = b.email;
  if (b.phone) data.company_phone = b.phone;
  if (b.address) data.address = b.address;
  if (b.city) data.city = b.city;
  // save may fail validation if company empty after import
  if (!String(data.company_name || '').trim()) data.company_name = cur.company_name || 'مستورد';
  if (!String(data.vat_no || '').trim()) data.vat_no = cur.vat_no || '-';
  if (!String(data.gst_no || '').trim()) data.gst_no = cur.gst_no || '-';
  if (!String(data.client_id || '').trim() || !String(data.secret_key || '').trim()) {
    return { ok: false, error: 'admin لا يحتوي Client ID / Secret كاملين.' };
  }
  const r = await saveEinvoiceSettings(data);
  if (!r.ok) return r;
  return { ok: true, message: 'تم استيراد إعدادات الفوترة من نظام admin بنجاح.' };
}

async function copyEinvoiceFromGalaxy() {
  const ext = await queryExternalSettings('galaxy');
  if (!ext.ok) return { ok: false, error: ext.error };
  if (!ext.settings) return { ok: false, error: 'لم تُعثر على بيانات اعتماد في Galaxy.' };
  const cur = await getEinvoiceSettings();
  const data = {
    ...cur,
    client_id: ext.settings.client_id || '',
    secret_key: ext.settings.secret_key || '',
    company_name: cur.company_name || 'Hypex',
    vat_no: cur.vat_no || '-',
    gst_no: cur.gst_no || '-',
  };
  if (!data.client_id || !data.secret_key) {
    return { ok: false, error: 'Galaxy لا يحتوي Client ID / Secret.' };
  }
  const r = await saveEinvoiceSettings(data);
  if (!r.ok) return r;
  return { ok: true, message: 'تم نسخ بيانات الاعتماد من Galaxy.' };
}

async function verifyEinvoiceCredentials() {
  const cur = await getEinvoiceSettings();
  const mask = (k) => {
    const s = String(k || '');
    return s ? s.slice(0, 12) + '… (' + s.length + ')' : '—';
  };
  const rows = [
    { src: 'النظام الحالي', client: cur.client_id || '', secret: mask(cur.secret_key) },
  ];
  const adm = await queryExternalSettings('admin');
  if (adm.ok && adm.settings) {
    rows.push({
      src: 'admin',
      client: adm.settings.client_id || '',
      secret: mask(adm.settings.secret_key),
    });
  } else if (!adm.ok) {
    rows.push({ src: 'admin', client: 'خطأ', secret: adm.error });
  }
  const gal = await queryExternalSettings('galaxy');
  if (gal.ok && gal.settings) {
    rows.push({
      src: 'Galaxy',
      client: gal.settings.client_id || '',
      secret: mask(gal.settings.secret_key),
    });
  } else if (!gal.ok) {
    rows.push({ src: 'Galaxy', client: 'خطأ', secret: gal.error });
  }
  let matchNote = 'تحقق من تطابق بيانات الاعتماد بين الأنظمة.';
  if (adm.ok && adm.settings) {
    const same =
      String(cur.client_id || '') === String(adm.settings.client_id || '') &&
      String(cur.secret_key || '') === String(adm.settings.secret_key || '');
    matchNote = same
      ? 'البيانات متطابقة مع admin.'
      : 'البيانات غير متطابقة مع admin.';
  }
  return { rows, matchNote };
}

module.exports = {
  listGroups,
  getGroup,
  saveGroup,
  listPermissionsMatrix,
  savePermissions,
  listActiveSessions,
  killSession,
  getCompanySettings,
  listActiveTaxRates,
  saveCompanySettings,
  currencyCatalogList,
  archiveRecommendedDir,
  CURRENCY_CATALOG,
  listDashboardAccountsFull,
  saveDashboardVisibility,
  listTaxRates,
  addTaxRate,
  saveTaxRatesAll,
  deleteTaxRate,
  backupRecommendedDir,
  todayFolderName,
  getBackupSettings,
  saveBackupDir,
  runBackup,
  listRecentBackups,
  ACCOUNT_TYPE_AR,
  WIN_ACTIVE_SEC,
  MOB_ACTIVE_SEC,
  INVOICE_CASH_OPTS,
  INVOICE_DEBIT_OPTS,
  DEFAULT_JOFOTARA_URL,
  getEinvoiceSettings,
  saveEinvoiceSettings,
  testEinvoiceConnection,
  importEinvoiceFromAdmin,
  copyEinvoiceFromGalaxy,
  verifyEinvoiceCredentials,
};
