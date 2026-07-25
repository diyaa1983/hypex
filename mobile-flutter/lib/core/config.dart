/// إعدادات ثابتة للتطبيق.
class AppConfig {
  AppConfig._();

  /// عنوان السيرفر الافتراضي (يمكن للمستخدم تغييره من شاشة الإعداد).
  static const String defaultServerBase = 'https://www.biodev.gppjo.com';

  /// المسارات على السيرفر.
  static const String sessionPath = 'api/mobile_session.php';
  static const String homePath = 'api/mobile_home.php';
  static const String pingPath = 'm/ping.php';

  /// واجهات الشاشات (JSON) — نفس ما يستدعيه /m الحالي.
  static const String salesInvoiceListPath = 'api/sales_invoices_list.php';
  static const String salesInvoiceViewPath = 'api/sales_invoice_view.php';
  static const String salesInvoicePostPath = 'api/sales_invoice_post.php';
  static const String salesInvoiceDeletePath = 'api/sales_invoice_delete.php';
  static const String salesInvoicePrintPath = 'api/mobile_invoice_print.php';
  static const String salesInvoicePdfPath = 'api/mobile_invoice_pdf.php';
  static const String salesInvoiceSaveRoute = 'm/index.php?r=m_sales_invoices';
  static const String itemsSearchPath = 'api/items_search.php';
  static const String invoiceMetaPath = 'api/mobile_invoice_meta.php';

  static const String receiptListPath = 'api/mobile_receipts_list.php';
  static const String receiptViewPath = 'api/fin_receipt_view.php';
  static const String receiptPostPath = 'api/fin_receipt_post.php';
  static const String receiptDeletePath = 'api/fin_receipt_delete.php';
  static const String receiptSaveRoute = 'm/index.php?r=m_receipt';

  static const String returnsListPath = 'api/mobile_returns_list.php';
  static const String returnLinesPath = 'api/mobile_return_lines.php';
  static const String returnInvoicesPath = 'api/mobile_return_invoices.php';
  static const String returnPostPath = 'api/sales_return_post.php';
  static const String returnSaveRoute = 'm/index.php?r=m_sales_returns';

  static const String partyStatementPath = 'api/mobile_party_statement.php';
  static const String partiesPath = 'api/mobile_parties.php';

  static const String repItemsPath = 'api/mobile_rep_items.php';
  static const String repStockPath = 'api/mobile_rep_stock.php';
  static const String repCustodyListPath = 'api/mobile_rep_custody_list.php';
  static const String repTransferPath = 'api/mobile_rep_transfer.php';

  static const String invoiceGpsListPath = 'api/sales_invoice_gps_list.php';
  static const String userGpsListPath = 'api/user_gps_locations_list.php';
  static const String userLocationPingPath = 'api/user_location_ping.php';
}
