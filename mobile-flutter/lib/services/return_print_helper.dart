import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../widgets/async_view.dart';
import '../widgets/thermal_preview_screen.dart';
import 'document_print_helper.dart';
import 'return_bluetooth_receipt.dart';

/// طباعة ومعاينة مرتجع المبيعات حرارياً (58/80 مم) وPDF A4.
class ReturnPrintHelper {
  ReturnPrintHelper._();

  static Future<Map<String, dynamic>> loadReturn(
    BuildContext context, {
    required int returnId,
    Map<String, dynamic>? fallback,
  }) async {
    var data = Map<String, dynamic>.from(fallback ?? const {});
    data['id'] = returnId;
    try {
      final fresh = await context.read<ApiClient>().getJson(
        AppConfig.returnViewPath,
        query: {'id': returnId},
      );
      final v = (fresh['return'] as Map?)?.cast<String, dynamic>();
      if (v != null) {
        data = v;
        data['id'] = returnId;
      }
    } catch (_) {}
    return data;
  }

  static Future<void> printBluetooth(
    BuildContext context, {
    required int returnId,
    Map<String, dynamic>? fallback,
  }) async {
    if (returnId < 1) {
      showSnack(context, 'احفظ المرتجع أولاً قبل الطباعة.', error: true);
      return;
    }
    showSnack(context, 'جاري الطباعة...');
    final data = await loadReturn(
      context,
      returnId: returnId,
      fallback: fallback,
    );
    if (!context.mounted) return;
    final err = await ReturnBluetoothReceipt.printReturn(data);
    if (!context.mounted) return;
    if (err != null) {
      showSnack(context, err, error: true);
    } else {
      showSnack(context, 'تم إرسال المرتجع للطابعة.');
    }
  }

  static Future<void> openThermalPreview(
    BuildContext context, {
    required int returnId,
    Map<String, dynamic>? fallback,
  }) async {
    if (returnId < 1) {
      showSnack(context, 'احفظ المرتجع أولاً قبل العرض.', error: true);
      return;
    }
    showSnack(context, 'جاري تجهيز العرض...');
    final data = await loadReturn(
      context,
      returnId: returnId,
      fallback: fallback,
    );
    if (!context.mounted) return;
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => ThermalPreviewScreen(
          title: 'عرض المرتجع',
          buildPdf: (paperMm) => ReturnBluetoothReceipt.buildThermalPdf(
            data,
            paperMm: paperMm,
          ),
          onPrint: (ctx) async {
            showSnack(ctx, 'جاري الطباعة...');
            final err = await ReturnBluetoothReceipt.printReturn(data);
            if (!ctx.mounted) return;
            if (err != null) {
              showSnack(ctx, err, error: true);
            } else {
              showSnack(ctx, 'تم إرسال المرتجع للطابعة.');
            }
          },
        ),
      ),
    );
  }

  static Future<void> openPdfA4(
    BuildContext context, {
    required int returnId,
    String returnNo = '',
  }) async {
    if (returnId < 1) {
      showSnack(context, 'احفظ المرتجع أولاً قبل PDF.', error: true);
      return;
    }
    final no = returnNo.trim();
    await DocumentPrintHelper.openPdfFromApi(
      context,
      apiPath: AppConfig.returnPdfPath,
      query: {'id': returnId},
      title: no.isEmpty ? 'مرتجع مبيعات' : 'مرتجع $no',
      fileName: no.isEmpty ? 'مرتجع.pdf' : 'مرتجع-$no.pdf',
    );
  }

  static Future<void> sharePdf(
    BuildContext context, {
    required int returnId,
    String returnNo = '',
  }) async {
    if (returnId < 1) {
      showSnack(context, 'احفظ المرتجع أولاً قبل المشاركة.', error: true);
      return;
    }
    final no = returnNo.trim();
    await DocumentPrintHelper.sharePdfFromApi(
      context,
      apiPath: AppConfig.returnPdfPath,
      query: {'id': returnId},
      fileName: no.isEmpty ? 'مرتجع.pdf' : 'مرتجع-$no.pdf',
    );
  }
}
