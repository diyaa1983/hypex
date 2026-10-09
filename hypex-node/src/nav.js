'use strict';

/**
 * شريط جانبي مثل النظام القديم: مجالات فقط (بدون «جميع الشاشات»)
 */
const auth = require('./auth');
const { DOMAIN_CATALOGS, resolveScreen } = require('./lib/screenMap');
const { permCodeFromNavItem, permCodeForRoute } = require('./lib/routePermissions');
const db = require('./db');

function canNavItem(user, it) {
  if (!user) return false;
  if (user.is_admin) return true;
  const code = permCodeFromNavItem(it);
  return code !== '' && auth.userCan(user, code);
}

function domainVisible(user, domainCatalog) {
  if (user.is_admin) return true;
  if (domainCatalog.id === 'main') return true;
  return domainCatalog.catalog.some((g) => g.items.some((it) => canNavItem(user, it)));
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
  return items;
}

function findDomainCatalog(domainId) {
  const raw = String(domainId || '').trim();
  if (!raw) return null;
  const asHub = raw.startsWith('/hub/') ? raw : `/hub/${raw}`;
  const und = raw.replace(/-/g, '_');
  const hyp = raw.replace(/_/g, '-');
  return (
    DOMAIN_CATALOGS.find((d) => d.id === raw) ||
    DOMAIN_CATALOGS.find((d) => d.id === und) ||
    DOMAIN_CATALOGS.find((d) => d.id === hyp) ||
    DOMAIN_CATALOGS.find((d) => d.hub === asHub || d.hub === `/hub/${und}` || d.hub === `/hub/${hyp}`) ||
    null
  );
}

function domainHubContent(user, domainId) {
  const domain = findDomainCatalog(domainId);
  if (!domain) return null;
  const groups = domain.catalog
    .map((g) => {
      const items = g.items.filter((it) => canNavItem(user, it));
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
    const favSvc = require('./favorites/favoritesService');
    await favSvc.ensureSchema();
    codes = await favSvc.codesForUser(user.id);
  } catch {
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
  }
  const items = [];
  for (const code of codes) {
    const sc = resolveScreen(code);
    if (!sc) continue;
    const perm = permCodeForRoute(sc.r) || sc.r;
    if (!user.is_admin && !auth.userCan(user, perm) && perm !== 'dashboard') continue;
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
        .filter((it) => {
          if (user.is_admin) return true;
          const code = permCodeFromNavItem(it);
          return code !== '' && userCan(user, code);
        })
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
