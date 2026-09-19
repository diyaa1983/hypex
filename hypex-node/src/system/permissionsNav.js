'use strict';

/**
 * بناء شجرة قوائم الصلاحيات من config/nav_menu.php
 * (نفس منطق تجميع panels في modules/users/permissions.php).
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const APP_ROOT = path.resolve(__dirname, '..', '..', '..');
const NAV_PATH = path.join(APP_ROOT, 'config', 'nav_menu.php');
const ACTION_PATH = path.join(APP_ROOT, 'config', 'action_permissions.php');

let cache = { mtimeMs: 0, data: null };
let actionCache = { mtimeMs: 0, data: null };

function phpBin() {
  for (const c of [
    process.env.PHP_BIN,
    'C:\\xampp\\php\\php.exe',
    'C:\\xampp\\php\\php',
    'php',
  ]) {
    if (!c) continue;
    if (c === 'php' || fs.existsSync(c)) return c;
  }
  return 'php';
}

function loadNavMenu() {
  let mtimeMs = 0;
  try {
    mtimeMs = fs.statSync(NAV_PATH).mtimeMs;
  } catch {
    return { domains: [] };
  }
  if (cache.data && cache.mtimeMs === mtimeMs) return cache.data;

  const navUnix = NAV_PATH.replace(/\\/g, '/');
  const phpCode = `echo json_encode(require ${JSON.stringify(navUnix)}, JSON_UNESCAPED_UNICODE);`;
  try {
    const out = execFileSync(phpBin(), ['-r', phpCode], {
      encoding: 'utf8',
      maxBuffer: 12 * 1024 * 1024,
      windowsHide: true,
    });
    cache = { mtimeMs, data: JSON.parse(out) };
  } catch (e) {
    console.error('permissionsNav.loadNavMenu', e.message || e);
    cache = { mtimeMs, data: { domains: [] } };
  }
  return cache.data;
}

function loadActionCatalog() {
  let mtimeMs = 0;
  try {
    mtimeMs = fs.statSync(ACTION_PATH).mtimeMs;
  } catch {
    return { groups: [] };
  }
  if (actionCache.data && actionCache.mtimeMs === mtimeMs) return actionCache.data;

  const phpUnix = ACTION_PATH.replace(/\\/g, '/');
  const phpCode = `echo json_encode(require ${JSON.stringify(phpUnix)}, JSON_UNESCAPED_UNICODE);`;
  try {
    const out = execFileSync(phpBin(), ['-r', phpCode], {
      encoding: 'utf8',
      maxBuffer: 4 * 1024 * 1024,
      windowsHide: true,
    });
    actionCache = { mtimeMs, data: JSON.parse(out) };
  } catch (e) {
    console.error('permissionsNav.loadActionCatalog', e.message || e);
    actionCache = { mtimeMs, data: { groups: [] } };
  }
  return actionCache.data;
}

function actionItemsFlat() {
  const out = [];
  for (const g of loadActionCatalog().groups || []) {
    for (const item of g.items || []) {
      if (item && item.code) out.push(item);
    }
  }
  return out;
}

function permCodeFromItem(it) {
  if (!it || typeof it !== 'object') return '';
  const code = String(it.code || '').trim();
  if (code) return code;
  return String(it.r || '').trim();
}

function kindFor(code, typeByCode) {
  const c = String(code || '');
  if (c.startsWith('action_') || c === 'sales_send_einvoice') return 'action';
  const type = String(typeByCode[c] || '');
  if (type === 'dashboard' || c.startsWith('dashboard_kpi_') || c.startsWith('dashboard_panel_')) {
    return 'dashboard';
  }
  if (type === 'report' || c.startsWith('report_')) return 'report';
  return type || 'screen';
}

/** kind used for type filter radios */
function filterKind(kind) {
  return kind === 'dashboard' ? 'screen' : kind;
}

function typeLabelAr(kind) {
  switch (kind) {
    case 'action':
      return 'إجراء';
    case 'report':
      return 'تقرير';
    case 'dashboard':
      return 'مؤشر';
    default:
      return 'شاشة';
  }
}

/**
 * @param {Array<{id:number,code:string,name_ar:string,screen_type:string}>} screens
 * @param {{ isMobile?: boolean }} [opts]
 */
