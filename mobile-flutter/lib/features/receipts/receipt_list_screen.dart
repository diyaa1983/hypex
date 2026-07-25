import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

class ReceiptListScreen extends StatefulWidget {
  const ReceiptListScreen({super.key});

  @override
  State<ReceiptListScreen> createState() => _ReceiptListScreenState();
}

class _ReceiptListScreenState extends State<ReceiptListScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.receiptListPath,
        query: {'filter': 'all', 'q': _search.text.trim()},
      );
      setState(() {
        _rows = (res['receipts'] as List? ?? [])
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
      appBar: AppBar(title: const Text('سندات القبض')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/receipts/new').then((_) => _load()),
        icon: const Icon(Icons.add),
        label: const Text('سند جديد'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'بحث برقم السند أو العميل...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.arrow_circle_left_outlined),
                  onPressed: _load,
                ),
              ),
              onSubmitted: (_) => _load(),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _rows.isEmpty
                    ? ListView(children: const [
                        SizedBox(height: 100),
                        EmptyState(message: 'لا توجد سندات.'),
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
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold)),
                              subtitle: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const SizedBox(height: 4),
                                  Text('رقم: ${r['voucher_no'] ?? '—'}  •  ${r['voucher_date_dmy'] ?? ''}',
                                      textDirection: TextDirection.ltr),
                                  Text('${r['amount_fmt'] ?? '0'}  •  ${r['pay_label'] ?? ''}',
                                      textDirection: TextDirection.ltr),
                                ],
                              ),
                              trailing: Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 10, vertical: 5),
                                decoration: BoxDecoration(
                                  color: (posted
                                          ? AppTheme.success
                                          : AppTheme.warn)
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
          ),
        ],
      ),
    );
  }
}
