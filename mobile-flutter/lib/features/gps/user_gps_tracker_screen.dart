import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/theme.dart';
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
  String get status => (data['status'] ?? (online ? 'online' : 'away')).toString();
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
      case 'away':
        return AppTheme.warn;
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
  bool _loading = true;
  String? _error;
  String _tileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  List<_Marker> _markers = [];
  int _online = 0;
  int? _selectedId;
  bool _fitOnce = true;
  bool _listOpen = false;

  @override
  void initState() {
    super.initState();
    _load();
    _poll = Timer.periodic(const Duration(seconds: 5), (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _poll?.cancel();
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
          'online_seconds': 600,
          'stale_seconds': 600,
          'include_stale': 0,
          'q': _search.text.trim(),
        },
      );
      if (!mounted) return;
      final mapCfg = (res['map'] as Map?)?.cast<String, dynamic>() ?? {};
      final tile = (mapCfg['tile_url'] ?? '').toString();
      final counts = (res['counts'] as Map?)?.cast<String, dynamic>() ?? {};
      final rows = (res['markers'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _Marker(e.cast<String, dynamic>()))
          .where((m) => m.lat != 0 || m.lng != 0)
          .toList();

      setState(() {
        if (tile.isNotEmpty) _tileUrl = tile;
        _markers = rows;
        _online = (counts['online'] as num?)?.toInt() ??
            rows.where((m) => m.online).length;
        _loading = false;
        _error = null;
      });

      if (_fitOnce && rows.isNotEmpty) {
        _fitOnce = false;
        WidgetsBinding.instance.addPostFrameCallback((_) => _fitAll());
      } else if (_selectedId != null) {
        final sel = rows.where((m) => m.userId == _selectedId).toList();
        if (sel.isNotEmpty) {
          WidgetsBinding.instance.addPostFrameCallback((_) {
            _map.move(sel.first.point, _map.camera.zoom);
          });
        }
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

  void _fitAll() {
    if (_markers.isEmpty) return;
    if (_markers.length == 1) {
      _map.move(_markers.first.point, 14);
      return;
    }
    final bounds = LatLngBounds.fromPoints(_markers.map((m) => m.point).toList());
    _map.fitCamera(
      CameraFit.bounds(bounds: bounds, padding: const EdgeInsets.all(48)),
    );
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
    });
    _map.move(m.point, 15);
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
      body: AsyncView(
        loading: _loading && _markers.isEmpty,
        error: _error,
        onRetry: _load,
        skeleton: false,
        child: Stack(
          children: [
            FlutterMap(
              mapController: _map,
              options: MapOptions(
                initialCenter: const LatLng(31.9539, 35.9106),
                initialZoom: 8,
                minZoom: 4,
                maxZoom: 18,
                onTap: (_, __) => setState(() => _selectedId = null),
              ),
              children: [
                TileLayer(
                  urlTemplate: _tileUrl,
                  userAgentPackageName: 'com.gppjo.biodev.mobile',
                ),
                MarkerLayer(
                  markers: [
                    for (final m in _markers)
                      Marker(
                        point: m.point,
                        width: 44,
                        height: 44,
                        alignment: Alignment.bottomCenter,
                        child: GestureDetector(
                          onTap: () => _select(m),
                          child: _MapPin(
                            label: m.initials,
                            color: m.color,
                            selected: m.userId == _selectedId,
                          ),
                        ),
                      ),
                  ],
                ),
              ],
            ),

            Positioned(
              top: 12,
              left: 12,
              right: 12,
              child: Row(
                children: [
                  _StatChip(
                    label: 'متصل الآن',
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
                    Text(' متصل الآن (آخر 10 دقائق)  ', style: TextStyle(fontSize: 11.5)),
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
      ),
    );
  }
}

class _MapPin extends StatelessWidget {
  const _MapPin({
    required this.label,
    required this.color,
    required this.selected,
  });

  final String label;
  final Color color;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    return AnimatedScale(
      scale: selected ? 1.15 : 1,
      duration: const Duration(milliseconds: 150),
      child: Container(
        width: 38,
        height: 38,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: color,
          shape: BoxShape.circle,
          border: Border.all(color: Colors.white, width: 2.5),
          boxShadow: [
            BoxShadow(
              color: color.withValues(alpha: 0.35),
              blurRadius: 10,
              offset: const Offset(0, 3),
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
