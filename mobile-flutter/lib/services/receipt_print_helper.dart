import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../widgets/async_view.dart';
import '../widgets/thermal_preview_screen.dart';
import 'receipt_bluetooth_receipt.dart';

/// طباعة ومعاينة سند القبض حرارياً (58/80 مم).
class ReceiptPrintHelper {
  ReceiptPrintHelper._();

  static Future<Map<String, dynamic>> loadReceipt(
    BuildContext context, {
    required int receiptId,
    Map<String, dynamic>? fallback,
  }) async {
    var data = Map<String, dynamic>.from(fallback ?? const {});
    data['id'] = receiptId;
    try {
      final fresh = await context.read<ApiClient>().getJson(
        AppConfig.receiptViewPath,
        query: {'id': receiptId},
      );
      final v = (fresh['voucher'] as Map?)?.cast<String, dynamic>();
      if (v != null) {
        data = v;
        data['id'] = receiptId;
      }
    } catch (_) {}
    return data;
  }

  static Future<void> printBluetooth(
    BuildContext context, {
    required int receiptId,
    Map<String, dynamic>? fallback,
  }) async {
    if (receiptId < 1) {
      showSnack(context, 'احفظ السند أولاً قبل الطباعة.', error: true);
      return;
    }
    showSnack(context, 'جاري الطباعة...');
    final data = await loadReceipt(
      context,
      receiptId: receiptId,
      fallback: fallback,
    );
    if (!context.mounted) return;
    final err = await ReceiptBluetoothReceipt.printReceipt(data);
    if (!context.mounted) return;
    if (err != null) {
      showSnack(context, err, error: true);
    } else {
      showSnack(context, 'تم إرسال السند للطابعة.');
    }
  }

  static Future<void> openThermalPreview(
    BuildContext context, {
    required int receiptId,
    Map<String, dynamic>? fallback,
  }) async {
    if (receiptId < 1) {
      showSnack(context, 'احفظ السند أولاً قبل العرض.', error: true);
      return;
    }
    showSnack(context, 'جاري تجهيز العرض...');
    final data = await loadReceipt(
      context,
      receiptId: receiptId,
      fallback: fallback,
    );
    if (!context.mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => ThermalPreviewScreen(
          title: 'عرض سند القبض',
          buildPdf: (paperMm) => ReceiptBluetoothReceipt.buildThermalPdf(
            data,
            paperMm: paperMm,
          ),
          onPrint: (ctx) async {
            showSnack(ctx, 'جاري الطباعة...');
            final err = await ReceiptBluetoothReceipt.printReceipt(data);
            if (!ctx.mounted) return;
            if (err != null) {
              showSnack(ctx, err, error: true);
            } else {
              showSnack(ctx, 'تم إرسال السند للطابعة.');
            }
          },
        ),
      ),
    );
  }
}
