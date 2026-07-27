import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

class _TrackPoint {
  _TrackPoint(this.data);
  final Map<String, dynamic> data;

  double get lat => (data['latitude'] as num?)?.toDouble() ?? 0;
  double get lng => (data['longitude'] as num?)?.toDouble() ?? 0;
  String get time => (data['time'] ?? '').toString();
  String get timeFull => (data['time_full'] ?? data['time'] ?? '').toString();
  String get accuracyLabel => (data['accuracy_label'] ?? '').toString();
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
  String _tileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

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
      final segmentPaths = <List<LatLng>>[];
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
      final roadPaths = <List<LatLng>>[];
      final rawPaths = res['road_paths'];
      if (rawPaths is List) {
        for (final path in rawPaths) {
          if (path is! List) continue;
          final pts = <LatLng>[];
          for (final p in path) {
            if (p is! Map) continue;
            final lat = (p['latitude'] as num?)?.toDouble();
            final lng = (p['longitude'] as num?)?.toDouble();
            if (lat == null || lng == null) continue;
            pts.add(LatLng(lat, lng));
          }
          if (pts.length >= 2) roadPaths.add(pts);
        }
      }
      if (roadPaths.isEmpty) {
        final road = <LatLng>[];
        for (final p in (res['road_path'] as List? ?? [])) {
          if (p is! Map) continue;
          final lat = (p['latitude'] as num?)?.toDouble();
          final lng = (p['longitude'] as num?)?.toDouble();
          if (lat == null || lng == null) continue;
          road.add(LatLng(lat, lng));
        }
        if (road.length >= 2) roadPaths.add(road);
      }
      if (roadPaths.isEmpty && segmentPaths.isEmpty && points.length >= 2) {
        segmentPaths.add(points.map((p) => p.point).toList());
      }
      final matched = res['road_matched'] == true && roadPaths.isNotEmpty;
      setState(() {
        _points = points;
        _stops = stops;
        _presence = presence;
        _segmentPaths = segmentPaths;
        _roadPaths = roadPaths;
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
    for (final path in _segmentPaths) {
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
    void addPath(List<LatLng> path) {
      if (path.length < 2) return;
      lines.add(Polyline(
        points: path,
        strokeWidth: 12,
        color: const Color(0xFF93C5FD).withValues(alpha: 0.55),
      ));
      lines.add(Polyline(
        points: path,
        strokeWidth: 6,
        color: const Color(0xFF1D4ED8),
      ));
    }

    for (final path in _segmentPaths) {
      addPath(path);
    }
    for (final path in _roadPaths) {
      addPath(path);
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
          _map.move(s.point, 16);
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
            if (_points.isNotEmpty) _buildSummary(distance, first, last, active, stopsCount),
            Expanded(
              child: Stack(
                children: [
                  FlutterMap(
                    mapController: _map,
                    options: const MapOptions(
                      initialCenter: LatLng(31.9539, 35.9106),
                      initialZoom: 8,
                      minZoom: 4,
                      maxZoom: 18,
                    ),
                    children: [
                      TileLayer(
                        urlTemplate: _tileUrl,
                        userAgentPackageName: 'com.gppjo.biodev.mobile',
                      ),
                      PolylineLayer(polylines: _buildPolylines()),
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

  Widget _buildSummary(
      String distance, String first, String last, String active, int stops) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
      color: Colors.white,
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          _chip('المسافة', distance, AppTheme.primary),
          _chip('من', first, AppTheme.teal),
          _chip('إلى', last, AppTheme.teal),
          _chip('المدة', active, AppTheme.success),
          _chip('التوقفات', '$stops', AppTheme.warn),
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

  List<Marker> _buildMarkers() {
    final markers = <Marker>[];
    final name = _userLabel;

    for (var i = 0; i < _stops.length; i++) {
      final s = _stops[i];
      markers.add(Marker(
        point: s.point,
        width: 130,
        height: 46,
        child: _LabelPin(
          title: 'توقف ${i + 1}',
          name: name,
          color: AppTheme.warn,
        ),
      ));
    }

    for (final p in _presence) {
      markers.add(Marker(
        point: p.point,
        width: 130,
        height: 46,
        child: _LabelPin(
          title: 'توقف',
          name: name,
          color: const Color(0xFF7C3AED),
        ),
      ));
    }

    if (_points.isNotEmpty) {
      markers.add(Marker(
        point: _points.first.point,
        width: 130,
        height: 46,
        child: _LabelPin(
          title: 'البداية',
          name: name,
          color: AppTheme.success,
        ),
      ));
      markers.add(Marker(
        point: _points.last.point,
        width: 130,
        height: 46,
        child: _LabelPin(
          title: 'النهاية',
          name: name,
          color: const Color(0xFFDC2626),
        ),
      ));
    }

    return markers;
  }
}

class _LabelPin extends StatelessWidget {
  const _LabelPin({
    required this.title,
    required this.name,
    required this.color,
  });

  final String title;
  final String name;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.white, width: 2),
        boxShadow: [
          BoxShadow(
            color: color.withValues(alpha: 0.4),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w900,
            ),
          ),
          if (name.isNotEmpty)
            Text(
              name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 10,
                fontWeight: FontWeight.w700,
              ),
            ),
        ],
      ),
    );
  }
}

class _NumberPin extends StatelessWidget {
  const _NumberPin({required this.number, required this.color});
  final String number;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return _LabelPin(title: number, name: '', color: color);
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
