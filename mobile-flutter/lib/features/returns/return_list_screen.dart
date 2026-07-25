import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

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
      appBar: AppBar(title: const Text('مرتجعات المبيعات')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/returns/new').then((_) => _load()),
        icon: const Icon(Icons.add),
        label: const Text('مرتجع جديد'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: AsyncView(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _rows.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 100),
                  EmptyState(message: 'لا توجد مرتجعات.'),
                ])
              : ListView.builder(
                  padding: const EdgeInsets.all(10),
                  itemCount: _rows.length,
                  itemBuilder: (_, i) {
                    final r = _rows[i];
                    final posted = r['is_posted'] == true;
                    return Card(
                      child: ListTile(
                        title: Text((r['customer_name'] ?? '—').toString(),
                            style:
                                const TextStyle(fontWeight: FontWeight.bold)),
                        subtitle: Text(
                          'رقم: ${r['return_no'] ?? '—'}  •  ${r['return_date_dmy'] ?? r['return_date'] ?? ''}  •  ${r['total_fmt'] ?? r['total'] ?? ''}',
                          textDirection: TextDirection.ltr,
                        ),
                        trailing: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 5),
                          decoration: BoxDecoration(
                            color: (posted ? AppTheme.success : AppTheme.warn)
                                .withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(posted ? 'مرحّل' : 'غير مرحّل',
                              style: TextStyle(
                                  color: posted
                                      ? AppTheme.success
                                      : AppTheme.warn,
                                  fontSize: 12,
                                  fontWeight: FontWeight.bold)),
                        ),
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
