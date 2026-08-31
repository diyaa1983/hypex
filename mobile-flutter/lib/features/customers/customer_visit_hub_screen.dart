import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/visit_status.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/fullscreen_binder.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';
import '../../widgets/app_confirm_dialog.dart';
import '../../widgets/location_map_picker.dart';
import '../gps/gps_map_tiles.dart';
import '../party/party_statement_screen.dart';
import '../customer_orders/customer_order_form_screen.dart';

/// مركز زيارة العملاء: قائمة + تفاصيل مع تبويبات (لوحة عريضة / ضيقة).
class CustomerVisitHubScreen extends StatefulWidget {
  const CustomerVisitHubScreen({
    super.key,
    this.initialCustomerId,
  });

  final int? initialCustomerId;

  @override
  State<CustomerVisitHubScreen> createState() => _CustomerVisitHubScreenState();
}

class _CustomerVisitHubScreenState extends State<CustomerVisitHubScreen>
    with WidgetsBindingObserver {
  final _search = TextEditingController();
  final _searchFocus = FocusNode();
  Timer? _debounce;

  bool _listLoading = true;
  String? _listError;
  List<Map<String, dynamic>> _customers = [];

  /// حالة زيارة اليوم لكل عميل: checked_in | pending_manual_checkout | checked_out | idle
  final Map<int, String> _visitStatusByCustomer = {};
  final Map<int, String> _visitCheckinAtByCustomer = {};
  final Map<int, String> _visitCheckoutAtByCustomer = {};

  /// طلب الشراء المحفوظ للزيارة الحالية (يبقى حتى الخروج).
  int _visitOrderId = 0;

  int? _selectedId;
  bool _detailLoading = false;
  String? _detailError;
  Map<String, dynamic>? _customer;
  Map<String, dynamic>? _visit;
  List<Map<String, dynamic>> _noOrderReasons = [];
  int _radiusM = 200;

  /// عميل الزيارة المفتوحة حالياً (إن وُجدت).
  int _openVisitCustomerId = 0;
  String _openVisitCheckinAt = '';
  String _openVisitCheckinMethod = '';

  bool _busy = false;
  bool _showNarrowDetail = false;

  List<Map<String, dynamic>> _histOrders = [];
  bool _histOrdersLoading = false;
  String? _histOrdersError;
  DateTime _histOrdersFrom =
      DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _histOrdersTo = DateTime.now();

  List<Map<String, dynamic>> _histInvoices = [];
  bool _histInvoicesLoading = false;
  String? _histInvoicesError;
  DateTime _histInvoicesFrom =
      DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _histInvoicesTo = DateTime.now();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _searchFocus.addListener(() {
      if (!mounted) return;
      if (_searchFocus.hasFocus) {
        unawaited(FullscreenBinder.allowKeyboard());
      }
      setState(() {});
    });
    final off = context.read<OfflineController>();
    _restoreLocalVisit().then((_) async {
      if (off.online) {
        await off.syncIfOnline();
      }
      if (!mounted) return;
      _loadCustomers();
      _refreshOpenVisit();
    });
    final bootId = widget.initialCustomerId ?? 0;
    if (bootId > 0) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _selectCustomer(bootId, openNarrowDetail: true);
      });
    }
  }

  Future<void> _restoreLocalVisit() async {
    try {
      final v = await OfflineStore.instance.loadOpenVisit();
      if (v == null || !mounted) return;
      final cid = Fmt.toInt(v['customer_id']);
      if (cid < 1) return;
      setState(() {
        _openVisitCustomerId = cid;
        _openVisitCheckinAt = Fmt.str(v['visit_checkin_at']);
        _openVisitCheckinMethod = Fmt.str(v['method']).isEmpty
            ? 'MANUAL'
            : Fmt.str(v['method']);
        _visitStatusByCustomer[cid] = 'checked_in';
        _visitCheckinAtByCustomer[cid] = Fmt.str(v['visit_checkin_at']);
        final r = Fmt.toInt(v['visit_radius_m']);
        if (r > 0) _radiusM = r;
        _putOpenCustomerFirst();
      });
    } catch (_) {}
  }

  int _offlineRouteLineId(int customerId) => -(1000000000 + customerId);

  Future<void> _persistOpenVisit({
    required int customerId,
    required String checkinAt,
    required int routeLineId,
    String method = 'MANUAL',
  }) async {
    await OfflineStore.instance.saveOpenVisit({
      'customer_id': customerId,
      'visit_checkin_at': checkinAt,
      'route_line_id': routeLineId,
      'method': method,
      'visit_radius_m': _radiusM,
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _debounce?.cancel();
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _onSearchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 280), () {
      _loadCustomers();
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      final off = context.read<OfflineController>();
      if (off.online) {
        unawaited(off.syncIfOnline().then((_) {
          if (mounted) _loadCustomers();
        }));
      }
      _refreshOpenVisit();
    }
  }

  String _effectiveVisitStatus(int customerId) {
    final raw = _visitStatusByCustomer[customerId] ?? '';
    return VisitStatus.effective(
      status: raw,
      checkinAt: _visitCheckinAtByCustomer[customerId],
      checkoutAt: _visitCheckoutAtByCustomer[customerId],
      referenceDate: Fmt.todayIso(),
    );
  }

  void _onVisitOrderSaved(int orderId) {
    // Offline: orderId قد يكون -1 أو 0 بعد الحفظ المحلي
    if (!mounted) return;
    setState(() {
      if (orderId > 0) _visitOrderId = orderId;
      if (_visit != null) {
        _visit = Map<String, dynamic>.from(_visit!)..['has_order'] = true;
      }
    });
    if (_selectedId != null && orderId > 0) {
      _loadHistOrders(_selectedId!);
    }
  }

  void _onVisitOrderDeleted() {
    if (!mounted) return;
    setState(() => _visitOrderId = 0);
    if (_visit != null) {
      _visit!['has_order'] = false;
      _visit!['order_id'] = 0;
    }
    final id = _selectedId;
    if (id != null && id > 0) {
      _selectCustomer(id);
    }
  }

  bool _isNetworkFail(Object e) {
    final m = e is ApiException ? e.message : e.toString();
    return m.contains('تعذر الاتصال') ||
        m.contains('الإنترنت') ||
        m.contains('SocketException') ||
        m.contains('connection');
  }

  Future<List<Map<String, dynamic>>> _customersFromLocal(String q) async {
    final rows = await OfflineStore.instance.searchCustomers(q, limit: 2000);
    return rows
        .map(
          (e) => <String, dynamic>{
            'id': e['id'],
            'name': e['name'],
            'code': e['code'],
            'phone': e['phone'],
            'address': e['address'],
            'latitude': e['latitude'],
            'longitude': e['longitude'],
          },
        )
        .toList();
  }

  Future<Map<String, dynamic>?> _customerFromLocal(int id) async {
    final e = await OfflineStore.instance.getCustomerById(id);
    if (e == null) return null;
    return {
      'id': e['id'],
      'name': e['name'],
      'code': e['code'],
      'phone': e['phone'],
      'address': e['address'],
      'address_ar': e['address'],
      'latitude': e['latitude'],
      'longitude': e['longitude'],
      'payment_period': e['payment_period'],
    };
  }

  Future<void> _loadCustomers() async {
    setState(() {
      if (_customers.isEmpty) _listLoading = true;
      _listError = null;
    });
    final offline = context.read<OfflineController>();
    final q = _search.text.trim();
    try {
      List<Map<String, dynamic>> list;
      if (!offline.online && offline.catalogReady) {
        list = await _customersFromLocal(q);
      } else {
        try {
          final res = await context.read<ApiClient>().getJson(
            AppConfig.partiesPath,
            query: {'type': 'customer', 'q': q},
          );
          list = (res['parties'] as List? ?? [])
              .whereType<Map>()
              .map((e) => e.cast<String, dynamic>())
              .toList();
        } on ApiException catch (e) {
          if (offline.catalogReady && _isNetworkFail(e)) {
            list = await _customersFromLocal(q);
          } else {
            rethrow;
          }
        }
      }
      if (!mounted) return;
      setState(() {
        _customers = list;
        _listLoading = false;
        _putOpenCustomerFirst();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        try {
          final list = await _customersFromLocal(q);
          if (!mounted) return;
          setState(() {
            _customers = list;
            _listLoading = false;
            _listError = null;
            _putOpenCustomerFirst();
          });
          return;
        } catch (_) {}
      }
      setState(() {
        _listError = e.message;
        _listLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        try {
          final list = await _customersFromLocal(q);
          if (!mounted) return;
          setState(() {
            _customers = list;
            _listLoading = false;
            _listError = null;
            _putOpenCustomerFirst();
          });
          return;
        } catch (_) {}
      }
      setState(() {
        _listError = e.toString();
        _listLoading = false;
      });
    }
  }

  Future<void> _refreshOpenVisit() async {
    final offline = context.read<OfflineController>();
    if (!offline.online) return;
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.repVisitListPath,
        query: {'date': Fmt.todayIso()},
      );
      if (!mounted) return;
      final visits = (res['visits'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>());
      var openId = 0;
      var checkinAt = '';
      var checkinMethod = '';
      final statusMap = <int, String>{};
      final checkinMap = <int, String>{};
      final checkoutMap = <int, String>{};
      for (final v in visits) {
        final cid = Fmt.toInt(v['customer_id']);
        if (cid < 1) continue;
        final s = Fmt.str(v['status']);
        if (s.isNotEmpty) statusMap[cid] = s;
        final cin = Fmt.str(v['visit_checkin_at']);
        final cout = Fmt.str(v['visit_checkout_at']);
        if (cin.isNotEmpty) checkinMap[cid] = cin;
        if (cout.isNotEmpty) checkoutMap[cid] = cout;
        if (openId == 0 &&
            (s == 'checked_in' || s == 'pending_manual_checkout')) {
          openId = cid;
          checkinAt = Fmt.str(v['visit_checkin_at']);
          checkinMethod = Fmt.str(v['checkin_method']);
        }
      }
      setState(() {
        _visitStatusByCustomer
          ..clear()
          ..addAll(statusMap);
        _visitCheckinAtByCustomer
          ..clear()
          ..addAll(checkinMap);
        _visitCheckoutAtByCustomer
          ..clear()
          ..addAll(checkoutMap);
        _openVisitCustomerId = openId;
        _openVisitCheckinAt = checkinAt;
        if (checkinMethod.isNotEmpty) {
          _openVisitCheckinMethod = checkinMethod;
        }
        _putOpenCustomerFirst();
        final r = Fmt.toInt(res['visit_radius_m']);
        if (r > 0) _radiusM = r;
      });
    } catch (_) {}
  }

  Color? _visitRowColor(int customerId) {
    final s = _effectiveVisitStatus(customerId);
    if (s == 'checked_in' || s == 'pending_manual_checkout') {
      return AppTheme.success.withValues(alpha: 0.14);
    }
    if (s == 'checked_out') {
      return AppTheme.danger.withValues(alpha: 0.14);
    }
    return null;
  }

  Color _visitAccent(int customerId) {
    final s = _effectiveVisitStatus(customerId);
    if (s == 'checked_in' || s == 'pending_manual_checkout') {
      return AppTheme.success;
    }
    if (s == 'checked_out') return AppTheme.danger;
    return AppTheme.primary;
  }

  bool get _selectedIsOpen =>
      _selectedId != null &&
      _selectedId! > 0 &&
      _selectedId == _openVisitCustomerId;

  bool get _openVisitWasManual {
    final m = Fmt.str(_visit?['checkin_method']);
    final raw = m.isNotEmpty ? m : _openVisitCheckinMethod;
    return raw.toUpperCase() == 'MANUAL';
  }

  void _putOpenCustomerFirst() {
    if (_search.text.trim().isNotEmpty) return;
    final id = _openVisitCustomerId;
    if (id <= 0 || _customers.length < 2) return;
    final i = _customers.indexWhere((c) => Fmt.toInt(c['id']) == id);
    if (i <= 0) return;
    final row = _customers.removeAt(i);
    _customers.insert(0, row);
  }

  bool get _hasOpenVisit => _openVisitCustomerId > 0;

  bool _visitOpenFromMap(Map<String, dynamic>? v) {
    if (v == null) return false;
    final cin = Fmt.str(v['visit_checkin_at']);
    final cout = Fmt.str(v['visit_checkout_at']);
    return cin.isNotEmpty && cout.isEmpty;
  }

  void _openOrderForActiveVisit() {
    final c = _customer;
    final visitLineId = Fmt.toInt(_visit?['route_line_id']);
    if (c == null || !_selectedIsOpen || visitLineId < 1) {
      showSnack(context, 'سجّل الدخول عند العميل أولاً لإنشاء طلبية.',
          error: true);
      return;
    }
    context
        .push(
      '/customer-orders/new?customer_id=${Fmt.toInt(c['id'])}'
      '&customer_name=${Uri.encodeComponent(Fmt.str(c['name']))}'
      '&customer_code=${Uri.encodeComponent(Fmt.str(c['code']))}'
      '&visit_route_line_id=$visitLineId',
    )
        .then((result) {
      if (!mounted) return;
      if (result is int && result > 0) {
        _onVisitOrderSaved(result);
      }
    });
  }

  Future<void> _selectCustomer(int id, {bool openNarrowDetail = false}) async {
    if (id == 0) return;
    _searchFocus.unfocus();
    // أعد التحقق من الخادم قبل فتح عميل آخر؛ قد تكون حالة الزيارة
    // المحلية قديمة بعد نجاح الخروج أو بعد اعتماد طلب خروج يدوي.
    if (_hasOpenVisit && id != _openVisitCustomerId) {
      await _refreshOpenVisit();
      if (!mounted) return;
    }
    // يمكن تصفح بيانات عميل آخر حتى لو كان الخروج اليدوي السابق قيد
    // الاعتماد. منع بدء زيارة ثانية يبقى في إجراء تسجيل الدخول نفسه.
    setState(() {
      if (_selectedId != null && _selectedId != id) _visitOrderId = 0;
      _selectedId = id;
      if (openNarrowDetail) _showNarrowDetail = true;
      _detailLoading = true;
      _detailError = null;
      _customer = null;
      _visit = null;
      _histOrders = [];
      _histOrdersError = null;
    });
    try {
      final offline = context.read<OfflineController>();
      Map<String, dynamic>? c;
      Map<String, dynamic>? v;
      var noOrderReasons = <Map<String, dynamic>>[];
      var radius = _radiusM;

      Future<void> applyLocal() async {
        c = await _customerFromLocal(id);
        if (c == null) {
          throw ApiException(
              'العميل غير موجود في البيانات المحلية. حدّث البيانات وأنت متصل.');
        }
        final reasons = await OfflineStore.instance.noOrderReasons();
        noOrderReasons = reasons
            .map((e) => {
                  'id': e['id'],
                  'name_ar': e['name_ar'],
                })
            .toList();
        final rLocal = await OfflineStore.instance.visitRadiusM();
        if (rLocal > 0) radius = rLocal;

        final open = await OfflineStore.instance.loadOpenVisit();
        final isOpen = open != null && Fmt.toInt(open['customer_id']) == id;
        final lineId = isOpen
            ? (Fmt.toInt(open['route_line_id']) != 0
                ? Fmt.toInt(open['route_line_id'])
                : _offlineRouteLineId(id))
            : (_openVisitCustomerId == id ? _offlineRouteLineId(id) : 0);
        final cin = isOpen
            ? Fmt.str(open['visit_checkin_at'])
            : (_visitCheckinAtByCustomer[id] ?? '');
        v = {
          'route_line_id': lineId,
          'status': isOpen || _openVisitCustomerId == id
              ? 'checked_in'
              : (_visitStatusByCustomer[id] ?? 'idle'),
          'visit_checkin_at': cin,
          'visit_checkout_at': _visitCheckoutAtByCustomer[id] ?? '',
          'order_id': _visitOrderId,
          'has_order': _visitOrderId > 0,
          'offline': true,
        };
      }

      if ((!offline.online && offline.catalogReady) || id < 0) {
        await applyLocal();
      } else {
        try {
          final res = await context.read<ApiClient>().getJson(
            AppConfig.customerViewPath,
            query: {'id': id},
          );
          c = (res['customer'] as Map?)?.cast<String, dynamic>();
          v = (res['visit'] as Map?)?.cast<String, dynamic>();
          noOrderReasons = (res['no_order_reasons'] as List? ?? [])
              .whereType<Map>()
              .map((e) => e.cast<String, dynamic>())
              .toList();
          radius = Fmt.toInt(res['visit_radius_m']);
        } on ApiException catch (e) {
          if (offline.catalogReady && _isNetworkFail(e)) {
            await applyLocal();
          } else {
            rethrow;
          }
        }
      }

      if (!mounted) return;
      setState(() {
        _customer = c;
        _visit = v;
        _noOrderReasons = noOrderReasons;
        if (radius > 0) _radiusM = radius;
        _detailLoading = false;
        final oid = Fmt.toInt(v?['order_id']);
        if (oid > 0) _visitOrderId = oid;
        if (_visitOpenFromMap(v)) {
          _openVisitCustomerId = id;
          _openVisitCheckinAt = Fmt.str(v?['visit_checkin_at']);
          final m = Fmt.str(v?['checkin_method']);
          if (m.isNotEmpty) _openVisitCheckinMethod = m;
        } else if (_openVisitCustomerId == id) {
          _openVisitCustomerId = 0;
          _openVisitCheckinAt = '';
          _openVisitCheckinMethod = '';
          _openVisitCheckinMethod = '';
        }
      });
      _loadHistOrders(id);
      _loadHistInvoices(id);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _detailError = e.message;
        _detailLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _detailError = e.toString();
        _detailLoading = false;
      });
    }
  }

  String _isoDate(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _loadHistOrders(int customerId) async {
    setState(() {
      _histOrdersLoading = true;
      _histOrdersError = null;
    });
    final offline = context.read<OfflineController>();
    if (!offline.online) {
      if (!mounted) return;
      setState(() {
        _histOrders = [];
        _histOrdersLoading = false;
        _histOrdersError =
            'سجل الطلبات يتطلب اتصالاً — متاح بعد عودة الإنترنت.';
      });
      return;
    }
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.customerOrderListPath,
        query: {
          'customer_id': customerId,
          'from': _isoDate(_histOrdersFrom),
          'to': _isoDate(_histOrdersTo),
          'page': 1,
        },
      );
      if (!mounted) return;
      setState(() {
        _histOrders = (res['orders'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _histOrdersLoading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _histOrdersError = _isNetworkFail(e)
            ? 'سجل الطلبات يتطلب اتصالاً — متاح بعد عودة الإنترنت.'
            : e.message;
        _histOrdersLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _histOrdersError = e.toString();
        _histOrdersLoading = false;
      });
    }
  }

  Future<void> _loadHistInvoices(int customerId) async {
    setState(() {
      _histInvoicesLoading = true;
      _histInvoicesError = null;
    });
    final offline = context.read<OfflineController>();
    if (!offline.online) {
      if (!mounted) return;
      setState(() {
        _histInvoices = [];
        _histInvoicesLoading = false;
        _histInvoicesError =
            'سجل الفواتير يتطلب اتصالاً — متاح بعد عودة الإنترنت.';
      });
      return;
    }
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceListPath,
        query: {
          'customer_id': customerId,
          'from': _isoDate(_histInvoicesFrom),
          'to': _isoDate(_histInvoicesTo),
          'filter': 'all',
          'page': 1,
        },
      );
      if (!mounted) return;
      setState(() {
        _histInvoices = (res['invoices'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _histInvoicesLoading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _histInvoicesError = _isNetworkFail(e)
            ? 'سجل الفواتير يتطلب اتصالاً — متاح بعد عودة الإنترنت.'
            : e.message;
        _histInvoicesLoading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _histInvoicesError = e.toString();
        _histInvoicesLoading = false;
      });
    }
  }

  Future<Map<String, dynamic>?> _gpsFields() async {
    final pos = await LocationService.tryGetPosition();
    if (pos == null) return null;
    return {
      'latitude': pos.latitude,
      'longitude': pos.longitude,
      'accuracy': pos.accuracy,
    };
  }

  Future<bool> _confirm(String title, String body) async {
    final ok = await showAppConfirmDialog(
      context,
      title: title,
      message: body,
    );
    return ok == true;
  }

  Future<void> _openCheckinMethodDialog() async {
    if (_busy || _selectedId == null || _customer == null) return;
    if (_hasOpenVisit && !_selectedIsOpen) {
      final pending =
          _visitStatusByCustomer[_openVisitCustomerId] ==
              'pending_manual_checkout';
      showSnack(
        context,
        pending
            ? 'الخروج اليدوي للعميل السابق بانتظار اعتماد المدير؛ يمكنك تصفح العملاء لكن لا يمكن بدء زيارة ثانية قبل الاعتماد.'
            : 'يوجد زيارة مفتوحة لعميل آخر. سجّل الخروج أولاً.',
        error: true,
      );
      return;
    }
    final method = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('طريقة تسجيل الدخول'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              _radiusM > 0
                  ? 'اختر طريقة تسجيل الدخول إلى العميل.\nنصف القطر المسموح: $_radiusM م'
                  : 'اختر طريقة تسجيل الدخول إلى العميل.',
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => Navigator.pop(ctx, 'MANUAL'),
                    icon: const Icon(Icons.edit_location_alt_rounded),
                    label: const Text('يدوي'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () => Navigator.pop(ctx, 'GPS'),
                    icon: const Icon(Icons.my_location_rounded),
                    label: const Text('GPS'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('إلغاء'),
            ),
          ],
        ),
        actions: const [],
      ),
    );
    if (method == null || !mounted) return;
    if (method == 'MANUAL') {
      await _checkin(manual: true);
    } else {
      await _gpsPreviewThenCheckin();
    }
  }

  Future<void> _gpsPreviewThenCheckin() async {
    final id = _selectedId;
    if (id == null || _busy) return;
    final offline = context.read<OfflineController>();
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    setState(() => _busy = true);
    try {
      final gps = await _gpsFields();
      if (gps == null) {
        if (!mounted) return;
        showSnack(context, 'تعذّر قراءة GPS. جرّب دخولاً يدوياً.', error: true);
        return;
      }

      // Offline: معاينة محلية بدون سيرفر
      if (!offline.online && offline.catalogReady) {
        final c = _customer;
        final clat = c?['latitude'];
        final clng = c?['longitude'];
        var within = true;
        var distance = 0.0;
        if (clat is num && clng is num) {
          distance = const Distance().as(
            LengthUnit.Meter,
            LatLng(clat.toDouble(), clng.toDouble()),
            LatLng(
              (gps['latitude'] as num).toDouble(),
              (gps['longitude'] as num).toDouble(),
            ),
          );
          within = distance <= _radiusM;
        }
        if (!mounted) return;
        final action = await showDialog<_GpsPreviewAction>(
          context: context,
          builder: (ctx) => _GpsPreviewDialog(
            preview: {
              'distance_m': distance.round(),
              'visit_radius_m': _radiusM,
              'within_geofence': within,
              'offline': true,
            },
            within: within,
          ),
        );
        if (action == null || !mounted) return;
        if (action == _GpsPreviewAction.checkinGps) {
          await _checkin(manual: false, gpsOverride: gps);
        } else if (action == _GpsPreviewAction.checkinManual) {
          await _checkin(manual: true, gpsOverride: gps);
        }
        return;
      }

      try {
        final preview = await api.postJson(
          AppConfig.visitGpsPreviewPath,
          body: {'customer_id': id, ...gps},
          csrf: csrf,
        );
        if (!mounted) return;
        final within = preview['within_geofence'] == true;
        final action = await showDialog<_GpsPreviewAction>(
          context: context,
          builder: (ctx) => _GpsPreviewDialog(
            preview: preview,
            within: within,
          ),
        );
        if (action == null || !mounted) return;
        if (action == _GpsPreviewAction.checkinGps) {
          await _checkin(manual: false, gpsOverride: gps);
        } else if (action == _GpsPreviewAction.checkinManual) {
          await _checkin(manual: true, gpsOverride: gps);
        }
      } on ApiException catch (e) {
        if (offline.catalogReady && _isNetworkFail(e)) {
          await _checkin(manual: true, gpsOverride: gps);
        } else {
          rethrow;
        }
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _applyLocalCheckin({
    required int id,
    required bool manual,
    required Map<String, dynamic> gps,
  }) async {
    final offline = context.read<OfflineController>();
    final now = DateTime.now().toIso8601String();
    final lineId = _offlineRouteLineId(id);
    await offline.enqueue(
      kind: 'visit_checkin',
      path: AppConfig.repVisitCheckinPath,
      body: {
        'customer_id': id,
        'method': manual ? 'MANUAL' : 'GPS',
        ...gps,
      },
    );
    await _persistOpenVisit(
      customerId: id,
      checkinAt: now,
      routeLineId: lineId,
      method: manual ? 'MANUAL' : 'GPS',
    );
    if (!mounted) return;
    setState(() {
      _visit = {
        'route_line_id': lineId,
        'status': 'checked_in',
        'visit_checkin_at': now,
        'visit_checkout_at': '',
        'checkin_method': manual ? 'MANUAL' : 'GPS',
        'has_order': false,
        'order_id': 0,
        'offline': true,
      };
      _openVisitCustomerId = id;
      _openVisitCheckinAt = now;
      _openVisitCheckinMethod = manual ? 'MANUAL' : 'GPS';
      _visitStatusByCustomer[id] = 'checked_in';
      _visitCheckinAtByCustomer[id] = now;
      _putOpenCustomerFirst();
    });
    showSnack(
      context,
      'تم تسجيل الدخول محلياً — سيُرحَّل عند عودة الاتصال.',
    );
  }

  Future<void> _checkin({
    required bool manual,
    Map<String, dynamic>? gpsOverride,
  }) async {
    final id = _selectedId;
    final c = _customer;
    if (id == null || c == null) return;
    if (_hasOpenVisit && id != _openVisitCustomerId) {
      showSnack(
        context,
        'يوجد زيارة مفتوحة لعميل آخر. سجّل الخروج أولاً.',
        error: true,
      );
      return;
    }
    final name = Fmt.str(c['name']);
    final ok = await _confirm(
      'تأكيد تسجيل الدخول',
      manual
          ? 'تأكيد الدخول اليدوي إلى «$name»؟'
          : 'تأكيد الدخول بـ GPS إلى «$name»؟',
    );
    if (!ok || !mounted) return;
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    final offline = context.read<OfflineController>();
    setState(() => _busy = true);
    try {
      Map<String, dynamic> gps = gpsOverride ?? {};
      if (!manual) {
        if (gps.isEmpty) {
          final g = await _gpsFields();
          if (g == null) {
            if (!mounted) return;
            showSnack(context, 'تعذّر قراءة GPS.', error: true);
            return;
          }
          gps = g;
        }
      } else {
        gps = gps.isEmpty ? (await _gpsFields() ?? {}) : gps;
      }
      if (!mounted) return;

      if (!offline.online && offline.catalogReady) {
        await _applyLocalCheckin(id: id, manual: manual, gps: gps);
        return;
      }

      try {
        final res = await api.postJson(
          AppConfig.repVisitCheckinPath,
          body: {
            'customer_id': id,
            'method': manual ? 'MANUAL' : 'GPS',
            ...gps,
          },
          csrf: csrf,
        );
        if (!mounted) return;
        final visit = (res['visit'] as Map?)?.cast<String, dynamic>();
        showSnack(
          context,
          Fmt.str(res['message']).isEmpty
              ? 'تم تسجيل الدخول.'
              : Fmt.str(res['message']),
        );
        setState(() {
          _visit = {
            ...?visit,
            'checkin_method':
                Fmt.str(visit?['checkin_method']).isEmpty
                    ? (manual ? 'MANUAL' : 'GPS')
                    : Fmt.str(visit?['checkin_method']),
          };
          _openVisitCustomerId = id;
          _openVisitCheckinAt = Fmt.str(
            visit?['visit_checkin_at'] ?? DateTime.now().toIso8601String(),
          );
          _openVisitCheckinMethod = Fmt.str(
            (_visit ?? const {})['checkin_method'],
          );
          _putOpenCustomerFirst();
        });
        await OfflineStore.instance.clearOpenVisit();
        await _refreshOpenVisit();
        await _selectCustomer(id);
      } on ApiException catch (e) {
        if (offline.catalogReady && _isNetworkFail(e)) {
          await _applyLocalCheckin(id: id, manual: manual, gps: gps);
        } else {
          rethrow;
        }
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
      await _refreshOpenVisit();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<List<int>?> _pickNoOrderReasons() async {
    // دائماً نفضّل المحلي لشاشة الزيارة Offline
    final local = await OfflineStore.instance.noOrderReasons();
    if (local.isNotEmpty) {
      _noOrderReasons = local
          .map((e) => {
                'id': e['id'],
                'name_ar': e['name_ar'],
              })
          .toList();
    }
    if (_noOrderReasons.isEmpty) {
      // أسباب افتراضية محلية حتى يعمل الخروج بدون نت
      _noOrderReasons = [
        {'id': -1, 'name_ar': 'لا يحتاج طلبية حالياً'},
        {'id': -2, 'name_ar': 'العميل مغلق'},
        {'id': -3, 'name_ar': 'أخرى'},
      ];
    }
    final selected = <int>{};
    return showDialog<List<int>>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('سبب عدم عمل طلبية'),
          content: SizedBox(
            width: 420,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: _noOrderReasons.map((r) {
                  final id = Fmt.toInt(r['id']);
                  return CheckboxListTile(
                    value: selected.contains(id),
                    controlAffinity: ListTileControlAffinity.leading,
                    title: Text(Fmt.str(r['name_ar'])),
                    onChanged: (on) => setLocal(() {
                      if (on == true) {
                        selected.add(id);
                      } else {
                        selected.remove(id);
                      }
                    }),
                  );
                }).toList(),
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: selected.isEmpty
                  ? null
                  : () => Navigator.pop(ctx, selected.toList()),
              child: const Text('اعتماد الأسباب'),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _checkout({required bool manual}) async {
    final id = _selectedId;
    final c = _customer;
    if (id == null || c == null || _busy) return;
    final name = Fmt.str(c['name']);
    List<int> noOrderReasonIds = [];
    String? offlineReasonNames;
    if (_visit?['has_order'] != true && _visitOrderId < 1) {
      final picked = await _pickNoOrderReasons();
      if (picked == null || picked.isEmpty) return;
      noOrderReasonIds = picked.where((id) => id > 0).toList();
      // أسباب محلية سالبة: نحفظ أسماءها كنص سبب للترحيل
      final names = _noOrderReasons
          .where((r) => picked.contains(Fmt.toInt(r['id'])))
          .map((r) => Fmt.str(r['name_ar']))
          .where((s) => s.isNotEmpty)
          .join('، ');
      if (names.isNotEmpty) offlineReasonNames = names;
    }
    final ok = await _confirm(
      'تأكيد تسجيل الخروج',
      manual
          ? 'تأكيد الخروج اليدوي من عند «$name»؟'
          : 'تأكيد الخروج بـ GPS من عند «$name»؟',
    );
    if (!ok || !mounted) return;
    String? reason;
    if (manual) {
      reason = await showManualCheckoutReasonDialog(context);
      if (reason == null) return;
    }
    if (!mounted) return;
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    final offline = context.read<OfflineController>();
    setState(() => _busy = true);
    try {
      Map<String, dynamic> gps = {};
      if (!manual) {
        final g = await _gpsFields();
        if (g == null) {
          if (!mounted) return;
          showSnack(context, 'تعذّر قراءة GPS. جرّب خروجاً يدوياً.',
              error: true);
          return;
        }
        gps = g;
      } else {
        gps = await _gpsFields() ?? {};
      }
      if (!mounted) return;

      final body = <String, dynamic>{
        'customer_id': id,
        'method': manual ? 'MANUAL' : 'GPS',
        'no_order_reason_ids': noOrderReasonIds,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
        if ((reason == null || reason.isEmpty) &&
            offlineReasonNames != null &&
            offlineReasonNames.isNotEmpty)
          'reason': offlineReasonNames,
        ...gps,
      };

      Future<void> applyLocalCheckout() async {
        await offline.enqueue(
          kind: 'visit_checkout',
          path: AppConfig.repVisitCheckoutPath,
          body: body,
        );
        await OfflineStore.instance.clearOpenVisit();
        if (!mounted) return;
        setState(() {
          _openVisitCustomerId = 0;
          _openVisitCheckinAt = '';
          _openVisitCheckinMethod = '';
          _visitOrderId = 0;
          _visitStatusByCustomer[id] = 'checked_out';
          _visitCheckoutAtByCustomer[id] = DateTime.now().toIso8601String();
          _visit = {
            ...?_visit,
            'status': 'checked_out',
            'visit_checkout_at': _visitCheckoutAtByCustomer[id],
            'offline': true,
          };
        });
        showSnack(
          context,
          'تم تسجيل الخروج محلياً — سيُرحَّل عند عودة الاتصال.',
        );
      }

      if (!offline.online && offline.catalogReady) {
        await applyLocalCheckout();
        return;
      }

      try {
        final res = await api.postJson(
          AppConfig.repVisitCheckoutPath,
          body: body,
          csrf: csrf,
        );
        if (!mounted) return;
        final msg = Fmt.str(res['message']);
        final needsApproval = res['requires_approval'] == true;
        showSnack(
          context,
          msg.isEmpty
              ? (needsApproval
                  ? 'بانتظار موافقة المدير'
                  : 'تم تسجيل الخروج وإغلاق الزيارة')
              : msg,
        );
        if (!needsApproval) {
          setState(() {
            _openVisitCustomerId = 0;
            _openVisitCheckinAt = '';
            _visitOrderId = 0;
          });
          await OfflineStore.instance.clearOpenVisit();
        }
        await _refreshOpenVisit();
        await _selectCustomer(id);
      } on ApiException catch (e) {
        if (offline.catalogReady && _isNetworkFail(e)) {
          await applyLocalCheckout();
        } else {
          rethrow;
        }
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _timeOnly(String raw) {
    final v = raw.trim();
    if (v.isEmpty) return '—';
    try {
      final d = DateTime.parse(v.contains('T') ? v : v.replaceFirst(' ', 'T'));
      final h = d.hour.toString().padLeft(2, '0');
      final m = d.minute.toString().padLeft(2, '0');
      final s = d.second.toString().padLeft(2, '0');
      return '$h:$m:$s';
    } catch (_) {
      if (v.length >= 19) return v.substring(11, 19);
      if (v.length >= 16) return v.substring(11, 16);
      return v;
    }
  }

  /// عرض منطقي للشاشة لا يتقلّص مع الكيبورد (عكس MediaQuery.size).
  Size _displayLogicalSize(BuildContext context) {
    final view = View.of(context);
    return view.display.size / view.devicePixelRatio;
  }

  @override
  Widget build(BuildContext context) {
    final screen = _displayLogicalSize(context);
    final imeOpen = MediaQuery.viewInsetsOf(context).bottom > 80;
    final wide = screen.shortestSide >= 550 || screen.width >= 900;
    return MobileScaffold(
      title: const Text('العملاء'),
      backgroundColor: const Color(0xFFF0F4F8),
      actions: [
        IconButton(
          onPressed: _busy
              ? null
              : () async {
                  await _loadCustomers();
                  await _refreshOpenVisit();
                  if (_selectedId != null) await _selectCustomer(_selectedId!);
                },
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: Stack(
        children: [
          if (wide)
            Row(
              children: [
                SizedBox(
                  width: (screen.width * 0.34).clamp(280.0, 400.0),
                  child: _buildLeftPanel(
                    openNarrowOnSelect: false,
                    imeOpen: imeOpen,
                  ),
                ),
                const VerticalDivider(width: 1),
                Expanded(child: _buildRightPanel()),
              ],
            )
          else if (_showNarrowDetail && _selectedId != null)
            Column(
              children: [
                Material(
                  color: Colors.white,
                  child: ListTile(
                    leading: IconButton(
                      icon: const Icon(Icons.arrow_forward_rounded),
                      onPressed: () => setState(() {
                        _showNarrowDetail = false;
                      }),
                    ),
                    title: Text(
                      Fmt.str(_customer?['name']).isEmpty
                          ? 'تفاصيل العميل'
                          : Fmt.str(_customer?['name']),
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ),
                const Divider(height: 1),
                Expanded(child: _buildRightPanel()),
                if (!imeOpen) _buildCheckInOutCard(),
              ],
            )
          else
            _buildLeftPanel(openNarrowOnSelect: true, imeOpen: imeOpen),
          if (_busy)
            const Positioned(
              left: 0,
              right: 0,
              top: 0,
              child: LinearProgressIndicator(minHeight: 2),
            ),
        ],
      ),
    );
  }

  Widget _buildLeftPanel({
    required bool openNarrowOnSelect,
    required bool imeOpen,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'جميع العملاء',
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 16,
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                key: const ValueKey('customer-search'),
                controller: _search,
                focusNode: _searchFocus,
                onChanged: _onSearchChanged,
                keyboardType: TextInputType.text,
                textInputAction: TextInputAction.search,
                autocorrect: false,
                enableSuggestions: false,
                scrollPadding: const EdgeInsets.only(bottom: 120),
                decoration: InputDecoration(
                  hintText: 'بحث بالاسم أو الرمز…',
                  prefixIcon: const Icon(Icons.search_rounded),
                  filled: true,
                  fillColor: Colors.white,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                  isDense: true,
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncView(
            loading: _listLoading,
            error: _listError,
            onRetry: _loadCustomers,
            child: _customers.isEmpty
                ? ListView(
                    children: const [
                      SizedBox(height: 60),
                      EmptyState(
                        message: 'لا يوجد عملاء.',
                        icon: Icons.people_outline_rounded,
                      ),
                    ],
                  )
                : RefreshIndicator(
                    onRefresh: () async {
                      await _loadCustomers();
                      await _refreshOpenVisit();
                    },
                    child: ListView.separated(
                      cacheExtent: 800,
                      addAutomaticKeepAlives: false,
                      padding: const EdgeInsets.fromLTRB(10, 0, 10, 12),
                      itemCount: _customers.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 4),
                      itemBuilder: (_, i) {
                        final c = _customers[i];
                        final id = Fmt.toInt(c['id']);
                        final selected = id == _selectedId;
                        final isOpen = id == _openVisitCustomerId;
                        final visitStatus = _effectiveVisitStatus(id);
                        final isCheckedOut = visitStatus == 'checked_out';
                        final accent = _visitAccent(id);
                        final rowBg = selected
                            ? AppTheme.primary.withValues(alpha: 0.10)
                            : (_visitRowColor(id) ?? Colors.white);
                        return RepaintBoundary(
                          child: Material(
                          color: rowBg,
                          borderRadius: BorderRadius.circular(12),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(12),
                            onTap: _busy
                                ? null
                                : () => _selectCustomer(
                                      id,
                                      openNarrowDetail: openNarrowOnSelect,
                                    ),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 12,
                                vertical: 10,
                              ),
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    radius: 18,
                                    backgroundColor:
                                        accent.withValues(alpha: 0.12),
                                    child: Icon(
                                      isOpen
                                          ? Icons.login_rounded
                                          : (isCheckedOut
                                              ? Icons.logout_rounded
                                              : Icons.storefront_rounded),
                                      size: 18,
                                      color: accent,
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          Fmt.str(c['name']),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: TextStyle(
                                            fontWeight: FontWeight.w800,
                                            fontSize: 14,
                                            color: selected
                                                ? AppTheme.primary
                                                : AppTheme.textMain,
                                          ),
                                        ),
                                        Text(
                                          c['pending_oracle_link'] == true
                                              ? 'بانتظار ربط Oracle'
                                              : Fmt.str(c['code']),
                                          style: TextStyle(
                                            color: c['pending_oracle_link'] == true
                                                ? AppTheme.warn
                                                : AppTheme.textSoft,
                                            fontSize: 12,
                                            fontWeight: c['pending_oracle_link'] == true
                                                ? FontWeight.w700
                                                : FontWeight.normal,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                  if (isOpen)
                                    const StatusPill(
                                      text: 'مفتوحة',
                                      color: AppTheme.success,
                                    )
                                  else if (isCheckedOut)
                                    const StatusPill(
                                      text: 'منتهية',
                                      color: AppTheme.danger,
                                    ),
                                ],
                              ),
                            ),
                          ),
                        ),
                        );
                      },
                    ),
                  ),
          ),
        ),
        if (!imeOpen) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: IconButton.filledTonal(
                tooltip: _searchFocus.hasFocus
                    ? 'إخفاء لوحة المفاتيح'
                    : 'فتح لوحة المفاتيح',
                onPressed: () {
                  if (_searchFocus.hasFocus) {
                    _searchFocus.unfocus();
                  } else {
                    unawaited(FullscreenBinder.allowKeyboard());
                    _searchFocus.requestFocus();
                  }
                },
                icon: Icon(
                  _searchFocus.hasFocus
                      ? Icons.keyboard_hide_rounded
                      : Icons.keyboard_rounded,
                ),
              ),
            ),
          ),
          _buildCheckInOutCard(),
        ],
      ],
    );
  }

  Widget _buildCheckInOutCard() {
    final hasSelection = _selectedId != null && _customer != null;
    final open = _selectedIsOpen ||
        (_visitOpenFromMap(_visit) && _selectedId == _openVisitCustomerId);

    if (!hasSelection) {
      return Container(
        margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppTheme.border),
          boxShadow: AppTheme.softShadow,
        ),
        child: const Text(
          'اختر عميلاً لتسجيل الدخول أو الخروج.',
          style: TextStyle(color: AppTheme.textSoft, fontSize: 13),
        ),
      );
    }

    if (open) {
      final at = _openVisitCheckinAt.isNotEmpty
          ? _openVisitCheckinAt
          : Fmt.str(_visit?['visit_checkin_at']);
      return Container(
        margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AppTheme.danger.withValues(alpha: 0.35)),
          boxShadow: AppTheme.softShadow,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            FilledButton.icon(
              onPressed: _busy ? null : _openOrderForActiveVisit,
              icon: const Icon(Icons.shopping_cart_checkout_rounded),
              label: const Text('عمل طلب شراء للعميل'),
            ),
            const SizedBox(height: 8),
            InkWell(
              onTap: _busy ? null : () => _showCheckoutMethodDialog(),
              borderRadius: BorderRadius.circular(14),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Container(
                      width: 52,
                      height: 52,
                      decoration: BoxDecoration(
                        color: AppTheme.danger.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: const Icon(
                        Icons.logout_rounded,
                        color: AppTheme.danger,
                        size: 28,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'تسجيل خروج من العميل',
                            style: TextStyle(
                              fontWeight: FontWeight.w800,
                              fontSize: 15,
                              color: AppTheme.danger,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'بداية الزيارة : ${_timeOnly(at)}',
                            style: const TextStyle(
                              color: AppTheme.textSoft,
                              fontSize: 12.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_left_rounded,
                        color: AppTheme.danger),
                  ],
                ),
              ),
            ),
          ],
        ),
      );
    }

    return Container(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      child: Material(
        color: AppTheme.primary,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: _busy ? null : _openCheckinMethodDialog,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
            child: Row(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.18),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: const Icon(
                    Icons.meeting_room_rounded,
                    color: Colors.white,
                    size: 26,
                  ),
                ),
                const SizedBox(width: 12),
                const Expanded(
                  child: Text(
                    'تسجيل دخول الى العميل',
                    style: TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 15,
                    ),
                  ),
                ),
                Icon(
                  Icons.arrow_back_rounded,
                  color: Colors.white.withValues(alpha: 0.9),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _showCheckoutMethodDialog() async {
    if (_openVisitWasManual) {
      await _checkout(manual: true);
      return;
    }
    final method = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('طريقة تسجيل الخروج'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(ctx, 'MANUAL'),
                    child: const Text('يدوي'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton(
                    onPressed: () => Navigator.pop(ctx, 'GPS'),
                    child: const Text('GPS'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('إلغاء'),
            ),
          ],
        ),
        actions: const [],
      ),
    );
    if (method == null || !mounted) return;
    await _checkout(manual: method == 'MANUAL');
  }

  Widget _buildRightPanel() {
    if (_selectedId == null) {
      return const Center(
        child: Text(
          'اختر عميلاً من القائمة',
          style: TextStyle(color: AppTheme.textSoft, fontSize: 15),
        ),
      );
    }
    return AsyncView(
      loading: _detailLoading,
      error: _detailError,
      onRetry: () => _selectCustomer(_selectedId!),
      child: _customer == null
          ? const SizedBox.shrink()
          : DefaultTabController(
              length: 5,
              initialIndex: 0,
              child: Column(
                children: [
                  Material(
                    color: Colors.white,
                    child: TabBar(
                      isScrollable: true,
                      labelColor: AppTheme.primary,
                      unselectedLabelColor: AppTheme.textSoft,
                      tabs: const [
                        Tab(text: 'طلب شراء'),
                        Tab(text: 'معلومات العميل'),
                        Tab(text: 'كشف حساب'),
                        Tab(text: 'الطلبات التاريخية'),
                        Tab(text: 'الفواتير التاريخية'),
                      ],
                    ),
                  ),
                  Expanded(
                    child: TabBarView(
                      children: [
                        _PurchaseOrderTab(
                          customer: _customer!,
                          visitOpen: _selectedIsOpen,
                          visitRouteLineId: Fmt.toInt(_visit?['route_line_id']),
                          orderId: _visitOrderId > 0 ? _visitOrderId : null,
                          onSaved: _onVisitOrderSaved,
                          onDeleted: _onVisitOrderDeleted,
                        ),
                        _InfoTab(
                          customer: _customer!,
                          onSaved: () => _selectCustomer(_selectedId!),
                          onDeleted: () {
                            setState(() {
                              _selectedId = null;
                              _customer = null;
                              _showNarrowDetail = false;
                            });
                            _loadCustomers();
                          },
                        ),
                        _StatementTab(customer: _customer!),
                        _OrdersTab(
                          loading: _histOrdersLoading,
                          error: _histOrdersError,
                          orders: _histOrders,
                          from: _histOrdersFrom,
                          to: _histOrdersTo,
                          onFromChanged: (d) {
                            setState(() => _histOrdersFrom = d);
                            _loadHistOrders(_selectedId!);
                          },
                          onToChanged: (d) {
                            setState(() => _histOrdersTo = d);
                            _loadHistOrders(_selectedId!);
                          },
                          onRetry: () => _loadHistOrders(_selectedId!),
                        ),
                        _InvoicesTab(
                          loading: _histInvoicesLoading,
                          error: _histInvoicesError,
                          invoices: _histInvoices,
                          from: _histInvoicesFrom,
                          to: _histInvoicesTo,
                          onFromChanged: (d) {
                            setState(() => _histInvoicesFrom = d);
                            _loadHistInvoices(_selectedId!);
                          },
                          onToChanged: (d) {
                            setState(() => _histInvoicesTo = d);
                            _loadHistInvoices(_selectedId!);
                          },
                          onRetry: () => _loadHistInvoices(_selectedId!),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}

enum _GpsPreviewAction { checkinGps, checkinManual }

class _GpsPreviewDialog extends StatelessWidget {
  const _GpsPreviewDialog({
    required this.preview,
    required this.within,
  });

  final Map<String, dynamic> preview;
  final bool within;

  @override
  Widget build(BuildContext context) {
    final userLat = Fmt.toDouble(preview['user_lat']);
    final userLng = Fmt.toDouble(preview['user_lng']);
    final custLat = Fmt.toDouble(preview['customer_lat']);
    final custLng = Fmt.toDouble(preview['customer_lng']);
    final accuracy = Fmt.toDouble(preview['accuracy_m']);
    final distance = Fmt.toDouble(preview['distance_m']);
    final radius = Fmt.toInt(preview['visit_radius_m']);
    final msg = Fmt.str(preview['message']);

    final center = LatLng(
      (userLat + custLat) / 2,
      (userLng + custLng) / 2,
    );

    return AlertDialog(
      title: const Text('معاينة الموقع'),
      content: SizedBox(
        width: 360,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: SizedBox(
                height: 220,
                child: FlutterMap(
                  options: MapOptions(
                    initialCenter: center,
                    initialZoom: 15,
                    interactionOptions: const InteractionOptions(
                      flags: InteractiveFlag.pinchZoom | InteractiveFlag.drag,
                    ),
                  ),
                  children: [
                    ...GpsMapTiles.layers(mapProvider: 'osm'),
                    MarkerLayer(
                      markers: [
                        Marker(
                          point: LatLng(custLat, custLng),
                          width: 40,
                          height: 40,
                          child: const Icon(
                            Icons.storefront_rounded,
                            color: AppTheme.primary,
                            size: 36,
                          ),
                        ),
                        Marker(
                          point: LatLng(userLat, userLng),
                          width: 40,
                          height: 40,
                          child: const Icon(
                            Icons.person_pin_circle_rounded,
                            color: AppTheme.teal,
                            size: 36,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              msg.isEmpty
                  ? (within
                      ? 'أنت ضمن حدود موقع العميل.'
                      : 'أنت خارج نطاق الموقع المسموح.')
                  : msg,
              style: const TextStyle(height: 1.4, fontSize: 13.5),
            ),
            const SizedBox(height: 6),
            Text(
              'الدقة: ${Fmt.trimNum(accuracy)} م  •  المسافة: ${Fmt.trimNum(distance)} م'
              '${radius > 0 ? '  •  المسموح: $radius م' : ''}',
              style: const TextStyle(
                color: AppTheme.textSoft,
                fontSize: 12.5,
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('إلغاء'),
        ),
        if (!within)
          OutlinedButton(
            onPressed: () =>
                Navigator.pop(context, _GpsPreviewAction.checkinManual),
            child: const Text('دخول يدوي'),
          ),
        FilledButton(
          onPressed: () => Navigator.pop(
            context,
            within
                ? _GpsPreviewAction.checkinGps
                : _GpsPreviewAction.checkinManual,
          ),
          child: Text(within ? 'تأكيد دخول GPS' : 'متابعة يدوياً'),
        ),
      ],
    );
  }
}

class _InfoTab extends StatefulWidget {
  const _InfoTab({
    required this.customer,
    required this.onSaved,
    required this.onDeleted,
  });
  final Map<String, dynamic> customer;
  final VoidCallback onSaved;
  final VoidCallback onDeleted;

  @override
  State<_InfoTab> createState() => _InfoTabState();
}

class _InfoTabState extends State<_InfoTab> {
  late final TextEditingController _phone;
  late final TextEditingController _tax;
  late final TextEditingController _email;
  late final TextEditingController _address;
  double? _lat;
  double? _lng;
  double? _accuracy;
  double? _origLat;
  double? _origLng;
  bool _hadSavedGps = false;
  bool _saving = false;
  bool _locating = false;

  @override
  void initState() {
    super.initState();
    final c = widget.customer;
    _phone = TextEditingController(text: Fmt.str(c['phone']));
    _tax = TextEditingController(text: Fmt.str(c['tax_number']));
    _email = TextEditingController(text: Fmt.str(c['email']));
    _address = TextEditingController(text: Fmt.str(c['address']));
    _lat = c['latitude'] != null ? Fmt.toDouble(c['latitude']) : null;
    _lng = c['longitude'] != null ? Fmt.toDouble(c['longitude']) : null;
    _origLat = _lat;
    _origLng = _lng;
    _hadSavedGps = _lat != null && _lng != null;
  }

  @override
  void dispose() {
    _phone.dispose();
    _tax.dispose();
    _email.dispose();
    _address.dispose();
    super.dispose();
  }

  String get _locLabel {
    if (_lat == null || _lng == null) return 'لم يُحدَّد موقع بعد.';
    return '${_lat!.toStringAsFixed(6)} ، ${_lng!.toStringAsFixed(6)}';
  }

  Future<void> _useCurrentLocation() async {
    setState(() => _locating = true);
    try {
      final pos = await LocationService.requirePosition();
      if (!mounted) return;
      setState(() {
        _lat = pos.latitude;
        _lng = pos.longitude;
        _accuracy = pos.accuracy;
      });
    } catch (e) {
      if (!mounted) return;
      showSnack(context, LocationService.friendlyError(e), error: true);
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _pickOnMap() async {
    final hasLoc = _lat != null && _lng != null;
    final start =
        hasLoc ? LatLng(_lat!, _lng!) : const LatLng(31.9539, 35.9106);
    final picked = await pickLocationOnMap(
      context,
      initial: start,
      hasInitialLocation: hasLoc,
    );
    if (picked == null || !mounted) return;
    setState(() {
      _lat = picked.latitude;
      _lng = picked.longitude;
      _accuracy = null;
    });
  }

  void _clearLocation() {
    setState(() {
      _lat = null;
      _lng = null;
      _accuracy = null;
    });
  }

  bool _gpsChanged() {
    final nowHas = _lat != null && _lng != null;
    if (_hadSavedGps != nowHas) return true;
    if (!_hadSavedGps || _lat == null || _lng == null) return false;
    return (_lat! - (_origLat ?? _lat!)).abs() > 1e-6 ||
        (_lng! - (_origLng ?? _lng!)).abs() > 1e-6;
  }

  Future<void> _save() async {
    final s = context.read<SessionController>();
    if (_hadSavedGps && _gpsChanged()) {
      final go = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('تعديل موقع العميل'),
          content: const Text(
            'الموقع محفوظ مسبقاً. سيتم إرسال التعديل لمدير المبيعات للاعتماد، ولن يتغيّر موقع العميل حتى تتم الموافقة.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('إرسال للاعتماد'),
            ),
          ],
        ),
      );
      if (go != true || !mounted) return;
    }
    setState(() => _saving = true);
    final offline = context.read<OfflineController>();
    try {
      final fields = <String, dynamic>{
        'id': Fmt.toInt(widget.customer['id']),
        'phone': _phone.text.trim(),
        'tax_number': _tax.text.trim(),
        'email': _email.text.trim(),
        'address_ar': _address.text.trim(),
      };
      if (_lat != null && _lng != null) {
        fields['latitude'] = _lat;
        fields['longitude'] = _lng;
        if (_accuracy != null) fields['gps_accuracy'] = _accuracy;
      } else {
        fields['clear_gps'] = '1';
      }

      Future<void> saveLocal({String? note}) async {
        final id = Fmt.toInt(widget.customer['id']);
        await OfflineStore.instance.upsertLocalCustomer(
          id: id,
          name: Fmt.str(widget.customer['name']),
          code: Fmt.str(widget.customer['code']),
          phone: _phone.text.trim(),
          address: _address.text.trim(),
          latitude: _lat,
          longitude: _lng,
          paymentPeriod: Fmt.toInt(widget.customer['payment_period']),
        );
        await offline.enqueue(
          kind: 'customer_update',
          path: AppConfig.customerUpdatePath,
          body: fields,
          method: 'POST_FORM',
        );
        if (!mounted) return;
        showSnack(
          context,
          note ?? 'حُفظ التعديل محلياً — سيُرحَّل تلقائياً عند عودة الاتصال.',
        );
        _origLat = _lat;
        _origLng = _lng;
        _hadSavedGps = _lat != null && _lng != null;
        widget.onSaved();
      }

      if (!offline.online && offline.catalogReady) {
        await saveLocal();
        return;
      }

      try {
        final res = await context.read<ApiClient>().postForm(
              AppConfig.customerUpdatePath,
              csrf: s.csrf,
              fields: fields,
            );
        if (!mounted) return;
        final msg = Fmt.str(res['message']);
        showSnack(
          context,
          msg.isEmpty ? 'تم حفظ بيانات العميل.' : msg,
        );
        if (res['pending'] == true) {
          setState(() {
            _lat = _origLat;
            _lng = _origLng;
            _accuracy = null;
          });
        } else {
          _origLat = _lat;
          _origLng = _lng;
          _hadSavedGps = _lat != null && _lng != null;
        }
        widget.onSaved();
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await saveLocal(note: 'لا اتصال — حُفظ التعديل محلياً.');
        } else if (mounted) {
          showSnack(context, e.message, error: true);
        }
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _delete() async {
    final id = Fmt.toInt(widget.customer['id']);
    if (id == 0) return;
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف العميل'),
        content: Text(
          id < 0
              ? 'سيُحذف العميل من الجهاز فقط (لم يُرحَّل بعد).'
              : 'سيتم حذف العميل من الجهاز، ثم من النظام عند عودة الاتصال إن لم يكن مرتبطاً بحركات.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (go != true || !mounted) return;
    setState(() => _saving = true);
    final offline = context.read<OfflineController>();
    final s = context.read<SessionController>();
    final api = context.read<ApiClient>();
    try {
      await OfflineStore.instance.dropPendingCustomerMutations(id);
      await OfflineStore.instance.deleteLocalCustomer(id);

      if (id < 0) {
        if (!mounted) return;
        showSnack(context, 'حُذف العميل المحلي.');
        widget.onDeleted();
        return;
      }

      final fields = <String, dynamic>{
        'id': id,
        'snapshot': {
          'id': id,
          'name': Fmt.str(widget.customer['name']),
          'code': Fmt.str(widget.customer['code']),
          'phone': Fmt.str(widget.customer['phone']),
          'address': Fmt.str(widget.customer['address']),
          'latitude': widget.customer['latitude'],
          'longitude': widget.customer['longitude'],
          'payment_period': widget.customer['payment_period'],
        },
      };
      if (!offline.online) {
        await offline.enqueue(
          kind: 'customer_delete',
          path: AppConfig.customerDeletePath,
          body: fields,
          method: 'POST_FORM',
        );
        if (!mounted) return;
        showSnack(context, 'سيُحذف من النظام عند عودة الاتصال.');
        widget.onDeleted();
        return;
      }

      try {
        final res = await api.postForm(
              AppConfig.customerDeletePath,
              csrf: s.csrf,
              fields: fields,
            );
        if (!mounted) return;
        showSnack(
          context,
          Fmt.str(res['message']).isEmpty
              ? 'تم حذف العميل.'
              : Fmt.str(res['message']),
        );
        widget.onDeleted();
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await offline.enqueue(
            kind: 'customer_delete',
            path: AppConfig.customerDeletePath,
            body: fields,
            method: 'POST_FORM',
          );
          if (!mounted) return;
          showSnack(context, 'سيُحذف من النظام عند عودة الاتصال.');
          widget.onDeleted();
        } else if (mounted) {
          showSnack(context, e.message, error: true);
        }
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Widget _readonlyRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 90,
            child: Text(
              label,
              style: const TextStyle(
                color: AppTheme.textSoft,
                fontWeight: FontWeight.w600,
                fontSize: 13,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value.isEmpty ? '—' : value,
              style: const TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: 14,
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.customer;
    final hasGps = _lat != null && _lng != null;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: AppTheme.border),
            boxShadow: AppTheme.softShadow,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _readonlyRow('الاسم', Fmt.str(c['name'])),
              _readonlyRow(
                'الرمز',
                c['pending_oracle_link'] == true
                    ? 'بانتظار ربط Oracle'
                    : Fmt.str(c['code']),
              ),
              if (Fmt.str(c['payment_period_label']).isNotEmpty)
                _readonlyRow('فترة السداد', Fmt.str(c['payment_period_label'])),
              if (Fmt.str(c['region_name']).isNotEmpty)
                _readonlyRow('المنطقة', Fmt.str(c['region_name'])),
              const Divider(height: 22),
              TextField(
                controller: _phone,
                keyboardType: TextInputType.phone,
                textDirection: TextDirection.ltr,
                decoration: const InputDecoration(
                  labelText: 'الهاتف',
                  prefixIcon: Icon(Icons.phone_rounded),
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _tax,
                keyboardType: TextInputType.number,
                textDirection: TextDirection.ltr,
                decoration: const InputDecoration(
                  labelText: 'الرقم الضريبي',
                  prefixIcon: Icon(Icons.badge_rounded),
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                textDirection: TextDirection.ltr,
                decoration: const InputDecoration(
                  labelText: 'البريد',
                  prefixIcon: Icon(Icons.email_rounded),
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _address,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'العنوان',
                  prefixIcon: Icon(Icons.location_on_outlined),
                  alignLabelWithHint: true,
                ),
              ),
              const SizedBox(height: 14),
              Text(
                'موقع العميل',
                style: Theme.of(context)
                    .textTheme
                    .titleSmall
                    ?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 4),
              Text(
                _hadSavedGps
                    ? 'الموقع محفوظ. أي تعديل لاحق يُرسل لمدير المبيعات للاعتماد.'
                    : 'الحفظ الأول للموقع يتم مباشرة.',
                style: const TextStyle(
                  fontSize: 12,
                  color: AppTheme.textSoft,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                _locLabel,
                style: const TextStyle(
                  fontSize: 13,
                  color: AppTheme.textSoft,
                ),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: (_saving || _locating) ? null : _pickOnMap,
                      icon: const Icon(Icons.map_rounded),
                      label: const Text('تحديد على الخريطة'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed:
                          (_saving || _locating) ? null : _useCurrentLocation,
                      icon: _locating
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.my_location_rounded),
                      label: const Text('موقعي الحالي'),
                    ),
                  ),
                ],
              ),
              if (hasGps) ...[
                const SizedBox(height: 4),
                TextButton.icon(
                  onPressed: _saving ? null : _clearLocation,
                  icon: const Icon(Icons.clear_rounded),
                  label: const Text('مسح الموقع'),
                ),
              ],
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _saving || _locating ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Icon(Icons.save_rounded),
                label: Text(_saving ? 'جاري الحفظ...' : 'حفظ التعديلات'),
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: _saving || _locating ? null : _delete,
                icon: const Icon(Icons.delete_outline_rounded),
                style: OutlinedButton.styleFrom(foregroundColor: AppTheme.danger),
                label: const Text('حذف العميل'),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _StatementTab extends StatelessWidget {
  const _StatementTab({required this.customer});
  final Map<String, dynamic> customer;

  @override
  Widget build(BuildContext context) {
    final id = Fmt.toInt(customer['id']);
    return PartyStatementScreen(
      key: ValueKey('stmt-$id'),
      initialCustomerId: id,
      initialCustomerName: Fmt.str(customer['name']),
      initialCustomerCode: Fmt.str(customer['code']),
      autoRun: true,
      embedded: true,
      hidePartyPicker: true,
    );
  }
}

class _PurchaseOrderTab extends StatelessWidget {
  const _PurchaseOrderTab({
    required this.customer,
    required this.visitOpen,
    required this.visitRouteLineId,
    this.orderId,
    required this.onSaved,
    required this.onDeleted,
  });

  final Map<String, dynamic> customer;
  final bool visitOpen;
  final int visitRouteLineId;
  final int? orderId;
  final void Function(int orderId) onSaved;
  final VoidCallback onDeleted;

  @override
  Widget build(BuildContext context) {
    // Offline: route_line_id سالب مقبول طالما الزيارة مفتوحة محلياً
    final canOrder = visitOpen && (visitRouteLineId != 0);
    if (!canOrder) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'سجّل الدخول عند العميل أولاً لإنشاء طلب شراء.',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: AppTheme.textSoft,
              fontWeight: FontWeight.w700,
              fontSize: 14.5,
            ),
          ),
        ),
      );
    }
    return CustomerOrderFormScreen(
      key: ValueKey(
        'po-${Fmt.toInt(customer['id'])}-$visitRouteLineId-${orderId ?? 0}',
      ),
      embedded: true,
      hideCustomerPicker: true,
      initialCustomerId: Fmt.toInt(customer['id']),
      initialCustomerName: Fmt.str(customer['name']),
      initialCustomerCode: Fmt.str(customer['code']),
      visitRouteLineId: visitRouteLineId,
      orderId: (orderId ?? 0) > 0 ? orderId : null,
      onSaved: onSaved,
      onDeleted: onDeleted,
    );
  }
}

class _OrdersTab extends StatelessWidget {
  const _OrdersTab({
    required this.loading,
    required this.error,
    required this.orders,
    required this.from,
    required this.to,
    required this.onFromChanged,
    required this.onToChanged,
    required this.onRetry,
  });

  final bool loading;
  final String? error;
  final List<Map<String, dynamic>> orders;
  final DateTime from;
  final DateTime to;
  final ValueChanged<DateTime> onFromChanged;
  final ValueChanged<DateTime> onToChanged;
  final VoidCallback onRetry;

  Future<void> _pick(BuildContext context, bool isFrom) async {
    final initial = isFrom ? from : to;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    if (isFrom) {
      onFromChanged(picked);
    } else {
      onToChanged(picked);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _pick(context, true),
                  icon: const Icon(Icons.calendar_today, size: 16),
                  label: Text(
                      'من: ${Fmt.dmy('${from.year.toString().padLeft(4, '0')}-${from.month.toString().padLeft(2, '0')}-${from.day.toString().padLeft(2, '0')}')}'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _pick(context, false),
                  icon: const Icon(Icons.calendar_today, size: 16),
                  label: Text(
                      'إلى: ${Fmt.dmy('${to.year.toString().padLeft(4, '0')}-${to.month.toString().padLeft(2, '0')}-${to.day.toString().padLeft(2, '0')}')}'),
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncView(
            loading: loading,
            error: error,
            onRetry: onRetry,
            child: orders.isEmpty
                ? ListView(
                    children: const [
                      SizedBox(height: 50),
                      EmptyState(
                        message: 'لا توجد طلبات في هذه الفترة.',
                        icon: Icons.shopping_cart_outlined,
                      ),
                    ],
                  )
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: orders.length,
                    itemBuilder: (_, i) {
                      final o = orders[i];
                      return AppCard(
                        onTap: () => context.push(
                          '/customer-orders/${Fmt.toInt(o['id'])}',
                        ),
                        child: Row(
                          children: [
                            const MiniIcon(
                              Icons.shopping_cart_checkout_rounded,
                              color: AppTheme.success,
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                Fmt.dmy(Fmt.str(o['order_date'])),
                                style: const TextStyle(
                                  fontWeight: FontWeight.w800,
                                  fontSize: 14,
                                ),
                              ),
                            ),
                            Text(
                              Fmt.money(Fmt.toDouble(o['total'])),
                              textDirection: TextDirection.ltr,
                              style: const TextStyle(
                                fontWeight: FontWeight.w800,
                                fontSize: 14,
                              ),
                            ),
                            const SizedBox(width: 6),
                            const Icon(
                              Icons.chevron_left_rounded,
                              color: AppTheme.textSoft,
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}

class _InvoicesTab extends StatelessWidget {
  const _InvoicesTab({
    required this.loading,
    required this.error,
    required this.invoices,
    required this.from,
    required this.to,
    required this.onFromChanged,
    required this.onToChanged,
    required this.onRetry,
  });

  final bool loading;
  final String? error;
  final List<Map<String, dynamic>> invoices;
  final DateTime from;
  final DateTime to;
  final ValueChanged<DateTime> onFromChanged;
  final ValueChanged<DateTime> onToChanged;
  final VoidCallback onRetry;

  Future<void> _pick(BuildContext context, bool isFrom) async {
    final initial = isFrom ? from : to;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    if (isFrom) {
      onFromChanged(picked);
    } else {
      onToChanged(picked);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _pick(context, true),
                  icon: const Icon(Icons.calendar_today, size: 16),
                  label: Text(
                      'من: ${Fmt.dmy('${from.year.toString().padLeft(4, '0')}-${from.month.toString().padLeft(2, '0')}-${from.day.toString().padLeft(2, '0')}')}'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _pick(context, false),
                  icon: const Icon(Icons.calendar_today, size: 16),
                  label: Text(
                      'إلى: ${Fmt.dmy('${to.year.toString().padLeft(4, '0')}-${to.month.toString().padLeft(2, '0')}-${to.day.toString().padLeft(2, '0')}')}'),
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: AsyncView(
            loading: loading,
            error: error,
            onRetry: onRetry,
            child: invoices.isEmpty
                ? ListView(
                    children: const [
                      SizedBox(height: 50),
                      EmptyState(
                        message: 'لا توجد فواتير في هذه الفترة.',
                        icon: Icons.receipt_long_outlined,
                      ),
                    ],
                  )
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: invoices.length,
                    itemBuilder: (_, i) {
                      final inv = invoices[i];
                      return AppCard(
                        onTap: () => context.push(
                          '/invoices/${Fmt.toInt(inv['id'])}',
                        ),
                        child: Row(
                          children: [
                            const MiniIcon(
                              Icons.receipt_long_rounded,
                              color: AppTheme.primarySoft,
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    Fmt.str(inv['invoice_no']).isEmpty
                                        ? '#${Fmt.toInt(inv['id'])}'
                                        : Fmt.str(inv['invoice_no']),
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                  Text(
                                    Fmt.dmy(Fmt.str(inv['invoice_date'])),
                                    style: const TextStyle(
                                      color: AppTheme.textSoft,
                                      fontSize: 12.5,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            Text(
                              Fmt.money(Fmt.toDouble(inv['total'])),
                              textDirection: TextDirection.ltr,
                              style: const TextStyle(
                                fontWeight: FontWeight.w800,
                                fontSize: 14,
                              ),
                            ),
                            const SizedBox(width: 6),
                            const Icon(
                              Icons.chevron_left_rounded,
                              color: AppTheme.textSoft,
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }
}
