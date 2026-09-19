'use strict';

/**
 * أيقونات SVG خفيفة للشريط الجانبي (2027)
 */
const ICONS = {
  main: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg>`,
  sales: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>`,
  customers: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5.5 19.5a6.5 6.5 0 0 1 13 0"/></svg>`,
  suppliers: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20h18"/><path d="M5 20V9l5-4 4 3 5-2v14"/><path d="M9 20v-5h4v5"/></svg>`,
  sales_reps: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M2.5 19a6.5 6.5 0 0 1 13 0"/><circle cx="17.5" cy="9.5" r="2.25"/><path d="M16 19c1.1-2.4 2.9-3.5 5-3.5"/></svg>`,
  purchases: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h2l2.2 10.2a1 1 0 0 0 1 .8h7.9a1 1 0 0 0 1-.8L19.5 8H7"/><circle cx="10" cy="19.5" r="1.3"/><circle cx="16.5" cy="19.5" r="1.3"/></svg>`,
  inventory: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7z"/><path d="M12 12 20 7.5M12 12v8M12 12 4 7.5"/></svg>`,
  accounting: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18"/><path d="M7 8c0-2 2.2-3.5 5-3.5s5 1.5 5 3.5-2.2 3.5-5 3.5-5 1.5-5 3.5 2.2 3.5 5 3.5 5-1.5 5-3.5"/></svg>`,
  hr: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8.5" cy="8" r="2.8"/><circle cx="16" cy="9" r="2.4"/><path d="M2.8 19a5.7 5.7 0 0 1 11.4 0"/><path d="M14 19c.7-2.7 2.4-4 4.8-4 1.1 0 2 .3 2.8.8"/></svg>`,
  system: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.6.9 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>`,
  mobile: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18h2"/></svg>`,
  favorites: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3.7 2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 16l-4.8 2.6.9-5.4L4.2 9.4l5.4-.8L12 3.7z"/></svg>`,
  backup: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V7"/><path d="m8.5 10.5 3.5-3.5 3.5 3.5"/><path d="M5 16.5v2A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-2"/></svg>`,
};

/** بادئات المسارات لتظليل العنصر النشط */
const PATH_PREFIXES = {
  main: ['/app'],
  sales: ['/hub/sales', '/sales'],
  customers: ['/hub/customers', '/customers'],
  suppliers: ['/hub/suppliers', '/suppliers'],
  sales_reps: ['/hub/sales-reps', '/sales-reps'],
  purchases: ['/hub/purchases', '/purchases'],
  inventory: ['/hub/inventory', '/inventory'],
  accounting: ['/hub/accounting', '/accounting'],
  hr: ['/hub/hr', '/hr'],
  system: ['/hub/system', '/system'],
  mobile: ['/hub/mobile', '/mobile'],
  favorites: ['/hub/favorites'],
  backup: ['/system/backup'],
};

function iconFor(id) {
  return ICONS[id] || ICONS.main;
}

function isPathActive(itemId, href, activePath) {
  const path = String(activePath || '');
  if (!path) return false;
  if (href === '/app') {
    return path === '/' || path === '/app';
  }
  if (itemId === 'backup') {
    return path.startsWith('/system/backup') || path.includes('backup');
  }
  if (itemId === 'system') {
    // backup له بند منفصل
    if (path.startsWith('/system/backup')) return false;
  }
  const prefixes = PATH_PREFIXES[itemId] || (href ? [href] : []);
  for (const p of prefixes) {
    if (!p) continue;
    if (path === p || path.startsWith(p + '/')) return true;
  }
  if (href && (path === href || path.startsWith(href + '/'))) return true;
  return false;
}

module.exports = { iconFor, isPathActive, PATH_PREFIXES, ICONS };
