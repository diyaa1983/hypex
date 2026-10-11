import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import 'gps_map_tiles.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class _Marker {
  _Marker(this.data);

  final Map<String, dynamic> data;

  int get userId => (data['user_id'] as num?)?.toInt() ?? 0;
  String get label => (data['user_label'] ?? '').toString();
  double get lat => (data['latitude'] as num?)?.toDouble() ?? 0;
  double get lng => (data['longitude'] as num?)?.toDouble() ?? 0;
  bool get online => data['is_online'] == true;
  String get status =>
      (data['status'] ?? (online ? 'online' : 'away')).toString();
  String get statusLabel => (data['status_label'] ?? '').toString();
  String get ageLabel => (data['age_label'] ?? '').toString();
  String get sourceLabel => (data['source_label'] ?? '').toString();
  String get accuracyLabel => (data['accuracy_label'] ?? '').toString();
  String get capturedAt => (data['captured_at_dmy'] ?? '').toString();
  String get mapUrl => (data['map_url'] ?? '').toString();

  LatLng get point => LatLng(lat, lng);

  Color get color {
    switch (status) {
      case 'online':
        return AppTheme.success;
      default:
        return AppTheme.textSoft;
    }
  }

  String get initials {
    final parts =
        label.trim().split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) {
      final s = parts.first;
      return s.length <= 2 ? s : s.substring(0, 2);
    }
    final a = parts[0].isNotEmpty ? parts[0].substring(0, 1) : '';
    final b = parts[1].isNotEmpty ? parts[1].substring(0, 1) : '';
    return '$a$b';
  }

  /// اسم قصير تحت الدبوس على الخريطة.
  String get shortName {
    final parts =
        label.trim().split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList();
    if (parts.isEmpty) return 'مندوب';
    if (parts.length >= 2) {
      final two = '${parts[0]} ${parts[1]}';
      return two.length > 18 ? '${two.substring(0, 17)}…' : two;
    }
    final s = parts.first;
    return s.length > 16 ? '${s.substring(0, 15)}…' : s;
  }
}

/// خريطة تتبّع حية للأجهزة المتصلة (مثل تتبّع السيارات).
class UserGpsTrackerScreen extends StatefulWidget {
  const UserGpsTrackerScreen({super.key});

  @override
  State<UserGpsTrackerScreen> createState() => _UserGpsTrackerScreenState();
}

class _UserGpsTrackerScreenState extends State<UserGpsTrackerScreen> {
  final _map = MapController();
  final _search = TextEditingController();
  Timer? _poll;
  Timer? _animTimer;
  bool _loading = true;
  bool _mapReady = false;
  String? _error;
  double _mapZoom = 8;
  List<_Marker> _markers = [];
  final Map<int, List<LatLng>> _trails = {};
  final Map<int, LatLng> _displayPos = {};
  final Map<int, _MoveAnim> _anims = {};
  /// إزاحة دائرية للمندوبين في نفس النقطة (userId → نقطة العرض).
  final Map<int, LatLng> _spreadPos = {};
  /// نقطة الموقع الحقيقي لرسم خط الربط عند التفريق.
  final Map<int, LatLng> _truePos = {};
  static const int _maxTrailPoints = 60;
  static const double _minTrailMoveMeters = 20;
  static const double _maxTrailJumpMeters = 800;
  static const int _smoothMoveMs = 1800;
  /// تجميع من هم أقرب من هذه المسافة (متر) كـ«نفس الموقع».
  static const double _sameSpotMeters = 28;
  int _online = 0;
  int? _selectedId;
  bool _fitOnce = true;
  bool _listOpen = false;
  DateTime? _lastAnimFrame;

