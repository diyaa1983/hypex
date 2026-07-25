import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../widgets/async_view.dart';

class InvoiceGpsScreen extends StatefulWidget {
  const InvoiceGpsScreen({super.key});

  @override
  State<InvoiceGpsScreen> createState() => _InvoiceGpsScreenState();
}

class _InvoiceGpsScreenState extends State<InvoiceGpsScreen> {
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
        AppConfig.invoiceGpsListPath,
        query: {'show': '1', 'q': _search.text.trim()},
      );
      setState(() {
        _rows = (res['rows'] as List? ?? [])
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

  Future<void> _openMap(double lat, double lng) async {
    final uri = Uri.parse('https://www.google.com/maps/search/?api=1&query=$lat,$lng');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      if (mounted) showSnack(context, 'تعذر فتح الخريطة', error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('مواقع الفواتير')),
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
                        EmptyState(
                            message: 'لا توجد إحداثيات مسجّلة.',
                            icon: Icons.location_off_outlined),
                      ])
                    : ListView.builder(
                        padding: const EdgeInsets.all(10),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) {
                          final r = _rows[i];
                          final lat = Fmt.toDouble(r['latitude'] ?? r['lat']);
                          final lng = Fmt.toDouble(r['longitude'] ?? r['lng']);
                          return Card(
                            child: ListTile(
                              leading: const Icon(Icons.location_on,
                                  color: Colors.red),
                              title: Text(
                                  '${r['customer_name'] ?? '—'}',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold)),
                              subtitle: Text(
                                'فاتورة ${r['invoice_no'] ?? ''}  •  ${r['captured_at_dmy'] ?? r['captured_at'] ?? ''}',
                                textDirection: TextDirection.ltr,
                              ),
                              trailing: IconButton(
                                icon: const Icon(Icons.map_outlined),
                                onPressed: (lat == 0 && lng == 0)
                                    ? null
                                    : () => _openMap(lat, lng),
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
