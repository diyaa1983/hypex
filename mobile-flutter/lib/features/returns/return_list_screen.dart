import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../services/return_print_helper.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class ReturnListScreen extends StatefulWidget {
  const ReturnListScreen({super.key});

  @override
  State<ReturnListScreen> createState() => _ReturnListScreenState();
}

class _ReturnListScreenState extends State<ReturnListScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.returnsListPath,
        query: {'filter': 'all'},
      );
      setState(() {
        _rows = (res['returns'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('مرتجعات المبيعات'),
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/returns/new').then((_) => _load()),
        icon: const Icon(Icons.add_rounded, size: 20),
        label: const Text('مرتجع جديد'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: AsyncView(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _rows.isEmpty
              ? ListView(
                  children: [
                    const SizedBox(height: 60),
                    EmptyState(
                      message: 'لا توجد مرتجعات.',
                      icon: Icons.assignment_returned_rounded,
                      actionLabel: 'مرتجع جديد',
                      onAction: () =>
                          context.push('/returns/new').then((_) => _load()),
                    ),
                  ],
                )
              : ListView.builder(
                  padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
                  itemCount: _rows.length,
                  itemBuilder: (_, i) {
                    final r = _rows[i];
                    final id = int.tryParse('${r['id']}') ?? 0;
                    return _ReturnCard(
                      row: r,
                      onPreview: () {
                        if (id < 1) return;
                        ReturnPrintHelper.openThermalPreview(
                          context,
                          returnId: id,
                          fallback: r,
                        );
                      },
                      onPrint: () {
                        if (id < 1) return;
                        ReturnPrintHelper.printBluetooth(
                          context,
                          returnId: id,
                          fallback: r,
                        );
                      },
                    );
                  },
                ),
        ),
      ),
    );
  }
}

class _ReturnCard extends StatelessWidget {
  const _ReturnCard({
    required this.row,
    required this.onPreview,
    required this.onPrint,
  });

  final Map<String, dynamic> row;
  final VoidCallback onPreview;
  final VoidCallback onPrint;

  @override
  Widget build(BuildContext context) {
    final posted = row['is_posted'] == true;
    return AppCard(
      padding: const EdgeInsets.fromLTRB(12, 12, 6, 8),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              MiniIcon(
                Icons.keyboard_return_rounded,
                color: posted ? AppTheme.success : AppTheme.warn,
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      (row['customer_name'] ?? '—').toString(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '#${row['return_no'] ?? '—'}  •  '
                      '${row['return_date_dmy'] ?? row['return_date'] ?? ''}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppTheme.textSoft,
                      ),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: Text(
                      '${row['total_fmt'] ?? row['total'] ?? '0'}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w800,
                        color: AppTheme.rose,
                      ),
                    ),
                  ),
                  const SizedBox(height: 5),
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: StatusPill(
                      text: posted ? 'مرحّل' : 'غير مرحّل',
                      color: posted ? AppTheme.success : AppTheme.warn,
                    ),
                  ),
                ],
              ),
            ],
          ),
          Row(
            children: [
              const Spacer(),
              IconButton(
                tooltip: 'عرض',
                visualDensity: VisualDensity.compact,
                onPressed: onPreview,
                icon: const Icon(Icons.receipt_long_outlined, size: 19),
                color: AppTheme.teal,
              ),
              IconButton(
                tooltip: 'طباعة',
                visualDensity: VisualDensity.compact,
                onPressed: onPrint,
                icon: const Icon(Icons.print_outlined, size: 19),
                color: AppTheme.primary,
              ),
            ],
          ),
        ],
      ),
    );
  }
}
