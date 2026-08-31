import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../core/visit_status.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/app_confirm_dialog.dart';
import '../../widgets/ui_kit.dart';
import '../customer_orders/customer_order_form_screen.dart';
import 'visit_workspace_panel.dart';

class RepVisitsScreen extends StatefulWidget {
  const RepVisitsScreen({super.key});

  @override
  State<RepVisitsScreen> createState() => _RepVisitsScreenState();
}

class _RepVisitsScreenState extends State<RepVisitsScreen> {
  bool _loading = true;
  String? _error;
  String _routeDate = '';
  String _weekdayLabel = '';
  int _radiusM = 200;
  List<Map<String, dynamic>> _visits = [];
  List<Map<String, dynamic>> _noOrderReasons = [];
  Map<String, dynamic>? _selected;
  bool _busy = false;
  final _dateCtrl = TextEditingController();
  final _listSearch = TextEditingController();

  /// أجندة الشهر: كل يوم + عملاء الجولة المرحّلة
  String _monthYm = '';
  List<Map<String, dynamic>> _agendaDays = [];
  bool _monthMode = false;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _routeDate = Fmt.todayIso();
    _dateCtrl.text = _routeDate;
    _monthYm =
        '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}';
    _load();
  }

  @override
  void dispose() {
    _dateCtrl.dispose();
    _listSearch.dispose();
    super.dispose();
  }

  /// تخطيط BlueStacks (قائمة + تفاصيل) على التاب وأي شاشة عرضية كافية.
  bool _useSplitLayout(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    return size.shortestSide >= 550 ||
        size.width >= 900 ||
        (size.width > size.height && size.shortestSide >= 500);
  }

  void _selectTourCustomer(Map<String, dynamic> v) {
    if (_busy) return;
    if (v['in_plan'] != true) return;
    setState(() => _selected = v);
  }

  bool _isVisitOpen(Map<String, dynamic>? v) {
    final s = _statusOf(v);
    return s == 'checked_in' || s == 'pending_manual_checkout';
  }

  bool _canCheckin(Map<String, dynamic>? v) {
    if (v == null || v['in_plan'] != true) return false;
    final s = _statusOf(v);
    return s == 'idle' || s == 'checked_out' || s.isEmpty;
  }

  bool _canCheckout(Map<String, dynamic>? v) {
    return v != null && _statusOf(v) == 'checked_in';
  }

  Future<bool?> _askVisitMethod({required bool checkout}) {
    return showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(checkout ? 'طريقة تسجيل الخروج' : 'طريقة تسجيل الدخول'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              checkout
                  ? 'اختر طريقة تسجيل الخروج من العميل.'
                  : (_radiusM > 0
                      ? 'اختر طريقة تسجيل الدخول إلى العميل.\nنصف القطر المسموح: $_radiusM م'
                      : 'اختر طريقة تسجيل الدخول إلى العميل.'),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => Navigator.pop(ctx, true),
                    icon: const Icon(Icons.edit_location_alt_rounded, size: 18),
                    label: const Text('يدوي'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () => Navigator.pop(ctx, false),
                    icon: const Icon(Icons.my_location_rounded, size: 18),
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
  }

  Future<void> _startCheckin() async {
    if (_selected == null || _busy) return;
    if (!_canCheckin(_selected)) return;
    final method = await _askVisitMethod(checkout: false);
    if (method == null || !mounted) return;
    await _checkin(manual: method);
  }

  Future<void> _startCheckout() async {
    if (_selected == null || _busy) return;
    if (!_canCheckout(_selected)) return;
    if (VisitStatus.isManualMethod(_selected!['checkin_method'])) {
      await _checkout(manual: true);
      return;
    }
    final method = await _askVisitMethod(checkout: true);
    if (method == null || !mounted) return;
    await _checkout(manual: method);
  }

  Future<void> _applyMonthPayload(Map<String, dynamic> res) async {
    final days = (res['days'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    setState(() {
      _agendaDays = days;
      _monthYm =
          Fmt.str(res['month']).isEmpty ? _monthYm : Fmt.str(res['month']);
      final r = Fmt.toInt(res['visit_radius_m']);
      if (r > 0) _radiusM = r;
      _loading = false;
    });
  }

  Future<void> _loadMonth() async {
    setState(() {
      _loading = true;
      _error = null;
      _monthMode = true;
      _selected = null;
    });
    final offline = context.read<OfflineController>();
    try {
      if (offline.online) {
        await offline.syncIfOnline();
        if (!mounted) return;
      }
      if (!offline.online && offline.catalogReady) {
        final local = await OfflineStore.instance.monthAgenda(_monthYm);
        if (!mounted) return;
        if (local == null) {
          setState(() {
            _error =
                'لا توجد أجندة محلية لهذا الشهر. حدّث البيانات وأنت متصل.';
            _loading = false;
          });
          return;
        }
        await _applyMonthPayload(local);
        return;
      }
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitListPath,
            query: {'mode': 'month', 'month': _monthYm},
          );
      if (!mounted) return;
      await OfflineStore.instance.saveMonthAgenda(_monthYm, res);
      if (!mounted) return;
      await _applyMonthPayload(res);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        final local = await OfflineStore.instance.monthAgenda(_monthYm);
        if (local != null) {
          await _applyMonthPayload(local);
          return;
        }
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _shiftMonth(int delta) async {
    final parts = _monthYm.split('-');
    if (parts.length != 2) return;
    var y = int.tryParse(parts[0]) ?? DateTime.now().year;
    var m = int.tryParse(parts[1]) ?? DateTime.now().month;
    m += delta;
    while (m < 1) {
      m += 12;
      y -= 1;
    }
    while (m > 12) {
      m -= 12;
      y += 1;
    }
    setState(() {
      _monthYm =
          '${y.toString().padLeft(4, '0')}-${m.toString().padLeft(2, '0')}';
    });
    await _loadMonth();
  }

  void _openCustomerHub(int customerId) {
    if (customerId < 1) return;
    context.push('/customers?id=$customerId').then((_) {
      if (mounted) {
        if (_monthMode) {
          _loadMonth();
        } else {
          _load();
        }
      }
    });
  }

  Future<void> _applyDayPayload(
    Map<String, dynamic> res, {
    required int keepId,
  }) async {
    final visits = (res['visits'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    var noOrderReasons = (res['no_order_reasons'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    if (noOrderReasons.isEmpty) {
      noOrderReasons = await OfflineStore.instance.noOrderReasons();
    }
    // دمج الزيارة المفتوحة محلياً
    final open = await OfflineStore.instance.loadOpenVisit();
    if (open != null) {
      final oid = Fmt.toInt(open['customer_id']);
      if (oid != 0) {
        final idx = visits.indexWhere((v) => Fmt.toInt(v['customer_id']) == oid);
        final patch = {
          'status': 'checked_in',
          'visit_checkin_at': open['visit_checkin_at'],
          'route_line_id': open['route_line_id'],
          'checkin_method': open['method'],
          'offline': true,
        };
        if (idx >= 0) {
          visits[idx] = {...visits[idx], ...patch};
        } else {
          final c = await OfflineStore.instance.getCustomerById(oid);
          visits.add({
            'customer_id': oid,
            'name': c?['name'] ?? '',
            'code': c?['code'] ?? '',
            'in_plan': false,
            ...patch,
          });
        }
      }
    }
    visits.sort((a, b) {
      final ap = a['in_plan'] == true ? 0 : 1;
      final bp = b['in_plan'] == true ? 0 : 1;
      if (ap != bp) return ap - bp;
      return Fmt.toInt(a['sort_order']).compareTo(Fmt.toInt(b['sort_order']));
    });
    Map<String, dynamic>? selected;
    if (keepId != 0) {
      for (final v in visits) {
        if (Fmt.toInt(v['customer_id']) == keepId) {
          selected = v;
          break;
        }
      }
    }
    if (selected == null) {
      final openVisits = visits.where((v) {
        final s = Fmt.str(v['status']);
        return s == 'checked_in' || s == 'pending_manual_checkout';
      }).toList();
      if (openVisits.length == 1) {
        selected = openVisits.first;
      }
    }
    setState(() {
      _visits = visits;
      _noOrderReasons = noOrderReasons;
      _selected = selected;
      _routeDate = Fmt.str(res['route_date']).isEmpty
          ? _routeDate
          : Fmt.str(res['route_date']);
      _dateCtrl.text = _routeDate;
      _weekdayLabel = Fmt.str(res['weekday_label']);
      if (_weekdayLabel.isEmpty && visits.isNotEmpty) {
        _weekdayLabel = Fmt.str(visits.first['weekday_label']);
      }
      _radiusM = Fmt.toInt(res['visit_radius_m']);
      if (_radiusM < 1) {
        _radiusM = 200;
      }
      _loading = false;
    });
  }

  Future<Map<String, dynamic>?> _localDayPayload() async {
    final store = OfflineStore.instance;
    final visits = await store.visitsForDate(_routeDate);
    if (visits.isEmpty && !await store.hasCatalog) return null;
    final wd = await store.weekdayLabelForDate(_routeDate);
    final radius = await store.visitRadiusM();
    final reasons = await store.noOrderReasons();
    return {
      'route_date': _routeDate,
      'weekday_label': wd,
      'visit_radius_m': radius,
      'visits': visits,
      'no_order_reasons': reasons,
    };
  }

  Future<void> _load({int? keepCustomerId}) async {
    final keepId = keepCustomerId ?? Fmt.toInt(_selected?['customer_id']);
    setState(() {
      _loading = true;
      _error = null;
      _monthMode = false;
    });
    final offline = context.read<OfflineController>();
    try {
      if (offline.online) {
        await offline.syncIfOnline();
        if (!mounted) return;
      }
      if (!offline.online && offline.catalogReady) {
        final local = await _localDayPayload();
        if (!mounted) return;
        if (local == null) {
          setState(() {
            _error = 'لا توجد جولة محلية. حدّث البيانات وأنت متصل.';
            _loading = false;
          });
          return;
        }
        await _applyDayPayload(local, keepId: keepId);
        return;
      }
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitListPath,
            query: {'date': _routeDate},
          );
      if (!mounted) return;
      final live = (res['visits'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      await OfflineStore.instance.saveVisitsForDate(
        Fmt.str(res['route_date']).isEmpty ? _routeDate : Fmt.str(res['route_date']),
        live,
        weekdayLabel: Fmt.str(res['weekday_label']),
      );
      if (!mounted) return;
      await _applyDayPayload(res, keepId: keepId);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        final local = await _localDayPayload();
        if (local != null) {
          await _applyDayPayload(local, keepId: keepId);
          return;
        }
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _pickDate() async {
    if (_busy) return;
    final initial = DateTime.tryParse(_routeDate) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(initial.year - 1),
      lastDate: DateTime(initial.year + 1),
    );
    if (picked == null) return;
    setState(() {
      _routeDate =
          '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
      _dateCtrl.text = _routeDate;
      _selected = null;
    });
    await _load();
  }

  bool _inPlan(Map<String, dynamic> v) {
    final p = v['in_plan'];
    return p == true || p == 1 || p == '1';
  }

  List<Map<String, dynamic>> get _plannedVisits {
    final list = _visits.where(_inPlan).toList();
    _sortOpenVisitsFirst(list);
    return list;
  }

  List<Map<String, dynamic>> get _extraOpenOrDone {
    final list = _visits.where((v) {
        if (_inPlan(v)) return false;
        final s = Fmt.str(v['status']);
        return s == 'checked_in' ||
            s == 'pending_manual_checkout' ||
            s == 'checked_out';
      }).toList();
    _sortOpenVisitsFirst(list);
    return list;
  }

  void _sortOpenVisitsFirst(List<Map<String, dynamic>> list) {
    list.sort((a, b) {
      int rank(Map<String, dynamic> v) {
        final s = _statusOf(v);
        if (s == 'checked_in' || s == 'pending_manual_checkout') return 0;
        return 1;
      }
      return rank(a).compareTo(rank(b));
    });
  }

  String _statusOf(Map<String, dynamic>? v, {String? referenceDate}) {
    if (v == null) return '';
    return VisitStatus.effective(
      status: Fmt.str(v['status']),
      checkinAt: Fmt.str(v['visit_checkin_at']),
      checkoutAt: Fmt.str(v['visit_checkout_at']),
      referenceDate: referenceDate ?? _routeDate,
    );
  }

  String _statusLabel(String s) {
    switch (s) {
      case 'checked_in':
        return 'داخل الزيارة';
      case 'checked_out':
        return 'تم الخروج';
      case 'pending_manual_checkout':
        return 'بانتظار موافقة المدير';
      default:
        return 'جاهز للدخول';
    }
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'checked_in':
        return AppTheme.success;
      case 'checked_out':
        return AppTheme.danger;
      case 'pending_manual_checkout':
        return AppTheme.success;
      default:
        return AppTheme.primary;
    }
  }

  IconData _statusIcon(String s) {
    switch (s) {
      case 'checked_in':
        return Icons.login_rounded;
      case 'checked_out':
        return Icons.check_circle_rounded;
      case 'pending_manual_checkout':
        return Icons.hourglass_top_rounded;
      default:
        return Icons.storefront_rounded;
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

  Future<void> _pickCustomer({bool outsidePlanOnly = false}) async {
    if (_busy) return;
    if (!outsidePlanOnly && _visits.isNotEmpty) {
      final list = outsidePlanOnly
          ? _visits.where((v) => v['in_plan'] != true).toList()
          : _visits;
      final picked = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (ctx) => _CustomerPickSheet(visits: list, routeDate: _routeDate),
      );
      if (picked != null && mounted) {
        setState(() => _selected = picked);
      }
      return;
    }
    final party = await pickParty(context);
    if (party == null || !mounted) return;
    Map<String, dynamic>? existing;
    for (final v in _visits) {
      if (Fmt.toInt(v['customer_id']) == party.id) {
        existing = v;
        break;
      }
    }
    setState(() {
      _selected = existing ??
          {
            'customer_id': party.id,
            'name': party.name,
            'code': party.code,
            'status': 'idle',
            'in_plan': false,
            'checkin_method': '',
            'checkout_method': '',
          };
    });
  }

  List<Map<String, dynamic>> get _openVisits => _visits.where((v) {
        final s = Fmt.str(v['status']);
        return s == 'checked_in' || s == 'pending_manual_checkout';
      }).toList();

  Future<bool> _confirm(String title, String body) async {
    final ok = await showAppConfirmDialog(
      context,
      title: title,
      message: body,
    );
    return ok == true;
  }

  int _offlineRouteLineId(int customerId) => -(1000000000 + customerId.abs());

  Future<void> _applyLocalCheckin({
    required int customerId,
    required bool manual,
    required Map<String, dynamic> gps,
  }) async {
    final offline = context.read<OfflineController>();
    final now = DateTime.now().toIso8601String();
    final lineId = _offlineRouteLineId(customerId);
    final method = manual ? 'MANUAL' : 'GPS';
    await offline.enqueue(
      kind: 'visit_checkin',
      path: AppConfig.repVisitCheckinPath,
      body: {
        'customer_id': customerId,
        'method': method,
        ...gps,
      },
    );
    await OfflineStore.instance.saveOpenVisit({
      'customer_id': customerId,
      'visit_checkin_at': now,
      'route_line_id': lineId,
      'method': method,
      'visit_radius_m': _radiusM,
    });
    await OfflineStore.instance.patchLocalVisitCheckin(
      routeDate: _routeDate,
      customerId: customerId,
      checkinAt: now,
      method: method,
      routeLineId: lineId,
    );
    if (!mounted) return;
    showSnack(context, 'تم تسجيل الدخول محلياً — سيُرحَّل عند عودة الاتصال.');
    await _load(keepCustomerId: customerId);
  }

  Future<void> _checkin({required bool manual}) async {
    final v = _selected;
    if (v == null || _busy) return;
    final name = Fmt.str(v['name']);
    final ok = await _confirm(
      'تأكيد تسجيل الدخول',
      manual
          ? 'تأكيد الدخول اليدوي إلى «$name»؟\nستبقى الزيارة مفتوحة حتى تسجّل الخروج.'
          : 'تأكيد الدخول بـ GPS إلى «$name»؟\nستبقى الزيارة مفتوحة حتى تسجّل الخروج.',
    );
    if (!ok || !mounted) return;
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
          showSnack(context, 'تعذّر قراءة GPS. جرّب دخولاً يدوياً.', error: true);
          return;
        }
        gps = g;
      } else {
        gps = await _gpsFields() ?? {};
      }
      final customerId = Fmt.toInt(v['customer_id']);
      if (!offline.online && offline.catalogReady) {
        await _applyLocalCheckin(
          customerId: customerId,
          manual: manual,
          gps: gps,
        );
        return;
      }
      try {
        final res = await api.postJson(
          AppConfig.repVisitCheckinPath,
          body: {
            'customer_id': customerId,
            'method': manual ? 'MANUAL' : 'GPS',
            ...gps,
          },
          csrf: csrf,
        );
        if (!mounted) return;
        showSnack(
          context,
          Fmt.str(res['message']).isEmpty
              ? 'تم تسجيل الدخول. الزيارة مفتوحة.'
              : Fmt.str(res['message']),
        );
        await _load(keepCustomerId: customerId);
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await _applyLocalCheckin(
            customerId: customerId,
            manual: manual,
            gps: gps,
          );
        } else {
          if (!mounted) return;
          showSnack(context, e.message, error: true);
        }
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<List<int>?> _pickNoOrderReasons() async {
    if (_noOrderReasons.isEmpty) {
      final local = await OfflineStore.instance.noOrderReasons();
      if (local.isNotEmpty && mounted) {
        setState(() => _noOrderReasons = local);
      }
    }
    if (_noOrderReasons.isEmpty) {
      showSnack(
        context,
        'لا توجد أسباب «عدم طلب العميل». حدّث البيانات أولاً.',
        error: true,
      );
      return null;
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
    final leave =
        await CustomerOrderFormScreenState.active?.confirmLeave() ?? true;
    if (!leave || !mounted) return;
    final v = _selected;
    if (v == null || _busy) return;
    final name = Fmt.str(v['name']);
    List<int> noOrderReasonIds = [];
    if (v['has_order'] != true) {
      final picked = await _pickNoOrderReasons();
      if (picked == null || picked.isEmpty) return;
      noOrderReasonIds = picked;
    }
    final matchedManual =
        manual && VisitStatus.isManualMethod(v['checkin_method']);
    final ok = await _confirm(
      'تأكيد تسجيل الخروج',
      matchedManual
          ? 'تأكيد تسجيل الخروج من عند «$name»؟'
          : (manual
              ? 'تأكيد الخروج اليدوي من عند «$name»؟'
              : 'تأكيد الخروج بـ GPS من عند «$name»؟'),
    );
    if (!ok || !mounted) return;
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    final offline = context.read<OfflineController>();
    String? reason;
    if (manual && !matchedManual) {
      reason = await showManualCheckoutReasonDialog(context);
      if (reason == null) return;
    }
    setState(() => _busy = true);
    try {
      Map<String, dynamic> gps = {};
      if (!manual) {
        final g = await _gpsFields();
        if (g == null) {
          if (!mounted) return;
          showSnack(context, 'تعذّر قراءة GPS. جرّب خروجاً يدوياً.', error: true);
          return;
        }
        gps = g;
      } else {
        gps = await _gpsFields() ?? {};
      }
      final customerId = Fmt.toInt(v['customer_id']);
      final body = <String, dynamic>{
        'customer_id': customerId,
        'method': manual ? 'MANUAL' : 'GPS',
        'no_order_reason_ids':
            noOrderReasonIds.where((id) => id > 0).toList(),
        if (reason != null && reason.isNotEmpty) 'reason': reason,
        ...gps,
      };
      // أسباب سالبة (محلية): أرسل نص السبب فقط
      if (noOrderReasonIds.any((id) => id < 0) &&
          (reason == null || reason.isEmpty)) {
        final names = _noOrderReasons
            .where((r) => noOrderReasonIds.contains(Fmt.toInt(r['id'])))
            .map((r) => Fmt.str(r['name_ar']))
            .where((s) => s.isNotEmpty)
            .join('، ');
        if (names.isNotEmpty) body['reason'] = names;
      }

      Future<void> localCheckout() async {
        await offline.enqueue(
          kind: 'visit_checkout',
          path: AppConfig.repVisitCheckoutPath,
          body: body,
        );
        final now = DateTime.now().toIso8601String();
        final reasonNames = _noOrderReasons
            .where((r) => noOrderReasonIds.contains(Fmt.toInt(r['id'])))
            .map((r) => Fmt.str(r['name_ar']))
            .where((s) => s.isNotEmpty)
            .toList();
        await OfflineStore.instance.patchLocalVisitCheckout(
          routeDate: _routeDate,
          customerId: customerId,
          checkoutAt: now,
          method: manual ? 'MANUAL' : 'GPS',
          reasonNames: reasonNames,
        );
        await OfflineStore.instance.clearOpenVisit();
        if (!mounted) return;
        showSnack(
          context,
          'تم تسجيل الخروج محلياً — سيُرحَّل عند عودة الاتصال.',
        );
        await _load();
      }

      if (!offline.online && offline.catalogReady) {
        await localCheckout();
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
        await OfflineStore.instance.clearOpenVisit();
        await _load(
          keepCustomerId: needsApproval ? customerId : 0,
        );
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await localCheckout();
        } else {
          if (!mounted) return;
          showSnack(context, e.message, error: true);
        }
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: Text(_monthMode ? 'جولة الشهر' : 'جولة اليوم'),
      backgroundColor: const Color(0xFFF0F4F8),
      actions: [
        if (!_monthMode)
          IconButton(
            tooltip: 'أجندة الشهر',
            onPressed: _busy ? null : _loadMonth,
            icon: const Icon(Icons.calendar_view_month_rounded),
          ),
        IconButton(
          tooltip: 'تقرير الزيارات',
          onPressed: _busy ? null : () => context.push('/rep/visit-report'),
          icon: const Icon(Icons.assignment_rounded),
        ),
        IconButton(
          onPressed: _busy
              ? null
              : () => _monthMode
                  ? _loadMonth()
                  : _load(keepCustomerId: Fmt.toInt(_selected?['customer_id'])),
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: AsyncView(
        loading: _loading && _selected == null,
        error: _error,
        onRetry: _monthMode ? _loadMonth : _load,
        child: Stack(
          children: [
            Positioned(
              top: -80,
              left: -40,
              child: _blob(160, AppTheme.primary.withValues(alpha: 0.08)),
            ),
            Positioned(
              bottom: 40,
              right: -50,
              child: _blob(200, AppTheme.teal.withValues(alpha: 0.07)),
            ),
            if (_useSplitLayout(context) && !_monthMode)
              _buildTabletDay()
            else if (_useSplitLayout(context) && _monthMode)
              _buildTabletMonth()
            else if (_selected == null)
              (_monthMode ? _buildMonthAgenda() : _buildHome())
            else
              _buildVisit(),
            if (_busy)
              const Positioned(
                left: 0,
                right: 0,
                top: 0,
                child: LinearProgressIndicator(minHeight: 2),
              ),
          ],
        ),
      ),
    );
  }

  Widget _blob(double size, Color color) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(shape: BoxShape.circle, color: color),
      ),
    );
  }

  List<Map<String, dynamic>> _filterPlanned(List<Map<String, dynamic>> planned) {
    final q = _listSearch.text.trim();
    if (q.isEmpty) return planned;
    return planned.where((v) {
      return Fmt.str(v['name']).contains(q) || Fmt.str(v['code']).contains(q);
    }).toList();
  }

  Widget _buildTabletDay() {
    final planned = _plannedVisits;
    final list = _filterPlanned(planned);
    final open = _isVisitOpen(_selected);
    final pending = _statusOf(_selected) == 'pending_manual_checkout';
    if (open) {
      return Column(
        children: [
          _tabletCheckedInBar(),
          Expanded(
            child: VisitWorkspacePanel(
              key: ValueKey(
                'ws-${Fmt.toInt(_selected!['customer_id'])}-${Fmt.toInt(_selected!['route_line_id'])}',
              ),
              customerId: Fmt.toInt(_selected!['customer_id']),
              customerName: Fmt.str(_selected!['name']),
              customerCode: Fmt.str(_selected!['code']),
              visitRouteLineId: Fmt.toInt(_selected!['route_line_id']),
              visitOpen: true,
              orderId: Fmt.toInt(_selected!['order_id']) > 0
                  ? Fmt.toInt(_selected!['order_id'])
                  : null,
              onOrderChanged: () => _load(
                keepCustomerId: Fmt.toInt(_selected!['customer_id']),
              ),
            ),
          ),
        ],
      );
    }
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _tourListPane(
          width: (MediaQuery.sizeOf(context).width * 0.34).clamp(320.0, 460.0),
          title: _weekdayLabel.isEmpty ? 'عملاء الجولة' : _weekdayLabel,
          subtitle: '${planned.length} عميل ضمن الجولة',
          customers: list,
          header: _tabletDayHeader(),
          footer: _tabletVisitFooter(),
        ),
        Expanded(
          child: _selected == null
              ? _tabletEmptyHint(
                  planned.isEmpty
                      ? 'لا توجد جولة مخططة لهذا اليوم.'
                      : 'اختر عميلاً من القائمة ثم اضغط تسجيل الدخول.',
                )
              : pending
                  ? _tabletEmptyHint(
                      'تم إرسال الخروج اليدوي بانتظار اعتماد المدير.',
                    )
                  : _tabletEmptyHint(
                      'اضغط «تسجيل دخول الى العميل» أسفل القائمة.',
                    ),
        ),
      ],
    );
  }

  Widget _tabletCheckedInBar() {
    final v = _selected!;
    return Material(
      color: const Color(0xFFDCFCE7),
      child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 6),
          child: Row(
            children: [
              const Icon(Icons.login_rounded, color: AppTheme.success, size: 18),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  [
                    Fmt.str(v['name']),
                    if (Fmt.str(v['code']).isNotEmpty) Fmt.str(v['code']),
                  ].join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 14,
                    color: Color(0xFF0B6B3A),
                  ),
                ),
              ),
              TextButton.icon(
                onPressed: _busy ? null : _startCheckout,
                icon: const Icon(Icons.logout_rounded, size: 18),
                label: const Text('تسجيل خروج'),
                style: TextButton.styleFrom(
                  foregroundColor: AppTheme.danger,
                  visualDensity: VisualDensity.compact,
                ),
              ),
            ],
          ),
      ),
    );
  }

  Widget _tabletVisitFooter() {
    final v = _selected;
    if (v == null || v['in_plan'] != true) {
      return const SizedBox.shrink();
    }
    if (_canCheckin(v)) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
        child: SizedBox(
          height: 48,
          width: double.infinity,
          child: FilledButton.icon(
            onPressed: _busy ? null : _startCheckin,
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFFF5C518),
              foregroundColor: const Color(0xFF3D2E00),
              disabledBackgroundColor: const Color(0xFFF5C518).withValues(alpha: 0.45),
            ),
            icon: const Icon(Icons.login_rounded, size: 18),
            label: const Text(
              'تسجيل دخول الى العميل',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
            ),
          ),
        ),
      );
    }
    if (_canCheckout(v)) {
      final at = Fmt.str(v['visit_checkin_at']);
      return Padding(
        padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
        child: Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: _busy ? null : _startCheckout,
            child: Container(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: AppTheme.danger.withValues(alpha: 0.35),
                ),
              ),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(
                      color: AppTheme.danger.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(
                      Icons.logout_rounded,
                      color: AppTheme.danger,
                      size: 20,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'تسجيل خروج من العميل',
                          style: TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 14,
                            color: AppTheme.danger,
                          ),
                        ),
                        if (at.isNotEmpty)
                          Text(
                            'بداية الزيارة : $at',
                            style: const TextStyle(
                              color: AppTheme.textSoft,
                              fontSize: 12,
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }
    if (_statusOf(v) == 'pending_manual_checkout') {
      return const Padding(
        padding: EdgeInsets.fromLTRB(12, 10, 12, 12),
        child: Text(
          'بانتظار موافقة المدير على الخروج اليدوي.',
          textAlign: TextAlign.center,
          style: TextStyle(
            color: AppTheme.warn,
            fontWeight: FontWeight.w700,
            fontSize: 12.5,
          ),
        ),
      );
    }
    return const SizedBox.shrink();
  }

  Widget _tabletDayHeader() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _dateCtrl,
          readOnly: true,
          onTap: _pickDate,
          decoration: const InputDecoration(
            labelText: 'تاريخ الجولة',
            suffixIcon: Icon(Icons.calendar_month_rounded),
            isDense: true,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'نصف القطر: $_radiusM م · الزيارة لعملاء الجولة فقط',
          style: const TextStyle(color: AppTheme.textSoft, fontSize: 12),
        ),
      ],
    );
  }

  Widget _buildTabletMonth() {
    final rows = <Map<String, dynamic>>[];
    for (final day in _agendaDays) {
      final date = Fmt.str(day['route_date']);
      final wd = Fmt.str(day['weekday_label']);
      for (final raw in (day['customers'] as List? ?? [])) {
        if (raw is! Map) continue;
        final c = raw.cast<String, dynamic>();
        rows.add({
          ...c,
          'in_plan': true,
          'route_date': date,
          'weekday_label': wd,
        });
      }
    }
    final q = _listSearch.text.trim();
    final list = q.isEmpty
        ? rows
        : rows.where((v) {
            return Fmt.str(v['name']).contains(q) ||
                Fmt.str(v['code']).contains(q);
          }).toList();
    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _tourListPane(
          width: (MediaQuery.sizeOf(context).width * 0.34).clamp(320.0, 460.0),
          title: _monthTitleAr(),
          subtitle: '${rows.length} زيارة مخططة هذا الشهر',
          customers: list,
          useAgendaDate: true,
          header: Row(
            children: [
              IconButton(
                onPressed: _busy ? null : () => _shiftMonth(-1),
                icon: const Icon(Icons.chevron_right_rounded),
              ),
              const Expanded(
                child: Text(
                  'عملاء الجولة',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
              IconButton(
                onPressed: _busy ? null : () => _shiftMonth(1),
                icon: const Icon(Icons.chevron_left_rounded),
              ),
            ],
          ),
        ),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(8, 12, 16, 16),
            child: _tabletEmptyHint(
              rows.isEmpty
                  ? 'انتظر ترحيل الجولة من الإدارة.'
                  : 'اختر عميلاً من القائمة لفتح زيارة ذلك اليوم.',
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _openAgendaCustomer(String date, Map<String, dynamic> c) async {
    if (_busy) return;
    setState(() {
      _monthMode = false;
      _routeDate = date;
      _dateCtrl.text = date;
      _selected = null;
    });
    await _load(keepCustomerId: Fmt.toInt(c['customer_id']));
  }

  Widget _tabletEmptyHint(String text) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppTheme.border),
        boxShadow: AppTheme.softShadow,
      ),
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.touch_app_rounded,
                size: 48,
                color: AppTheme.primary.withValues(alpha: 0.45),
              ),
              const SizedBox(height: 12),
              Text(
                text,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 15,
                  height: 1.45,
                  color: AppTheme.textSoft,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _tourListPane({
    required String title,
    required String subtitle,
    required List<Map<String, dynamic>> customers,
    Widget? header,
    Widget? footer,
    bool useAgendaDate = false,
    double width = 360,
  }) {
    final selectedId = Fmt.toInt(_selected?['customer_id']);
    return Container(
      width: width,
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(
          left: BorderSide(color: AppTheme.border),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: const TextStyle(
                    color: AppTheme.textSoft,
                    fontSize: 12.5,
                  ),
                ),
                if (header != null) ...[
                  const SizedBox(height: 10),
                  header,
                ],
                const SizedBox(height: 10),
                TextField(
                  controller: _listSearch,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    hintText: 'بحث في عملاء الجولة',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20),
                    isDense: true,
                    suffixIcon: _listSearch.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.close_rounded, size: 18),
                            onPressed: () {
                              _listSearch.clear();
                              setState(() {});
                            },
                          ),
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: customers.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(20),
                      child: Text(
                        'لا يوجد عملاء ضمن الجولة.',
                        textAlign: TextAlign.center,
                        style: TextStyle(color: AppTheme.textSoft),
                      ),
                    ),
                  )
                : ListView.separated(
                    cacheExtent: 600,
                    addAutomaticKeepAlives: false,
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    itemCount: customers.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (_, i) {
                      final v = customers[i];
                      final date = Fmt.str(v['route_date']);
                      final st = _statusOf(
                        v,
                        referenceDate: date.isEmpty ? null : date,
                      );
                      final id = Fmt.toInt(v['customer_id']);
                      return _PlanCustomerTile(
                        index: i + 1,
                        name: Fmt.str(v['name']),
                        code: [
                          Fmt.str(v['code']),
                          if (useAgendaDate && date.isNotEmpty)
                            '${Fmt.str(v['weekday_label'])} ${Fmt.dmy(date)}',
                        ].where((s) => s.isNotEmpty).join(' · '),
                        status: st,
                        statusLabel: _statusLabel(st),
                        statusColor: _statusColor(st),
                        statusIcon: _statusIcon(st),
                        hasGps: v['has_gps'] == true,
                        selected: id == selectedId &&
                            (!useAgendaDate || date == _routeDate),
                        onTap: _busy
                            ? null
                            : () {
                                if (useAgendaDate) {
                                  _openAgendaCustomer(date, v);
                                } else {
                                  _selectTourCustomer(v);
                                }
                              },
                      );
                    },
                  ),
          ),
          if (footer != null) ...[
            const Divider(height: 1),
            footer,
          ],
        ],
      ),
    );
  }

  String _monthTitleAr() {
    final parts = _monthYm.split('-');
    if (parts.length != 2) return _monthYm;
    const names = [
      '',
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ];
    final m = int.tryParse(parts[1]) ?? 0;
    final y = parts[0];
    if (m < 1 || m > 12) return _monthYm;
    return '${names[m]} $y';
  }

  Widget _buildMonthAgenda() {
    final today = Fmt.todayIso();
    var totalCustomers = 0;
    for (final d in _agendaDays) {
      totalCustomers +=
          ((d['customers'] as List?) ?? const []).length;
    }
    return RefreshIndicator(
      onRefresh: _loadMonth,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 36),
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: AppTheme.border),
              boxShadow: AppTheme.softShadow,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    IconButton(
                      onPressed: _busy ? null : () => _shiftMonth(-1),
                      icon: const Icon(Icons.chevron_right_rounded),
                      tooltip: 'الشهر السابق',
                    ),
                    Expanded(
                      child: Text(
                        _monthTitleAr(),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: _busy ? null : () => _shiftMonth(1),
                      icon: const Icon(Icons.chevron_left_rounded),
                      tooltip: 'الشهر التالي',
                    ),
                  ],
                ),
                Text(
                  totalCustomers == 0
                      ? 'لا توجد زيارات مخططة في جولة مرحّلة لهذا الشهر.'
                      : '$totalCustomers زيارة مخططة ضمن الجولة · اختر عميلاً لفتح الزيارة',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: AppTheme.textSoft,
                    fontSize: 12.5,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  alignment: WrapAlignment.center,
                  children: [
                    FilledButton.tonalIcon(
                      onPressed: _busy
                          ? null
                          : () {
                              setState(() {
                                _routeDate = today;
                                _dateCtrl.text = today;
                              });
                              _load();
                            },
                      icon: const Icon(Icons.today_rounded, size: 18),
                      label: const Text('جولة اليوم'),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          if (_agendaDays.isEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 40),
              child: Center(
                child: Text(
                  'انتظر ترحيل الجولة من الإدارة.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: AppTheme.textSoft),
                ),
              ),
            )
          else
            ..._agendaDays.map((day) {
              final date = Fmt.str(day['route_date']);
              final wd = Fmt.str(day['weekday_label']);
              final customers = (day['customers'] as List? ?? [])
                  .whereType<Map>()
                  .map((e) => e.cast<String, dynamic>())
                  .toList();
              final isToday = date == today;
              return Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Container(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(
                      color: isToday
                          ? AppTheme.primary.withValues(alpha: 0.45)
                          : AppTheme.border,
                      width: isToday ? 1.5 : 1,
                    ),
                    boxShadow: AppTheme.softShadow,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Container(
                        padding: const EdgeInsets.fromLTRB(14, 10, 14, 8),
                        decoration: BoxDecoration(
                          color: isToday
                              ? AppTheme.primary.withValues(alpha: 0.08)
                              : const Color(0xFFF8FAFC),
                          borderRadius: const BorderRadius.vertical(
                            top: Radius.circular(16),
                          ),
                        ),
                        child: Text(
                          [
                            if (wd.isNotEmpty) wd,
                            Fmt.dmy(date),
                            if (isToday) 'اليوم',
                          ].join(' · '),
                          style: TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 13.5,
                            color: isToday
                                ? AppTheme.primary
                                : AppTheme.textMain,
                          ),
                        ),
                      ),
                      ...customers.asMap().entries.map((e) {
                        final i = e.key + 1;
                        final c = e.value;
                        final st = VisitStatus.effective(
                          status: Fmt.str(c['status']),
                          checkinAt: Fmt.str(c['visit_checkin_at']),
                          checkoutAt: Fmt.str(c['visit_checkout_at']),
                          referenceDate: date,
                        );
                        return _PlanCustomerTile(
                          index: i,
                          name: Fmt.str(c['name']),
                          code: Fmt.str(c['code']),
                          status: st,
                          statusLabel: _statusLabel(st),
                          statusColor: _statusColor(st),
                          statusIcon: _statusIcon(st),
                          hasGps: c['has_gps'] == true,
                          onTap: () => _openAgendaCustomer(date, c),
                        );
                      }),
                    ],
                  ),
                ),
              );
            }),
        ],
      ),
    );
  }

  Widget _buildHome() {
    final open = _openVisits.where((v) => v['in_plan'] == true).toList();
    final planned = _plannedVisits;
    return RefreshIndicator(
      onRefresh: () => _load(),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 36),
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: AppTheme.border),
              boxShadow: AppTheme.softShadow,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  _weekdayLabel.isEmpty
                      ? 'خطة الجولة من مدير المبيعات'
                      : '$_weekdayLabel · خطة الجولة',
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 15.5,
                    color: AppTheme.textMain,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'عملاء هذه الشاشة من ضمن الجولة فقط. اختر عميلاً ثم سجّل الدخول أو الخروج.',
                  style: TextStyle(color: AppTheme.textSoft, fontSize: 12.5, height: 1.45),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _dateCtrl,
                  readOnly: true,
                  onTap: _pickDate,
                  decoration: const InputDecoration(
                    labelText: 'تاريخ الجولة',
                    suffixIcon: Icon(Icons.calendar_month_rounded),
                    isDense: true,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'نصف القطر المسموح حول العميل: $_radiusM م',
                  style: TextStyle(
                    fontSize: 12,
                    color: AppTheme.textSoft.withValues(alpha: 0.9),
                  ),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: _busy ? null : _loadMonth,
                  icon: const Icon(Icons.calendar_view_month_rounded, size: 18),
                  label: const Text('عرض أجندة الشهر'),
                ),
              ],
            ),
          ),
          if (open.isNotEmpty) ...[
            const SizedBox(height: 16),
            const Text(
              'زيارات مفتوحة',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5),
            ),
            const SizedBox(height: 8),
            ...open.where((v) => v['in_plan'] == true).map(
              (v) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: _OpenVisitTile(
                  name: Fmt.str(v['name']),
                  code: Fmt.str(v['code']),
                  status: _statusLabel(_statusOf(v)),
                  color: _statusColor(_statusOf(v)),
                  checkinAt: Fmt.str(v['visit_checkin_at']),
                  onTap: _busy ? null : () => _selectTourCustomer(v),
                ),
              ),
            ),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              const Expanded(
                child: Text(
                  'عملاء الجولة',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5),
                ),
              ),
              Text(
                '${planned.length}',
                style: const TextStyle(color: AppTheme.textSoft, fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (planned.isEmpty)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppTheme.border),
              ),
              child: const Text(
                'لا توجد جولة مخططة لهذا اليوم من مدير المبيعات.',
                style: TextStyle(color: AppTheme.textSoft, height: 1.4),
              ),
            )
          else
            Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: AppTheme.border),
                boxShadow: AppTheme.softShadow,
              ),
              child: Column(
                children: [
                  for (var i = 0; i < planned.length; i++) ...[
                    if (i > 0) const Divider(height: 1),
                    _PlanCustomerTile(
                      index: i + 1,
                      name: Fmt.str(planned[i]['name']),
                      code: Fmt.str(planned[i]['code']),
                      status: _statusOf(planned[i]),
                      statusLabel: _statusLabel(_statusOf(planned[i])),
                      statusColor: _statusColor(_statusOf(planned[i])),
                      statusIcon: _statusIcon(_statusOf(planned[i])),
                      hasGps: planned[i]['has_gps'] == true,
                      onTap: _busy
                          ? null
                          : () => _selectTourCustomer(planned[i]),
                    ),
                  ],
                ],
              ),
            ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            height: 48,
            child: TextButton.icon(
              onPressed: _busy ? null : () => context.push('/rep/visit-report'),
              icon: const Icon(Icons.assignment_rounded),
              label: const Text('تقرير الزيارات'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildVisit({bool tablet = false}) {
    final v = _selected!;
    if (v['in_plan'] != true) {
      return _tabletEmptyHint('هذا العميل ليس ضمن الجولة.');
    }
    final status = _statusOf(v);
    final color = _statusColor(status);
    final canIn = status == 'idle' || status == 'checked_out' || status.isEmpty;
    final canOut = status == 'checked_in';
    final pending = status == 'pending_manual_checkout';
    final gap = tablet ? 12.0 : 28.0;

    Widget actions(List<Widget> buttons) {
      if (tablet && buttons.length >= 2) {
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final b in buttons)
              SizedBox(
                width: 280,
                child: b,
              ),
          ],
        );
      }
      return Column(
        children: [
          for (var i = 0; i < buttons.length; i++) ...[
            if (i > 0) const SizedBox(height: 10),
            buttons[i],
          ],
        ],
      );
    }

    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(tablet ? 8 : 20, tablet ? 4 : 12, tablet ? 8 : 20, 24),
      child: Column(
        children: [
          _CustomerChip(
            name: Fmt.str(v['name']),
            code: Fmt.str(v['code']),
            badge: 'ضمن الجولة',
            onChange: tablet
                ? null
                : (_busy
                    ? null
                    : () => setState(() => _selected = null)),
            onClear: tablet
                ? null
                : (_busy ? null : () => setState(() => _selected = null)),
          ),
          if (tablet) ...[
            const SizedBox(height: 10),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: _busy
                    ? null
                    : () => _openCustomerHub(Fmt.toInt(v['customer_id'])),
                icon: const Icon(Icons.badge_outlined, size: 18),
                label: const Text('حساب العميل'),
              ),
            ),
          ],
          SizedBox(height: gap),
          _StatusHero(
            icon: _statusIcon(status),
            color: color,
            label: _statusLabel(status),
            subtitle: _statusSubtitle(v, status),
            compact: tablet,
          ),
          SizedBox(height: gap),
          if (canIn)
            _ActionCard(
              title: 'تسجيل الدخول',
              child: actions([
                _BigActionButton(
                  label: 'دخول GPS',
                  hint: 'الافتراضي · داخل $_radiusM م',
                  icon: Icons.my_location_rounded,
                  color: AppTheme.primary,
                  onPressed: _busy ? null : () => _checkin(manual: false),
                ),
                _BigActionButton(
                  label: 'دخول يدوي',
                  hint: 'بدون شرط الموقع',
                  icon: Icons.edit_location_alt_rounded,
                  color: AppTheme.teal,
                  outlined: true,
                  onPressed: _busy ? null : () => _checkin(manual: true),
                ),
              ]),
            ),
          if (canOut)
            _ActionCard(
              title: 'الطلب والخروج',
              child: actions([
                _BigActionButton(
                    label: 'عمل طلب شراء',
                    hint: 'إنشاء طلبية أثناء الزيارة',
                    icon: Icons.shopping_cart_checkout_rounded,
                    color: AppTheme.primary,
                    onPressed: _busy
                        ? null
                        : () {
                            final lineId = Fmt.toInt(v['route_line_id']);
                            context
                                .push(
                                  '/customer-orders/new?customer_id=${Fmt.toInt(v['customer_id'])}'
                                  '&customer_name=${Uri.encodeComponent(Fmt.str(v['name']))}'
                                  '&customer_code=${Uri.encodeComponent(Fmt.str(v['code']))}'
                                  '&visit_route_line_id=$lineId',
                                )
                                .then((_) {
                              if (mounted) {
                                _load(
                                  keepCustomerId:
                                      Fmt.toInt(v['customer_id']),
                                );
                              }
                            });
                          },
                  ),
                  if (!VisitStatus.isManualMethod(v['checkin_method']))
                    _BigActionButton(
                      label: 'خروج GPS',
                      hint: 'من موقع العميل',
                      icon: Icons.logout_rounded,
                      color: AppTheme.teal,
                      onPressed: _busy ? null : () => _checkout(manual: false),
                    ),
                  _BigActionButton(
                    label: 'خروج يدوي',
                    hint: VisitStatus.isManualMethod(v['checkin_method'])
                        ? 'نفس طريقة الدخول اليدوي'
                        : 'يحتاج موافقة إذا كان الدخول GPS',
                    icon: Icons.output_rounded,
                    color: AppTheme.warn,
                    outlined: true,
                    onPressed: _busy ? null : () => _checkout(manual: true),
                  ),
              ]),
            ),
          if (pending)
            const _ActionCard(
              title: 'بانتظار الموافقة',
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    Icon(Icons.notifications_active_rounded, color: AppTheme.warn),
                    SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'تم إرسال إشعار للمسؤول على الويندوز لاعتماد الخروج اليدوي.',
                        style: TextStyle(height: 1.45, fontSize: 14),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          if (Fmt.str(v['checkin_method']).isNotEmpty ||
              Fmt.str(v['checkout_method']).isNotEmpty) ...[
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: [
                if (Fmt.str(v['checkin_method']).isNotEmpty)
                  _MetaChip(
                    icon: Icons.login_rounded,
                    text: 'دخول ${Fmt.str(v['checkin_method'])}',
                  ),
                if (Fmt.str(v['checkout_method']).isNotEmpty)
                  _MetaChip(
                    icon: Icons.logout_rounded,
                    text: 'خروج ${Fmt.str(v['checkout_method'])}',
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  String _statusSubtitle(Map<String, dynamic> v, String status) {
    if (status == 'checked_in') {
      final at = Fmt.str(v['visit_checkin_at']);
      return at.isEmpty ? 'الزيارة مفتوحة الآن' : 'دخول: $at';
    }
    if (status == 'checked_out') {
      final at = Fmt.str(v['visit_checkout_at']);
      return at.isEmpty ? 'انتهت الزيارة' : 'خروج: $at';
    }
    if (status == 'pending_manual_checkout') {
      return 'لن تُغلق الزيارة قبل اعتماد المدير';
    }
    return 'اضغط دخول GPS وأنت عند العميل';
  }
}

class _PlanCustomerTile extends StatelessWidget {
  const _PlanCustomerTile({
    required this.index,
    required this.name,
    required this.code,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.statusIcon,
    required this.hasGps,
    this.onTap,
    this.selected = false,
  });

  final int index;
  final String name;
  final String code;
  final String status;
  final String statusLabel;
  final Color statusColor;
  final IconData statusIcon;
  final bool hasGps;
  final VoidCallback? onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final inVisit =
        status == 'checked_in' || status == 'pending_manual_checkout';
    final bg = inVisit
        ? AppTheme.success.withValues(alpha: selected ? 0.32 : 0.22)
        : (selected
            ? AppTheme.primary.withValues(alpha: 0.12)
            : (status == 'checked_out'
                ? AppTheme.danger.withValues(alpha: 0.12)
                : null));
    return Material(
      color: bg ?? Colors.transparent,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        decoration: inVisit
            ? BoxDecoration(
                border: Border.all(
                  color: AppTheme.success.withValues(alpha: 0.55),
                ),
                borderRadius: BorderRadius.circular(12),
              )
            : null,
        child: Semantics(
          label: '$index $name',
          child: ListTile(
          onTap: onTap,
          dense: true,
          visualDensity: VisualDensity.compact,
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
          leading: CircleAvatar(
            radius: 16,
            backgroundColor: statusColor.withValues(alpha: 0.12),
            child: Icon(
              inVisit
                  ? Icons.login_rounded
                  : (status == 'checked_out'
                      ? Icons.logout_rounded
                      : Icons.storefront_rounded),
              size: 16,
              color: statusColor,
            ),
          ),
          title: Text(
            name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 13.5,
              color: inVisit ? const Color(0xFF0B6B3A) : AppTheme.textMain,
            ),
          ),
          subtitle: Text(
            [
              if (code.isNotEmpty) code,
              statusLabel,
              hasGps ? 'GPS' : 'بدون موقع',
            ].join(' · '),
            style: const TextStyle(color: AppTheme.textSoft, fontSize: 11.5),
          ),
          trailing: inVisit
              ? const StatusPill(text: 'مفتوحة', color: AppTheme.success)
              : (status == 'checked_out'
                  ? const StatusPill(text: 'منتهية', color: AppTheme.danger)
                  : Icon(statusIcon, color: statusColor, size: 18)),
          ),
        ),
      ),
    );
  }
}

