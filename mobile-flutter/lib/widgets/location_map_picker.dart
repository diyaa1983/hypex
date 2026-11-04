import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/device_layout.dart';
import '../core/theme.dart';
import '../features/gps/gps_map_tiles.dart';
import '../services/location_service.dart';
import 'async_view.dart';
import 'mobile_scaffold.dart';

/// فتح خريطة لاختيار موقع العميل (بحث مكان + تقاطع مركزي دقيق).
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
        hasInitialLocation: hasInitialLocation,
      ),
    ),
  );
}

class _PlaceHit {
  const _PlaceHit({
    required this.id,
    required this.name,
    required this.address,
    required this.provider,
    this.lat,
    this.lng,
  });

  final String id;
  final String name;
  final String address;
  final String provider;
  final double? lat;
  final double? lng;

  bool get hasCoords => lat != null && lng != null;

  factory _PlaceHit.fromJson(Map<String, dynamic> m) {
    double? d(dynamic v) {
      if (v is num) return v.toDouble();
      return double.tryParse('$v');
    }

    return _PlaceHit(
      id: '${m['id'] ?? ''}',
      name: '${m['name'] ?? ''}'.trim(),
      address: '${m['address'] ?? ''}'.trim(),
      provider: '${m['provider'] ?? ''}',
      lat: d(m['lat']),
      lng: d(m['lng']),
    );
  }
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
  final TextEditingController _search = TextEditingController();
  final FocusNode _searchFocus = FocusNode();

  /// الموقع المحفوظ سابقاً — علامة على الخريطة فقط (بدون بطاقة كبيرة).
  LatLng? _savedPoint;
  bool _locating = false;
  bool _searching = false;
  bool _resolvingPlace = false;
  bool _reverseLoading = false;
  bool _satellite = false;
  double _zoom = 17;
  String? _placeLabel;
  String? _placeSub;
  String _providerHint = '';
  List<_PlaceHit> _hits = const [];
  Timer? _debounce;
  Timer? _reverseDebounce;
  int _searchGen = 0;
  int _reverseGen = 0;

