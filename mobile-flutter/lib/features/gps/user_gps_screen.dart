import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_service.dart';
import '../../services/location_tracking_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class UserGpsScreen extends StatefulWidget {
  const UserGpsScreen({super.key});

  @override
  State<UserGpsScreen> createState() => _UserGpsScreenState();
}

class _UserGpsScreenState extends State<UserGpsScreen> {
  bool _loading = true;
  bool _sending = false;
  bool _tracking = false;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
    _refreshTracking();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _refreshTracking() async {
    final on = await LocationTrackingService.isRunning;
    if (mounted) setState(() => _tracking = on);
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
      if (!mounted) return;
      setState(() {
        _rows = (res['rows'] as List? ?? [])
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
    }
  }

  Future<void> _toggleTracking(bool on) async {
    String? msg;
    if (on) {
      msg = await LocationTrackingService.start();
    } else {
      await LocationTrackingService.stop();
    }
    if (!mounted) return;
    if (msg != null) {
      showSnack(context, msg, error: true);
    } else {
      showSnack(context, on ? 'تم تشغيل التتبّع.' : 'تم إيقاف التتبّع.');
    }
    await _refreshTracking();
  }

  Future<void> _sendMyLocation() async {
    final s = context.read<SessionController>();
    final api = context.read<ApiClient>();
    setState(() => _sending = true);
    try {
      final pos = await LocationService.requirePosition();
      final res = await api.postForm(
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
      appBar: AppBar(
        title: const Text('مواقع المستخدمين'),
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _sending ? null : _sendMyLocation,
        icon: _sending
            ? const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  color: Colors.white,
                ),
              )
            : const Icon(Icons.my_location_rounded, size: 20),
        label: const Text('إرسال موقعي'),
      ),
      body: Column(
        children: [
          Container(
            color: AppTheme.surface,
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
            child: Column(
              children: [
                TextField(
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: const InputDecoration(
                    hintText: 'بحث باسم المستخدم...',
                    prefixIcon: Icon(Icons.search_rounded, size: 20),
                  ),
                  onSubmitted: (_) => _load(),
                ),
                const SizedBox(height: 10),
                Container(
                  decoration: BoxDecoration(
                    color: (_tracking ? AppTheme.success : AppTheme.warn)
                        .withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: SwitchListTile(
                    dense: true,
                    contentPadding:
                        const EdgeInsets.symmetric(horizontal: 12),
                    value: _tracking,
                    onChanged: _toggleTracking,
                    title: Text(
                      _tracking
                          ? 'التتبّع التلقائي يعمل'
                          : 'التتبّع التلقائي متوقف',
                      style: const TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    subtitle: const Text(
                      'يرسل موقعك دورياً حتى مع إغلاق التطبيق',
                      style: TextStyle(
                        fontSize: 11.5,
                        color: AppTheme.textSoft,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _rows.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 60),
                          EmptyState(
                            message: 'لا توجد مواقع مسجّلة.',
                            icon: Icons.location_off_rounded,
                          ),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) {
                          final r = _rows[i];
                          final lat = Fmt.toDouble(r['latitude'] ?? r['lat']);
                          final lng = Fmt.toDouble(r['longitude'] ?? r['lng']);
                          final hasLoc = lat != 0 || lng != 0;
                          return AppCard(
                            onTap: hasLoc ? () => _openMap(lat, lng) : null,
                            padding: const EdgeInsets.all(12),
                            child: Row(
                              children: [
                                const MiniIcon(
                                  Icons.person_pin_circle_rounded,
                                  color: AppTheme.primary,
                                ),
                                const SizedBox(width: 11),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        Fmt.str(
                                          r['user_name'] ??
                                              r['full_name_ar'] ??
                                              r['name'],
                                        ),
                                        style: const TextStyle(
                                          fontSize: 14,
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        Fmt.str(
                                          r['recorded_at_dmy'] ??
                                              r['recorded_at'] ??
                                              r['ping_time'] ??
                                              '',
                                        ),
                                        textDirection: TextDirection.ltr,
                                        style: const TextStyle(
                                          fontSize: 12,
                                          color: AppTheme.textSoft,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                IconButton(
                                  tooltip: 'فتح الخريطة',
                                  icon: const Icon(
                                    Icons.map_outlined,
                                    size: 20,
                                  ),
                                  color: AppTheme.teal,
                                  onPressed:
                                      hasLoc ? () => _openMap(lat, lng) : null,
                                ),
                              ],
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