  @override
  void initState() {
    super.initState();
    _load();
    _poll =
        Timer.periodic(const Duration(seconds: 12), (_) => _load(silent: true));
    _animTimer =
        Timer.periodic(const Duration(milliseconds: 220), (_) => _tickAnims());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _animTimer?.cancel();
    _search.dispose();
    _map.dispose();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.userGpsTrackerLivePath,
        query: {
          'online_seconds': 60,
          'stale_seconds': 60,
          'include_stale': 0,
          'include_day_reps': 1,
          'q': _search.text.trim(),
        },
      );
      if (!mounted) return;
      final counts = (res['counts'] as Map?)?.cast<String, dynamic>() ?? {};
      final rows = (res['markers'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _Marker(e.cast<String, dynamic>()))
          .where((m) => m.lat != 0 || m.lng != 0)
          .toList();
      final nextOnline = (counts['online'] as num?)?.toInt() ??
          rows.where((m) => m.online).length;
      final prevIds = _markers.map((m) => m.userId).toSet();
      final nextIds = rows.map((m) => m.userId).toSet();
      final rosterChanged = prevIds.length != nextIds.length ||
          !prevIds.containsAll(nextIds) ||
          _online != nextOnline;

      for (final m in rows) {
        _appendTrailPoint(m.userId, m.point);
        _animateTo(m.userId, m.point);
      }
      final alive = nextIds;
      _displayPos.removeWhere((id, _) => !alive.contains(id));
      _anims.removeWhere((id, _) => !alive.contains(id));
      _spreadPos.removeWhere((id, _) => !alive.contains(id));
      _truePos.removeWhere((id, _) => !alive.contains(id));
      _markers = rows;
      _online = nextOnline;
      _loading = false;
      _error = null;
      _recomputeSpread();

      // صامت بدون تغيّر القائمة: لا نُعد رسم الشاشة كاملة (يومض كل ~10ث).
      // مؤقّت الحركة يحدّث الدبابيس بهدوء عند وجود حركة.
      if (!silent || rosterChanged) {
        setState(() {});
      }

      if (_fitOnce && rows.isNotEmpty && _mapReady) {
        _fitOnce = false;
        WidgetsBinding.instance.addPostFrameCallback((_) => _fitAll());
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (!silent) {
          _error = e.message;
          _loading = false;
        }
      });
      if (silent) showSnack(context, e.message, error: true);
    }
  }

  void _safeMapMove(LatLng center, double zoom) {
    if (!_mapReady || !mounted) return;
    try {
      _map.move(center, zoom);
    } catch (_) {
      /* الخريطة غير جاهزة بعد */
    }
  }

  void _fitAll() {
    if (_markers.isEmpty || !_mapReady) return;
    try {
      if (_markers.length == 1) {
        _safeMapMove(_markers.first.point, 14);
        return;
      }
      final bounds =
          LatLngBounds.fromPoints(_markers.map((m) => m.point).toList());
      _map.fitCamera(
        CameraFit.bounds(bounds: bounds, padding: const EdgeInsets.all(48)),
      );
    } catch (_) {
      /* تجاهل حتى تكتمل تهيئة الخريطة */
    }
  }

  double _haversineMeters(LatLng a, LatLng b) {
    const r = 6371000.0;
    final p1 = a.latitude * math.pi / 180;
    final p2 = b.latitude * math.pi / 180;
    final dp = (b.latitude - a.latitude) * math.pi / 180;
    final dl = (b.longitude - a.longitude) * math.pi / 180;
    final h = math.sin(dp / 2) * math.sin(dp / 2) +
        math.cos(p1) * math.cos(p2) * math.sin(dl / 2) * math.sin(dl / 2);
    return r * 2 * math.atan2(math.sqrt(h), math.sqrt(1 - h));
  }

  void _appendTrailPoint(int userId, LatLng point) {
    if (userId < 1) return;
    final trail = _trails.putIfAbsent(userId, () => <LatLng>[]);
    if (trail.isEmpty) {
      trail.add(point);
      return;
    }
    final last = trail.last;
    final dist = _haversineMeters(last, point);
    if (dist < _minTrailMoveMeters) return;
    if (dist > _maxTrailJumpMeters) {
      trail
        ..clear()
        ..add(point);
      return;
    }
    trail.add(point);
    if (trail.length > _maxTrailPoints) {
      trail.removeRange(0, trail.length - _maxTrailPoints);
    }
  }

  void _animateTo(int userId, LatLng target) {
    final from = _displayPos[userId] ?? target;
    final dist = _haversineMeters(from, target);
    if (dist < 1.5) {
      _displayPos[userId] = target;
      _anims.remove(userId);
      return;
    }
    if (dist > _maxTrailJumpMeters || !_displayPos.containsKey(userId)) {
      _displayPos[userId] = target;
      _anims.remove(userId);
      return;
    }
    final duration = Duration(
      milliseconds:
          math.min(_smoothMoveMs, math.max(1200, (dist * 18).round())),
    );
    _anims[userId] = _MoveAnim(
      from: from,
      to: target,
      start: DateTime.now(),
      duration: duration,
      follow: userId == _selectedId,
    );
  }

  double _easeInOut(double t) {
    return t < 0.5 ? 2 * t * t : 1 - math.pow(-2 * t + 2, 2) / 2;
  }

  DateTime? _lastFollowMove;

  void _tickAnims() {
    if (_anims.isEmpty || !mounted || !_mapReady) return;
    final now = DateTime.now();
    // حدّ أقصى لإعادة الرسم حتى تبقى الخريطة سلسة على التابلت.
    if (_lastAnimFrame != null &&
        now.difference(_lastAnimFrame!).inMilliseconds < 180) {
      return;
    }
    _lastAnimFrame = now;
    var changed = false;
    final done = <int>[];
    double? followZoom;
    try {
      followZoom = _map.camera.zoom;
    } catch (_) {
      _mapReady = false;
      return;
    }
    final canFollow = followZoom != null &&
        (_lastFollowMove == null ||
            now.difference(_lastFollowMove!).inMilliseconds >= 400);
    _anims.forEach((id, anim) {
      final elapsed = now.difference(anim.start).inMilliseconds /
          anim.duration.inMilliseconds;
      if (elapsed >= 1) {
        _displayPos[id] = anim.to;
        _appendTrailPoint(id, anim.to);
        done.add(id);
        changed = true;
        if (anim.follow && canFollow) {
          _lastFollowMove = now;
          final show = _spreadPos[id] ?? anim.to;
          _safeMapMove(show, followZoom!);
        }
        return;
      }
      final e = _easeInOut(elapsed.clamp(0.0, 1.0));
      final lat =
          anim.from.latitude + (anim.to.latitude - anim.from.latitude) * e;
      final lng =
          anim.from.longitude + (anim.to.longitude - anim.from.longitude) * e;
      final pos = LatLng(lat, lng);
      _displayPos[id] = pos;
      if (anim.follow && canFollow) {
        _lastFollowMove = now;
        final show = _spreadPos[id] ?? pos;
        _safeMapMove(show, followZoom!);
      }
      changed = true;
    });
    for (final id in done) {
      _anims.remove(id);
    }
    if (changed) {
      _recomputeSpread();
      setState(() {});
    }
  }

  /// يفرّق المندوبين المتطابقين/القريبين جداً على دائرة حول الموقع الحقيقي.
  void _recomputeSpread() {
    _spreadPos.clear();
    _truePos.clear();
    if (_markers.isEmpty) return;

    final used = <int>{};
    for (var i = 0; i < _markers.length; i++) {
      final a = _markers[i];
      if (used.contains(a.userId)) continue;
      final aPos = _displayPos[a.userId] ?? a.point;
      final group = <_Marker>[a];
      for (var j = i + 1; j < _markers.length; j++) {
        final b = _markers[j];
        if (used.contains(b.userId)) continue;
        final bPos = _displayPos[b.userId] ?? b.point;
        if (_haversineMeters(aPos, bPos) <= _sameSpotMeters) {
          group.add(b);
        }
      }
      for (final m in group) {
        used.add(m.userId);
      }
      if (group.length == 1) {
        final p = _displayPos[a.userId] ?? a.point;
        _spreadPos[a.userId] = p;
        _truePos[a.userId] = p;
        continue;
      }
      // مركز المجموعة
      var latSum = 0.0;
      var lngSum = 0.0;
      for (final m in group) {
        final p = _displayPos[m.userId] ?? m.point;
        latSum += p.latitude;
        lngSum += p.longitude;
      }
      final center = LatLng(latSum / group.length, lngSum / group.length);
      final n = group.length;
      // نصف قطر يتسع مع عدد المندوبين ويُكبَّر عند التصغير.
      final meters = (26.0 + (n - 1) * 10.0) *
          math.pow(2.0, (14.5 - _mapZoom).clamp(-1.5, 3.5));
      final cosLat = math.cos(center.latitude * math.pi / 180).abs().clamp(0.2, 1.0);
      for (var k = 0; k < n; k++) {
        final m = group[k];
        final angle = (2 * math.pi * k / n) - (math.pi / 2);
        final dLat = (meters * math.cos(angle)) / 111320.0;
        final dLng = (meters * math.sin(angle)) / (111320.0 * cosLat);
        final show = LatLng(center.latitude + dLat, center.longitude + dLng);
        _spreadPos[m.userId] = show;
        _truePos[m.userId] = center;
      }
    }
  }

  LatLng _showPointFor(_Marker m) =>
      _spreadPos[m.userId] ?? _displayPos[m.userId] ?? m.point;

  void _clearTrails() {
    setState(() => _trails.clear());
    showSnack(context, 'تم مسح الخطوط الحيّة');
  }

  List<Polyline> _buildLiveTrails() {
    final lines = <Polyline>[];
    _trails.forEach((userId, pts) {
      if (pts.length < 2) return;
      final selected = userId == _selectedId;
      lines.add(Polyline(
        points: List<LatLng>.from(pts),
        strokeWidth: selected ? 4 : 3,
        color: selected
            ? const Color(0xFFDC2626)
            : const Color(0xFF2563EB).withValues(alpha: 0.75),
      ));
    });
    // خطوط ربط من الموقع الحقيقي إلى الدبوس المفرَّق
    _spreadPos.forEach((userId, show) {
      final truePt = _truePos[userId];
      if (truePt == null) return;
      if (_haversineMeters(truePt, show) < 3) return;
      lines.add(Polyline(
        points: [truePt, show],
        strokeWidth: 1.6,
        color: const Color(0xFF64748B).withValues(alpha: 0.75),
      ));
    });
    return lines;
  }

  LatLng _pointFor(_Marker m) => _displayPos[m.userId] ?? m.point;

  double _headingFor(_Marker m) {
    final anim = _anims[m.userId];
    if (anim == null) return 0;
    final from = anim.from;
    final to = anim.to;
    final lat1 = from.latitude * math.pi / 180;
    final lat2 = to.latitude * math.pi / 180;
    final dLng = (to.longitude - from.longitude) * math.pi / 180;
    final y = math.sin(dLng) * math.cos(lat2);
    final x = math.cos(lat1) * math.sin(lat2) -
        math.sin(lat1) * math.cos(lat2) * math.cos(dLng);
    return (math.atan2(y, x) * 180 / math.pi + 360) % 360;
  }

  Future<void> _openExternal(_Marker m) async {
    final uri = Uri.tryParse(m.mapUrl);
    if (uri == null) return;
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  void _select(_Marker m) {
    setState(() {
      _selectedId = m.userId;
      _listOpen = false;
      final anim = _anims[m.userId];
      if (anim != null) {
        _anims[m.userId] = anim.copyWith(follow: true);
      }
    });
    _safeMapMove(_showPointFor(m), 15);
  }

  List<_Marker> _matesNear(_Marker m) {
    final p = _pointFor(m);
    return _markers
        .where((o) => _haversineMeters(p, _pointFor(o)) <= _sameSpotMeters)
        .toList();
  }

  Future<void> _onPinTap(_Marker m) async {
    final mates = _matesNear(m);
    if (mates.length <= 1) {
      _select(m);
      return;
    }
    // عدة مندوبين في نفس الموقع — اختيار من قائمة
    final picked = await showModalBottomSheet<_Marker>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(ctx).size.height * 0.55,
          ),
          child: ListView.separated(
            shrinkWrap: true,
            padding: const EdgeInsets.only(bottom: 12),
            itemCount: mates.length + 1,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (_, i) {
              if (i == 0) {
                return const Padding(
                  padding: EdgeInsets.fromLTRB(16, 4, 16, 10),
                  child: Text(
                    'مندوبون في نفس الموقع',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                  ),
                );
              }
              final row = mates[i - 1];
              return ListTile(
                leading: CircleAvatar(
                  backgroundColor: row.color,
                  child: Text(
                    row.initials,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 12,
                    ),
                  ),
                ),
                title: Text(row.label),
                subtitle: Text(
                  '${row.statusLabel} · ${row.ageLabel}',
                  style: const TextStyle(fontSize: 12),
                ),
                onTap: () => Navigator.pop(ctx, row),
              );
            },
          ),
        ),
      ),
    );
    if (picked != null && mounted) _select(picked);
  }

  @override
  Widget build(BuildContext context) {
    final selected = _markers.where((m) => m.userId == _selectedId).toList();
    final selectedMarker = selected.isEmpty ? null : selected.first;

    return Scaffold(
      appBar: AppBar(
        title: const Text('تتبّع المواقع الحية'),
        actions: [
          IconButton(
            tooltip: 'المسار اليومي',
            onPressed: () => context.push('/gps/route'),
            icon: const Icon(Icons.route_rounded),
          ),
          IconButton(
            tooltip: 'مسح الخط الحي',
            onPressed: _trails.isEmpty ? null : _clearTrails,
            icon: const Icon(Icons.clear_all_rounded),
          ),
          IconButton(
            tooltip: 'قائمة الأجهزة',
            onPressed: () => setState(() => _listOpen = !_listOpen),
            icon: const Icon(Icons.view_list_rounded),
          ),
          IconButton(
            tooltip: 'ملاءمة الكل',
            onPressed: _markers.isEmpty ? null : _fitAll,
            icon: const Icon(Icons.zoom_out_map_rounded),
          ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: () {
              _fitOnce = true;
              _load();
            },
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      // لا نُزيل FlutterMap أثناء التحميل — إزالته تُبقي _mapReady=true
      // بينما MapController غير مربوط → إغلاق التطبيق عند move/camera.
      body: Stack(
        children: [
            FlutterMap(
              mapController: _map,
              options: MapOptions(
                initialCenter: const LatLng(31.9539, 35.9106),
                initialZoom: 8,
                minZoom: 4,
                maxZoom: 18,
                onMapReady: () {
                  if (!mounted) return;
                  _mapReady = true;
                  if (_fitOnce && _markers.isNotEmpty) {
                    _fitOnce = false;
                    WidgetsBinding.instance
                        .addPostFrameCallback((_) => _fitAll());
                  }
                },
                onPositionChanged: (camera, _) {
                  final z = camera.zoom;
                  if ((z - _mapZoom).abs() > 0.35 && mounted) {
                    setState(() {
                      _mapZoom = z;
                      _recomputeSpread();
                    });
                  }
                },
                onTap: (_, __) => setState(() => _selectedId = null),
              ),
              children: [
                ...GpsMapTiles.safeLayers(
                  mapProvider: 'osm',
                  zoom: _mapZoom,
                ),
                PolylineLayer(polylines: _buildLiveTrails()),
                MarkerLayer(
                  markers: [
                    for (final m in _markers)
                      Marker(
                        point: _showPointFor(m),
                        width: 96,
                        height: 70,
                        alignment: Alignment.bottomCenter,
                        child: GestureDetector(
                          onTap: () => _onPinTap(m),
                          child: _MapPin(
                            label: m.initials,
                            name: m.shortName,
                            color: m.color,
                            selected: m.userId == _selectedId,
                            heading: _headingFor(m),
                            moving: _anims.containsKey(m.userId),
                            stacked: (_truePos[m.userId] != null) &&
                                _haversineMeters(
                                      _truePos[m.userId]!,
                                      _showPointFor(m),
                                    ) >
                                    3,
                          ),
                        ),
                      ),
                  ],
                ),
              ],
            ),
            if (_loading && _markers.isEmpty)
              const ColoredBox(
                color: Color(0x66FFFFFF),
                child: Center(child: CircularProgressIndicator()),
              ),
            if (_error != null && _markers.isEmpty)
              ColoredBox(
                color: Colors.white,
                child: AsyncView(
                  loading: false,
                  error: _error,
                  onRetry: _load,
                  skeleton: false,
                  child: const SizedBox.shrink(),
                ),
              ),
            Positioned(
              top: 12,
              left: 12,
              right: 12,
              child: Row(
                children: [
                  _StatChip(
                    label: 'متصل',
                    value: '$_online',
                    color: AppTheme.success,
                  ),
                  const SizedBox(width: 8),
                  _StatChip(
                    label: 'على الخريطة',
                    value: '${_markers.length}',
                    color: AppTheme.primary,
                  ),
                ],
              ),
            ),
            Positioned(
              left: 12,
              bottom: 18,
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.94),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AppTheme.border),
                ),
                child: const Row(
                  children: [
                    _LegendDot(AppTheme.success),
                    Text(' متصل · ', style: TextStyle(fontSize: 11.5)),
                    _LegendLine(Color(0xFF2563EB)),
                    Text(' خط حي · ', style: TextStyle(fontSize: 11.5)),
                    _LegendLine(Color(0xFF64748B)),
                    Text(' تفريق نفس الموقع  ',
                        style: TextStyle(fontSize: 11.5)),
                  ],
                ),
              ),
            ),
            if (selectedMarker != null)
              Positioned(
                left: 12,
                right: 12,
                bottom: 64,
                child: _DetailCard(
                  marker: selectedMarker,
                  onOpenMap: () => _openExternal(selectedMarker),
                  onClose: () => setState(() => _selectedId = null),
                ),
              ),
            if (_listOpen)
              Positioned.fill(
                child: _DeviceDrawer(
                  markers: _markers,
                  search: _search,
                  selectedId: _selectedId,
                  onClose: () => setState(() => _listOpen = false),
                  onSearch: () {
                    _fitOnce = true;
                    _load();
                  },
                  onSelect: _select,
                ),
              ),
          ],
      ),
    );
  }
}

