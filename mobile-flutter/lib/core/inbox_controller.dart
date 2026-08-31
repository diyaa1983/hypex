import 'dart:async';

import 'package:flutter/widgets.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/format.dart';
import '../core/session.dart';
import '../offline/offline_store.dart';

class InboxItem {
  InboxItem({
    required this.id,
    required this.kind,
    required this.title,
    required this.body,
    required this.isRead,
    required this.createdAt,
    required this.customerId,
    this.customerName = '',
    this.latitude,
    this.longitude,
    this.clearGps = false,
  });

  final int id;
  final String kind;
  final String title;
  final String body;
  final bool isRead;
  final String createdAt;
  final int customerId;
  final String customerName;
  final double? latitude;
  final double? longitude;
  final bool clearGps;

  bool get isGpsApproved => kind == 'gps_change_approved';

  factory InboxItem.fromJson(Map<String, dynamic> m) {
    double? numOrNull(dynamic v) {
      if (v == null) return null;
      if (v is num) return v.toDouble();
      return double.tryParse(v.toString());
    }

    return InboxItem(
      id: Fmt.toInt(m['id']),
      kind: Fmt.str(m['kind']),
      title: Fmt.str(m['title']),
      body: Fmt.str(m['body']),
      isRead: m['is_read'] == true || m['is_read'] == 1,
      createdAt: Fmt.str(m['created_at']),
      customerId: Fmt.toInt(m['customer_id']),
      customerName: Fmt.str(m['customer_name']),
      latitude: numOrNull(m['latitude']),
      longitude: numOrNull(m['longitude']),
      clearGps: m['clear_gps'] == true || m['clear_gps'] == 1,
    );
  }
}

/// صندوق إشعارات المندوب على التاب — يعتمد على السيرفر ويُحدَّث دورياً.
class InboxController extends ChangeNotifier with WidgetsBindingObserver {
  InboxController(this.api, this.session);

  final ApiClient api;
  final SessionController session;

  Timer? _timer;
  bool _polling = false;
  bool _started = false;
  List<InboxItem> items = const [];
  int unreadCount = 0;

  void start() {
    if (_started) return;
    _started = true;
    WidgetsBinding.instance.addObserver(this);
    session.addListener(_onSession);
    _onSession();
  }

  @override
  void dispose() {
    _timer?.cancel();
    session.removeListener(_onSession);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && session.authenticated) {
      unawaited(refresh());
    }
  }

  void _onSession() {
    if (session.authenticated) {
      unawaited(refresh());
      _timer ??= Timer.periodic(const Duration(seconds: 10), (_) {
        unawaited(refresh());
      });
    } else {
      _timer?.cancel();
      _timer = null;
      if (items.isNotEmpty || unreadCount != 0) {
        items = const [];
        unreadCount = 0;
        notifyListeners();
      }
    }
  }

  Future<void> applyFromMap(Map<String, dynamic> res) async {
    if (!res.containsKey('items') && !res.containsKey('inbox_items')) return;
    final rawItems = res['inbox_items'] ?? res['items'];
    final list = (rawItems as List? ?? [])
        .whereType<Map>()
        .map((e) => InboxItem.fromJson(e.cast<String, dynamic>()))
        .toList();
    final unread = res.containsKey('inbox_unread')
        ? Fmt.toInt(res['inbox_unread'])
        : Fmt.toInt(res['unread_count']);
    final changed = unread != unreadCount ||
        list.length != items.length ||
        (list.isNotEmpty && items.isNotEmpty && list.first.id != items.first.id);
    items = list;
    unreadCount = unread;
    await _applyApprovedGps(list);
    if (changed) notifyListeners();
  }

  Future<void> refresh() async {
    if (!session.authenticated || api.base.isEmpty || _polling) return;
    _polling = true;
    try {
      Map<String, dynamic>? res;
      for (final path in [AppConfig.inboxPath, AppConfig.inboxNodePath]) {
        try {
          final got = await api.getJson(path);
          if (got['items'] is List || got['inbox_items'] is List) {
            res = got;
            break;
          }
        } catch (_) {}
      }
      if (res != null) await applyFromMap(res);
    } finally {
      _polling = false;
    }
  }

  Future<void> _applyApprovedGps(List<InboxItem> list) async {
    for (final it in list) {
      if (!it.isGpsApproved || it.isRead || it.customerId < 1) continue;
      try {
        await OfflineStore.instance.patchCustomerGps(
          it.customerId,
          latitude: it.clearGps ? null : it.latitude,
          longitude: it.clearGps ? null : it.longitude,
        );
      } catch (_) {}
    }
  }

  Future<void> markAllRead() async {
    if (!session.authenticated) return;
    try {
      Map<String, dynamic>? res;
      for (final path in [AppConfig.inboxPath, AppConfig.inboxNodePath]) {
        try {
          res = await api.postJson(
            path,
            body: {'action': 'mark_all_read'},
            csrf: await session.ensureCsrf(),
          );
          break;
        } catch (_) {}
      }
      if (res != null) await applyFromMap(res);
    } catch (_) {}
  }
}
