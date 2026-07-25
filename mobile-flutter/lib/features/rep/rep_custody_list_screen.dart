import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

class RepCustodyListScreen extends StatefulWidget {
  const RepCustodyListScreen({super.key});

  @override
  State<RepCustodyListScreen> createState() => _RepCustodyListScreenState();
}

class _RepCustodyListScreenState extends State<RepCustodyListScreen> {
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
        AppConfig.repCustodyListPath,
        query: {'filter': 'all'},
      );
      setState(() {
        _rows = (res['moves'] as List? ?? [])
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
      appBar: AppBar(title: const Text('قائمة العهدات')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: AsyncView(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _rows.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 100),
                  EmptyState(message: 'لا توجد سندات عهدة.'),
                ])
              : ListView.builder(
                  padding: const EdgeInsets.all(10),
                  itemCount: _rows.length,
                  itemBuilder: (_, i) {
                    final r = _rows[i];
                    final posted = r['is_posted'] == true;
                    final isReturn =
                        (r['direction'] ?? '').toString() == 'return';
                    return Card(
                      child: ListTile(
                        leading: Icon(
                          isReturn ? Icons.upload : Icons.download,
                          color: AppTheme.primary,
                        ),
                        title: Text(
                          '${isReturn ? 'إرجاع' : 'تحميل'} - ${r['move_no_fmt'] ?? r['move_no'] ?? '—'}',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                        subtitle: Text(
                          '${r['move_date_dmy'] ?? r['move_date'] ?? ''}  •  ${r['lines_count'] ?? r['items_count'] ?? ''} صنف',
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
                          child: Text(posted ? 'مرحّل' : 'مسودة',
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