class _OpenVisitTile extends StatelessWidget {
  const _OpenVisitTile({
    required this.name,
    required this.code,
    required this.status,
    required this.color,
    required this.checkinAt,
    this.onTap,
  });

  final String name;
  final String code;
  final String status;
  final Color color;
  final String checkinAt;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: color.withValues(alpha: 0.35)),
            boxShadow: AppTheme.softShadow,
          ),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(Icons.login_rounded, color: color),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                    ),
                    Text(
                      [
                        if (code.isNotEmpty) code,
                        status,
                        if (checkinAt.isNotEmpty) checkinAt,
                      ].join(' · '),
                      style: const TextStyle(color: AppTheme.textSoft, fontSize: 12),
                    ),
                  ],
                ),
              ),
              Icon(Icons.chevron_left_rounded, color: color),
            ],
          ),
        ),
      ),
    );
  }
}

class _CustomerChip extends StatelessWidget {
  const _CustomerChip({
    required this.name,
    required this.code,
    this.badge,
    this.onChange,
    this.onClear,
  });

  final String name;
  final String code;
  final String? badge;
  final VoidCallback? onChange;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppTheme.border),
        boxShadow: AppTheme.softShadow,
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              gradient: AppTheme.brandGradient,
            ),
            child: const Icon(Icons.business_rounded, color: Colors.white, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5),
                ),
                Text(
                  [
                    if (code.isNotEmpty) code,
                    if (badge != null && badge!.isNotEmpty) badge!,
                  ].join(' · '),
                  style: const TextStyle(color: AppTheme.textSoft, fontSize: 12.5),
                ),
              ],
            ),
          ),
          TextButton(onPressed: onChange, child: const Text('تغيير')),
          IconButton(
            tooltip: 'مسح',
            onPressed: onClear,
            icon: const Icon(Icons.close_rounded, size: 20),
            color: AppTheme.textSoft,
          ),
        ],
      ),
    );
  }
}

