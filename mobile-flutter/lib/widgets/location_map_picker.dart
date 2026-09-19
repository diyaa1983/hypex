import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../core/theme.dart';
import '../features/gps/gps_map_tiles.dart';
import '../services/location_service.dart';
import 'async_view.dart';
import 'mobile_scaffold.dart';
import 'package:flutter_map/flutter_map.dart';

/// فتح خريطة لاختيار موقع العميل (بالضغط أو السحب).
Future<LatLng?> pickLocationOnMap(
  BuildContext context, {
  LatLng? initial,
  bool hasInitialLocation = false,
}) {
  final start = initial ?? const LatLng(31.9539, 35.9106);
  return Navigator.of(context).push<LatLng>(
    MaterialPageRoute(
      builder: (_) => LocationMapPickerScreen(
        initial: start,
        hasInitialLocation: hasInitialLocation || initial != null,
      ),
    ),
  );
}

class LocationMapPickerScreen extends StatefulWidget {
  const LocationMapPickerScreen({
    super.key,
    required this.initial,
    this.hasInitialLocation = false,
  });

  final LatLng initial;
  final bool hasInitialLocation;

  @override
  State<LocationMapPickerScreen> createState() =>
      _LocationMapPickerScreenState();
}

class _LocationMapPickerScreenState extends State<LocationMapPickerScreen> {
  late LatLng _selected = widget.initial;
  final MapController _map = MapController();
  bool _locating = false;
  double _zoom = 16;
  bool _satellite = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (widget.hasInitialLocation) return;
      final pos = await LocationService.instantPosition();
      if (!mounted || pos == null) return;
      _moveTo(LatLng(pos.latitude, pos.longitude), zoom: 16);
    });
  }

  void _moveTo(LatLng p, {double zoom = 17}) {
    setState(() {
      _selected = p;
      _zoom = zoom;
    });
    _map.move(p, zoom);
  }

  Future<void> _goToCurrent() async {
    setState(() => _locating = true);
    try {
      final quick = await LocationService.instantPosition();
      if (mounted && quick != null) {
        _moveTo(LatLng(quick.latitude, quick.longitude), zoom: 16);
      }
      final pos = await LocationService.requirePosition();
      if (!mounted) return;
      _moveTo(LatLng(pos.latitude, pos.longitude));
    } catch (e) {
      if (!mounted) return;
      showSnack(context, LocationService.friendlyError(e), error: true);
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('تحديد موقع العميل'),
      body: Stack(
        children: [
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              initialCenter: _selected,
              initialZoom: _zoom,
              maxZoom: 19,
              onTap: (_, p) => setState(() => _selected = p),
              onPositionChanged: (pos, _) {
                if ((pos.zoom - _zoom).abs() > 0.01) {
                  setState(() => _zoom = pos.zoom);
                }
              },
            ),
            children: [
              ...GpsMapTiles.layers(
                mapProvider: _satellite ? 'imagery' : 'osm',
                zoom: _zoom,
              ),
              MarkerLayer(
                markers: [
                  Marker(
                    point: _selected,
                    width: 46,
                    height: 46,
                    child: const Icon(
                      Icons.location_on_rounded,
                      color: AppTheme.danger,
                      size: 44,
                    ),
                  ),
                ],
              ),
            ],
          ),
          Positioned(
            right: 12,
            bottom: 92,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                FloatingActionButton.small(
                  heroTag: 'loc_layer_add',
                  tooltip: _satellite
                      ? 'عرض الشوارع والأسماء'
                      : 'عرض الأقمار الصناعية',
                  onPressed: () => setState(() => _satellite = !_satellite),
                  child: Icon(
                    _satellite ? Icons.map_rounded : Icons.satellite_alt_rounded,
                  ),
                ),
                const SizedBox(height: 8),
                FloatingActionButton(
                  heroTag: 'loc_cur_add',
                  onPressed: _locating ? null : _goToCurrent,
                  child: _locating
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.my_location_rounded),
                ),
              ],
            ),
          ),
          Positioned(
            left: 12,
            right: 12,
            bottom: 16,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: AppTheme.softShadow,
                  ),
                  child: Text(
                    'الموقع المختار: ${_selected.latitude.toStringAsFixed(6)} ، ${_selected.longitude.toStringAsFixed(6)}',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 13,
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: () => Navigator.of(context).pop(_selected),
                    icon: const Icon(Icons.check_rounded),
                    label: const Text('اعتماد هذا الموقع'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
