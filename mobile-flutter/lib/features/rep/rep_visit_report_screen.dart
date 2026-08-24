import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class RepVisitReportScreen extends StatefulWidget {
  const RepVisitReportScreen({super.key});

  @override
  State<RepVisitReportScreen> createState() => _RepVisitReportScreenState();
}

class _RepVisitReportScreenState extends State<RepVisitReportScreen> {
  bool _loading = true;
  String? _error;
  String _from = '';
  String _to = '';
  List<Map<String, dynamic>> _rows = [];
  List<Map<String, dynamic>> _customers = [];
  int _customerId = 0;
  int _orderCount = 0;
  double _orderTotal = 0;

  @override
  void initState() {
    super.initState();
    _to = Fmt.todayIso();
    final now = DateTime.now();
    _from =
        '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-01';
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final offline = context.read<OfflineController>();
    try {
      if (!offline.online && offline.catalogReady) {
        await _loadLocal();
        return;
      }
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitReportPath,
            query: {
              'from': _from,
              'to': _to,
              if (_customerId > 0) 'customer_id': '$_customerId',
            },
          );
      if (!mounted) return;
      setState(() {
        _from = Fmt.str(res['from']).isEmpty ? _from : Fmt.str(res['from']);
        _to = Fmt.str(res['to']).isEmpty ? _to : Fmt.str(res['to']);
        _rows = (res['visits'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _customers = (res['customers'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _orderCount = Fmt.toInt(res['order_count']);
        _orderTotal = Fmt.toDouble(res['order_total']);
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        await _loadLocal();
        return;
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

  Future<void> _loadLocal() async {
    final store = OfflineStore.instance;
    final rows = await store.visitReportRows(
      from: _from,
      to: _to,
      customerId: _customerId,
    );
    final custMap = <int, Map<String, dynamic>>{};
    var orderCount = 0;
    var orderTotal = 0.0;
    for (final r in rows) {
      final cid = Fmt.toInt(r['customer_id']);
      if (cid != 0 && !custMap.containsKey(cid)) {
        custMap[cid] = {
          'id': cid,
          'name': Fmt.str(r['customer_name']),
          'code': Fmt.str(r['customer_code']),
        };
      }
      orderCount += Fmt.toInt(r['order_count']);
      orderTotal += Fmt.toDouble(r['order_total']);
    }
    if (!mounted) return;
    setState(() {
      _rows = rows;
      _customers = custMap.values.toList();
      _orderCount = orderCount;
      _orderTotal = orderTotal;
      _loading = false;
    });
  }

  Future<void> _pick(bool isFrom) async {
    final current = DateTime.tryParse(isFrom ? _from : _to) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: current,
      firstDate: DateTime(current.year - 2),
      lastDate: DateTime(current.year + 1),
    );
    if (picked == null) return;
    final iso =
        '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
    setState(() {
      if (isFrom) {
        _from = iso;
      } else {
        _to = iso;
      }
    });
    await _load();
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'closed':
        return AppTheme.success;
      case 'pending':
        return AppTheme.warn;
      default:
        return AppTheme.teal;
    }
  }

  Color _methodColor(String m) {
    return m.toUpperCase() == 'GPS' ? AppTheme.primary : AppTheme.warn;
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('تقرير الزيارات'),
      actions: [
        IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh_rounded)),
      ],
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 14, 6),
            child: AppCard(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(child: _DateChip(label: 'من', value: Fmt.dmy(_from), onTap: () => _pick(true))),
                      const SizedBox(width: 8),
                      Expanded(child: _DateChip(label: 'إلى', value: Fmt.dmy(_to), onTap: () => _pick(false))),
                    ],
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<int>(
                    key: ValueKey(_customerId),
                    initialValue: _customerId,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'العميل',
                      prefixIcon: Icon(Icons.storefront_rounded),
                    ),
                    items: [
                      const DropdownMenuItem<int>(
                        value: 0,
                        child: Text('جميع العملاء'),
                      ),
                      ..._customers.map(
                        (c) => DropdownMenuItem<int>(
                          value: Fmt.toInt(c['id']),
                          child: Text(
                            '${Fmt.str(c['name'])} · ${Fmt.str(c['code'])}',
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ),
                    ],
                    onChanged: _loading
                        ? null
                        : (v) {
                            setState(() => _customerId = v ?? 0);
                            _load();
                          },
                  ),
                ],
              ),
            ),
          ),
          if (!_loading && _error == null)
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 2, 14, 6),
              child: AppCard(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                child: Row(
                  children: [
                    Expanded(
                      child: _ReportStat(
                        label: 'عدد الزيارات',
                        value: '${_rows.length}',
                        icon: Icons.location_on_rounded,
                        color: AppTheme.primary,
                      ),
                    ),
                    Expanded(
                      child: _ReportStat(
                        label: 'عدد الطلبيات',
                        value: '$_orderCount',
                        icon: Icons.shopping_cart_checkout_rounded,
                        color: AppTheme.success,
                      ),
                    ),
                    Expanded(
                      child: _ReportStat(
                        label: 'إجمالي الطلبيات',
                        value: Fmt.money(_orderTotal),
                        icon: Icons.payments_rounded,
                        color: AppTheme.violet,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _rows.isEmpty
                  ? const EmptyState(
                      message: 'لا تسجيلات دخول/خروج في هذه الفترة.',
                      icon: Icons.assignment_outlined,
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                      itemCount: _rows.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 10),
                      itemBuilder: (_, i) => _VisitCard(
                        row: _rows[i],
                        statusColor: _statusColor(Fmt.str(_rows[i]['status'])),
                        methodColor: _methodColor,
                      ),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DateChip extends StatelessWidget {
  const _DateChip({required this.label, required this.value, required this.onTap});

  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: AppTheme.surfaceAlt,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontSize: 11.5, color: AppTheme.textSoft)),
            const SizedBox(height: 2),
            Text(value, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
          ],
        ),
      ),
    );
  }
}

