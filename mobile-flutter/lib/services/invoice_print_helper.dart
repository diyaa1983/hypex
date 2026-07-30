import 'package:flutter/material.dart';

import '../core/config.dart';
import '../widgets/async_view.dart';
import 'document_print_helper.dart';

/// طباعة / PDF لفاتورة المبيعات عبر الطابعة المحفوظة (Bluetooth) أو حوار النظام.
class InvoicePrintHelper {
  InvoicePrintHelper._();

  static Future<void> openPdf(
    BuildContext context, {
    required int invoiceId,
    required String invoiceNo,
    bool preferSystemDialog = false,
  }) async {
    if (invoiceId < 1) {
      showSnack(context, 'احفظ الفاتورة أولاً قبل الطباعة.', error: true);
      return;
    }
    final safeNo = invoiceNo.trim().isEmpty
        ? 'invoice_$invoiceId'
        : invoiceNo.replaceAll(RegExp(r'[^\w\-]+'), '_');

    await DocumentPrintHelper.printFromApi(
      context,
      apiPath: AppConfig.salesInvoicePdfPath,
      query: {'id': invoiceId},
      jobName: 'فاتورة_$safeNo',
      preferSystemDialog: preferSystemDialog,
    );
  }
}
