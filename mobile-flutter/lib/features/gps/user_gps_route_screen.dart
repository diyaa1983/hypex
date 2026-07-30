import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import 'gps_map_tiles.dart';
import '../../widgets/async_view.dart';

class _TrackPoint {
  _TrackPoint(this.data);
  final Map<String, dynamic> data;

  double get lat => (data['latitude'] as num?)?.toDouble() ?? 0;
  double get lng => (data['longitude'] as num?)?.toDouble() ?? 0;
  String get time => (data['time'] ?? '').toString();
  String get timeFull => (data['time_full'] ?? data['time'] ?? '').toString();
  String get accuracyLabel => (data['accuracy_label'] ?? '').toString();
  String get speedLabel => (data['speed_label'] ?? '').toString();
  double? get speedKmh => (data['speed_kmh'] as num?)?.toDouble();
  LatLng get point => LatLng(lat, lng);
}

class _Stop {
  _Stop(this.data);
  final Map<String, dynamic> data;

  double get lat => (data['latitude'] as num?)?.toDouble() ?? 0;
  double get lng => (data['longitude'] as num?)?.toDouble() ?? 0;
  String get arrive => (data['arrive'] ?? '').toString();
  String get leave => (data['leave'] ?? '').toString();
  String get durationLabel => (data['duration_label'] ?? '').toString();
  LatLng get point => LatLng(lat, lng);
}

class _UserOption {
  _UserOption(this.id, this.label);
  final int id;
  final String label;
}

/// خط السير اليومي لمندوب — المسار على الخريطة مع أوقات التوقف.
class UserGpsRouteScreen extends StatefulWidget {
  const UserGpsRouteScreen({super.key});

  @override
  State<UserGpsRouteScreen> createState() => _UserGpsRouteScreenState();
}

class _UserGpsRouteScreenState extends State<UserGpsRouteScreen> {
  final _map = MapController();

  bool _loading = true;
  String? _error;
  String _tileUrl = GpsMapTiles.esriUrl;
  String _mapProvider = 'esri';
  double _mapZoom = 8;

  List<_UserOption> _users = [];
  int? _userId;
  DateTime _date = DateTime.now();

  List<_TrackPoint> _points = [];
  List<_Stop> _stops = [];
  List<List<LatLng>> _roadPaths = [];
  List<List<LatLng>> _segmentPaths = [];
  List<_Stop> _presence = [];
  bool _roadMatched = false;
  String _userLabel = '';
  Map<String, dynamic> _summary = {};
  bool _trackLoading = false;

  @override
  void initState() {
    super.initState();
    _loadUsers();
  }

  @override
  void dispose() {
    _map.dispose();
    super.dispose();
  }

  String get _dateIso =>
      '${_date.year.toString().padLeft(4, '0')}-${_date.month.toString().padLeft(2, '0')}-${_date.day.toString().padLeft(2, '0')}';

  bool get _isToday {
    final now = DateTime.now();
    return _date.year == now.year &&
        _date.month == now.month &&
        _date.day == now.day;
  }

