import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:shared_preferences/shared_preferences.dart';

/// ترويسة الطباعة: شعار الشركة واسمها.
///
/// تُحفظ عند تحميل الشاشة الرئيسية (`api/mobile_home.php`) ثم تُستخدم في كل
/// الإيصالات الحرارية حتى لو لم ترسلها واجهة المستند نفسها، ويبقى الشعار
/// متاحاً للطباعة دون إنترنت لأنه يُخزَّن على الجهاز.
class PrintBrand {
  PrintBrand._();

  static const _kName = 'print_brand_company';
  static const _kLogoUrl = 'print_brand_logo_url';
  static const _kServerBase = 'print_brand_server_base';
  static const _logoFile = 'print_brand_logo.img';
  static const _assetFallbacks = [
    'assets/branding/logo.png',
    'assets/icon/app_icon.png',
  ];

  static String _company = '';
  static String _logoUrl = '';
  static String _serverBase = '';
  static Uint8List? _logoBytes;
  static pw.MemoryImage? _logoImage;
  static bool _prefsRead = false;

  /// تخزين بيانات الشركة القادمة من الرئيسية.
  ///
  /// [serverBase] لازم لتحويل مسار الشعار النسبي إلى رابط كامل.
  static Future<void> remember(
    String companyName,
    String? logoUrl, {
    String? serverBase,
  }) async {
    final name = companyName.trim();
    final base = (serverBase ?? '').trim();
    if (base.isNotEmpty) _serverBase = base;
    final url = (logoUrl ?? '').trim();
    final urlChanged = url.isNotEmpty && url != _logoUrl;
    if (name.isNotEmpty) _company = name;
    if (url.isNotEmpty) _logoUrl = url;
    _prefsRead = true;

    try {
      final prefs = await SharedPreferences.getInstance();
      if (name.isNotEmpty) await prefs.setString(_kName, name);
      if (url.isNotEmpty) await prefs.setString(_kLogoUrl, url);
      if (base.isNotEmpty) await prefs.setString(_kServerBase, base);
    } catch (_) {
      // التخزين المحلي غير حرج
    }

    if (urlChanged) {
      _logoBytes = null;
      _logoImage = null;
      await _downloadLogo(url);
    }
  }