class _MoveAnim {
  const _MoveAnim({
    required this.from,
    required this.to,
    required this.start,
    required this.duration,
    required this.follow,
  });

  final LatLng from;
  final LatLng to;
  final DateTime start;
  final Duration duration;
  final bool follow;

  _MoveAnim copyWith({bool? follow}) => _MoveAnim(
        from: from,
        to: to,
        start: start,
        duration: duration,
        follow: follow ?? this.follow,
      );
}

class _MapPin extends StatelessWidget {
  const _MapPin({
    required this.label,
    required this.name,
    required this.color,
    required this.selected,
    this.heading = 0,
    this.moving = false,
    this.stacked = false,
  });

  final String label;
  final String name;
  final Color color;
  final bool selected;
  final double heading;
  final bool moving;
  final bool stacked;

  @override
  Widget build(BuildContext context) {
    final size = selected ? 38.0 : 34.0;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (moving || selected)
          Transform.rotate(
            angle: heading * math.pi / 180,
            child: Icon(
              Icons.navigation_rounded,
              size: 14,
              color: moving ? const Color(0xFF2563EB) : const Color(0xFF0F172A),
            ),
          ),
        Container(
          width: size,
          height: size,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: color,
            shape: BoxShape.circle,
            border: Border.all(
              color: stacked ? const Color(0xFFF59E0B) : Colors.white,
              width: stacked ? 3 : 2.2,
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.22),
                blurRadius: 4,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w900,
            ),
          ),
        ),
        const SizedBox(height: 2),
        Container(
          constraints: const BoxConstraints(maxWidth: 90),
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
          decoration: BoxDecoration(
            color: const Color(0xE00F172A),
            borderRadius: BorderRadius.circular(4),
          ),
          child: Text(
            name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 10,
              fontWeight: FontWeight.w800,
              height: 1.15,
            ),
          ),
        ),
      ],
    );
  }
}