class _VisitCard extends StatelessWidget {
  const _VisitCard({
    required this.row,
    required this.statusColor,
    required this.methodColor,
  });

  final Map<String, dynamic> row;
  final Color statusColor;
  final Color Function(String) methodColor;

  @override
  Widget build(BuildContext context) {
    final inMethod = Fmt.str(row['checkin_method_label']).isEmpty
        ? Fmt.str(row['checkin_method'])
        : Fmt.str(row['checkin_method_label']);
    final outMethod = Fmt.str(row['checkout_method_label']).isEmpty
        ? Fmt.str(row['checkout_method'])
        : Fmt.str(row['checkout_method_label']);
    final inPlan = row['in_plan'] == true || Fmt.toInt(row['in_plan']) == 1;
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              MiniIcon(Icons.storefront_rounded, color: statusColor),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      Fmt.str(row['customer_name']),
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                    ),
                    Text(
                      '${Fmt.str(row['customer_code'])} · ${Fmt.dmy(Fmt.str(row['route_date']))}',
                      style: const TextStyle(color: AppTheme.textSoft, fontSize: 12.5),
                    ),
                  ],
                ),
              ),
              StatusPill(text: Fmt.str(row['status_label']), color: statusColor),
            ],
          ),
          const SizedBox(height: 8),
          StatusPill(
            text: inPlan ? 'داخل الجولة' : 'خارج الجولة',
            color: inPlan ? AppTheme.success : AppTheme.warn,
          ),
          if (Fmt.str(row['no_order_reasons']).isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              'سبب عدم الطلب: ${Fmt.str(row['no_order_reasons'])}',
              style: const TextStyle(
                color: AppTheme.textSoft,
                fontWeight: FontWeight.w700,
                fontSize: 12.5,
              ),
            ),
          ],
          if (Fmt.toInt(row['order_count']) > 0) ...[
            const SizedBox(height: 8),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
              decoration: BoxDecoration(
                color: AppTheme.success.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(11),
                border: Border.all(
                  color: AppTheme.success.withValues(alpha: 0.22),
                ),
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.shopping_cart_checkout_rounded,
                    color: AppTheme.success,
                    size: 20,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'طلب رقم: ${Fmt.str(row['order_numbers'])}',
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 12.5,
                      ),
                    ),
                  ),
                  Text(
                    Fmt.money(Fmt.toDouble(row['order_total'])),
                    style: const TextStyle(
                      color: AppTheme.success,
                      fontWeight: FontWeight.w900,
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 12),
          _Pair(
            icon: Icons.login_rounded,
            title: 'دخول',
            time: Fmt.dmyHm(Fmt.str(row['visit_checkin_at'])),
            method: inMethod,
            methodColor: methodColor(Fmt.str(row['checkin_method'])),
          ),
          const SizedBox(height: 8),
          _Pair(
            icon: Icons.logout_rounded,
            title: 'خروج',
            time: Fmt.dmyHm(Fmt.str(row['visit_checkout_at'])),
            method: outMethod,
            methodColor: methodColor(Fmt.str(row['checkout_method'])),
          ),
          if (Fmt.str(row['duration_label']) != '' && Fmt.str(row['duration_label']) != '—') ...[
            const SizedBox(height: 10),
            Text(
              'المدة: ${Fmt.str(row['duration_label'])}',
              style: const TextStyle(color: AppTheme.textSoft, fontWeight: FontWeight.w700, fontSize: 12.5),
            ),
          ],
        ],
      ),
    );
  }
}

class _ReportStat extends StatelessWidget {
  const _ReportStat({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(height: 3),
        Text(
          value,
          textAlign: TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: color,
            fontWeight: FontWeight.w900,
            fontSize: 13,
          ),
        ),
        Text(
          label,
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppTheme.textSoft, fontSize: 10.5),
        ),
      ],
    );
  }
}

class _Pair extends StatelessWidget {
  const _Pair({
    required this.icon,
    required this.title,
    required this.time,
    required this.method,
    required this.methodColor,
  });

  final IconData icon;
  final String title;
  final String time;
  final String method;
  final Color methodColor;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, size: 18, color: AppTheme.textSoft),
        const SizedBox(width: 8),
        Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        const SizedBox(width: 8),
        Expanded(
          child: Text(time, style: const TextStyle(fontSize: 13, fontFeatures: [FontFeature.tabularFigures()])),
        ),
        if (method.isNotEmpty && method != '—')
          StatusPill(text: method, color: methodColor, icon: Icons.tune_rounded),
      ],
    );
  }
}
