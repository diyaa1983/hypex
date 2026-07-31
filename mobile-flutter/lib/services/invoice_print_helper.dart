import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../widgets/async_view.dart';
import '../widgets/invoice_thermal_preview_screen.dart';
import 'invoice_bluetooth_receipt.dart';

/// طباعة Bluetooth ومعاينة إيصال حراري 58/80 مم.
class InvoicePrintHelper {
  InvoicePrintHelper._();

  static Future<Map<String, dynamic>> _freshInvoice(
    BuildContext context,
    Map<String, dynamic> invoice,
  ) async {
    final id = int.tryParse('${invoice['id'] ?? ''}') ?? 0;
    var data = Map<String, dynamic>.from(invoice);
    if (id < 1) return data;
    try {
      final fresh = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceViewPath,
        query: {'id': id},
      );
      final inv = (fresh['invoice'] as Map?)?.cast<String, dynamic>();
      if (inv != null) {
        data = inv;
        data['id'] = id;
      }
    } catch (_) {}
    return data;
  }

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
    showSnack(context, 'جاري الطباعة...');
    final data = await _freshInvoice(context, invoice);
    if (!context.mounted) return;
    final err = await InvoiceBluetoothReceipt.printInvoice(data);
    if (!context.mounted) return;
    if (err != null) {
      showSnack(context, err, error: true);
    } else {
      showSnack(context, 'تم إرسال الإيصال للطابعة.');
    }
  }

  /// زر عرض → معاينة الإيصال الحراري قبل الطباعة.
  static Future<void> openThermalPreview(
    BuildContext context, {
    required Map<String, dynamic> invoice,
  }) async {
    final id = int.tryParse('${invoice['id'] ?? ''}') ?? 0;
    if (id < 1) {
      showSnack(context, 'احفظ الفاتورة أولاً قبل العرض.', error: true);
      return;
    }
    showSnack(context, 'جاري تجهيز العرض...');
    final data = await _freshInvoice(context, invoice);
    if (!context.mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => InvoiceThermalPreviewScreen(invoice: data),
      ),
    );
  }
}