  @override
  void initState() {
    super.initState();
    if (widget.hasInitialLocation) {
      _savedPoint = widget.initial;
      _placeLabel = 'الموقع المحفوظ';
      _placeSub = null;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      if (widget.hasInitialLocation) {
        _scheduleReverse(_selected, force: true);
        return;
      }
      final pos = await LocationService.instantPosition();
      if (!mounted || pos == null) return;
      _moveTo(
        LatLng(pos.latitude, pos.longitude),
        zoom: 17,
        label: 'موقعك الحالي',
        clearSub: true,
      );
      _scheduleReverse(_selected);
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _reverseDebounce?.cancel();
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _moveTo(
    LatLng p, {
    double zoom = 18,
    String? label,
    String? sub,
    bool clearSub = false,
  }) {
    setState(() {
      _selected = p;
      _zoom = zoom;
      if (label != null) _placeLabel = label;
      if (clearSub) {
        _placeSub = null;
      } else if (sub != null) {
        _placeSub = sub;
      }
    });
    try {
      _map.move(p, zoom);
    } catch (_) {}
  }

  void _goToSaved() {
    final s = _savedPoint;
    if (s == null) return;
    _moveTo(s, zoom: 18, label: 'الموقع المحفوظ', clearSub: true);
    _scheduleReverse(s, force: true);
  }

  void _onMapEvent(MapEvent event) {
    final ended = event is MapEventMoveEnd ||
        event is MapEventFlingAnimationEnd ||
        event is MapEventDoubleTapZoomEnd ||
        event is MapEventScrollWheelZoom;
    if (!ended) return;
    final cam = event.camera;
    final c = cam.center;
    final z = cam.zoom;
    if ((c.latitude - _selected.latitude).abs() < 1e-7 &&
        (c.longitude - _selected.longitude).abs() < 1e-7 &&
        (z - _zoom).abs() < 0.01) {
      return;
    }
    setState(() {
      _selected = c;
      _zoom = z;
      if (_hits.isEmpty) {
        _placeLabel = null;
        _placeSub = null;
      }
    });
    _scheduleReverse(c);
  }

  void _scheduleReverse(LatLng p, {bool force = false}) {
    _reverseDebounce?.cancel();
    _reverseDebounce = Timer(
      Duration(milliseconds: force ? 80 : 550),
      () => _reverseLookup(p),
    );
  }

  Future<void> _reverseLookup(LatLng p) async {
    final gen = ++_reverseGen;
    if (mounted) setState(() => _reverseLoading = true);
    try {
      final api = context.read<ApiClient>();
      final res = await api.getJson(
        AppConfig.placesSearchPath,
        query: {
          'reverse': '1',
          'lat': p.latitude.toStringAsFixed(7),
          'lng': p.longitude.toStringAsFixed(7),
        },
        receiveTimeout: const Duration(seconds: 12),
      );
      if (!mounted || gen != _reverseGen) return;
      final place = res['place'];
      if (place is Map) {
        final hit = _PlaceHit.fromJson(Map<String, dynamic>.from(place));
        setState(() {
          _placeLabel = hit.name.isNotEmpty ? hit.name : null;
          _placeSub = hit.address.isNotEmpty ? hit.address : null;
          _reverseLoading = false;
        });
        return;
      }
    } catch (_) {}
    if (!mounted || gen != _reverseGen) return;
    setState(() => _reverseLoading = false);
  }

  Future<void> _goToCurrent() async {
    if (_locating) return;
    setState(() => _locating = true);
    try {
      final pos = await LocationService.requirePosition(fresh: true)
          .timeout(const Duration(seconds: 25));
      if (!mounted) return;
      final p = LatLng(pos.latitude, pos.longitude);
      _moveTo(p, zoom: 18, label: 'موقعك الحالي', clearSub: true);
      _scheduleReverse(p, force: true);
    } catch (e) {
      if (!mounted) return;
      showSnack(context, LocationService.friendlyError(e), error: true);
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  void _onSearchChanged(String raw) {
    _debounce?.cancel();
    final q = raw.trim();
    if (q.length < 2) {
      setState(() {
        _hits = const [];
        _searching = false;
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 420), () {
      _runSearch(q);
    });
  }

  Future<void> _runSearch(String q) async {
    final gen = ++_searchGen;
    setState(() => _searching = true);
    try {
      final api = context.read<ApiClient>();
      final res = await api.getJson(
        AppConfig.placesSearchPath,
        query: {
          'q': q,
          'lat': _selected.latitude.toStringAsFixed(6),
          'lng': _selected.longitude.toStringAsFixed(6),
        },
        receiveTimeout: const Duration(seconds: 20),
      );
      if (!mounted || gen != _searchGen) return;
      final list = <_PlaceHit>[];
      final raw = res['results'];
      if (raw is List) {
        for (final item in raw) {
          if (item is Map) {
            final hit = _PlaceHit.fromJson(Map<String, dynamic>.from(item));
            if (hit.name.isNotEmpty) list.add(hit);
          }
        }
      }
      final provider = '${res['provider'] ?? ''}';
      final hasGoogle = res['has_google'] == true;
      setState(() {
        _hits = list;
        _searching = false;
        _providerHint = hasGoogle
            ? (provider == 'google' ? 'نتائج Google Maps' : 'نتائج بديلة')
            : 'نتائج OpenStreetMap';
      });
    } catch (e) {
      if (!mounted || gen != _searchGen) return;
      setState(() {
        _hits = const [];
        _searching = false;
      });
      showSnack(
        context,
        e is ApiException
            ? e.message
            : 'تعذّر البحث عن المكان. تحقق من الاتصال.',
        error: true,
      );
    }
  }

  Future<void> _selectHit(_PlaceHit hit) async {
    _searchFocus.unfocus();
    setState(() {
      _hits = const [];
      _search.text = hit.name;
    });

    if (hit.hasCoords) {
      _moveTo(
        LatLng(hit.lat!, hit.lng!),
        zoom: 19,
        label: hit.name,
        sub: hit.address.isNotEmpty ? hit.address : null,
      );
      return;
    }

    if (hit.provider == 'google' && hit.id.isNotEmpty) {
      setState(() => _resolvingPlace = true);
      try {
        final api = context.read<ApiClient>();
        final res = await api.getJson(
          AppConfig.placesSearchPath,
          query: {'place_id': hit.id},
          receiveTimeout: const Duration(seconds: 15),
        );
        if (!mounted) return;
        final place = res['place'];
        if (place is Map) {
          final detail = _PlaceHit.fromJson(Map<String, dynamic>.from(place));
          if (detail.hasCoords) {
            _moveTo(
              LatLng(detail.lat!, detail.lng!),
              zoom: 19,
              label: detail.name,
              sub: detail.address.isNotEmpty ? detail.address : null,
            );
            return;
          }
        }
        showSnack(context, 'تعذّر جلب إحداثيات المكان.', error: true);
      } catch (e) {
        if (!mounted) return;
        showSnack(
          context,
          e is ApiException ? e.message : 'تعذّر جلب إحداثيات المكان.',
          error: true,
        );
      } finally {
        if (mounted) setState(() => _resolvingPlace = false);
      }
    }
  }

  void _confirm() {
    try {
      Navigator.of(context).pop(_map.camera.center);
    } catch (_) {
      Navigator.of(context).pop(_selected);
    }
  }

  String get _compactLabel {
    if (_placeLabel != null && _placeLabel!.trim().isNotEmpty) {
      return _placeLabel!.trim();
    }
    return '${_selected.latitude.toStringAsFixed(5)} ، ${_selected.longitude.toStringAsFixed(5)}';
  }

  Widget _buildSearchPanel({required bool tablet, required double maxWidth}) {
    return ConstrainedBox(
      constraints: BoxConstraints(maxWidth: maxWidth),
      child: Material(
        elevation: 2,
        borderRadius: BorderRadius.circular(12),
        color: Colors.white,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: _search,
              focusNode: _searchFocus,
              textInputAction: TextInputAction.search,
              style: TextStyle(fontSize: tablet ? 14 : 15),
              decoration: InputDecoration(
                hintText: 'ابحث عن محل أو شارع…',
                hintStyle: TextStyle(
                  fontSize: 13,
                  color: Colors.grey.shade600,
                ),
                isDense: true,
                prefixIcon: const Icon(Icons.search_rounded, size: 22),
                suffixIcon: _searching || _resolvingPlace
                    ? const Padding(
                        padding: EdgeInsets.all(12),
                        child: SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      )
                    : (_search.text.isEmpty
                        ? null
                        : IconButton(
                            tooltip: 'مسح',
                            visualDensity: VisualDensity.compact,
                            onPressed: () {
                              _debounce?.cancel();
                              _search.clear();
                              setState(() => _hits = const []);
                            },
                            icon: const Icon(Icons.close_rounded, size: 20),
                          )),
                border: InputBorder.none,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 8,
                  vertical: 12,
                ),
              ),
              onChanged: (v) {
                setState(() {});
                _onSearchChanged(v);
              },
              onSubmitted: (v) {
                _debounce?.cancel();
                final q = v.trim();
                if (q.length >= 2) _runSearch(q);
              },
            ),
            if (_providerHint.isNotEmpty && _hits.isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 4),
                child: Align(
                  alignment: Alignment.centerRight,
                  child: Text(
                    _providerHint,
                    style: TextStyle(
                      fontSize: 10.5,
                      color: Colors.grey.shade600,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
            if (_hits.isNotEmpty)
              ConstrainedBox(
                constraints: BoxConstraints(maxHeight: tablet ? 220 : 200),
                child: ListView.separated(
                  shrinkWrap: true,
                  padding: const EdgeInsets.only(bottom: 4),
                  itemCount: _hits.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (ctx, i) {
                    final h = _hits[i];
                    final isGoogle = h.provider == 'google';
                    return ListTile(
                      dense: true,
                      visualDensity: VisualDensity.compact,
                      leading: Icon(
                        isGoogle
                            ? Icons.storefront_rounded
                            : Icons.map_outlined,
                        size: 22,
                        color: isGoogle
                            ? const Color(0xFF1A73E8)
                            : AppTheme.primary,
                      ),
                      title: Text(
                        h.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontWeight: FontWeight.w700,
                          fontSize: 13,
                        ),
                      ),
                      subtitle: h.address.isEmpty
                          ? null
                          : Text(
                              h.address,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontSize: 11),
                            ),
                      onTap: () => _selectHit(h),
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// شريط سفلي رفيع: اسم المكان + اعتماد — بدون بطاقات تغطي الخريطة.
  Widget _buildCompactBottomBar({required bool tablet}) {
    final saved = _savedPoint;
    return Material(
      elevation: 4,
      color: Colors.white.withValues(alpha: 0.98),
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          tablet ? 12 : 10,
          tablet ? 8 : 8,
          tablet ? 10 : 8,
          tablet ? 8 : 8,
        ),
        child: Row(
          children: [
            if (saved != null) ...[
              Tooltip(
                message: 'العودة للموقع المحفوظ',
                child: InkWell(
                  onTap: _goToSaved,
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xFF1565C0).withValues(alpha: 0.10),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(
                        color: const Color(0xFF1565C0).withValues(alpha: 0.35),
                      ),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          Icons.store_mall_directory_rounded,
                          size: 16,
                          color: Color(0xFF1565C0),
                        ),
                        SizedBox(width: 4),
                        Text(
                          'محفوظ',
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF1565C0),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      const Icon(
                        Icons.location_on_rounded,
                        size: 16,
                        color: AppTheme.danger,
                      ),
                      const SizedBox(width: 4),
                      Expanded(
                        child: Text(
                          _compactLabel,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 13,
                          ),
                        ),
                      ),
                      if (_reverseLoading)
                        const Padding(
                          padding: EdgeInsets.only(right: 4),
                          child: SizedBox(
                            width: 14,
                            height: 14,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        ),
                    ],
                  ),
                  if (_placeSub != null &&
                      _placeSub!.isNotEmpty &&
                      _placeSub != _compactLabel)
                    Padding(
                      padding: const EdgeInsets.only(top: 1, right: 20),
                      child: Text(
                        _placeSub!,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 11,
                          color: Colors.grey.shade700,
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            FilledButton(
              onPressed: _resolvingPlace ? null : _confirm,
              style: FilledButton.styleFrom(
                padding: EdgeInsets.symmetric(
                  horizontal: tablet ? 18 : 14,
                  vertical: 12,
                ),
                minimumSize: const Size(0, 42),
                visualDensity: VisualDensity.compact,
              ),
              child: const Text(
                'اعتماد',
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMapControls() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        FloatingActionButton.small(
          heroTag: 'loc_zoom_in',
          tooltip: 'تكبير',
          onPressed: () {
            final z = (_zoom + 1).clamp(5.0, 20.0);
            _moveTo(_selected, zoom: z);
          },
          child: const Icon(Icons.add_rounded),
        ),
        const SizedBox(height: 8),
        FloatingActionButton.small(
          heroTag: 'loc_zoom_out',
          tooltip: 'تصغير',
          onPressed: () {
            final z = (_zoom - 1).clamp(5.0, 20.0);
            _moveTo(_selected, zoom: z);
          },
          child: const Icon(Icons.remove_rounded),
        ),
        const SizedBox(height: 8),
        FloatingActionButton.small(
          heroTag: 'loc_layer_add',
          tooltip: _satellite ? 'شوارع' : 'أقمار صناعية',
          onPressed: () => setState(() => _satellite = !_satellite),
          child: Icon(
            _satellite ? Icons.map_rounded : Icons.satellite_alt_rounded,
          ),
        ),
        const SizedBox(height: 10),
        FloatingActionButton(
          heroTag: 'loc_cur_add',
          tooltip: 'موقعي الحالي',
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
    );
  }

  @override
  Widget build(BuildContext context) {
    final bottomPad = MediaQuery.paddingOf(context).bottom;
    final size = MediaQuery.sizeOf(context);
    final tablet = DeviceLayout.isTabletSize(size);
    final saved = _savedPoint;
    // على التابلت: شريط بحث محدود العرض حتى لا يغطي كامل العرض.
    final searchMaxW = tablet
        ? (size.width * 0.42).clamp(320.0, 420.0)
        : size.width - 24;

    return MobileScaffold(
      title: const Text('تحديد موقع العميل'),
      body: Stack(
        children: [
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              initialCenter: _selected,
              initialZoom: _zoom,
              minZoom: 5,
              maxZoom: 20,
              // مساحة سفلية بصرية للدبوس فوق الشريط الرفيع.
              interactionOptions: const InteractionOptions(
                flags: InteractiveFlag.drag |
                    InteractiveFlag.flingAnimation |
                    InteractiveFlag.pinchZoom |
                    InteractiveFlag.doubleTapZoom |
                    InteractiveFlag.scrollWheelZoom,
              ),
              onMapEvent: _onMapEvent,
              onTap: (_, __) {
                if (_hits.isNotEmpty) setState(() => _hits = const []);
                _searchFocus.unfocus();
              },
            ),
            children: [
              ...GpsMapTiles.layers(
                mapProvider: _satellite ? 'imagery' : 'osm',
              ),
              if (saved != null)
                MarkerLayer(
                  markers: [
                    Marker(
                      point: saved,
                      width: 40,
                      height: 40,
                      alignment: Alignment.topCenter,
                      child: const Icon(
                        Icons.store_mall_directory_rounded,
                        color: Color(0xFF1565C0),
                        size: 36,
                      ),
                    ),
                  ],
                ),
            ],
          ),

          // دبوس التحديد في المنتصف فقط.
          IgnorePointer(
            child: Center(
              child: Padding(
                // يرفع طرف الدبوس فوق الشريط السفلي قليلاً على التابلت.
                padding: EdgeInsets.only(bottom: tablet ? 56 : 48),
                child: const Icon(
                  Icons.location_on_rounded,
                  color: AppTheme.danger,
                  size: 44,
                ),
              ),
            ),
          ),

          // البحث أعلى اليمين/اليسار — عرض محدود على التابلت.
          Positioned(
            top: 10,
            left: 12,
            right: tablet ? null : 12,
            child: _buildSearchPanel(tablet: tablet, maxWidth: searchMaxW),
          ),

          // أدوات الخريطة بجانب الشريط السفلي مباشرة (بدون رفع كبير).
          Positioned(
            right: 12,
            bottom: 72 + bottomPad,
            child: _buildMapControls(),
          ),

          // شريط واحد مضغوط أسفل الشاشة — لا يغطي الخريطة.
          Positioned(
            left: 12,
            right: 12,
            bottom: 10 + bottomPad,
            child: Align(
              alignment: Alignment.bottomCenter,
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  maxWidth: tablet ? 520 : double.infinity,
                ),
                child: _buildCompactBottomBar(tablet: tablet),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
