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
  Future<String> Function()? csrfRefresh;

  StreamSubscription<List<ConnectivityResult>>? _sub;
  Timer? _reconnectFlushTimer;
  Timer? _pingTimer;
  Timer? _autoFlushTimer;
  bool _probing = false;
  bool online = true;
  /// نتيجة فحص السيرفر (`m/ping.php`) وليس مجرد وجود شبكة.
  bool serverReachable = false;
  bool catalogReady = false;
  bool busy = false;
  bool _flushing = false;
  OfflinePhase phase = OfflinePhase.idle;
  String? lastError;
  String? statusMessage;
  OfflineSyncInfo info = const OfflineSyncInfo(hasData: false);
  double pullProgress = 0;
  DateTime? flushScheduledAt;

  bool get canWorkOffline => catalogReady;

  /// أخضر عندما يرد السيرفر — لا نعتمد على connectivity_plus وحده.
  bool get serverConnected => serverReachable;

  static const reconnectFlushDelay = Duration(seconds: 2);

  /// يتجنّب ترحيل الطلب المفتوح إذا كان قيد التعديل (تُضبطه شاشة الطلب).
  int? Function()? skipDirtyOrderId;

  /// بعد الترحيل التلقائي — لتحديث شاشة الطلب المفتوحة.
  void Function(List<int> ids)? onOrdersSentFromSync;

  Future<void> start() async {
    api.onHttpSuccess = _onHttpSuccess;
    await refreshInfo();
    final results = await Connectivity().checkConnectivity();
    _applyConnectivity(results, notify: false);
    _sub?.cancel();
    _sub = Connectivity().onConnectivityChanged.listen((r) {
      final wasOnline = online;
      _applyConnectivity(r);
      if (!wasOnline && online) {
        _scheduleReconnectFlush();
      }
    });
    _pingTimer?.cancel();
    _pingTimer = Timer.periodic(const Duration(seconds: 12), (_) {
      unawaited(_recoverConnection());
    });
    _autoFlushTimer?.cancel();
    _autoFlushTimer = Timer.periodic(const Duration(seconds: 20), (_) {
      if (info.pendingOutbox > 0 || info.ordersPending > 0) {
        unawaited(flushAndAutoPost());
      }
    });
    unawaited(_recoverConnection(initial: true));
  }

  void _onHttpSuccess() {
    final was = serverReachable;
    if (!was) {
      _setReachable(true);
      if (info.pendingOutbox > 0 || info.ordersPending > 0) {
        unawaited(flushAndAutoPost());
      }
    }
  }

  /// من أيقونة الواي فاي — إعادة فحص وترحيل المعلّق.
  Future<void> retryConnection() => _recoverConnection(initial: true);

  void _scheduleReconnectFlush() {
    _reconnectFlushTimer?.cancel();
    flushScheduledAt = DateTime.now().add(reconnectFlushDelay);
    statusMessage = 'عادت الشبكة — جاري الاتصال بالسيرفر وترحيل البيانات…';
    notifyListeners();
    _reconnectFlushTimer = Timer(reconnectFlushDelay, () {
      flushScheduledAt = null;
      unawaited(_recoverConnection(initial: true));
      notifyListeners();
    });
  }

  void _cancelReconnectFlush() {
    _reconnectFlushTimer?.cancel();
    _reconnectFlushTimer = null;
    flushScheduledAt = null;
  }

  void _applyConnectivity(List<ConnectivityResult> results,
      {bool notify = true}) {
    // قائمة فارغة = غير معروف؛ لا نعتبرها انقطاعاً.
    final next = results.isEmpty ||
        results.any((r) => r != ConnectivityResult.none);
    if (next == online) return;
    online = next;
    if (!online) {
      statusMessage = 'وضع Offline — يعمل من البيانات المحلية';
    } else {
      statusMessage = 'متصل بالشبكة — جاري التحقق من السيرفر…';
    }
    if (notify) notifyListeners();
  }

  void _setReachable(bool ok) {
    if (serverReachable == ok) return;
    serverReachable = ok;
    if (ok) {
      online = true;
    }
    statusMessage = ok
        ? 'متصل بالسيرفر'
        : (online
            ? 'لا يوجد اتصال بالسيرفر'
            : 'وضع Offline — يعمل من البيانات المحلية');
    notifyListeners();
  }

  Future<bool> _pingServer() async {
    if (api.base.isEmpty) return false;
    try {
      return await api.ping();
    } catch (_) {
      return false;
    }
  }

  Future<void> _recoverConnection({bool initial = false}) async {
    if (_probing) return;
    _probing = true;
    try {
      final attempts = initial ? 3 : 2;
      for (var i = 0; i < attempts; i++) {
        final ok = await _pingServer();
        final was = serverReachable;
        _setReachable(ok);
        if (ok) {
          if (!was || initial) {
            await refreshInfo();
            await flushAndAutoPost();
          } else if (info.pendingOutbox > 0 || info.ordersPending > 0) {
            await flushAndAutoPost();
          }
          return;
        }
        if (i < attempts - 1) {
          await Future<void>.delayed(const Duration(seconds: 2));
        }
      }
    } finally {
      _probing = false;
    }
  }

  /// ترحيل الطابور ثم إرسال الطلبات المحفوظة غير المرحَّلة تلقائياً.
  Future<int> flushAndAutoPost({bool silent = true}) async {
    await _waitNotFlushing();
    var total = 0;
    for (var round = 0; round < 4; round++) {
      final n = await flushOutbox(silent: silent || round > 0);
      total += n;
      final queued = await _queueUnsentOrderSends();
      await refreshInfo();
      if (queued == 0 && n == 0) break;
      if (info.pendingOutbox < 1) break;
    }
    if (!silent) {
      statusMessage = total > 0
          ? 'تم ترحيل $total عملية.'
          : (info.pendingOutbox > 0
              ? (lastError ?? 'بقي ${info.pendingOutbox} بانتظار الترحيل.')
              : 'لا توجد عمليات معلّقة.');
      notifyListeners();
    }
    return total;
  }

  Future<void> _waitNotFlushing() async {
    for (var i = 0; i < 40; i++) {
      if (!_flushing) return;
      await Future<void>.delayed(const Duration(milliseconds: 150));
    }
    _flushing = false;
  }

  Future<int> _queueUnsentOrderSends() async {
    if (!serverReachable) return 0;
    final pending = await store.pendingOutbox(limit: 200);
    final already = <int>{};
    for (final row in pending) {
      if ((row['kind'] as String?) != 'customer_order_send') continue;
      try {
        final body =
            jsonDecode(row['body_json'] as String) as Map<String, dynamic>;
        for (final id in (body['ids'] as List? ?? const [])) {
          already.add((id as num?)?.toInt() ?? 0);
        }
      } catch (_) {}
    }
    final skip = skipDirtyOrderId?.call() ?? 0;
    final unsent = await store.listOrders(isSent: 0, limit: 200);
    final toSend = <int>[];
    for (final o in unsent) {
      final id = (o['id'] as num?)?.toInt() ?? 0;
      if (id <= 0) continue;
      if (id == skip) continue;
      if (already.contains(id)) continue;
      toSend.add(id);
    }
    if (toSend.isEmpty) return 0;
    final uuid = _uuid.v4();
    await store.enqueueOutbox(
      clientUuid: uuid,
      kind: 'customer_order_send',
      path: AppConfig.customerOrderSendPath,
      body: {'ids': toSend, 'client_uuid': uuid},
    );
    return toSend.length;
  }

  Future<void> refreshInfo() async {
    final next = await store.syncInfo();
    final changed = next.hasData != info.hasData ||
        next.pendingOutbox != info.pendingOutbox ||
        next.ordersPending != info.ordersPending ||
        next.syncedAt != info.syncedAt;
    info = next;
    catalogReady = info.hasData;
    if (changed) notifyListeners();
  }

  /// زر «تحديث البيانات» — تحميل كامل الكتالوج من السيرفر.
  Future<bool> pullCatalog({
    void Function(String step)? onStep,
    bool forceFull = true,
  }) async {
    if (!online && !serverReachable) {
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
      final since = forceFull ? '' : (info.syncedAt ?? '').trim();
      final res = await api.getJson(
        AppConfig.syncPullPath,
        query: since.isEmpty ? const {} : {'since': since},
        receiveTimeout: const Duration(minutes: 5),
      );
      pullProgress = 0.65;
      statusMessage = 'جاري حفظ البيانات على الجهاز…';
      onStep?.call(statusMessage!);
      notifyListeners();
      await store.replaceCatalog(res);
      pullProgress = 1;
      await refreshInfo();
      final delta = res['customers_delta'] == true;
      statusMessage = delta
          ? 'تحديث تزايدي: ${info.customers} عميل محلي، ${info.oracleStatements} كشف، ${info.routeDays} يوم جولة.'
          : 'تم التحديث: ${info.customers} عميل، ${info.items} مادة، ${info.ordersPending} طلب غير مرسل، ${info.oracleStatements} كشف حساب.';
      onStep?.call(statusMessage!);
      if (info.noOrderReasons < 1) {
        statusMessage =
            '$statusMessage\nتنبيه: لم تُحمَّل أسباب عدم الطلب — أعد التحديث بعد نشر API المحدّث.';
      }
      unawaited(flushAndAutoPost());
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
    String method = 'POST_JSON',
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
      method: method,
    );
    await refreshInfo();
    unawaited(flushOutbox(silent: true));
    return id;
  }

  /// ترحيل فوري عند فتح شاشة تحتاج مزامنة، إن كان الجهاز متصلاً.
  Future<int> syncIfOnline() async {
    return flushOutbox(silent: true);
  }

  bool _alreadyOnServer(String message) {
    final m = message.toLowerCase();
    return m.contains('مسبقا') ||
        m.contains('مسجّل') ||
        m.contains('مسجل') ||
        m.contains('already') ||
        m.contains('duplicate') ||
        m.contains('تم الترحيل') ||
        m.contains('تم الحفظ سابقا');
  }

  Future<int> flushOutbox({bool silent = false}) async {
    if (_flushing) return 0;
    if (busy && phase == OfflinePhase.pulling) return 0;

    var csrf = csrfProvider == null ? '' : await csrfProvider!();
    if (csrf.isEmpty) {
      lastError = 'تعذر الترحيل: لا توجد جلسة صالحة. أعد تسجيل الدخول.';
      statusMessage = lastError;
      if (!silent) notifyListeners();
      return 0;
    }

    final rows = await store.pendingOutbox(limit: 200);
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
          final sendBody = Map<String, dynamic>.from(body);
          sendBody.remove('local_customer_id');
          sendBody.remove('local_order_id');
          sendBody.remove('snapshot');

          Map<String, dynamic> res;
          Future<Map<String, dynamic>> send() async {
            if (method == 'POST_FORM') {
              final fields = <String, dynamic>{};
              sendBody.forEach((k, v) {
                if (v == null) return;
                fields[k] = v;
              });
              return api.postForm(path, fields: fields, csrf: csrf);
            }
            return api.postJson(path, body: sendBody, csrf: csrf);
          }

          try {
            res = await send();
          } on ApiException catch (e) {
            final csrfFail = e.code == 'csrf' ||
                e.isUnauthorized ||
                e.message.toLowerCase().contains('csrf');
            if (csrfFail) {
              if (csrfRefresh != null) {
                csrf = await csrfRefresh!();
              } else if (csrfProvider != null) {
                csrf = await csrfProvider!();
              }
              if (csrf.isNotEmpty) {
                res = await send();
              } else {
                rethrow;
              }
            } else {
              rethrow;
            }
          }
          final kind = (row['kind'] as String?) ?? '';
          await _afterFlushSuccess(kind: kind, body: body, res: res);
          await store.markOutboxDone(id);
          okCount++;
        } on ApiException catch (e) {
          if (e.message.contains('تعذر الاتصال') ||
              e.message.contains('الإنترنت')) {
            _setReachable(false);
            lastError = e.message;
            break;
          }
          if (_alreadyOnServer(e.message)) {
            final kind = (row['kind'] as String?) ?? '';
            await _afterFlushSuccess(kind: kind, body: body, res: {'ok': true});
            await store.markOutboxDone(id);
            okCount++;
            continue;
          }
          final kind = (row['kind'] as String?) ?? '';
          if (kind == 'customer_delete') {
            await _restoreDeletedCustomer(body);
          }
          lastError = e.message;
          await store.markOutboxError(id, e.message);
        } catch (e) {
          final kind = (row['kind'] as String?) ?? '';
          if (kind == 'customer_delete') {
            await _restoreDeletedCustomer(body);
          }
          lastError = e.toString();
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
                ? (lastError ?? 'بقي ${info.pendingOutbox} بانتظار الترحيل.')
                : 'لا توجد عمليات معلّقة.');
      } else if (okCount > 0) {
        lastError = null;
        statusMessage = 'تم ترحيل $okCount عملية تلقائياً.';
      }
      notifyListeners();
    }
    return okCount;
  }

  Future<void> _afterFlushSuccess({
    required String kind,
    required Map<String, dynamic> body,
    required Map<String, dynamic> res,
  }) async {
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
      return;
    }
    if (kind == 'customer_update') {
      final cid = (body['id'] as num?)?.toInt() ??
          (body['customer_id'] as num?)?.toInt() ??
          0;
      if (cid != 0) {
        final existing = await store.getCustomerById(cid);
        await store.upsertLocalCustomer(
          id: cid,
          name: (existing?['name'] ?? '').toString(),
          code: (existing?['code'] ?? '').toString(),
          phone: (body['phone'] ?? existing?['phone'] ?? '').toString(),
          address:
              (body['address_ar'] ?? existing?['address'] ?? '').toString(),
          latitude: (body['latitude'] as num?)?.toDouble() ??
              (existing?['latitude'] as num?)?.toDouble(),
          longitude: (body['longitude'] as num?)?.toDouble() ??
              (existing?['longitude'] as num?)?.toDouble(),
          paymentPeriod: (existing?['payment_period'] as num?)?.toInt() ?? 0,
        );
      }
      return;
    }
    if (kind == 'customer_delete') {
      final cid = (body['id'] as num?)?.toInt() ??
          (body['customer_id'] as num?)?.toInt() ??
          0;
      if (cid != 0) {
        await store.deleteLocalCustomer(cid);
      }
      return;
    }
    if (kind == 'customer_save') {
      final localId = (body['local_customer_id'] as num?)?.toInt() ?? 0;
      final cust = (res['customer'] as Map?)?.cast<String, dynamic>();
      final serverId = (cust?['id'] as num?)?.toInt() ?? 0;
      if (localId < 0 && serverId > 0) {
        await store.rewriteLocalCustomerId(localId, serverId);
      }
      return;
    }
    if (kind == 'customer_order_save') {
      final localId = (body['local_order_id'] as num?)?.toInt() ??
          (body['id'] as num?)?.toInt() ??
          0;
      final serverId = (res['order_id'] as num?)?.toInt() ??
          (res['id'] as num?)?.toInt() ??
          0;
      final orderNo = (res['order_no'] ?? '').toString();
      if (localId < 0 && serverId > 0) {
        await store.rewriteLocalOrderId(
          localId: localId,
          serverId: serverId,
          orderNo: orderNo,
        );
      }
      return;
    }
    if (kind == 'customer_order_send') {
      final ids = (body['ids'] as List? ?? [])
          .map((e) => (e as num?)?.toInt() ?? 0)
          .where((e) => e != 0)
          .toList();
      if (ids.isNotEmpty) {
        await store.markOrderSent(ids);
        onOrdersSentFromSync?.call(ids);
      }
      return;
    }
    if (kind == 'customer_order_delete') {
      final oid = (body['id'] as num?)?.toInt() ?? 0;
      if (oid != 0) {
        await store.deleteLocalOrder(oid);
      }
    }
  }

  Future<void> _restoreDeletedCustomer(Map<String, dynamic> body) async {
    final snap = body['snapshot'];
    if (snap is! Map) return;
    final id = (snap['id'] as num?)?.toInt() ??
        (body['id'] as num?)?.toInt() ??
        0;
    if (id == 0) return;
    await store.upsertLocalCustomer(
      id: id,
      name: (snap['name'] ?? '').toString(),
      code: (snap['code'] ?? '').toString(),
      phone: (snap['phone'] ?? '').toString(),
      address: (snap['address'] ?? '').toString(),
      latitude: (snap['latitude'] as num?)?.toDouble(),
      longitude: (snap['longitude'] as num?)?.toDouble(),
      paymentPeriod: (snap['payment_period'] as num?)?.toInt() ?? 0,
    );
  }

  @override
  void dispose() {
    _sub?.cancel();
    _pingTimer?.cancel();
    _autoFlushTimer?.cancel();
    _cancelReconnectFlush();
    api.onHttpSuccess = null;
    super.dispose();
  }
}
