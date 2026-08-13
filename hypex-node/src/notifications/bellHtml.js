'use strict';

const { esc, fmtAmt } = require('../lib/html');
const basePath = require('../lib/basePath');

function href(path) {
  return basePath.url(path || '/');
}

const BELL_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>`;

function badgeText(n) {
  if (n > 99) return '99+';
  return n > 0 ? String(n) : '';
}

function renderPanelBody(data) {
  const summary = data.summary || {};
  const alertCount = Number(summary.alert_count || 0);
  const visitCheckoutAlerts = data.visit_checkout_alerts || [];
  const customerOrderAlerts = data.customer_order_alerts || [];
  const unpostedAlerts = data.unposted_alerts || [];
  const alertChecks = data.alert_checks || [];
  const deliveryAlerts = data.delivery_alerts || [];
  const visitCheckoutCount = Number(summary.visit_checkout_count || 0);
  const customerOrderCount = Number(summary.customer_order_count || 0);
  const unpostedCount = Number(summary.unposted_count || 0);

  const hasAny =
    visitCheckoutAlerts.length ||
    customerOrderAlerts.length ||
    unpostedAlerts.length ||
    alertChecks.length ||
    deliveryAlerts.length;

  let html = `<header class="app-check-bell-panel-head">
    <strong>التنبيهات</strong>
    ${alertCount > 0 ? `<span class="app-check-bell-panel-count">${alertCount} تنبيه</span>` : ''}
  </header>`;

  if (!hasAny) {
    html += `<p class="app-check-bell-panel-empty">لا توجد تنبيهات حالياً.</p>`;
    return html;
  }

  if (visitCheckoutAlerts.length) {
    html += `<p class="app-check-bell-section-title">خروج يدوي من زيارة بانتظار الاعتماد${
      visitCheckoutCount ? ` (${visitCheckoutCount})` : ''
    }</p><ul class="app-check-bell-list">`;
    for (const vc of visitCheckoutAlerts) {
      html += `<li>
        <a class="app-check-bell-item app-check-bell-item--link" href="${esc(vc.url || href('/sales-reps/visit-checkout-approve'))}">
          <span class="dashboard-check-status dashboard-check-status--pending">${esc(
            vc.urgency_label || 'بانتظار اعتماد الخروج'
          )}</span>
          <span class="app-check-bell-item-main">
            <span class="app-check-bell-item-no">${esc(vc.customer_code || '—')}</span>
            <span class="app-check-bell-item-party">${esc(vc.customer_name || '—')}${
        vc.sales_rep_name ? ` · ${esc(vc.sales_rep_name)}` : ''
      }</span>
          </span>
          <span class="app-check-bell-item-meta">${esc(vc.created_at || '—')}</span>
        </a>
      </li>`;
    }
    html += `</ul>`;
    if (visitCheckoutCount > visitCheckoutAlerts.length) {
      html += `<p class="app-check-bell-panel-more muted">و${
        visitCheckoutCount - visitCheckoutAlerts.length
      } طلباً إضافياً…</p>`;
    }
  }

  if (customerOrderAlerts.length) {
    html += `<p class="app-check-bell-section-title">طلبات شراء بانتظار الاعتماد${
      customerOrderCount ? ` (${customerOrderCount})` : ''
    }</p><ul class="app-check-bell-list">`;
    for (const ord of customerOrderAlerts) {
      html += `<li>
        <a class="app-check-bell-item app-check-bell-item--link" href="${esc(ord.url || href('/sales/orders/approve'))}">
          <span class="dashboard-check-status dashboard-check-status--pending">${esc(
            ord.urgency_label || 'بانتظار الاعتماد'
          )}</span>
          <span class="app-check-bell-item-main">
            <span class="app-check-bell-item-no">${esc(ord.order_no || '—')}</span>
            <span class="app-check-bell-item-party">${esc(ord.customer_name || '—')}${
        ord.sales_rep_name ? ` · ${esc(ord.sales_rep_name)}` : ''
      }</span>
          </span>
          <span class="app-check-bell-item-meta">${esc(ord.order_date_fmt || ord.order_date || '—')}</span>
        </a>
      </li>`;
    }
    html += `</ul>`;
    if (customerOrderCount > customerOrderAlerts.length) {
      html += `<p class="app-check-bell-panel-more muted">و${
        customerOrderCount - customerOrderAlerts.length
      } طلباً إضافياً…</p>`;
    }
  }

  if (unpostedAlerts.length) {
    html += `<p class="app-check-bell-section-title">مستندات بحاجة ترحيل${
      unpostedCount ? ` (${unpostedCount})` : ''
    }</p><ul class="app-check-bell-list">`;
    for (const doc of unpostedAlerts) {
      const amount =
        Number(doc.amount || 0) > 0.000001 ? ` · ${esc(fmtAmt(doc.amount))}` : '';
      html += `<li>
        <a class="app-check-bell-item app-check-bell-item--link" href="${esc(doc.url || '#')}">
          <span class="dashboard-check-status dashboard-check-status--unposted">${esc(
            doc.type_label || 'بحاجة ترحيل'
          )}</span>
          <span class="app-check-bell-item-main">
            <span class="app-check-bell-item-no">${esc(doc.doc_no || '—')}</span>
            ${
              doc.party_name
                ? `<span class="app-check-bell-item-party">${esc(doc.party_name)}</span>`
                : ''
            }
          </span>
          <span class="app-check-bell-item-meta">${esc(doc.doc_date_fmt || doc.doc_date || '—')}${amount}</span>
        </a>
      </li>`;
    }
    html += `</ul>`;
    if (unpostedCount > unpostedAlerts.length) {
      html += `<p class="app-check-bell-panel-more muted">و${
        unpostedCount - unpostedAlerts.length
      } مستنداً إضافياً…</p>`;
    }
  }

  if (alertChecks.length) {
    html += `<p class="app-check-bell-section-title">شيكات مستحقة / قريبة (${alertChecks.length})</p><ul class="app-check-bell-list">`;
    for (const chk of alertChecks) {
      const statusClass =
        chk.urgency === 'overdue'
          ? 'dashboard-check-status--overdue'
          : chk.urgency === 'today'
            ? 'dashboard-check-status--today'
            : 'dashboard-check-status--soon';
      html += `<li>
        <a class="app-check-bell-item app-check-bell-item--link" href="${esc(chk.url || href('/accounting/checks-in'))}">
          <span class="dashboard-check-status ${statusClass}">${esc(chk.urgency_label || 'شيك')}</span>
          <span class="app-check-bell-item-main">
            <span class="app-check-bell-item-no">${esc(chk.check_no || chk.voucher_no || '—')}</span>
            <span class="app-check-bell-item-party">${esc(chk.party_name || '—')}${
        chk.bank_name ? ` · ${esc(chk.bank_name)}` : ''
      }</span>
          </span>
          <span class="app-check-bell-item-meta">${esc(chk.due_date_fmt || chk.due_date || '—')}${
        Number(chk.check_amount || 0) > 0 ? ` · ${esc(fmtAmt(chk.check_amount))}` : ''
      }</span>
        </a>
      </li>`;
    }
    html += `</ul>`;
  }

  if (deliveryAlerts.length) {
    html += `<p class="app-check-bell-section-title">سندات تسليم بلا فاتورة</p><ul class="app-check-bell-list">`;
    for (const del of deliveryAlerts) {
      html += `<li>
        <a class="app-check-bell-item app-check-bell-item--link" href="${esc(del.url || '#')}">
          <span class="dashboard-check-status dashboard-check-status--pending">${esc(
            del.urgency_label || 'بلا فاتورة'
          )}</span>
          <span class="app-check-bell-item-main">
            <span class="app-check-bell-item-no">${esc(del.delivery_no || '—')}</span>
            <span class="app-check-bell-item-party">${esc(del.customer_name || '—')}</span>
          </span>
          <span class="app-check-bell-item-meta">${esc(
            del.delivery_date_fmt || del.delivery_date || '—'
          )}</span>
        </a>
      </li>`;
    }
    html += `</ul>`;
  }

  html += `<footer class="app-check-bell-panel-foot">
    <a href="${esc(href('/sales/orders/approve'))}">اعتماد الطلبات</a>
    <a href="${esc(href('/sales/posting'))}">ترحيل المبيعات</a>
    <a href="${esc(href('/accounting/receipts'))}">سندات القبض</a>
  </footer>`;

  return html;
}

function renderBellShell(data) {
  if (!data || !data.enabled) return '';
  const alertCount = Number((data.summary && data.summary.alert_count) || 0);
  const bellClass = 'app-check-bell js-app-check-bell' + (alertCount > 0 ? ' has-alerts' : '');
  const badgeHidden = alertCount > 0 ? '' : ' hidden';
  const panelBody = renderPanelBody(data);

  return `<div class="app-check-bell-wrap no-print"
       data-needs-refresh="1"
       data-refresh-url="${esc(href('/api/notifications'))}">
    <button type="button"
            class="${esc(bellClass)}"
            aria-label="التنبيهات${alertCount > 0 ? ` — ${alertCount} تنبيه` : ''}"
            aria-expanded="false"
            aria-haspopup="true"
            title="التنبيهات${alertCount > 0 ? ` (${alertCount})` : ''}">
      <span class="app-check-bell-icon" aria-hidden="true">${BELL_SVG}</span>
      <span class="app-check-bell-badge js-check-bell-badge" aria-hidden="true"${badgeHidden}>${esc(
        badgeText(alertCount)
      )}</span>
    </button>
    <div class="app-check-bell-panel js-check-bell-panel" hidden role="dialog" aria-label="قائمة التنبيهات">
      ${panelBody}
    </div>
  </div>`;
}

module.exports = {
  renderBellShell,
  renderPanelBody,
  badgeText,
};
