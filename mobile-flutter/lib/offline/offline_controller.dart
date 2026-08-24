import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import 'offline_store.dart';

enum OfflinePhase { idle, pulling, pushing }

/// حالة الاتصال + تحميل الكتالوج + ترحيل الطابور تلقائياً.
class OfflineController extends ChangeNotifier {
  OfflineController(this.api);

  final ApiClient api;
  final OfflineStore store = OfflineStore.instance;
  final _uuid = const Uuid();

  Future<String> Function()? csrfProvider;

  StreamSubscription<List<ConnectivityResult>>? _sub;
  bool online = true;
  bool catalogReady = false;
  bool busy = false;
  bool _flushing = false;
  OfflinePhase phase = OfflinePhase.idle;
  String? lastError;
  String? statusMessage;
  OfflineSyncInfo info = const OfflineSyncInfo(hasData: false);
  double pullProgress = 0;

  bool get canWorkOffline => catalogReady;

  Future<void> start() async {
    await refreshInfo();
    final results = await Connectivity().checkConnectivity();
    _applyConnectivity(results, notify: false);
    _sub?.cancel();
    _sub = Connectivity().onConnectivityChanged.listen((r) {
      final wasOnline = online;
      _applyConnectivity(r);
      if (!wasOnline && online) {
        unawaited(flushOutbox(silent: true));
      }
    });
    if (online) {
      unawaited(flushOutbox(silent: true));
    }
  }

  void _applyConnectivity(List<ConnectivityResult> results,
      {bool notify = true}) {
    final next = results.isNotEmpty &&
        results.any((r) => r != ConnectivityResult.none);
    if (next == online) return;
    online = next;
    statusMessage = online ? 'متصل بالشبكة' : 'وضع Offline — يعمل من البيانات المحلية';
    if (notify) notifyListeners();
  }

  Future<void> refreshInfo() async {
    info = await store.syncInfo();
    catalogReady = info.hasData;
    notifyListeners();
  }

  /// زر «تحديث البيانات» — تحميل كامل الكتالوج من السيرفر.
  Future<bool> pullCatalog({void Function(String step)? onStep}) async {
    if (!online) {
      lastError = 'لا يوجد اتصال. اتصل بالإنترنت ثم حدّث البيانات.';
      statusMessage = lastError;
      notifyListeners();
      return false;
    }
    busy = true;
    phase = OfflinePhase.pulling;
    pullProgress = 0.05;
    lastError = null;
    statusMessage = 'جاري تحميل البيانات من السيرفر…';
    onStep?.call(statusMessage!);
    notifyListeners();
    try {
      final res = await api.getJson(
        AppConfig.syncPullPath,
        receiveTimeout: const Duration(minutes: 3),
      );
      pullProgress = 0.65;
      statusMessage = 'جاري حفظ البيانات على الجهاز…';
      onStep?.call(statusMessage!);
      notifyListeners();
      await store.replaceCatalog(res);
      pullProgress = 1;
      await refreshInfo();
      statusMessage =
          'تم التحديث: ${info.customers} عميل، ${info.items} مادة، ${info.warehouses} مستودع.';
      onStep?.call(statusMessage!);
      // بعد التحديث حاول ترحيل أي طابور معلّق
      unawaited(flushOutbox(silent: true));
      return true;
    } on ApiException catch (e) {
      lastError = e.message;
      statusMessage = e.message;
      return false;
    } catch (e) {
      lastError = e.toString();
      statusMessage = lastError;
      return false;
    } finally {
      busy = false;
      phase = OfflinePhase.idle;
      notifyListeners();
    }
  }

  Future<String> enqueue({
    required String kind,
    required String path,
    required Map<String, dynamic> body,
    String? clientUuid,
  }) async {
    final id = (clientUuid == null || clientUuid.isEmpty)
        ? _uuid.v4()
        : clientUuid;
    final payload = Map<String, dynamic>.from(body);
    payload['client_uuid'] = id;
    await store.enqueueOutbox(
      clientUuid: id,
      kind: kind,
      path: path,
      body: payload,
    );
    await refreshInfo();
    if (online) {
      unawaited(flushOutbox(silent: true));
    }
    return id;
  }

  Future<int> flushOutbox({bool silent = false}) async {
    if (!online || _flushing) return 0;
    if (busy && phase == OfflinePhase.pulling) return 0;

    final csrf = csrfProvider == null ? '' : await csrfProvider!();
    if (csrf.isEmpty) return 0;

    final rows = await store.pendingOutbox();
    if (rows.isEmpty) return 0;

    _flushing = true;
    if (!silent) {
      busy = true;
      phase = OfflinePhase.pushing;
      statusMessage = 'جاري ترحيل البيانات المعلّقة…';
      notifyListeners();
    }

    var okCount = 0;
    try {
      for (final row in rows) {
        final id = row['id'] as int;
        final path = row['path'] as String;
        final method = (row['method'] as String?) ?? 'POST_JSON';
        Map<String, dynamic> body;
        try {
          body =
              jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
        } catch (_) {
          await store.markOutboxError(id, 'جسم الطلب تالف');
          continue;
        }
        try {
          Map<String, dynamic> res;
          if (method == 'POST_FORM') {
            res = await api.postForm(path, fields: body, csrf: csrf);
          } else {
            res = await api.postJson(path, body: body, csrf: csrf);
          }
          final kind = (row['kind'] as String?) ?? '';
          if (kind == 'visit_checkin') {
            final visit = (res['visit'] as Map?)?.cast<String, dynamic>();
            final lineId = (visit?['route_line_id'] as num?)?.toInt() ??
                (res['route_line_id'] as num?)?.toInt() ??
                0;
            final cid = (body['customer_id'] as num?)?.toInt() ?? 0;
            if (lineId > 0 && cid > 0) {
              await store.rewritePendingOrdersVisitLine(
                customerId: cid,
                routeLineId: lineId,
              );
            }
          }
          await store.markOutboxDone(id);
          okCount++;
        } on ApiException catch (e) {
          if (e.message.contains('تعذر الاتصال') ||
              e.message.contains('الإنترنت')) {
            online = false;
            break;
          }
          await store.markOutboxError(id, e.message);
        } catch (e) {
          await store.markOutboxError(id, e.toString());
        }
      }
    } finally {
      _flushing = false;
      await refreshInfo();
      if (!silent) {
        busy = false;
        phase = OfflinePhase.idle;
        statusMessage = okCount > 0
            ? 'تم ترحيل $okCount عملية.'
            : (info.pendingOutbox > 0
                ? 'بقي ${info.pendingOutbox} بانتظار الترحيل.'
                : 'لا توجد عمليات معلّقة.');
      }
      notifyListeners();
    }
    return okCount;
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }
}
