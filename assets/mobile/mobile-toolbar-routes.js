/**
 * أزرار شريط الأدوات الافتراضية لكل مسار — تُستبدل عند اختيار مستند أو تحميل بيانات الشاشة.
 */
(function (global) {
  'use strict';

  /** @type {Record<string, { skip?: boolean, title?: string, formId?: string, cols?: number, buttons?: Record<string, boolean> }>} */
  global.MobileToolbarRoutes = {
    m_home: {
      skip: true,
    },
    m_sales_invoices: {
      skip: true,
    },
    m_sales_invoice_list: {
      title: 'اختر فاتورة من القائمة',
    },
    m_sales_invoice_gps: {
      title: 'إحداثيات مواقع فواتير البيع',
    },
    m_sales_invoice_view: {
      skip: true,
    },
    m_party_statement: {
      buttons: { run: true },
      cols: 1,
    },
    m_receipt: {
      skip: true,
    },
    m_sales_returns: {
      buttons: { save: true },
      formId: 'm-ret-form',
      cols: 1,
    },
    m_sales_returns_list: {
      title: 'اختر مرتجعاً من القائمة',
    },
    m_receipt_list: {
      title: 'اختر سنداً من القائمة',
    },
    m_rep_load: {
      skip: true,
    },
    m_rep_return: {
      skip: true,
    },
    m_rep_custody_list: {
      title: 'اختر عهدة من القائمة',
    },
    m_rep_stock: {
      title: 'رصيد عهدة المندوب',
    },
  };

  function initRouteToolbar() {
    if (!global.MobileToolbar || !global.AppMobile) return;
    var route = AppMobile.activeRoute || '';
    MobileToolbar.applyRouteDefaults(route);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRouteToolbar);
  } else {
    initRouteToolbar();
  }
})(typeof window !== 'undefined' ? window : this);
