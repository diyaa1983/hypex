import 'dart:io';

import 'package:android_intent_plus/android_intent.dart';
import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;
import 'package:permission_handler/permission_handler.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:printing/printing.dart';

import 'bluetooth_printer_settings.dart';
import 'thermal_raster.dart';

/// خدمة الطباعة عبر Bluetooth الحرارية فقط.
class BluetoothPrintService {
  BluetoothPrintService._();

  static Future<bool> ensurePermissions() async {
    if (kIsWeb || !Platform.isAndroid) return true;

    final statuses = await [
      Permission.bluetoothScan,
      Permission.bluetoothConnect,
      Permission.locationWhenInUse,
    ].request();
    final scan = statuses[Permission.bluetoothScan] ?? PermissionStatus.denied;
    final connect =
        statuses[Permission.bluetoothConnect] ?? PermissionStatus.denied;
    return scan.isGranted && connect.isGranted;
  }

  static Future<void> openSystemBluetoothSettings() async {
    if (kIsWeb) return;
    if (Platform.isAndroid) {
      const intent =
          AndroidIntent(action: 'android.settings.BLUETOOTH_SETTINGS');
      await intent.launch();
      return;
    }
    await openAppSettings();
  }

  static Future<List<BluetoothInfo>> pairedDevices() async {
    await ensurePermissions();
    final on = await PrintBluetoothThermal.bluetoothEnabled;
    if (!on) {
      throw StateError('البلوتوث مغلق. فعّله ثم أعد المحاولة.');
    }
    return PrintBluetoothThermal.pairedBluetooths;
  }

  static Future<bool> connect(String mac) async {
    if (mac.trim().isEmpty) return false;
    await ensurePermissions();
    final status = await PrintBluetoothThermal.connectionStatus;
    if (status) {
      await PrintBluetoothThermal.disconnect;
    }
    return PrintBluetoothThermal.connect(macPrinterAddress: mac.trim());
  }

  /// طباعة PDF على طابعة Bluetooth المحفوظة.
  /// [bluetoothOnly] يمنع الرجوع لحوار طباعة النظام (الافتراضي: true).
  static Future<String?> printPdfBytes(
    Uint8List pdfBytes, {
    required String jobName,
    bool bluetoothOnly = true,
  }) async {
    if (pdfBytes.isEmpty) return 'ملف PDF فارغ.';

    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) {
      return 'اختر طابعة Bluetooth من الإعدادات أولاً.';
    }
    if (kIsWeb || !Platform.isAndroid) {
      return 'الطباعة عبر Bluetooth متاحة على أندرويد فقط.';
    }

    try {
      await _printPdfViaBluetooth(pdfBytes, cfg);
      return null;
    } catch (e) {
      final msg = e.toString().replaceFirst('Bad state: ', '');
      if (bluetoothOnly) {
        return msg.startsWith('تعذر') || msg.startsWith('فشل')
            ? msg
            : 'تعذر الطباعة على Bluetooth: $msg';
      }
      return 'تعذر الطباعة على Bluetooth: $msg';
    }
  }

  static Future<void> _printPdfViaBluetooth(
    Uint8List pdfBytes,
    BluetoothPrinterConfig cfg,
  ) async {
    final okConnect = await connect(cfg.mac);
    if (!okConnect) {
      throw StateError(
        'تعذر الاتصال بالطابعة «${cfg.displayLabel}». تأكد أنها مشغّلة ومقترنة.',
      );
    }

    final profile = await CapabilityProfile.load();
    final paper = cfg.paperMm == 80 ? PaperSize.mm80 : PaperSize.mm58;
    final generator = Generator(paper, profile);
    final targetWidth = cfg.paperMm == 80 ? 576 : 384;

    final chunks = <int>[];
    chunks.addAll(generator.reset());

    var pageCount = 0;
    await for (final page in Printing.raster(pdfBytes, dpi: 150)) {
      pageCount++;
      final png = await page.toPng();
      final decoded = img.decodeImage(Uint8List.fromList(png));
      if (decoded == null) continue;
      var resized = decoded;
      if (decoded.width > targetWidth) {
        resized = img.copyResize(
          decoded,
          width: targetWidth,
          interpolation: img.Interpolation.average,
        );
      }
      chunks.addAll(
        generator.imageRaster(
          flattenOnWhite(resized),
          imageFn: PosImageFn.bitImageRaster,
        ),
      );
      chunks.addAll(generator.feed(2));
    }

    if (pageCount == 0) {
      throw StateError('تعذر تحويل PDF للطباعة.');
    }

    chunks.addAll(generator.cut());
    final written = await PrintBluetoothThermal.writeBytes(chunks);
    if (!written) {
      throw StateError('فشل إرسال البيانات للطابعة.');
    }
  }

  /// صفحة اختبار قصيرة للتأكد من الربط.
  static Future<String?> testPrint() async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) {
      return 'اختر طابعة Bluetooth من الإعدادات أولاً.';
    }
    try {
      final okConnect = await connect(cfg.mac);
      if (!okConnect) {
        return 'تعذر الاتصال بالطابعة. تأكد أنها مشغّلة ومقترنة.';
      }
      final profile = await CapabilityProfile.load();
      final paper = cfg.paperMm == 80 ? PaperSize.mm80 : PaperSize.mm58;
      final g = Generator(paper, profile);
      final bytes = <int>[];
      bytes.addAll(g.reset());
      bytes.addAll(g.text(
        'NAMMA PRINT TEST',
        styles: const PosStyles(
          align: PosAlign.center,
          bold: true,
          height: PosTextSize.size2,
          width: PosTextSize.size2,
        ),
      ));
      bytes.addAll(g.feed(1));
      bytes.addAll(g.text(
        cfg.displayLabel,
        styles: const PosStyles(align: PosAlign.center),
      ));
      bytes.addAll(g.text(
        DateTime.now().toIso8601String(),
        styles: const PosStyles(align: PosAlign.center),
      ));
      bytes.addAll(g.feed(3));
      bytes.addAll(g.cut());
      final ok = await PrintBluetoothThermal.writeBytes(bytes);
      return ok ? null : 'فشل إرسال صفحة الاختبار.';
    } catch (e) {
      return 'فشل الاختبار: $e';
    }
  }
}
