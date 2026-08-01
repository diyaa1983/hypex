import 'dart:convert';
import 'dart:io';

import 'package:cookie_jar/cookie_jar.dart';
import 'package:dio/dio.dart';
import 'package:dio_cookie_manager/dio_cookie_manager.dart';
import 'package:path_provider/path_provider.dart';

/// استثناء موحّد لأخطاء الـ API (رسالة عربية جاهزة للعرض).
class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.code});

  final String message;
  final int? statusCode;
  final String? code;

  bool get isUnauthorized =>
      statusCode == 401 || code == 'unauthorized' || code == 'forbidden';

  @override
  String toString() => message;
}

/// عميل HTTP يعتمد جلسة الكوكيز (نفس آلية /m الحالية).
class ApiClient {
  ApiClient._(this._dio, this._cookieJar);

  final Dio _dio;
  final PersistCookieJar _cookieJar;
  String _base = '';
  String _deviceId = '';
  String _deviceLabel = 'هاتف';

  static Future<ApiClient> create() async {
    final dir = await getApplicationSupportDirectory();
    final jar = PersistCookieJar(
      ignoreExpires: true,
      storage: FileStorage('${dir.path}/.cookies/'),
    );
    final dio = Dio(
      BaseOptions(
        connectTimeout: const Duration(seconds: 20),
        receiveTimeout: const Duration(seconds: 30),
        headers: {'Accept': 'application/json'},
        followRedirects: true,
        // نتعامل مع كل الأكواد يدوياً بدل رمي استثناء تلقائي.
        validateStatus: (code) => code != null && code < 500,
      ),
    );
    dio.interceptors.add(CookieManager(jar));
    return ApiClient._(dio, jar);
  }

  void setDevice(String id, {String label = 'هاتف'}) {
    _deviceId = id.trim();
    _deviceLabel = label.trim().isEmpty ? 'هاتف' : label.trim();
  }

  Map<String, String> _deviceQuery() {
    if (_deviceId.isEmpty) return const {};
    return {'device_id': _deviceId, 'device_label': _deviceLabel};
  }

  Map<String, dynamic> _mergeDeviceFields(Map<String, dynamic>? fields) {
    final merged = <String, dynamic>{..._deviceQuery(), ...?fields};
    return merged;
  }

  Map<String, dynamic> _mergeDeviceQuery(Map<String, dynamic>? query) {
    return {..._deviceQuery(), ...?query};
  }

  Map<String, String> _deviceHeaders({String? csrf}) {
    return {
      if (_deviceId.isNotEmpty) 'X-Device-Id': _deviceId,
      if (csrf != null && csrf.isNotEmpty) 'X-CSRF-Token': csrf,
    };
  }

  /// ضبط عنوان السيرفر (يُطبَّع بإزالة السلاش الأخير و/m أو login).
  void setBase(String raw) {
    var s = raw.trim();
    s = s.replaceAll(RegExp(r'/+$'), '');
    s = s.replaceAll(RegExp(r'/m/login\.php$', caseSensitive: false), '');
    s = s.replaceAll(RegExp(r'/m$', caseSensitive: false), '');
    if (!RegExp(r'^https?://', caseSensitive: false).hasMatch(s)) {
      s = 'https://$s';
    }
    _base = s.replaceAll(RegExp(r'/+$'), '');
  }

  String get base => _base;

  String url(String path) => '$_base/$path';

  Future<void> clearCookies() => _cookieJar.deleteAll();

  /// طلب GET يُرجع خريطة JSON.
  Future<Map<String, dynamic>> getJson(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    return _handle(
      () => _dio.get(
        url(path),
        queryParameters: _mergeDeviceQuery(query),
        options: Options(headers: _deviceHeaders()),
      ),
    );
  }

