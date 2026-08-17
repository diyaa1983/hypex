import 'dart:io';

import 'package:android_intent_plus/android_intent.dart';
import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:image/image.dart' as img;
import 'package:permission_handler/permission_handler.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart'
    show BluetoothInfo;
import 'package:printing/printing.dart';

import 'bluetooth_printer_settings.dart';
import 'thermal_raster.dart';

/// خدمة الطباعة عبر Bluetooth الحرارية.
///
/// تستخدم قناة أصلية داخل التطبيق بدل الاعتماد على اتصال
/// `print_bluetooth_thermal` الذي يفشل عند وجود outputStream قديم، ولا
/// يجرّب insecure RFCOMM اللازم لطابعات Xprinter مثل XP-P810.
class BluetoothPrintService {
  BluetoothPrintService._();

  static const MethodChannel _channel = MethodChannel(
    'com.gppjo.biodev.namma_mobile/thermal_printer',
  );

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

  static Future<bool> _isBluetoothOn() async {
    if (!Platform.isAndroid) return false;
    try {
      return await _channel.invokeMethod<bool>('isBluetoothOn') ?? false;
    } catch (_) {
      return false;
    }
  }

  static Future<List<BluetoothInfo>> pairedDevices() async {
    await ensurePermissions();
    if (!await _isBluetoothOn()) {
      throw StateError('البلوتوث مغلق. فعّله ثم أعد المحاولة.');
    }
    try {
      final raw = await _channel.invokeMethod<List<dynamic>>('pairedDevices');
      final items = <BluetoothInfo>[];
      for (final entry in raw ?? const []) {
        final text = entry?.toString() ?? '';
        final parts = text.split('#');
        if (parts.length < 2) continue;
        items.add(
          BluetoothInfo(
            name: parts.first,
            macAdress: parts.sublist(1).join('#'),
          ),
        );
      }
      return items;
    } catch (e) {
      throw StateError('تعذر قراءة الأجهزة المقترنة: $e');
    }
  }

  static Future<bool> connectionStatus() async {
    if (!Platform.isAndroid) return false;
    try {
      return await _channel.invokeMethod<bool>('connectionStatus') ?? false;
    } catch (_) {
      return false;
    }
  }

  static Future<bool> disconnect() async {
    if (!Platform.isAndroid) return true;
    try {
      return await _channel.invokeMethod<bool>('disconnect') ?? true;
    } catch (_) {
      return false;
    }
  }

  static Future<bool> writeBytes(List<int> bytes) async {
    if (!Platform.isAndroid || bytes.isEmpty) return false;
    try {
      return await _channel
              .invokeMethod<bool>('writeBytes', bytes)
              .timeout(const Duration(seconds: 25)) ??
          false;
    } on PlatformException catch (e) {
      if (kDebugMode) {
        debugPrint('thermal write failed: ${e.code} ${e.message}');
      }
      return false;
    } catch (e) {
      if (kDebugMode) debugPrint('thermal write timeout/error: $e');
      return false;
    }
  }