class _StatusHero extends StatelessWidget {
  const _StatusHero({
    required this.icon,
    required this.color,
    required this.label,
    required this.subtitle,
    this.compact = false,
  });

  final IconData icon;
  final Color color;
  final String label;
  final String subtitle;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: compact ? 108 : 148,
          height: compact ? 108 : 148,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: Colors.white,
            boxShadow: [
              BoxShadow(
                color: color.withValues(alpha: 0.22),
                blurRadius: 28,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: Center(
            child: Container(
              width: compact ? 86 : 118,
              height: compact ? 86 : 118,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    color.withValues(alpha: 0.18),
                    color.withValues(alpha: 0.05),
                  ],
                ),
                border: Border.all(color: color.withValues(alpha: 0.35), width: 2),
              ),
              child: Icon(icon, size: compact ? 40 : 56, color: color),
            ),
          ),
        ),
        const SizedBox(height: 18),
        Text(
          label,
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 13.5, height: 1.4, color: AppTheme.textSoft),
        ),
      ],
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppTheme.border),
        boxShadow: AppTheme.softShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 14,
              color: AppTheme.textSoft,
            ),
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}

class _BigActionButton extends StatelessWidget {
  const _BigActionButton({
    required this.label,
    required this.hint,
    required this.icon,
    required this.color,
    this.outlined = false,
    this.onPressed,
  });

