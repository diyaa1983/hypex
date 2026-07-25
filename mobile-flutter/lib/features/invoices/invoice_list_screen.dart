import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

class InvoiceListScreen extends StatefulWidget {
  const InvoiceListScreen({super.key});

  @override
  State<InvoiceListScreen> createState() => _InvoiceListScreenState();
}

class _InvoiceListScreenState extends State<InvoiceListScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  String _filter = 'all';
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
        AppConfig.salesInvoiceListPath,
        query: {'filter': _filter, 'q': _search.text.trim()},
      );
      setState(() {
        _rows = (res['invoices'] as List? ?? [])
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
      appBar: AppBar(title: const Text('فواتير المبيعات')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/invoices/new'),
        icon: const Icon(Icons.add),
        label: const Text('فاتورة جديدة'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'بحث برقم الفاتورة أو العميل...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.arrow_circle_left_outlined),
                  onPressed: _load,
                ),
              ),
              onSubmitted: (_) => _load(),
            ),
          ),
          _FilterChips(
            value: _filter,
            onChanged: (v) {
              setState(() => _filter = v);
              _load();
            },
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
                        EmptyState(message: 'لا توجد فواتير.'),
                      ])
                    : ListView.builder(
                        padding: const EdgeInsets.all(10),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) => _InvoiceCard(
                          row: _rows[i],
                          onTap: () => context.push(
                            '/invoices/${_rows[i]['id']}',
                          ),
                        ),
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FilterChips extends StatelessWidget {
  const _FilterChips({required this.value, required this.onChanged});
  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    const items = {
      'all': 'الكل',
      'unposted': 'غير مرحّلة',
      'posted': 'مرحّلة',
    };
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Row(
        children: items.entries.map((e) {
          final sel = e.key == value;
          return Padding(
            padding: const EdgeInsets.only(left: 8),
            child: ChoiceChip(
              label: Text(e.value),
              selected: sel,
              onSelected: (_) => onChanged(e.key),
            ),
          );
        }).toList(),
      ),
    );
  }
}

class _InvoiceCard extends StatelessWidget {
  const _InvoiceCard({required this.row, required this.onTap});
  final Map<String, dynamic> row;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final posted = row['is_posted'] == true;
    return Card(
      child: ListTile(
        onTap: onTap,
        title: Text(
          (row['customer_name'] ?? '—').toString(),
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: 4),
            Row(
              children: [
                Text('رقم: ${row['invoice_no'] ?? '—'}'),
                const SizedBox(width: 12),
                Text((row['invoice_date_dmy'] ?? '').toString(),
                    textDirection: TextDirection.ltr),
              ],
            ),
            const SizedBox(height: 4),
            Text('${row['total_fmt'] ?? '0'}  •  ${row['payment_label'] ?? ''}',
                textDirection: TextDirection.ltr),
          ],
        ),
        trailing: _Badge(
          text: posted ? 'مرحّلة' : 'غير مرحّلة',
          color: posted ? AppTheme.success : AppTheme.warn,
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.text, required this.color});
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(text,
          style: TextStyle(
              color: color, fontSize: 12, fontWeight: FontWeight.bold)),
    );
  }
}