  static Future<bool> connect(String mac) async {
    if (mac.trim().isEmpty) return false;
    if (!await ensurePermissions()) {
      throw StateError(
        'يلزم منح إذن «الأجهزة القريبة/البلوتوث» للتطبيق من إعدادات أندرويد.',
      );
    }
    if (!await _isBluetoothOn()) {
      throw StateError('البلوتوث مغلق. فعّله ثم أعد المحاولة.');
    }

    var address = mac.trim().toUpperCase();
    try {
      final paired = await pairedDevices();
      for (final device in paired) {
        if (device.macAdress.trim().toUpperCase() == address) {
          address = device.macAdress.trim();
          break;
        }
      }
    } catch (_) {
      // نستخدم العنوان المحفوظ إن تعذر قراءة قائمة الأجهزة.
    }

    for (var attempt = 0; attempt < 2; attempt++) {
      try {
        if (attempt > 0) {
          await disconnect();
          await Future<void>.delayed(const Duration(milliseconds: 600));
        }
        final connected = await _channel
            .invokeMethod<bool>('connect', address)
            .timeout(const Duration(seconds: 28));
        if (connected == true) return true;
      } on PlatformException catch (e) {
        if (e.code == 'PERMISSION') {
          throw StateError(
            'يلزم منح إذن «الأجهزة القريبة/البلوتوث» للتطبيق من إعدادات أندرويد.',
          );
        }
        if (e.code == 'BT_OFF') {
          throw StateError('البلوتوث مغلق. فعّله ثم أعد المحاولة.');
        }
        if (kDebugMode) {
          debugPrint('thermal connect attempt $attempt: ${e.message}');
        }
      } catch (e) {
        if (kDebugMode) {
          debugPrint('thermal connect attempt $attempt: $e');
        }
      }
    }
    return false;
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

  /// تحويل PDF إلى ESC/POS بعرض مضاعف لـ 8 حتى لا ينهار imageRaster.
  static Future<List<int>> escPosFromPdf(
    Uint8List pdfBytes, {
    required int paperMm,
    int dpi = 203,
  }) async {
    final profile = await CapabilityProfile.load();
    final paper = paperMm == 80 ? PaperSize.mm80 : PaperSize.mm58;
    final generator = Generator(paper, profile);
    final targetWidth = paperMm == 80 ? 576 : 384;
    final chunks = <int>[];
    chunks.addAll(List<int>.from(generator.reset()));

    var pageCount = 0;
    await for (final page in Printing.raster(pdfBytes, dpi: dpi.toDouble())) {
      pageCount++;
      final png = await page.toPng();
      final decoded = img.decodeImage(Uint8List.fromList(png));
      if (decoded == null) continue;
      final prepared = _prepareRasterImage(decoded, targetWidth);
      for (final slice in _sliceRaster(prepared, 400)) {
        chunks.addAll(
          List<int>.from(
            generator.imageRaster(
              slice,
              imageFn: PosImageFn.bitImageRaster,
            ),
          ),
        );
      }
      chunks.addAll(List<int>.from(generator.feed(2)));
    }

    if (pageCount == 0) {
      throw StateError('تعذر تحويل PDF للطباعة.');
    }
    chunks.addAll(List<int>.from(generator.cut()));
    return chunks;
  }

  static img.Image _prepareRasterImage(img.Image decoded, int targetWidth) {
    var resized = decoded;
    if (decoded.width != targetWidth) {
      resized = img.copyResize(
        decoded,
        width: targetWidth,
        interpolation: img.Interpolation.average,
      );
    }
    return flattenOnWhite(resized);
  }

  static List<img.Image> _sliceRaster(img.Image src, int maxHeight) {
    if (src.height <= maxHeight) return [src];
    final slices = <img.Image>[];
    var y = 0;
    while (y < src.height) {
      final h = src.height - y < maxHeight ? src.height - y : maxHeight;
      slices.add(
        img.copyCrop(src, x: 0, y: y, width: src.width, height: h),
      );
      y += h;
    }
    return slices;
  }

  static Future<void> _printPdfViaBluetooth(
    Uint8List pdfBytes,
    BluetoothPrinterConfig cfg,
  ) async {
    final okConnect = await connect(cfg.mac);
    if (!okConnect) {
      throw StateError(
        'تعذر الاتصال بالطابعة «${cfg.displayLabel}». '
        'تأكد أنها مشغّلة ومقترنة، وأغلق أي تطبيق آخر يستخدمها ثم أعد المحاولة.',
      );
    }

    final chunks = await escPosFromPdf(pdfBytes, paperMm: cfg.paperMm);
    final written = await writeBytes(chunks);
    if (!written) {
      await disconnect();
      final reconnected = await connect(cfg.mac);
      if (!reconnected || !await writeBytes(chunks)) {
        throw StateError('فشل إرسال البيانات للطابعة.');
      }
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
        return 'تعذر الاتصال بالطابعة. تأكد أنها مشغّلة ومقترنة، '
            'وأغلق تطبيق الطابعة الرسمي إن كان مفتوحاً. '
            'إن ظهرت «متصلة» في إعدادات بلوتوث الأندرويد اضغط عليها وافصلها مع الإبقاء على الاقتران ثم أعد المحاولة.';
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
      var ok = await writeBytes(bytes);
      if (!ok) {
        await disconnect();
        if (await connect(cfg.mac)) {
          ok = await writeBytes(bytes);
        }
      }
      return ok ? null : 'فشل إرسال صفحة الاختبار.';
    } catch (e) {
      return 'فشل الاختبار: $e';
    }
  }
}
