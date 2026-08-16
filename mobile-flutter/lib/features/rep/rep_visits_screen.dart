import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';

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

  /// أجندة الشهر: كل يوم + عملاء الجولة المرحّلة
  String _monthYm = '';
  List<Map<String, dynamic>> _agendaDays = [];
  bool _monthMode = true;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _routeDate = Fmt.todayIso();
    _dateCtrl.text = _routeDate;
    _monthYm =
        '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}';
    _loadMonth();
  }

  @override
  void dispose() {
    _dateCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadMonth() async {
    setState(() {
      _loading = true;
      _error = null;
      _monthMode = true;
      _selected = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitListPath,
            query: {'mode': 'month', 'month': _monthYm},
          );
      if (!mounted) return;
      final days = (res['days'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _agendaDays = days;
        _monthYm = Fmt.str(res['month']).isEmpty ? _monthYm : Fmt.str(res['month']);
        final r = Fmt.toInt(res['visit_radius_m']);
        if (r > 0) _radiusM = r;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
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
      if (mounted) _loadMonth();
    });
  }

  Future<void> _load({int? keepCustomerId}) async {
    final keepId = keepCustomerId ?? Fmt.toInt(_selected?['customer_id']);
    setState(() {
      _loading = true;
      _error = null;
      _monthMode = false;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitListPath,
            query: {'date': _routeDate},
          );
      if (!mounted) return;
      final visits = (res['visits'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      final noOrderReasons = (res['no_order_reasons'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      visits.sort((a, b) {
        final ap = a['in_plan'] == true ? 0 : 1;
        final bp = b['in_plan'] == true ? 0 : 1;
        if (ap != bp) return ap - bp;
        return Fmt.toInt(a['sort_order']).compareTo(Fmt.toInt(b['sort_order']));
      });
      Map<String, dynamic>? selected;
      if (keepId > 0) {
        for (final v in visits) {
          if (Fmt.toInt(v['customer_id']) == keepId) {
            selected = v;
            break;
          }
        }
      }
      if (selected == null) {
        final open = visits.where((v) {
          final s = Fmt.str(v['status']);
          return s == 'checked_in' || s == 'pending_manual_checkout';
        }).toList();
        if (open.length == 1) {
          selected = open.first;
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
        if (_radiusM < 1) _radiusM = 200;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
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

  List<Map<String, dynamic>> get _plannedVisits =>
      _visits.where((v) => v['in_plan'] == true).toList();

  List<Map<String, dynamic>> get _extraOpenOrDone => _visits.where((v) {
        if (v['in_plan'] == true) return false;
        final s = Fmt.str(v['status']);
        return s == 'checked_in' ||
            s == 'pending_manual_checkout' ||
            s == 'checked_out';
      }).toList();

  String _statusOf(Map<String, dynamic>? v) => Fmt.str(v?['status']);

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
        builder: (ctx) => _CustomerPickSheet(visits: list),
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
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Text(body, style: const TextStyle(height: 1.45)),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
    return ok == true;
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
      final res = await api.postJson(
        AppConfig.repVisitCheckinPath,
        body: {
          'customer_id': v['customer_id'],
          'method': manual ? 'MANUAL' : 'GPS',
          ...gps,
        },
        csrf: csrf,
      );
      if (!mounted) return;
      showSnack(
        context,
        Fmt.str(res['message']).isEmpty ? 'تم تسجيل الدخول. الزيارة مفتوحة.' : Fmt.str(res['message']),
      );
      await _load(keepCustomerId: Fmt.toInt(v['customer_id']));
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<List<int>?> _pickNoOrderReasons() async {
    if (_noOrderReasons.isEmpty) {
      showSnack(
        context,
        'لا توجد أسباب «عدم طلب العميل» مضافة في إعدادات النظام.',
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
    final v = _selected;
    if (v == null || _busy) return;
    final name = Fmt.str(v['name']);
    List<int> noOrderReasonIds = [];
    if (v['has_order'] != true) {
      final picked = await _pickNoOrderReasons();
      if (picked == null || picked.isEmpty) return;
      noOrderReasonIds = picked;
    }
    final ok = await _confirm(
      'تأكيد تسجيل الخروج',
      manual
          ? 'تأكيد الخروج اليدوي من عند «$name»؟'
          : 'تأكيد الخروج بـ GPS من عند «$name»؟',
    );
    if (!ok || !mounted) return;
    final api = context.read<ApiClient>();
    final csrf = context.read<SessionController>().csrf;
    String? reason;
    if (manual) {
      reason = await showDialog<String>(
        context: context,
        builder: (ctx) {
          final c = TextEditingController(text: 'نسي الخروج بـ GPS من موقع العميل');
          return AlertDialog(
            title: const Text('خروج يدوي'),
            content: TextField(
              controller: c,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'السبب',
                hintText: 'لماذا الخروج يدوياً؟',
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, c.text.trim()),
                child: const Text('متابعة'),
              ),
            ],
          );
        },
      );
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
      final res = await api.postJson(
        AppConfig.repVisitCheckoutPath,
        body: {
          'customer_id': v['customer_id'],
          'method': manual ? 'MANUAL' : 'GPS',
          'no_order_reason_ids': noOrderReasonIds,
          if (reason != null && reason.isNotEmpty) 'reason': reason,
          ...gps,
        },
        csrf: csrf,
      );
      if (!mounted) return;
      final msg = Fmt.str(res['message']);
      final needsApproval = res['requires_approval'] == true;
      showSnack(
        context,
        msg.isEmpty
            ? (needsApproval ? 'بانتظار موافقة المدير' : 'تم تسجيل الخروج وإغلاق الزيارة')
            : msg,
      );
      await _load(keepCustomerId: needsApproval ? Fmt.toInt(v['customer_id']) : 0);
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
      title: Text(_monthMode ? 'جولة الشهر' : 'جولات المندوبين'),
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
            if (_selected == null)
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
                      : '$totalCustomers زيارة مخططة · اضغط العميل للدخول وتبويبات الحساب',
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
                      label: const Text('زيارات اليوم'),
                    ),
                    OutlinedButton.icon(
                      onPressed: _busy
                          ? null
                          : () => context.push('/customers').then((_) {
                                if (mounted) _loadMonth();
                              }),
                      icon: const Icon(Icons.person_search_rounded, size: 18),
                      label: const Text('زيارة خارج الجولة'),
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
                  'انتظر ترحيل الجولة من الإدارة، أو اختر زيارة خارج الجولة.',
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
                        final st = Fmt.str(c['status']);
                        return _PlanCustomerTile(
                          index: i,
                          name: Fmt.str(c['name']),
                          code: Fmt.str(c['code']),
                          status: st,
                          statusLabel: _statusLabel(st),
                          statusColor: _statusColor(st),
                          statusIcon: _statusIcon(st),
                          hasGps: c['has_gps'] == true,
                          onTap: () =>
                              _openCustomerHub(Fmt.toInt(c['customer_id'])),
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
    final open = _openVisits;
    final planned = _plannedVisits;
    final extras = _extraOpenOrDone;
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
                  'سجّل الدخول/الخروج GPS أو يدوياً حسب الشروط المعتادة. يمكنك أيضاً زيارة عميل خارج الخطة.',
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
            ...open.map(
              (v) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: _OpenVisitTile(
                  name: Fmt.str(v['name']),
                  code: Fmt.str(v['code']),
                  status: _statusLabel(_statusOf(v)),
                  color: _statusColor(_statusOf(v)),
                  checkinAt: Fmt.str(v['visit_checkin_at']),
                  onTap: _busy
                      ? null
                      : () => _openCustomerHub(Fmt.toInt(v['customer_id'])),
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
                          : () => _openCustomerHub(
                                Fmt.toInt(planned[i]['customer_id']),
                              ),
                    ),
                  ],
                ],
              ),
            ),
          if (extras.isNotEmpty) ...[
            const SizedBox(height: 16),
            const Text(
              'زيارات خارج الجولة',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5),
            ),
            const SizedBox(height: 8),
            ...extras.map(
              (v) => Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: _OpenVisitTile(
                  name: Fmt.str(v['name']),
                  code: Fmt.str(v['code']),
                  status: _statusLabel(_statusOf(v)),
                  color: _statusColor(_statusOf(v)),
                  checkinAt: Fmt.str(v['visit_checkin_at']),
                  onTap: _busy
                      ? null
                      : () => _openCustomerHub(Fmt.toInt(v['customer_id'])),
                ),
              ),
            ),
          ],
          const SizedBox(height: 18),
          SizedBox(
            width: double.infinity,
            height: 52,
            child: OutlinedButton.icon(
              onPressed: _busy ? null : () => _pickCustomer(outsidePlanOnly: true),
              icon: const Icon(Icons.person_add_alt_1_rounded),
              label: const Text(
                'زيارة عميل خارج الجولة',
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
              style: OutlinedButton.styleFrom(
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                ),
              ),
            ),
          ),
          const SizedBox(height: 10),
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

  Widget _buildVisit() {
    final v = _selected!;
    final status = _statusOf(v);
    final color = _statusColor(status);
    final canIn = status == 'idle' || status == 'checked_out' || status.isEmpty;
    final canOut = status == 'checked_in';
    final pending = status == 'pending_manual_checkout';

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      child: Column(
        children: [
          _CustomerChip(
            name: Fmt.str(v['name']),
            code: Fmt.str(v['code']),
            badge: v['in_plan'] == true ? 'ضمن الجولة' : 'خارج الجولة',
            onChange: _busy
                ? null
                : () {
                    setState(() => _selected = null);
                    _pickCustomer();
                  },
            onClear: _busy ? null : () => setState(() => _selected = null),
          ),
          const SizedBox(height: 28),
          _StatusHero(
            icon: _statusIcon(status),
            color: color,
            label: _statusLabel(status),
            subtitle: _statusSubtitle(v, status),
          ),
          const SizedBox(height: 28),
          if (canIn)
            _ActionCard(
              title: 'تسجيل الدخول',
              child: Column(
                children: [
                  _BigActionButton(
                    label: 'دخول GPS',
                    hint: 'الافتراضي · داخل $_radiusM م',
                    icon: Icons.my_location_rounded,
                    color: AppTheme.primary,
                    onPressed: _busy ? null : () => _checkin(manual: false),
                  ),
                  const SizedBox(height: 10),
                  _BigActionButton(
                    label: 'دخول يدوي',
                    hint: 'بدون شرط الموقع',
                    icon: Icons.edit_location_alt_rounded,
                    color: AppTheme.teal,
                    outlined: true,
                    onPressed: _busy ? null : () => _checkin(manual: true),
                  ),
                ],
              ),
            ),
          if (canOut)
            _ActionCard(
              title: 'الطلب والخروج',
              child: Column(
                children: [
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
                  const SizedBox(height: 10),
                  _BigActionButton(
                    label: 'خروج GPS',
                    hint: 'من موقع العميل',
                    icon: Icons.logout_rounded,
                    color: AppTheme.teal,
                    onPressed: _busy ? null : () => _checkout(manual: false),
                  ),
                  const SizedBox(height: 10),
                  _BigActionButton(
                    label: 'خروج يدوي',
                    hint: 'يحتاج موافقة إذا كان الدخول GPS',
                    icon: Icons.output_rounded,
                    color: AppTheme.warn,
                    outlined: true,
                    onPressed: _busy ? null : () => _checkout(manual: true),
                  ),
                ],
              ),
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

  @override
  Widget build(BuildContext context) {
    final bg = (status == 'checked_in' || status == 'pending_manual_checkout')
        ? AppTheme.success.withValues(alpha: 0.12)
        : (status == 'checked_out'
            ? AppTheme.danger.withValues(alpha: 0.12)
            : null);
    return Material(
      color: bg ?? Colors.transparent,
      borderRadius: BorderRadius.circular(12),
      child: ListTile(
      onTap: onTap,
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
      leading: CircleAvatar(
        radius: 18,
        backgroundColor: statusColor.withValues(alpha: 0.12),
        child: Text(
          '$index',
          style: TextStyle(
            color: statusColor,
            fontWeight: FontWeight.w800,
            fontSize: 12,
          ),
        ),
      ),
      title: Text(
        name,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5),
      ),
      subtitle: Text(
        [
          if (code.isNotEmpty) code,
          statusLabel,
          hasGps ? 'GPS' : 'بدون موقع',
        ].join(' · '),
        style: const TextStyle(color: AppTheme.textSoft, fontSize: 12),
      ),
      trailing: Icon(statusIcon, color: statusColor, size: 22),
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
  });

  final IconData icon;
  final Color color;
  final String label;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: 148,
          height: 148,
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
              width: 118,
              height: 118,
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
              child: Icon(icon, size: 56, color: color),
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
  const _CustomerPickSheet({required this.visits});

  final List<Map<String, dynamic>> visits;

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
                    final status = Fmt.str(v['status']);
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