  Future<void> _loadUsers() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.userGpsTrackDayPath,
      );
      if (!mounted) return;
      final mapCfg = (res['map'] as Map?)?.cast<String, dynamic>() ?? {};
      final tile = (mapCfg['tile_url'] ?? '').toString();
      final users = (res['users'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _UserOption(
                (e['user_id'] as num?)?.toInt() ?? 0,
                (e['user_label'] ?? '').toString(),
              ))
          .where((u) => u.id > 0)
          .toList();
      setState(() {
        if (tile.isNotEmpty) _tileUrl = tile;
        _mapProvider = (mapCfg['map_provider'] ?? 'esri').toString();
        _users = users;
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

  Future<void> _loadTrack() async {
    final uid = _userId;
    if (uid == null) return;
    setState(() {
      _trackLoading = true;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.userGpsTrackDayPath,
        query: {'user_id': uid, 'date': _dateIso},
      );
      if (!mounted) return;
      final points = (res['points'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _TrackPoint(e.cast<String, dynamic>()))
          .toList();
      final stops = (res['stops'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _Stop(e.cast<String, dynamic>()))
          .toList();
      final presence = (res['presence'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _Stop(e.cast<String, dynamic>()))
          .toList();
      var trackLines = _parseTrackLines(res['track_lines'], res['road_paths'], res['road_path']);
      final segmentPaths = <List<LatLng>>[];
      if (trackLines.isEmpty) {
        final rawSegs = res['segments'];
        if (rawSegs is List) {
          for (final seg in rawSegs) {
            if (seg is! List || seg.length < 2) continue;
            final pts = <LatLng>[];
            for (final idx in seg) {
              final i = (idx as num?)?.toInt();
              if (i == null || i < 0 || i >= points.length) continue;
              pts.add(points[i].point);
            }
            if (pts.length >= 2) segmentPaths.add(pts);
          }
        }
      }
      if (trackLines.isEmpty && segmentPaths.isEmpty && points.length >= 2) {
        segmentPaths.add(points.map((p) => p.point).toList());
      }

      var matched = res['road_matched'] == true && trackLines.isNotEmpty;
      // إن لم يلتصق السيرفر بالشارع جيداً: مطابقة OSRM بأجزاء لتغطية كامل اليوم.
      final needClientSnap = !matched ||
          trackLines.length < 2 && points.length > 400;
      if (needClientSnap && points.length >= 2) {
        final jobs = <List<_TrackPoint>>[];
        final rawSegs = res['segments'];
        if (rawSegs is List && rawSegs.isNotEmpty) {
          for (final seg in rawSegs) {
            if (seg is! List || seg.length < 2) continue;
            final pts = <_TrackPoint>[];
            for (final idx in seg) {
              final i = (idx as num?)?.toInt();
              if (i == null || i < 0 || i >= points.length) continue;
              pts.add(points[i]);
            }
            if (pts.length >= 2) {
              jobs.addAll(_splitTrackPoints(pts, 280, 5400));
            }
          }
        }
        if (jobs.isEmpty) {
          jobs.addAll(_splitTrackPoints(points, 280, 5400));
        }
        final snapped = <List<LatLng>>[];
        for (final job in jobs) {
          final gpsPath = job.map((p) => p.point).toList();
          final path = await _osrmSnapPoints(gpsPath);
          if (path.length >= 2 &&
              _pathLength(path) >= _pathLength(gpsPath) * 0.55) {
            snapped.add(path);
          } else if (gpsPath.length >= 2) {
            snapped.add(gpsPath);
          }
        }
        if (snapped.isNotEmpty) {
          trackLines = snapped;
          matched = true;
        }
      }

      if (!mounted) return;
      setState(() {
        _points = points;
        _stops = stops;
        _presence = presence;
        _segmentPaths = segmentPaths;
        _roadPaths = trackLines.isNotEmpty ? trackLines : segmentPaths;
        _roadMatched = matched;
        _userLabel = (res['user_label'] ?? '').toString();
        _summary = (res['summary'] as Map?)?.cast<String, dynamic>() ?? {};
        _trackLoading = false;
      });
      if (points.isNotEmpty) {
        WidgetsBinding.instance.addPostFrameCallback((_) => _fit());
      } else {
        showSnack(context, 'لا توجد نقاط مسجّلة في هذا اليوم');
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _trackLoading = false);
      showSnack(context, e.message, error: true);
    }
  }

  void _fit() {
    final fitPts = <LatLng>[];
    for (final path in _roadPaths) {
      fitPts.addAll(path);
    }
    if (fitPts.isEmpty) {
      fitPts.addAll(_points.map((p) => p.point));
    }
    if (fitPts.isEmpty) return;
    if (fitPts.length == 1) {
      _map.move(fitPts.first, 15);
      return;
    }
    final bounds = LatLngBounds.fromPoints(fitPts);
    _map.fitCamera(
      CameraFit.bounds(bounds: bounds, padding: const EdgeInsets.all(48)),
    );
  }

  double _haversine(LatLng a, LatLng b) {
    const r = 6371000.0;
    final p1 = a.latitude * math.pi / 180;
    final p2 = b.latitude * math.pi / 180;
    final dp = (b.latitude - a.latitude) * math.pi / 180;
    final dl = (b.longitude - a.longitude) * math.pi / 180;
    final h = math.sin(dp / 2) * math.sin(dp / 2) +
        math.cos(p1) * math.cos(p2) * math.sin(dl / 2) * math.sin(dl / 2);
    return r * 2 * math.atan2(math.sqrt(h), math.sqrt(1 - h));
  }

  double _pathLength(List<LatLng> path) {
    var total = 0.0;
    for (var i = 1; i < path.length; i++) {
      total += _haversine(path[i - 1], path[i]);
    }
    return total;
  }

  List<List<_TrackPoint>> _splitTrackPoints(
    List<_TrackPoint> points,
    int maxPoints,
    int maxSpanSec,
  ) {
    if (points.length < 2) {
      return points.isEmpty ? const [] : [points];
    }
    final chunks = <List<_TrackPoint>>[];
    var start = 0;
    while (start < points.length) {
      var end = start;
      final t0 = _pointTs(points[start]);
      while (end + 1 < points.length) {
        final next = end + 1;
        final t1 = _pointTs(points[next]);
        final span = (t0 > 0 && t1 > 0) ? t1 - t0 : 0;
        final count = next - start + 1;
        if (count > maxPoints || (maxSpanSec > 0 && span > maxSpanSec)) break;
        end = next;
      }
      if (end <= start && start + 1 < points.length) end = start + 1;
      chunks.add(points.sublist(start, end + 1));
      if (end >= points.length - 1) break;
      start = end;
    }
    return chunks;
  }

  int _pointTs(_TrackPoint p) {
    final raw = p.data['ts'];
    if (raw is num) return raw.toInt();
    final captured = (p.data['captured_at'] ?? '').toString();
    if (captured.isEmpty) return 0;
    final ms = DateTime.tryParse(captured)?.millisecondsSinceEpoch;
    return ms == null ? 0 : ms ~/ 1000;
  }

  Future<List<LatLng>> _osrmSnapPoints(List<LatLng> points) async {
    if (points.length < 2) return const [];
    final coords = <LatLng>[];
    LatLng? prev;
    for (final p in points) {
      if (prev != null && _haversine(prev, p) < 15) continue;
      coords.add(p);
      prev = p;
    }
    if (coords.length < 2) return const [];

    final waypoints = <LatLng>[coords.first];
    var last = coords.first;
    for (var i = 1; i < coords.length - 1; i++) {
      if (_haversine(last, coords[i]) >= 45) {
        waypoints.add(coords[i]);
        last = coords[i];
      }
    }
    waypoints.add(coords.last);

    final matched = await _osrmRouteWaypoints(waypoints);
    if (matched.length >= 2) return matched;
    final mapMatched = await _osrmMatch(waypoints);
    if (mapMatched.length >= 2) return mapMatched;
    return _osrmRoutePairs(waypoints);
  }

  Future<List<LatLng>> _osrmRoutePairs(List<LatLng> waypoints) async {
    if (waypoints.length < 2) return const [];
    final out = <LatLng>[];
    for (var i = 1; i < waypoints.length; i++) {
      final part = await _osrmRouteOnce([waypoints[i - 1], waypoints[i]]);
      if (part.isEmpty) {
        out.add(waypoints[i - 1]);
        out.add(waypoints[i]);
        continue;
      }
      _appendPath(out, part);
    }
    return out;
  }

  Future<List<LatLng>> _osrmMatch(List<LatLng> waypoints) async {
    if (waypoints.length < 2) return const [];
    final out = <LatLng>[];
    const size = 80;
    for (var offset = 0; offset < waypoints.length; offset += size - 1) {
      final end = math.min(offset + size, waypoints.length);
      final slice = waypoints.sublist(offset, end);
      if (slice.length < 2) break;
      final part = await _osrmMatchOnce(slice);
      _appendPath(out, part);
      if (end >= waypoints.length) break;
    }
    return out;
  }

  Future<List<LatLng>> _osrmRouteWaypoints(List<LatLng> waypoints) async {
    if (waypoints.length < 2) return const [];
    final out = <LatLng>[];
    const size = 40;
    for (var offset = 0; offset < waypoints.length; offset += size - 1) {
      final end = math.min(offset + size, waypoints.length);
      final slice = waypoints.sublist(offset, end);
      if (slice.length < 2) break;
      final part = await _osrmRouteOnce(slice);
      _appendPath(out, part);
      if (end >= waypoints.length) break;
    }
    return out;
  }

  void _appendPath(List<LatLng> acc, List<LatLng> part) {
    if (part.isEmpty) return;
    var start = 0;
    if (acc.isNotEmpty) {
      final last = acc.last;
      final first = part.first;
      if ((last.latitude - first.latitude).abs() < 0.00002 &&
          (last.longitude - first.longitude).abs() < 0.00002) {
        start = 1;
      }
    }
    acc.addAll(part.sublist(start));
  }

  Future<List<LatLng>> _osrmMatchOnce(List<LatLng> pts) async {
    final coordStr = pts
        .map((p) => '${p.longitude.toStringAsFixed(6)},${p.latitude.toStringAsFixed(6)}')
        .join(';');
    final url =
        'https://router.project-osrm.org/match/v1/driving/$coordStr?overview=full&geometries=geojson&gaps=ignore';
    return _osrmFetchCoords(url, matchings: true);
  }

  Future<List<LatLng>> _osrmRouteOnce(List<LatLng> pts) async {
    final coordStr = pts
        .map((p) => '${p.longitude.toStringAsFixed(6)},${p.latitude.toStringAsFixed(6)}')
        .join(';');
    final url =
        'https://router.project-osrm.org/route/v1/driving/$coordStr?overview=full&geometries=geojson&continue_straight=true';
    return _osrmFetchCoords(url, matchings: false);
  }

  Future<List<LatLng>> _osrmFetchCoords(String url, {required bool matchings}) async {
    try {
      final dio = Dio(BaseOptions(
        connectTimeout: const Duration(seconds: 12),
        receiveTimeout: const Duration(seconds: 25),
        headers: {'Accept': 'application/json'},
      ));
      final res = await dio.get<Map<String, dynamic>>(url);
      final data = res.data;
      if (data == null || data['code'] != 'Ok') return const [];
      final coords = <List<dynamic>>[];
      if (matchings) {
        final list = data['matchings'] as List? ?? [];
        for (final m in list) {
          if (m is! Map) continue;
          final geom = m['geometry'];
          if (geom is! Map) continue;
          final c = geom['coordinates'];
          if (c is List) coords.addAll(c.whereType<List>());
        }
      } else {
        final routes = data['routes'] as List? ?? [];
        if (routes.isEmpty || routes.first is! Map) return const [];
        final geom = (routes.first as Map)['geometry'];
        if (geom is! Map) return const [];
        final c = geom['coordinates'];
        if (c is List) coords.addAll(c.whereType<List>());
      }
      final out = <LatLng>[];
      for (final c in coords) {
        if (c.length < 2) continue;
        final lng = (c[0] as num?)?.toDouble();
        final lat = (c[1] as num?)?.toDouble();
        if (lat == null || lng == null) continue;
        out.add(LatLng(lat, lng));
      }
      return out;
    } catch (_) {
      return const [];
    }
  }

  List<List<LatLng>> _parseTrackLines(
    Object? rawTrackLines,
    Object? rawRoadPaths,
    Object? rawRoadPath,
  ) {
    final lines = <List<LatLng>>[];
    void addPathList(Object? source) {
      if (source is! List) return;
      for (final path in source) {
        if (path is! List) continue;
        final pts = <LatLng>[];
        for (final p in path) {
          if (p is! Map) continue;
          final lat = (p['latitude'] as num?)?.toDouble();
          final lng = (p['longitude'] as num?)?.toDouble();
          if (lat == null || lng == null) continue;
          pts.add(LatLng(lat, lng));
        }
        if (pts.length >= 2) lines.add(pts);
      }
    }

    addPathList(rawTrackLines);
    if (lines.isEmpty) addPathList(rawRoadPaths);
    if (lines.isEmpty) addPathList(rawRoadPath);
    return lines;
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now().subtract(const Duration(days: 60)),
      lastDate: DateTime.now(),
      locale: const Locale('ar'),
    );
    if (picked != null) {
      setState(() => _date = picked);
      if (_userId != null) _loadTrack();
    }
  }

  void _shiftDay(int days) {
    final next = _date.add(Duration(days: days));
    if (next.isAfter(DateTime.now())) return;
    setState(() => _date = next);
    if (_userId != null) _loadTrack();
  }

  List<Polyline> _buildPolylines() {
    final lines = <Polyline>[];
    for (final path in _roadPaths) {
      if (path.length < 2) continue;
      // خط واحد نظيف — بدون طبقة ظل مزدوجة.
      lines.add(Polyline(
        points: path,
        strokeWidth: 5,
        color: const Color(0xFF1D4ED8),
      ));
    }
    return lines;
  }

  Color _speedColor(double? kmh) {
    final s = kmh ?? 0;
    if (s < 3) return const Color(0xFF94A3B8);
    if (s < 40) return const Color(0xFF16A34A);
    if (s < 80) return const Color(0xFFF59E0B);
    return const Color(0xFFDC2626);
  }

  List<Polyline> _buildSpeedPolylines() {
    final lines = <Polyline>[];
    for (var i = 1; i < _points.length; i++) {
      final prev = _points[i - 1];
      final cur = _points[i];
      lines.add(Polyline(
        points: [prev.point, cur.point],
        strokeWidth: 5,
        color: _speedColor(cur.speedKmh),
      ));
    }
    return lines;
  }

  void _openStops() {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (_) => _StopsSheet(
        stops: _stops,
        onSelect: (s) {
          Navigator.of(context).pop();
          _map.move(s.point, 17);
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final distance = (_summary['distance_label'] ?? '—').toString();
    final first = (_summary['first_time'] ?? '—').toString();
    final last = (_summary['last_time'] ?? '—').toString();
    final active = (_summary['active_label'] ?? '—').toString();
    final avgSpeed = (_summary['avg_speed_label'] ?? '—').toString();
    final maxSpeed = (_summary['max_speed_label'] ?? '—').toString();
    final coverageNote = (_summary['coverage_note'] ?? '').toString();
    final stopsCount = (_summary['stops_count'] as num?)?.toInt() ?? 0;

    return Scaffold(
      appBar: AppBar(
        title: const Text('المسار اليومي'),
        actions: [
          IconButton(
            tooltip: 'ملاءمة',
            onPressed: _points.isEmpty ? null : _fit,
            icon: const Icon(Icons.zoom_out_map_rounded),
          ),
        ],
      ),
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _loadUsers,
        skeleton: false,
        child: Column(
          children: [
            _buildControls(),
            if (_points.isNotEmpty)
              _buildSummary(
                  distance, first, last, active, avgSpeed, maxSpeed, stopsCount,
                  coverageNote),
            Expanded(
              child: Stack(
                children: [
                  FlutterMap(
                    mapController: _map,
                    options: MapOptions(
                      initialCenter: LatLng(31.9539, 35.9106),
                      initialZoom: 8,
                      minZoom: 4,
                      maxZoom: 20,
                      onMapEvent: (e) {
                        final z = _map.camera.zoom;
                        if ((z - _mapZoom).abs() > 0.01) {
                          setState(() => _mapZoom = z);
                        }
                      },
                    ),
                    children: [
                      ...GpsMapTiles.layers(
                        mapProvider: _mapProvider,
                        tileUrl: _tileUrl,
                        zoom: _mapZoom,
                      ),
                      PolylineLayer(
                        polylines: _roadMatched
                            ? _buildPolylines()
                            : _buildSpeedPolylines(),
                      ),
                      MarkerLayer(markers: _buildMarkers()),
                    ],
                  ),
                  if (_trackLoading)
                    const Positioned(
                      top: 12,
                      left: 0,
                      right: 0,
                      child: Center(
                        child: Card(
                          child: Padding(
                            padding: EdgeInsets.symmetric(
                                horizontal: 16, vertical: 8),
                            child: Text('جاري تحميل المسار...'),
                          ),
                        ),
                      ),
                    ),
                  if (_points.isEmpty && !_trackLoading)
                    const Center(
                      child: Padding(
                        padding: EdgeInsets.all(24),
                        child: EmptyState(
                          message:
                              'اختر المندوب والتاريخ لعرض خط السير اليومي.',
                          icon: Icons.route_rounded,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
      floatingActionButton: _stops.isEmpty
          ? null
          : FloatingActionButton.extended(
              onPressed: _openStops,
              icon: const Icon(Icons.pause_circle_outline_rounded),
              label: Text('التوقفات ($stopsCount)'),
            ),
    );
  }

  Widget _buildControls() {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      color: AppTheme.surfaceAlt,
      child: Row(
        children: [
          Expanded(
            child: DropdownButtonFormField<int>(
              initialValue: _userId,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'المندوب',
                isDense: true,
              ),
              items: _users
                  .map((u) => DropdownMenuItem<int>(
                        value: u.id,
                        child: Text(
                          u.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ))
                  .toList(),
              onChanged: (v) {
                setState(() => _userId = v);
                if (v != null) _loadTrack();
              },
            ),
          ),
          const SizedBox(width: 8),
          IconButton.filledTonal(
            onPressed: () => _shiftDay(-1),
            icon: const Icon(Icons.chevron_right_rounded),
            tooltip: 'اليوم السابق',
          ),
          InkWell(
            onTap: _pickDate,
            borderRadius: BorderRadius.circular(10),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
              child: Column(
                children: [
                  const Icon(Icons.calendar_today_rounded, size: 16),
                  const SizedBox(height: 2),
                  Text(
                    '${_date.day}/${_date.month}',
                    style: const TextStyle(
                        fontSize: 12, fontWeight: FontWeight.w800),
                  ),
                ],
              ),
            ),
          ),
          IconButton.filledTonal(
            onPressed: _isToday ? null : () => _shiftDay(1),
            icon: const Icon(Icons.chevron_left_rounded),
            tooltip: 'اليوم التالي',
          ),
        ],
      ),
    );
  }

  Widget _buildSummary(String distance, String first, String last, String active,
      String avgSpeed, String maxSpeed, int stops, String coverageNote) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
      color: Colors.white,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _chip('المسافة', distance, AppTheme.primary),
              _chip('من', first, AppTheme.teal),
              _chip('إلى', last, AppTheme.teal),
              _chip('المدة', active, AppTheme.success),
              _chip('متوسط السرعة', avgSpeed, const Color(0xFF2563EB)),
              _chip('أقصى سرعة', maxSpeed, const Color(0xFFDC2626)),
              _chip('التوقفات', '$stops', AppTheme.warn),
            ],
          ),
          if (coverageNote.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              coverageNote,
              style: const TextStyle(
                fontSize: 12,
                color: Color(0xFF9A3412),
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _chip(String k, String v, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.30)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(k,
              style: const TextStyle(fontSize: 11.5, color: AppTheme.textSoft)),
          const SizedBox(width: 6),
          Text(v,
              style: TextStyle(
                  fontSize: 12.5, fontWeight: FontWeight.w900, color: color)),
        ],
      ),
    );
  }

  void _focusPoint(LatLng point, {double zoom = 17}) {
    final targetZoom = _map.camera.zoom < zoom ? zoom : _map.camera.zoom;
    _map.move(point, targetZoom);
  }

  List<Marker> _buildMarkers() {
    final markers = <Marker>[];

    for (var i = 0; i < _stops.length; i++) {
      final s = _stops[i];
      markers.add(Marker(
        point: s.point,
        width: 28,
        height: 28,
        alignment: Alignment.topCenter,
        child: GestureDetector(
          onTap: () => _focusPoint(s.point),
          child: _GpsPin(label: '${i + 1}', color: AppTheme.warn),
        ),
      ));
    }

    for (final p in _presence) {
      markers.add(Marker(
        point: p.point,
        width: 28,
        height: 28,
        alignment: Alignment.topCenter,
        child: GestureDetector(
          onTap: () => _focusPoint(p.point),
          child: const _GpsPin(label: '•', color: Color(0xFF7C3AED)),
        ),
      ));
    }

    if (_points.isNotEmpty) {
      final start = _points.first.point;
      final end = _points.last.point;
      markers.add(Marker(
        point: start,
        width: 28,
        height: 28,
        alignment: Alignment.topCenter,
        child: GestureDetector(
          onTap: () => _focusPoint(start),
          child: const _GpsPin(label: 'ب', color: AppTheme.success),
        ),
      ));
      markers.add(Marker(
        point: end,
        width: 28,
        height: 28,
        alignment: Alignment.topCenter,
        child: GestureDetector(
          onTap: () => _focusPoint(end),
          child: const _GpsPin(label: 'ن', color: Color(0xFFDC2626)),
        ),
      ));
    }

    return markers;
  }
}

class _GpsPin extends StatelessWidget {
  const _GpsPin({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Transform.rotate(
      angle: -0.78539816339,
      child: Container(
        width: 28,
        height: 28,
        decoration: BoxDecoration(
          color: color,
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(14),
            topRight: Radius.circular(14),
            bottomRight: Radius.circular(14),
          ),
          border: Border.all(color: Colors.white, width: 2),
          boxShadow: [
            BoxShadow(
              color: color.withValues(alpha: 0.35),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Transform.rotate(
          angle: 0.78539816339,
          child: Center(
            child: Text(
              label,
              style: TextStyle(
                color: Colors.white,
                fontSize: label == '•' ? 14 : 11,
                fontWeight: FontWeight.w900,
                height: 1,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _StopsSheet extends StatelessWidget {
  const _StopsSheet({required this.stops, required this.onSelect});

  final List<_Stop> stops;
  final ValueChanged<_Stop> onSelect;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: ListView.separated(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 16),
        itemCount: stops.length,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (_, i) {
          final s = stops[i];
          return ListTile(
            leading: CircleAvatar(
              backgroundColor: AppTheme.warn,
              radius: 16,
              child: Text(
                '${i + 1}',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: 13,
                ),
              ),
            ),
            title: Text(
              '${s.arrive} — ${s.leave}',
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text('توقّف ${s.durationLabel}'),
            trailing: const Icon(Icons.my_location_rounded, size: 20),
            onTap: () => onSelect(s),
          );
        },
      ),
    );
  }
}
