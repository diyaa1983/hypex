import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_service.dart';
import '../../services/location_presence_service.dart';
import '../../services/location_tracking_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

/// شاشة التتبّع التلقائي + قائمة آخر مواقع المستخدمين.
///
/// التتبّع التلقائي = إرسال موقع هذا الجهاز دورياً للسيرفر (خلفية + أثناء فتح التطبيق)
/// حتى يظهر المندوب على «تتبّع المواقع الحية» لدى المدير.
class UserGpsScreen extends StatefulWidget {
  const UserGpsScreen({super.key});

  @override
  State<UserGpsScreen> createState() => _UserGpsScreenState();
}

class _UserGpsScreenState extends State<UserGpsScreen> {
  bool _loading = true;
  bool _sending = false;
  bool _toggling = false;
  bool _tracking = false;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  final _search = TextEditingController();
  TrackingStatus? _status;
  Timer? _statusTimer;

  @override
  void initState() {
    super.initState();
    _load();
    _refreshTracking();
    _statusTimer =
        Timer.periodic(const Duration(seconds: 12), (_) => _refreshTracking());
  }

  @override
  void dispose() {
    _statusTimer?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _refreshTracking() async {
    final on = await LocationTrackingService.isRunning;
    final st = await LocationTrackingService.status();
    if (!mounted) return;
    final prev = _status;
    final same = _tracking == on &&
        prev != null &&
        prev.running == st.running &&
        prev.sentCount == st.sentCount &&
        prev.lastStatus == st.lastStatus &&
        prev.lastPing?.millisecondsSinceEpoch ==
            st.lastPing?.millisecondsSinceEpoch;
    if (same) return;
    setState(() {
      _tracking = on;
      _status = st;
    });
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
    if (_toggling) return;
    final session = context.read<SessionController>();
    if (!session.gpsConfig.userCanDisable && !on) {
      showSnack(context, 'إيقاف التتبّع يتم من إعدادات النظام فقط.',
          error: true);
      return;
    }
    setState(() => _toggling = true);
    String? msg;
    try {
      if (on) {
        msg = await LocationTrackingService.start();
        if (msg == null) {
          await LocationPresenceService.start(
            api: session.api,
            csrf: session.csrf,
            intervalSec: session.gpsConfig.intervalSec,
          );
          LocationTrackingService.requestImmediatePing();
          await LocationPresenceService.pingNow(force: true);
        }
      } else {
        await LocationPresenceService.stop();
        await LocationTrackingService.stop();
      }
      if (!mounted) return;
      if (msg != null) {
        showSnack(context, msg, error: true);
      } else {
        final tip =
            on ? await LocationTrackingService.backgroundPermissionTip() : null;
        if (!mounted) return;
        showSnack(
          context,
          tip ??
              (on
                  ? 'تم تشغيل التتبّع التلقائي — سيظهر موقعك للمدير على الخريطة الحية.'
                  : 'تم إيقاف التتبّع التلقائي.'),
        );
      }
    } finally {
      if (mounted) setState(() => _toggling = false);
      await _refreshTracking();
    }
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
      await LocationTrackingService.saveLastStatus(
        skipped ? 'موقع محدّث مسبقاً' : 'تم إرسال الموقع يدوياً',
        lat: pos.latitude,
        lng: pos.longitude,
      );
      await _load();
      await _refreshTracking();
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

  String _fmtHm(DateTime? t) {
    if (t == null) return '—';
    final hh = t.hour.toString().padLeft(2, '0');
    final mm = t.minute.toString().padLeft(2, '0');
    return '$hh:$mm';
  }

  String get _statusLine {
    final st = _status;
    if (!_tracking) return 'متوقف — لن يُرسل موقعك تلقائياً';
    if (st == null) return 'يعمل — جاري التحقق من آخر إرسال…';
    final interval = LocationTrackingService.humanInterval(st.intervalSec);
    if (st.lastPing != null) {
      final age = DateTime.now().difference(st.lastPing!).inSeconds;
      final ageLabel = age < 60
          ? 'منذ $age ث'
          : 'منذ ${(age / 60).floor()} د';
      return 'يعمل · كل $interval · آخر إرسال ${_fmtHm(st.lastPing)} ($ageLabel)';
    }
    if (st.lastStatus.isNotEmpty) return 'يعمل لكن: ${st.lastStatus}';
    return 'يعمل — بانتظار أول إرسال (كل $interval)';
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<SessionController>();
    final canDisable = session.gpsConfig.userCanDisable;

    return Scaffold(
      appBar: AppBar(
        title: const Text('التتبّع التلقائي'),
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: () {
              _load();
              _refreshTracking();
            },
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
        label: const Text('إرسال موقعي الآن'),
      ),
      body: Column(
        children: [
          Container(
            color: AppTheme.surface,
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.06),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: AppTheme.primary.withValues(alpha: 0.15),
                    ),
                  ),
                  child: const Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'ما وظيفة التتبّع التلقائي؟',
                        style: TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      SizedBox(height: 4),
                      Text(
                        'يرسل موقع هذا الهاتف للسيرفر بشكل دوري (حتى مع إغلاق التطبيق) '
                        'كي يظهر المندوب على شاشة «تتبّع المواقع الحية» لدى المدير. '
                        'ليس خريطة حية — بل تشغيل/إيقاف الإرسال من جهازك.',
                        style: TextStyle(
                          fontSize: 12,
                          height: 1.35,
                          color: AppTheme.textSoft,
                        ),
                      ),
                    ],
                  ),
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
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12),
                    value: _tracking,
                    onChanged: _toggling || (!canDisable && _tracking)
                        ? null
                        : _toggleTracking,
                    title: Text(
                      _tracking
                          ? 'التتبّع التلقائي يعمل'
                          : 'التتبّع التلقائي متوقف',
                      style: const TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    subtitle: Text(
                      _statusLine,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: AppTheme.textSoft,
                      ),
                    ),
                  ),
                ),
                if (_status != null &&
                    _tracking &&
                    _status!.lastStatus.isNotEmpty &&
                    !_status!.lastStatus.contains('تم إرسال') &&
                    !_status!.lastStatus.contains('تم تأكيد')) ...[
                  const SizedBox(height: 8),
                  Text(
                    _status!.lastStatus,
                    style: const TextStyle(
                      fontSize: 11.5,
                      color: AppTheme.danger,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                const SizedBox(height: 10),
                TextField(
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: const InputDecoration(
                    hintText: 'بحث باسم المستخدم...',
                    prefixIcon: Icon(Icons.search_rounded, size: 20),
                  ),
                  onSubmitted: (_) => _load(),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                await _load();
                await _refreshTracking();
              },
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _rows.isEmpty
                    ? ListView(
                        children: const [
                          SizedBox(height: 60),
                          EmptyState(
                            message:
                                'لا توجد مواقع مسجّلة بعد. شغّل التتبّع أو أرسل موقعك.',
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