class _StatChip extends StatelessWidget {
  const _StatChip({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.95),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppTheme.border),
        ),
        child: Column(
          children: [
            Text(
              value,
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w900,
                color: color,
              ),
            ),
            Text(
              label,
              style: const TextStyle(fontSize: 10.5, color: AppTheme.textSoft),
            ),
          ],
        ),
      ),
    );
  }
}

class _LegendDot extends StatelessWidget {
  const _LegendDot(this.color);
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 9,
      height: 9,
      margin: const EdgeInsets.only(left: 4),
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    );
  }
}

class _LegendLine extends StatelessWidget {
  const _LegendLine(this.color);
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 16,
      height: 3,
      margin: const EdgeInsets.only(left: 4, right: 2),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(2),
      ),
    );
  }
}

class _DetailCard extends StatelessWidget {
  const _DetailCard({
    required this.marker,
    required this.onOpenMap,
    required this.onClose,
  });

  final _Marker marker;
  final VoidCallback onOpenMap;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return Material(
      elevation: 6,
      borderRadius: BorderRadius.circular(16),
      color: Colors.white,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
        child: Row(
          children: [
            MiniIcon(
              Icons.person_pin_circle_rounded,
              color: marker.color,
              size: 42,
              iconSize: 22,
              radius: 14,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    marker.label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 14.5,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    '${marker.statusLabel} · ${marker.ageLabel}'
                    '${marker.sourceLabel.isNotEmpty ? ' · ${marker.sourceLabel}' : ''}',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppTheme.textSoft,
                    ),
                  ),
                  if (marker.accuracyLabel.isNotEmpty ||
                      marker.capturedAt.isNotEmpty)
                    Text(
                      [
                        if (marker.accuracyLabel.isNotEmpty)
                          'دقة ${marker.accuracyLabel}',
                        if (marker.capturedAt.isNotEmpty) marker.capturedAt,
                      ].join(' · '),
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: AppTheme.textSoft,
                      ),
                    ),
                ],
              ),
            ),
            IconButton(
              tooltip: 'Google Maps',
              onPressed: onOpenMap,
              icon: const Icon(Icons.map_outlined, color: AppTheme.teal),
            ),
            IconButton(
              tooltip: 'إغلاق',
              onPressed: onClose,
              icon: const Icon(Icons.close_rounded, size: 20),
            ),
          ],
        ),
      ),
    );
  }
}

