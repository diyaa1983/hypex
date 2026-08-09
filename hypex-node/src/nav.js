'use strict';

/**
 * شريط جانبي مثل النظام القديم: مجالات فقط (بدون «جميع الشاشات»)
 */
const auth = require('./auth');
const { DOMAIN_CATALOGS, resolveScreen } = require('./lib/screenMap');
const db = require('./db');

function domainVisible(user, domainCatalog) {
  if (user.is_admin) return true;
  if (domainCatalog.id === 'main') return true;
  return domainCatalog.catalog.some((g) =>
    g.items.some((it) => auth.userCan(user, it.r) || String(it.r).startsWith('dashboard_'))
  );
}

/** قائمة الشريط — متزامن (مثل PHP) */
function buildSidebar(user) {
  const items = [];
  for (const d of DOMAIN_CATALOGS) {
    if (!domainVisible(user, d)) continue;
    items.push({
      id: d.id,
      title: d.title,
      icon: d.icon,
      path: d.hub,
      isDomain: true,
    });
  }
  items.push({
    id: 'favorites',
    title: 'المفضلة',
    icon: '⭐',
    path: '/hub/favorites',
    isDomain: true,
  });
  if (auth.userCan(user, 'system_backup') || user.is_admin) {
    const backup = resolveScreen('system_backup');
    items.push({
      id: 'backup',
      title: 'نسخة احتياطية',
      icon: '💾',
      path: backup?.path || '/system/backup',
      isDomain: true,
      r: 'system_backup',
    });
  }
  return items;
}

function domainHubContent(user, domainId) {
  const domain = DOMAIN_CATALOGS.find((d) => d.id === domainId);
  if (!domain) return null;
  const groups = domain.catalog
    .map((g) => {
      const items = g.items.filter(
        (it) =>
          user.is_admin ||
          auth.userCan(user, it.r) ||
          String(it.r).startsWith('dashboard_')
      );
      return { title: g.title, items };
    })
    .filter((g) => g.items.length > 0);
  return {
    id: domain.id,
    title: domain.title,
    icon: domain.icon,
    hub: domain.hub,
    groups,
  };
}

async function favoritesHubContent(user) {
  let codes = [];
  try {
    const rows = await db.query(
      `SELECT screen_code FROM sys_user_favorite WHERE user_id = ?
       ORDER BY sort_order ASC, id ASC`,
      [user.id]
    );
    codes = rows.map((r) => String(r.screen_code));
  } catch {
    codes = [];
  }
  const items = [];
  for (const code of codes) {
    const sc = resolveScreen(code);
    if (!sc) continue;
    if (!user.is_admin && !auth.userCan(user, sc.r) && sc.r !== 'dashboard') continue;
    items.push(sc);
  }
  return {
    id: 'favorites',
    title: 'المفضلة',
    icon: '⭐',
    hub: '/hub/favorites',
    groups: [{ title: 'الشاشات المفضلة', items }],
    emptyHint:
      items.length === 0
        ? 'لا توجد شاشات في المفضلة بعد.'
        : '',
  };
}

function filterNav(user, userCan) {
  return DOMAIN_CATALOGS.filter((d) => domainVisible(user, d)).map((d) => ({
    id: d.id,
    title: d.title,
    icon: d.icon,
    items: d.catalog.flatMap((g) =>
      g.items
        .filter((it) => user.is_admin || userCan(user, it.r) || String(it.r).startsWith('dashboard_'))
        .map((it) => ({
          r: it.r,
          label: it.label,
          icon: it.icon,
          node: true,
          path: it.path,
        }))
    ),
  }));
}

module.exports = {
  buildSidebar,
  domainHubContent,
  favoritesHubContent,
  filterNav,
  DOMAIN_CATALOGS,
};