  static Future<void> _readPrefs() async {
    if (_prefsRead) return;
    _prefsRead = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      _company = prefs.getString(_kName) ?? _company;
      _logoUrl = prefs.getString(_kLogoUrl) ?? _logoUrl;
      _serverBase = prefs.getString(_kServerBase) ?? _serverBase;
    } catch (_) {
      // نكمل بالقيم الافتراضية
    }
  }

  /// روابط محتملة للشعار: قد ترسل الواجهة رابطاً كاملاً أو مساراً نسبياً
  /// (`/uploads/...` أو `/hypex/uploads/...`) فنجرّب الاحتمالين على السيرفر.
  static List<String> _logoCandidates(String url) {
    final raw = url.trim();
    if (raw.isEmpty) return const [];
    if (raw.startsWith('http')) return [raw];
    if (_serverBase.isEmpty) return const [];

    final base = _serverBase.replaceAll(RegExp(r'/+$'), '');
    final rel = raw.replaceAll(RegExp(r'^/+'), '');
    final out = <String>{'$base/$rel'};
    final parsed = Uri.tryParse(base);
    if (parsed != null) out.add(parsed.resolve('/$rel').toString());
    return out.toList();
  }

  /// اسم الشركة — أولوية لما ترسله واجهة المستند ثم المحفوظ محلياً.
  static Future<String> companyName([String? fromDocument]) async {
    final doc = (fromDocument ?? '').trim();
    if (doc.isNotEmpty) return doc;
    await _readPrefs();
    return _company.isNotEmpty ? _company : 'الشركة';
  }

  /// شعار الشركة: الذاكرة ثم ملف الجهاز ثم التنزيل ثم شعار التطبيق المضمّن.
  static Future<pw.MemoryImage?> logo([String? urlFromDocument]) async {
    if (_logoImage != null) return _logoImage;
    await _readPrefs();

    final url = (urlFromDocument ?? '').trim().isNotEmpty
        ? urlFromDocument!.trim()
        : _logoUrl;

    var bytes = _logoBytes ?? await _readCachedLogo();
    bytes ??= await _downloadLogo(url);
    bytes ??= await _assetLogo();
    if (bytes == null) return null;

    _logoBytes = bytes;
    _logoImage = pw.MemoryImage(bytes);
    return _logoImage;
  }

  static Future<File?> _logoCacheFile() async {
    if (kIsWeb) return null;
    try {
      final dir = await getApplicationSupportDirectory();
      return File('${dir.path}${Platform.pathSeparator}$_logoFile');
    } catch (_) {
      return null;
    }
  }

  static Future<Uint8List?> _readCachedLogo() async {
    final file = await _logoCacheFile();
    if (file == null) return null;
    try {
      if (!await file.exists()) return null;
      final bytes = await file.readAsBytes();
      return bytes.isEmpty ? null : bytes;
    } catch (_) {
      return null;
    }
  }

  static Future<Uint8List?> _downloadLogo(String url) async {
    for (final candidate in _logoCandidates(url)) {
      final bytes = await _fetchLogo(candidate);
      if (bytes == null) continue;
      final file = await _logoCacheFile();
      if (file != null) {
        try {
          await file.writeAsBytes(bytes, flush: true);
        } catch (_) {
          // التخزين المؤقت غير حرج
        }
      }
      return bytes;
    }
    return null;
  }

  static Future<Uint8List?> _fetchLogo(String url) async {
    try {
      final res = await Dio().get<List<int>>(
        url,
        options: Options(
          responseType: ResponseType.bytes,
          receiveTimeout: const Duration(seconds: 6),
          sendTimeout: const Duration(seconds: 6),
        ),
      );
      if (res.statusCode != 200) return null;
      final data = res.data;
      if (data == null || data.isEmpty) return null;
      // صفحة خطأ HTML بدل صورة.
      final type = (res.headers.value('content-type') ?? '').toLowerCase();
      if (type.contains('text/') || type.contains('json')) return null;
      return Uint8List.fromList(data);
    } catch (_) {
      return null;
    }
  }

  static Future<Uint8List?> _assetLogo() async {
    for (final path in _assetFallbacks) {
      try {
        final data = await rootBundle.load(path);
        return data.buffer.asUint8List();
      } catch (_) {
        continue;
      }
    }
    return null;
  }

  /// ترويسة موحّدة لكل الإيصالات: الشعار ثم اسم الشركة ثم عنوان المستند.
  static Future<pw.Widget> header({
    required int paperMm,
    required pw.Font bold,
    required String title,
    String? companyFromDocument,
    String? logoUrlFromDocument,
  }) async {
    final image = await logo(logoUrlFromDocument);
    final name = await companyName(companyFromDocument);
    final logoHeight = paperMm == 80 ? 44.0 : 34.0;

    return pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.stretch,
      children: [
        if (image != null)
          pw.Center(
            child: pw.Container(
              height: logoHeight,
              width: logoHeight * 2.2,
              alignment: pw.Alignment.center,
              child: pw.Image(image, fit: pw.BoxFit.contain),
            ),
          ),
        if (image != null) pw.SizedBox(height: 3),
        pw.Center(
          child: pw.Text(
            name,
            textAlign: pw.TextAlign.center,
            maxLines: 2,
            style: pw.TextStyle(
              font: bold,
              fontSize: paperMm == 80 ? 12.5 : 10.5,
              fontWeight: pw.FontWeight.bold,
            ),
          ),
        ),
        if (title.isNotEmpty) pw.SizedBox(height: 2),
        if (title.isNotEmpty)
          pw.Center(
            child: pw.Text(
              title,
              textAlign: pw.TextAlign.center,
              style: pw.TextStyle(
                font: bold,
                fontSize: paperMm == 80 ? 10.5 : 9,
                fontWeight: pw.FontWeight.bold,
              ),
            ),
          ),
      ],
    );
  }
}