class _DeviceDrawer extends StatelessWidget {
  const _DeviceDrawer({
    required this.markers,
    required this.search,
    required this.selectedId,
    required this.onClose,
    required this.onSearch,
    required this.onSelect,
  });

  final List<_Marker> markers;
  final TextEditingController search;
  final int? selectedId;
  final VoidCallback onClose;
  final VoidCallback onSearch;
  final ValueChanged<_Marker> onSelect;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.black45,
      child: SafeArea(
        child: Align(
          alignment: Alignment.centerRight,
          child: Container(
            width: MediaQuery.of(context).size.width * 0.86,
            height: double.infinity,
            color: Colors.white,
            child: Column(
              children: [
                ListTile(
                  title: const Text(
                    'المتصلون الآن',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                  trailing: IconButton(
                    onPressed: onClose,
                    icon: const Icon(Icons.close_rounded),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
                  child: TextField(
                    controller: search,
                    textInputAction: TextInputAction.search,
                    decoration: const InputDecoration(
                      hintText: 'بحث بالاسم...',
                      prefixIcon: Icon(Icons.search_rounded, size: 20),
                    ),
                    onSubmitted: (_) => onSearch(),
                  ),
                ),
                const Divider(height: 1),
                Expanded(
                  child: markers.isEmpty
                      ? const EmptyState(
                          message:
                              'لا يوجد أحد متصل الآن.\nفعّل تتبّع الموقع على هواتف المندوبين.',
                          icon: Icons.location_off_rounded,
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.all(10),
                          itemCount: markers.length,
                          itemBuilder: (_, i) {
                            final m = markers[i];
                            final sel = m.userId == selectedId;
                            return AppCard(
                              onTap: () => onSelect(m),
                              padding: const EdgeInsets.all(10),
                              margin: const EdgeInsets.only(bottom: 8),
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    radius: 18,
                                    backgroundColor: m.color,
                                    child: Text(
                                      m.initials,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 11,
                                        fontWeight: FontWeight.w900,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          m.label,
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: TextStyle(
                                            fontWeight: FontWeight.w800,
                                            color: sel
                                                ? AppTheme.primary
                                                : AppTheme.textMain,
                                          ),
                                        ),
                                        Text(
                                          '${m.ageLabel}${m.sourceLabel.isNotEmpty ? ' · ${m.sourceLabel}' : ''}',
                                          style: const TextStyle(
                                            fontSize: 11.5,
                                            color: AppTheme.textSoft,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                  StatusPill(
                                    text: m.statusLabel,
                                    color: m.color,
                                  ),
                                ],
                              ),
                            );
                          },
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