  /// تنزيل ملف ثنائي مع كوكيز الجلسة (مثل PDF الفاتورة).
  Future<List<int>> downloadBytes(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    try {
      final res = await _dio.get<List<int>>(
        url(path),
        queryParameters: query,
        options: Options(
          responseType: ResponseType.bytes,
          headers: {'Accept': 'application/pdf,*/*'},
          // نقرأ جسم الرد حتى عند 4xx/5xx بدل رمي DioException الخام.
          validateStatus: (code) => code != null && code < 600,
        ),
      );
      final code = res.statusCode ?? 0;
      final bytes = res.data ?? <int>[];
      if (code >= 400) {
        final body =
            bytes.isEmpty ? '' : String.fromCharCodes(bytes.take(200)).trim();
        if (code == 403) {
          throw ApiException('لا توجد صلاحية لتنزيل الملف.', statusCode: code);
        }
        if (code == 404) {
          throw ApiException('الملف غير موجود على السيرفر.', statusCode: code);
        }
        if (body.contains('PDF library not installed') ||
            body.contains('pdf_error')) {
          throw ApiException(
            'تعذر إنشاء PDF على السيرفر. تأكد من تثبيت مكتبات PDF (vendor) وصلاحية مجلد logs.',
            statusCode: code,
          );
        }
        throw ApiException(
          'تعذر تنزيل الملف (رمز $code).',
          statusCode: code,
        );
      }
      if (bytes.isEmpty) {
        throw ApiException('الملف فارغ أو غير متاح.');
      }
      // بعض السيرفرات ترجع HTML عند الخطأ بدل PDF.
      if (bytes.length > 15) {
        final head = String.fromCharCodes(bytes.take(20));
        if (head.contains('<!DOCTYPE') || head.contains('<html')) {
          throw ApiException(
            'تعذر تنزيل PDF — السيرفر أعاد صفحة ويب بدل ملف PDF.',
            statusCode: code,
          );
        }
      }
      return bytes;
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.connectionError ||
          e.error is SocketException) {
        throw ApiException('تعذر الاتصال بالسيرفر. تحقق من الإنترنت والعنوان.');
      }
      final code = e.response?.statusCode;
      throw ApiException(
        'تعذر تنزيل الملف${code != null ? ' (رمز $code)' : ''}.',
        statusCode: code,
      );
    }
  }

  /// طلب POST (نموذج form-urlencoded) يُرجع خريطة JSON.
  Future<Map<String, dynamic>> postForm(
    String path, {
    Map<String, dynamic>? fields,
    String? csrf,
  }) async {
    final data = _mergeDeviceFields(fields);
    if (csrf != null && csrf.isNotEmpty) {
      data['_csrf'] = csrf;
    }
    return _handle(
      () => _dio.post(
        url(path),
        data: data,
        options: Options(
          contentType: Headers.formUrlEncodedContentType,
          headers: _deviceHeaders(csrf: csrf),
        ),
      ),
    );
  }

  /// طلب POST بجسم JSON لواجهات الهاتف الحديثة.
  Future<Map<String, dynamic>> postJson(
    String path, {
    required Map<String, dynamic> body,
    String? csrf,
  }) {
    final data = _mergeDeviceFields(body);
    return _handle(
      () => _dio.post(
        url(path),
        data: data,
        options: Options(
          contentType: Headers.jsonContentType,
          headers: _deviceHeaders(csrf: csrf),
        ),
      ),
    );
  }

  Future<Map<String, dynamic>> _handle(
    Future<Response<dynamic>> Function() run,
  ) async {
    try {
      final res = await run();
      final map = _asJsonMap(res.data, res.statusCode);

      final ok = map['ok'] == true;
      if (!ok) {
        throw ApiException(
          (map['message'] as String?) ??
              (map['error'] as String?) ??
              'تعذر تنفيذ الطلب.',
          statusCode: res.statusCode,
          code: map['error'] as String?,
        );
      }
      return map;
    } on ApiException {
      rethrow;
    } on DioException catch (e) {
      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.connectionError ||
          e.error is SocketException) {
        throw ApiException('تعذر الاتصال بالسيرفر. تحقق من الإنترنت والعنوان.');
      }
      // ردّ وصل لكن Dio اعتبره خطأ (مثلاً 500 HTML).
      if (e.response != null) {
        throw _asJsonMapError(e.response!.data, e.response!.statusCode);
      }
      throw ApiException('خطأ في الاتصال: ${e.message ?? e.type.name}');
    }
  }

  Map<String, dynamic> _asJsonMap(dynamic data, int? statusCode) {
    if (data is Map<String, dynamic>) {
      return data;
    }
    if (data is Map) {
      return data.map((k, v) => MapEntry(k.toString(), v));
    }
    if (data is String) {
      final trimmed = data.trim();
      if (trimmed.isEmpty) {
        throw ApiException(
          'السيرفر أعاد رداً فارغاً.',
          statusCode: statusCode,
        );
      }
      if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
        try {
          final decoded = jsonDecode(trimmed);
          if (decoded is Map) {
            return decoded.map((k, v) => MapEntry(k.toString(), v));
          }
        } catch (_) {
          // fall through to HTML/message below
        }
      }
      throw _htmlOrUnexpected(trimmed, statusCode);
    }
    if (data == null) {
      throw ApiException(
        'لا يوجد رد من السيرفر (رمز ${statusCode ?? '?'}).',
        statusCode: statusCode,
      );
    }
    throw ApiException(
      'استجابة غير متوقعة من السيرفر.',
      statusCode: statusCode,
    );
  }

  ApiException _asJsonMapError(dynamic data, int? statusCode) {
    try {
      final map = _asJsonMap(data, statusCode);
      return ApiException(
        (map['message'] as String?) ??
            (map['error'] as String?) ??
            'تعذر تنفيذ الطلب.',
        statusCode: statusCode,
        code: map['error'] as String?,
      );
    } on ApiException catch (e) {
      return e;
    }
  }

  ApiException _htmlOrUnexpected(String body, int? statusCode) {
    final lower = body.toLowerCase();
    final looksHtml = lower.contains('<!doctype') ||
        lower.contains('<html') ||
        lower.contains('<body');
    if (statusCode == 404 || (looksHtml && body.contains('404'))) {
      return ApiException(
        'واجهة الدخول غير موجودة على السيرفر. ارفع ملفات api/mobile_session.php و api/mobile_home.php إلى الموقع.',
        statusCode: statusCode,
        code: 'not_found',
      );
    }
    if (looksHtml) {
      return ApiException(
        'السيرفر أعاد صفحة ويب بدل JSON. تأكد أن عنوان النظام صحيح وأن ملفات /api/ مرفوعة.',
        statusCode: statusCode,
      );
    }
    return ApiException(
      'استجابة غير متوقعة من السيرفر.',
      statusCode: statusCode,
    );
  }
}