  final String label;
  final String hint;
  final IconData icon;
  final Color color;
  final bool outlined;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(16);
    final content = Row(
      children: [
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: (outlined ? color : Colors.white).withValues(alpha: outlined ? 0.1 : 0.18),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icon, color: outlined ? color : Colors.white, size: 22),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: TextStyle(
                  color: outlined ? color : Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 15.5,
                ),
              ),
              Text(
                hint,
                style: TextStyle(
                  color: (outlined ? color : Colors.white).withValues(alpha: 0.78),
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
      ],
    );

    if (outlined) {
      return OutlinedButton(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          foregroundColor: color,
          side: BorderSide(color: color.withValues(alpha: 0.45)),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: radius),
        ),
        child: content,
      );
    }
    return FilledButton(
      onPressed: onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: color,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: radius),
      ),
      child: content,
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: AppTheme.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: AppTheme.textSoft),
          const SizedBox(width: 6),
          Text(text, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _CustomerPickSheet extends StatefulWidget {
  const _CustomerPickSheet({
    required this.visits,
    required this.routeDate,
  });

  final List<Map<String, dynamic>> visits;
  final String routeDate;

  @override
  State<_CustomerPickSheet> createState() => _CustomerPickSheetState();
}

class _CustomerPickSheetState extends State<_CustomerPickSheet> {
  final _q = TextEditingController();

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final q = _q.text.trim().toLowerCase();
    final items = widget.visits.where((v) {
      if (q.isEmpty) return true;
      final name = Fmt.str(v['name']).toLowerCase();
      final code = Fmt.str(v['code']).toLowerCase();
      return name.contains(q) || code.contains(q);
    }).toList();

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.72,
      minChildSize: 0.45,
      maxChildSize: 0.92,
      builder: (ctx, scroll) {
        return Container(
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
          ),
          child: Column(
            children: [
              const SizedBox(height: 10),
              Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: AppTheme.border,
                  borderRadius: BorderRadius.circular(99),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 14, 18, 8),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'اختيار العميل',
                        style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
                      ),
                    ),
                    Text('${items.length}', style: const TextStyle(color: AppTheme.textSoft)),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                child: TextField(
                  controller: _q,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    hintText: 'بحث بالاسم أو الرمز…',
                    prefixIcon: const Icon(Icons.search_rounded),
                    filled: true,
                    fillColor: AppTheme.surfaceAlt,
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide: BorderSide.none,
                    ),
                  ),
                ),
              ),
              Expanded(
                child: ListView.separated(
                  controller: scroll,
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 6),
                  itemBuilder: (_, i) {
                    final v = items[i];
                    final status = VisitStatus.effective(
                      status: Fmt.str(v['status']),
                      checkinAt: Fmt.str(v['visit_checkin_at']),
                      checkoutAt: Fmt.str(v['visit_checkout_at']),
                      referenceDate: widget.routeDate,
                    );
                    final color = switch (status) {
                      'checked_in' => AppTheme.success,
                      'checked_out' => AppTheme.danger,
                      'pending_manual_checkout' => AppTheme.success,
                      _ => AppTheme.primary,
                    };
                    return Material(
                      color: AppTheme.surfaceAlt,
                      borderRadius: BorderRadius.circular(14),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: () => Navigator.pop(ctx, v),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                          child: Row(
                            children: [
                              CircleAvatar(
                                backgroundColor: color.withValues(alpha: 0.12),
                                child: Icon(
                                  status == 'checked_in'
                                      ? Icons.login_rounded
                                      : Icons.storefront_rounded,
                                  color: color,
                                  size: 20,
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      Fmt.str(v['name']),
                                      style: const TextStyle(fontWeight: FontWeight.w700),
                                    ),
                                    Text(
                                      [
                                        Fmt.str(v['code']),
                                        if (status == 'checked_in') 'مفتوحة',
                                        if (status == 'pending_manual_checkout') 'بانتظار موافقة',
                                      ].where((s) => s.isNotEmpty).join(' · '),
                                      style: TextStyle(
                                        color: status == 'checked_in' || status == 'pending_manual_checkout'
                                            ? color
                                            : AppTheme.textSoft,
                                        fontSize: 12.5,
                                        fontWeight: status == 'checked_in' ? FontWeight.w700 : FontWeight.w400,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const Icon(Icons.chevron_left_rounded, color: AppTheme.textSoft),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
