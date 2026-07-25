import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';

class UserGpsScreen extends StatefulWidget {
  const UserGpsScreen({super.key});

  @override
  State<UserGpsScreen> createState() => _UserGpsScreenState();
}

class _UserGpsScreenState extends State<UserGpsScreen> {
  bool _loading = true;
  bool _sending = false;
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
        AppConfig.userGpsListPath,
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

  Future<void> _sendMyLocation() async {
    setState(() => _sending = true);
    try {
      final pos = await LocationService.requirePosition();
      final s = context.read<SessionController>();
      final res = await context.read<ApiClient>().postForm(
        AppConfig.userLocationPingPath,
        csrf: s.csrf,
        fields: {
          'latitude': pos.latitude,
          'longitude': pos.longitude,
          'gps_accuracy': pos.accuracy,
          'gps_source': 'mobile',
        },
      );
      if (!mounted) return;
      final skipped = res['skipped'] == true;
      showSnack(context, skipped ? 'موقعك محدّث مسبقاً' : 'تم إرسال موقعك');
      await _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _openMap(double lat, double lng) async {
    final uri =
        Uri.parse('https://www.google.com/maps/search/?api=1&query=$lat,$lng');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      if (mounted) showSnack(context, 'تعذر فتح الخريطة', error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('مواقع المستخدمين')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _sending ? null : _sendMyLocation,
        icon: _sending
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(
                    strokeWidth: 2, color: Colors.white),
              )
            : const Icon(Icons.my_location),
        label: const Text('إرسال موقعي'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'بحث باسم المستخدم...',
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
                            message: 'لا توجد مواقع مسجّلة.',
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
                              leading: const Icon(Icons.person_pin_circle,
                                  color: Colors.blue),
                              title: Text(
                                  Fmt.str(r['user_name'] ??
                                      r['full_name_ar'] ??
                                      r['name']),
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold)),
                              subtitle: Text(
                                Fmt.str(r['recorded_at_dmy'] ??
                                    r['recorded_at'] ??
                                    r['ping_time'] ??
                                    ''),
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
