import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../core/visit_status.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

/// اطلاع مدير المبيعات على جولة أي مندوب لأي تاريخ (قراءة فقط).
class RepToursManageScreen extends StatefulWidget {
  const RepToursManageScreen({super.key});

  @override
  State<RepToursManageScreen> createState() => _RepToursManageScreenState();
}

class _RepToursManageScreenState extends State<RepToursManageScreen> {
  bool _loading = true;
  bool _loadingVisits = false;
  String? _error;
  List<Map<String, dynamic>> _reps = [];
  List<Map<String, dynamic>> _visits = [];
  int _repId = 0;
  String _routeDate = '';
  String _weekdayLabel = '';
  int _plannedCount = 0;
  int _doneCount = 0;
  int _openCount = 0;

  @override
  void initState() {
    super.initState();
    _routeDate = Fmt.todayIso();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repToursManagePath,
          );
      if (!mounted) return;
      final reps = (res['reps'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _reps = reps;
        _loading = false;
        if (_repId < 1 && reps.isNotEmpty) {
          _repId = Fmt.toInt(reps.first['id']);
        }
      });
      if (_repId > 0) {
        await _loadVisits();
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _loadVisits() async {
    if (_repId < 1) return;
    setState(() {
      _loadingVisits = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repToursManagePath,
            query: {
              'sales_rep_id': '$_repId',
              'date': _routeDate,
            },
          );
      if (!mounted) return;
      setState(() {
        _visits = (res['visits'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _routeDate = Fmt.str(res['route_date']).isEmpty
            ? _routeDate
            : Fmt.str(res['route_date']);
        _weekdayLabel = Fmt.str(res['weekday_label']);
        _plannedCount = Fmt.toInt(res['planned_count']);
        _doneCount = Fmt.toInt(res['done_count']);
        _openCount = Fmt.toInt(res['open_count']);
        _loadingVisits = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loadingVisits = false;
      });
    }
  }

  Future<void> _pickDate() async {
    final initial = DateTime.tryParse(_routeDate) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(initial.year - 2),
      lastDate: DateTime(initial.year + 1),
    );
    if (picked == null) return;
    setState(() {
      _routeDate =
          '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
    });
    await _loadVisits();
  }

  String _statusOf(Map<String, dynamic> v) {
    return VisitStatus.effective(
      status: Fmt.str(v['status']),
      checkinAt: Fmt.str(v['visit_checkin_at']),
      checkoutAt: Fmt.str(v['visit_checkout_at']),
      referenceDate: _routeDate,
    );
  }

  String _statusLabel(String s) {
    switch (s) {
      case 'checked_in':
        return 'داخل الزيارة';
      case 'checked_out':
        return 'تم الخروج';
      case 'pending_manual_checkout':
        return 'بانتظار موافقة';
      default:
        return 'لم تُزر';
    }
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'checked_in':
        return AppTheme.teal;
      case 'checked_out':
        return AppTheme.success;
      case 'pending_manual_checkout':
        return AppTheme.amber;
      default:
        return AppTheme.danger;
    }
  }

  bool _inPlan(Map<String, dynamic> v) {
    final p = v['in_plan'];
    return p == true || p == 1 || p == '1';
  }

  List<Map<String, dynamic>> get _planned {
    final list = _visits.where(_inPlan).toList();
    list.sort((a, b) {
      final sa = _statusOf(a);
      final sb = _statusOf(b);
      int rank(String s) =>
          (s == 'checked_in' || s == 'pending_manual_checkout') ? 0 : 1;
      final c = rank(sa).compareTo(rank(sb));
      if (c != 0) return c;
      return Fmt.toInt(a['sort_order']).compareTo(Fmt.toInt(b['sort_order']));
    });
    return list;
  }

  List<Map<String, dynamic>> get _extra {
    return _visits.where((v) {
      if (_inPlan(v)) return false;
      final s = _statusOf(v);
      return s == 'checked_in' ||
          s == 'checked_out' ||
          s == 'pending_manual_checkout';
    }).toList();
  }

  String _repLabel(Map<String, dynamic> r) {
    final name = Fmt.str(r['name_ar']);
    final code = Fmt.str(r['code']);
    if (code.isEmpty) return name;
    return '$name ($code)';
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('إدارة جولات المندوبين'),
      backgroundColor: const Color(0xFFF0F4F8),
      actions: [
        IconButton(
          tooltip: 'تحديث',
          onPressed: _loading || _loadingVisits
              ? null
              : () async {
                  if (_repId > 0) {
                    await _loadVisits();
                  } else {
                    await _bootstrap();
                  }
                },
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _bootstrap,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
              child: Column(
                children: [
                  DropdownButtonFormField<int>(
                    value: _repId > 0 ? _repId : null,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'المندوب',
                      border: OutlineInputBorder(),
                      filled: true,
                      fillColor: Colors.white,
                      contentPadding:
                          EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    ),
                    items: [
                      for (final r in _reps)
                        DropdownMenuItem(
                          value: Fmt.toInt(r['id']),
                          child: Text(
                            _repLabel(r),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                    ],
                    onChanged: _loadingVisits
                        ? null
                        : (v) async {
                            if (v == null || v == _repId) return;
                            setState(() => _repId = v);
                            await _loadVisits();
                          },
                  ),
                  const SizedBox(height: 10),
                  Material(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(12),
                      onTap: _loadingVisits ? null : _pickDate,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 12,
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.calendar_month_rounded,
                                color: AppTheme.primary),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    Fmt.dmy(_routeDate),
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                      fontSize: 15,
                                    ),
                                  ),
                                  if (_weekdayLabel.isNotEmpty)
                                    Text(
                                      _weekdayLabel,
                                      style: const TextStyle(
                                        color: AppTheme.textSoft,
                                        fontSize: 12.5,
                                      ),
                                    ),
                                ],
                              ),
                            ),
                            const Text(
                              'تغيير',
                              style: TextStyle(
                                color: AppTheme.primary,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  if (_repId > 0) ...[
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Expanded(
                          child: _StatChip(
                            label: 'في الخطة',
                            value: '$_plannedCount',
                            color: AppTheme.primary,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: _StatChip(
                            label: 'مفتوحة',
                            value: '$_openCount',
                            color: AppTheme.teal,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: _StatChip(
                            label: 'مكتملة',
                            value: '$_doneCount',
                            color: AppTheme.success,
                          ),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
            Expanded(
              child: _repId < 1
                  ? const Center(
                      child: Text(
                        'اختر مندوباً لعرض جولته.',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: AppTheme.textSoft,
                        ),
                      ),
                    )
                  : _loadingVisits
                      ? const Center(child: CircularProgressIndicator())
                      : RefreshIndicator(
                          onRefresh: _loadVisits,
                          child: _planned.isEmpty && _extra.isEmpty
                              ? ListView(
                                  children: const [
                                    SizedBox(height: 80),
                                    Center(
                                      child: Text(
                                        'لا توجد جولة أو زيارات لهذا التاريخ.',
                                        style: TextStyle(
                                          fontWeight: FontWeight.w700,
                                          color: AppTheme.textSoft,
                                        ),
                                      ),
                                    ),
                                  ],
                                )
                              : ListView(
                                  padding:
                                      const EdgeInsets.fromLTRB(12, 4, 12, 20),
                                  children: [
                                    if (_planned.isNotEmpty) ...[
                                      const Padding(
                                        padding: EdgeInsets.only(
                                            bottom: 8, top: 4),
                                        child: Text(
                                          'خطة الجولة',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w900,
                                            fontSize: 14.5,
                                          ),
                                        ),
                                      ),
                                      for (final v in _planned)
                                        _VisitCard(
                                          visit: v,
                                          status: _statusOf(v),
                                          statusLabel:
                                              _statusLabel(_statusOf(v)),
                                          statusColor:
                                              _statusColor(_statusOf(v)),
                                          inPlan: true,
                                        ),
                                    ],
                                    if (_extra.isNotEmpty) ...[
                                      const Padding(
                                        padding: EdgeInsets.only(
                                            bottom: 8, top: 12),
                                        child: Text(
                                          'زيارات خارج الخطة',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w900,
                                            fontSize: 14.5,
                                          ),
                                        ),
                                      ),
                                      for (final v in _extra)
                                        _VisitCard(
                                          visit: v,
                                          status: _statusOf(v),
                                          statusLabel:
                                              _statusLabel(_statusOf(v)),
                                          statusColor:
                                              _statusColor(_statusOf(v)),
                                          inPlan: false,
                                        ),
                                    ],
                                  ],
                                ),
                        ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatChip extends StatelessWidget {
  const _StatChip({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        children: [
          Text(
            value,
            style: TextStyle(
              fontWeight: FontWeight.w900,
              fontSize: 16,
              color: color,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
              color: color.withValues(alpha: 0.9),
            ),
          ),
        ],
      ),
    );
  }
}

class _VisitCard extends StatelessWidget {
  const _VisitCard({
    required this.visit,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.inPlan,
  });

  final Map<String, dynamic> visit;
  final String status;
  final String statusLabel;
  final Color statusColor;
  final bool inPlan;

  @override
  Widget build(BuildContext context) {
    final hasOrder = visit['has_order'] == true ||
        visit['has_order'] == 1 ||
        '${visit['has_order']}' == '1';
    final checkin = Fmt.str(visit['visit_checkin_at']);
    final checkout = Fmt.str(visit['visit_checkout_at']);
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  MiniIcon(
                    status == 'checked_out'
                        ? Icons.check_circle_rounded
                        : (status == 'checked_in' ||
                                status == 'pending_manual_checkout'
                            ? Icons.login_rounded
                            : Icons.storefront_rounded),
                    color: statusColor,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          Fmt.str(visit['name']),
                          style: const TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 15,
                          ),
                        ),
                        if (Fmt.str(visit['code']).isNotEmpty)
                          Text(
                            Fmt.str(visit['code']),
                            style: const TextStyle(
                              color: AppTheme.textSoft,
                              fontSize: 12.5,
                            ),
                          ),
                      ],
                    ),
                  ),
                  StatusPill(text: statusLabel, color: statusColor),
                ],
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 6,
                children: [
                  if (inPlan)
                    const StatusPill(
                      text: 'في الخطة',
                      color: AppTheme.primary,
                      icon: Icons.route_rounded,
                    ),
                  if (hasOrder)
                    const StatusPill(
                      text: 'طلب شراء',
                      color: AppTheme.violet,
                      icon: Icons.shopping_bag_outlined,
                    ),
                  if (checkin.isNotEmpty)
                    StatusPill(
                      text: 'دخول ${Fmt.dmyHm(checkin)}',
                      color: AppTheme.teal,
                    ),
                  if (checkout.isNotEmpty)
                    StatusPill(
                      text: 'خروج ${Fmt.dmyHm(checkout)}',
                      color: AppTheme.success,
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
