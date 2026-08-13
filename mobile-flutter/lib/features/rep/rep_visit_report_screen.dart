import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
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
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repVisitReportPath,
            query: {'from': _from, 'to': _to},
          );
      if (!mounted) return;
      setState(() {
        _from = Fmt.str(res['from']).isEmpty ? _from : Fmt.str(res['from']);
        _to = Fmt.str(res['to']).isEmpty ? _to : Fmt.str(res['to']);
        _rows = (res['visits'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
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
              child: Row(
                children: [
                  Expanded(child: _DateChip(label: 'من', value: Fmt.dmy(_from), onTap: () => _pick(true))),
                  const SizedBox(width: 8),
                  Expanded(child: _DateChip(label: 'إلى', value: Fmt.dmy(_to), onTap: () => _pick(false))),
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