function buildPermissionPanels(screens, opts = {}) {
  const isMobile = !!opts.isMobile;
  const idByCode = Object.create(null);
  const nameByCode = Object.create(null);
  const typeByCode = Object.create(null);
  for (const s of screens || []) {
    const code = String(s.code || '');
    if (!code) continue;
    idByCode[code] = Number(s.id);
    nameByCode[code] = String(s.name_ar || code);
    typeByCode[code] = String(s.screen_type || 'screen');
  }

  const shown = new Set();
  const panels = [];

  const flatActions = isMobile ? [] : actionItemsFlat();

  function actionsForScreenCodes(screenCodes) {
    const set = new Set((screenCodes || []).filter(Boolean));
    const out = [];
    for (const actionItem of flatActions) {
      const code = String(actionItem.code || '');
      if (!code || !idByCode[code] || shown.has(code)) continue;
      const inherit = Array.isArray(actionItem.inherit_from) ? actionItem.inherit_from : [];
      let primary = '';
      for (const parent of inherit) {
        const p = String(parent || '').trim();
        if (p && idByCode[p]) {
          primary = p;
          break;
        }
      }
      if (!primary || !set.has(primary)) continue;
      out.push({
        id: idByCode[code],
        code,
        label: String(actionItem.name_ar || nameByCode[code] || code).trim() || code,
        kind: 'action',
        filterKind: 'action',
        typeLabel: typeLabelAr('action'),
      });
      shown.add(code);
    }
    return out;
  }

  function appendItems(navItems) {
    const items = [];
    const screenCodesInPanel = [];
    for (const it of navItems || []) {
      const code = permCodeFromItem(it);
      if (!code || !idByCode[code] || shown.has(code)) continue;
      if (isMobile && !code.startsWith('m_')) continue;
      const kind = kindFor(code, typeByCode);
      items.push({
        id: idByCode[code],
        code,
        label: String(it.label || nameByCode[code] || code).trim() || code,
        kind,
        filterKind: filterKind(kind),
        typeLabel: typeLabelAr(kind),
      });
      screenCodesInPanel.push(code);
      shown.add(code);
    }
    if (!isMobile) {
      items.push(...actionsForScreenCodes(screenCodesInPanel));
    }
    return items;
  }

  function walk(subgroups, domainId, domainTitle, idPrefix) {
    for (const sg of subgroups || []) {
      if (!sg || typeof sg !== 'object') continue;
      let sgId = String(sg.id || '').trim();
      if (!sgId) sgId = 'sg' + panels.length;
      const panelId = idPrefix + '__' + sgId;
      const nested = sg.subgroups || [];
      if (Array.isArray(nested) && nested.length) {
        walk(nested, domainId, domainTitle, panelId);
        continue;
      }
      const items = appendItems(sg.items || []);
      if (!items.length) continue;
      panels.push({
        id: panelId,
        domainId: String(domainId || ''),
        domainTitle: String(domainTitle || domainId || ''),
        title: String(sg.title || sgId),
        kind: 'menu',
        items,
      });
    }
  }

  const nav = loadNavMenu();
  for (const block of nav.domains || []) {
    const domainId = String(block.id || '');
    if (isMobile && domainId !== 'mobile') continue;
    walk(
      block.subgroups || [],
      domainId,
      String(block.title || domainId),
      domainId
    );
  }

  const leftoverReports = [];
  const leftoverScreens = [];
  for (const s of screens || []) {
    const code = String(s.code || '');
    if (!code || shown.has(code)) continue;
    if (isMobile && !code.startsWith('m_')) continue;
    if (!idByCode[code]) continue;
    const kind = kindFor(code, typeByCode);
    if (kind === 'action') continue;
    const row = {
      id: idByCode[code],
      code,
      label: nameByCode[code] || code,
      kind,
      filterKind: filterKind(kind),
      typeLabel: typeLabelAr(kind),
    };
    if (kind === 'report') leftoverReports.push(row);
    else leftoverScreens.push(row);
  }

  if (!isMobile) {
    for (const actionGroup of loadActionCatalog().groups || []) {
      const title = String(actionGroup.title || 'الإجراءات');
      const items = [];
      for (const actionItem of actionGroup.items || []) {
        const code = String(actionItem.code || '');
        if (!code || !idByCode[code] || shown.has(code)) continue;
        items.push({
          id: idByCode[code],
          code,
          label: String(actionItem.name_ar || nameByCode[code] || code).trim() || code,
          kind: 'action',
          filterKind: 'action',
          typeLabel: typeLabelAr('action'),
        });
        shown.add(code);
      }
      if (!items.length) continue;
      panels.push({
        id: 'actions__' + title,
        domainId: 'actions',
        domainTitle: 'صلاحيات الإجراءات',
        title,
        kind: 'actions',
        items,
      });
    }
  }

  if (leftoverReports.length) {
    panels.push({
      id: 'extras__reports',
      domainId: 'extras',
      domainTitle: 'شاشات وتقارير إضافية',
      title: 'تقارير إضافية',
      kind: 'extras',
      items: leftoverReports,
    });
  }
  if (leftoverScreens.length) {
    panels.push({
      id: 'extras__screens',
      domainId: 'extras',
      domainTitle: 'شاشات وتقارير إضافية',
      title: 'شاشات إضافية',
      kind: 'extras',
      items: leftoverScreens,
    });
  }

  /** @type {Record<string, { title: string, nodes: Array<{id:string,title:string,count:number}> }>} */
  const treeByDomain = {};
  for (const panel of panels) {
    const dom = panel.domainId || 'other';
    if (!treeByDomain[dom]) {
      treeByDomain[dom] = { title: panel.domainTitle || dom, nodes: [] };
    }
    treeByDomain[dom].nodes.push({
      id: panel.id,
      title: panel.title,
      count: panel.items.length,
    });
  }

  return {
    panels,
    treeByDomain,
    firstPanelId: panels[0] ? panels[0].id : '',
  };
}

module.exports = {
  loadNavMenu,
  loadActionCatalog,
  actionItemsFlat,
  buildPermissionPanels,
  typeLabelAr,
};
