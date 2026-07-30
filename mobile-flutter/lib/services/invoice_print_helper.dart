import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../widgets/async_view.dart';
import 'document_print_helper.dart';
import 'invoice_bluetooth_receipt.dart';

/// طباعة Bluetooth (إيصال حراري) ومعاينة PDF A4 — مساران منفصلان تماماً.
class InvoicePrintHelper {
  InvoicePrintHelper._();

  /// زر الطباعة → إيصال حراري 58/80 مم على طابعة Bluetooth.
  static Future<void> printBluetooth(
    BuildContext context, {
    required Map<String, dynamic> invoice,
  }) async {
    final id = int.tryParse('${invoice['id'] ?? ''}') ?? 0;
    if (id < 1) {
      showSnack(context, 'احفظ الفاتورة أولاً قبل الطباعة.', error: true);
      return;
    }
    showSnack(context, 'جاري الطباعة على Bluetooth...');
    var data = Map<String, dynamic>.from(invoice);
    try {
      // تحديث بيانات الشركة/QR من السيرفر قبل الطباعة الحرارية.
      final fresh = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceViewPath,
        query: {'id': id},
      );
      final inv = (fresh['invoice'] as Map?)?.cast<String, dynamic>();
      if (inv != null) {
        data = inv;
        data['id'] = id;
      }
    } catch (_) {
      // نطبع بالمتوفر محلياً إن تعذّر التحديث.
    }
    if (!context.mounted) return;
    final err = await InvoiceBluetoothReceipt.printInvoice(data);
    if (!context.mounted) return;
    if (err != null) {
      showSnack(context, err, error: true);
    } else {
      showSnack(context, 'تم إرسال الإيصال لطابعة Bluetooth.');
    }
  }

  /// زر PDF → معاينة فاتورة A4 فقط (بدون طباعة Bluetooth).
  static Future<void> openPdf(
    BuildContext context, {
    required int invoiceId,
    required String invoiceNo,
  }) async {
    if (invoiceId < 1) {
      showSnack(context, 'احفظ الفاتورة أولاً قبل التحويل إلى PDF.', error: true);
      return;
    }
    final safeNo = invoiceNo.trim().isEmpty
        ? 'invoice_$invoiceId'
        : invoiceNo.replaceAll(RegExp(r'[^\w\-]+'), '_');

    await DocumentPrintHelper.openPdfFromApi(
      context,
      apiPath: AppConfig.salesInvoicePdfPath,
      query: {'id': invoiceId},
      title: 'فاتورة $invoiceNo',
      fileName: 'فاتورة_$safeNo.pdf',
    );
  }
}
