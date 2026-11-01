import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
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

  bool _locating = false;
  bool _searching = false;
  bool _resolvingPlace = false;
  bool _satellite = false;
  double _zoom = 17;
  String? _placeLabel;
  String _providerHint = '';
  List<_PlaceHit> _hits = const [];
  Timer? _debounce;
  int _searchGen = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      if (widget.hasInitialLocation) {
        _placeLabel = 'موقع محفوظ للعميل';
        return;
      }
      // قراءة فورية بلا انتظار طويل — لا نعلّق الشاشة عند الفتح.
      final pos = await LocationService.instantPosition();
      if (!mounted || pos == null) return;
      _moveTo(LatLng(pos.latitude, pos.longitude), zoom: 17, label: 'موقعك الحالي');
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _moveTo(LatLng p, {double zoom = 18, String? label}) {
    setState(() {
      _selected = p;
      _zoom = zoom;
      if (label != null) _placeLabel = label;
    });
    try {
      _map.move(p, zoom);
    } catch (_) {
      // الخريطة قد لا تكون جاهزة بعد.
    }
  }

  void _onMapEvent(MapEvent event) {
    // نحدّث الإحداثيات عند انتهاء الحركة فقط — يمنع تعليق التابلت أثناء السحب.
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
      // عند التحريك اليدوي نُسقط تسمية البحث القديمة.
      if (_hits.isEmpty) _placeLabel = null;
    });
  }

  Future<void> _goToCurrent() async {
    if (_locating) return;
    setState(() => _locating = true);
    try {
      final pos = await LocationService.requirePosition(fresh: true)
          .timeout(const Duration(seconds: 25));
      if (!mounted) return;
      _moveTo(
        LatLng(pos.latitude, pos.longitude),
        zoom: 18,
        label: 'موقعك الحالي',
      );
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
        receiveTimeout: const Duration(seconds: 18),
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
            ? (provider == 'google' ? 'نتائج Google Maps' : 'نتائج بديلة (OSM)')
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
        e is ApiException ? e.message : 'تعذّر البحث عن المكان. تحقق من الاتصال.',
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
        zoom: 18,
        label: hit.address.isNotEmpty ? hit.address : hit.name,
      );
      return;
    }

    // Google Autocomplete يحتاج تفاصيل place_id.
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
              zoom: 18,
              label: detail.address.isNotEmpty ? detail.address : detail.name,
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
    // اقرأ مركز الخريطة الفعلي لحظة الاعتماد (أدق من آخر setState).
    try {
      final c = _map.camera.center;
      Navigator.of(context).pop(c);
    } catch (_) {
      Navigator.of(context).pop(_selected);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomPad = MediaQuery.paddingOf(context).bottom;

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
              // تفاعل سلس على التابلت — بدون دوران يربك التحديد.
              interactionOptions: const InteractionOptions(
                flags: InteractiveFlag.drag |
                    InteractiveFlag.flingAnimation |
                    InteractiveFlag.pinchZoom |
                    InteractiveFlag.doubleTapZoom |
                    InteractiveFlag.scrollWheelZoom,
              ),
              onMapEvent: _onMapEvent,
              onTap: (_, __) {
                if (_hits.isNotEmpty) {
                  setState(() => _hits = const []);
                }
                _searchFocus.unfocus();
              },
            ),
            children: [
              ...GpsMapTiles.layers(
                mapProvider: _satellite ? 'imagery' : 'osm',
              ),
            ],
          ),

          // تقاطع ثابت في المنتصف — الموقع = مركز الخريطة (أدق وأقل تعليقاً).
          const IgnorePointer(
            child: Center(
              child: Padding(
                // طرف الدبوس يشير لمنتصف الشاشة.
                padding: EdgeInsets.only(bottom: 40),
                child: Icon(
                  Icons.location_on_rounded,
                  color: AppTheme.danger,
                  size: 48,
                ),
              ),
            ),
          ),

          // شريط البحث أعلى الخريطة.
          Positioned(
            top: 10,
            left: 12,
            right: 12,
            child: Material(
              elevation: 3,
              borderRadius: BorderRadius.circular(14),
              color: Colors.white,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: _search,
                    focusNode: _searchFocus,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: 'ابحث عن اسم مكان أو شارع (Google Maps)…',
                      prefixIcon: const Icon(Icons.search_rounded),
                      suffixIcon: _searching || _resolvingPlace
                          ? const Padding(
                              padding: EdgeInsets.all(12),
                              child: SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              ),
                            )
                          : (_search.text.isEmpty
                              ? null
                              : IconButton(
                                  tooltip: 'مسح',
                                  onPressed: () {
                                    _debounce?.cancel();
                                    _search.clear();
                                    setState(() => _hits = const []);
                                  },
                                  icon: const Icon(Icons.close_rounded),
                                )),
                      border: InputBorder.none,
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 14,
                      ),
                    ),
                    onChanged: (v) {
                      setState(() {}); // لتحديث زر المسح
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
                      padding: const EdgeInsets.fromLTRB(14, 0, 14, 6),
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Text(
                          _providerHint,
                          style: TextStyle(
                            fontSize: 11,
                            color: Colors.grey.shade600,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                  if (_hits.isNotEmpty)
                    ConstrainedBox(
                      constraints: const BoxConstraints(maxHeight: 240),
                      child: ListView.separated(
                        shrinkWrap: true,
                        padding: const EdgeInsets.only(bottom: 6),
                        itemCount: _hits.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (ctx, i) {
                          final h = _hits[i];
                          final isGoogle = h.provider == 'google';
                          return ListTile(
                            dense: true,
                            leading: Icon(
                              isGoogle
                                  ? Icons.place_rounded
                                  : Icons.map_outlined,
                              color: isGoogle
                                  ? const Color(0xFF1A73E8)
                                  : AppTheme.primary,
                            ),
                            title: Text(
                              h.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w700),
                            ),
                            subtitle: h.address.isEmpty
                                ? null
                                : Text(
                                    h.address,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(fontSize: 12),
                                  ),
                            onTap: () => _selectHit(h),
                          );
                        },
                      ),
                    ),
                ],
              ),
            ),
          ),

          Positioned(
            right: 12,
            bottom: 108 + bottomPad,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                FloatingActionButton.small(
                  heroTag: 'loc_zoom_in',
                  tooltip: 'تكبير لرؤية الشوارع',
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
                  tooltip: _satellite
                      ? 'عرض الشوارع والأسماء'
                      : 'عرض الأقمار الصناعية',
                  onPressed: () => setState(() => _satellite = !_satellite),
                  child: Icon(
                    _satellite
                        ? Icons.map_rounded
                        : Icons.satellite_alt_rounded,
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
            bottom: 12 + bottomPad,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: double.infinity,
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.96),
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: AppTheme.softShadow,
                  ),
                  child: Column(
                    children: [
                      if (_placeLabel != null && _placeLabel!.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 4),
                          child: Text(
                            _placeLabel!,
                            textAlign: TextAlign.center,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontWeight: FontWeight.w700,
                              fontSize: 13,
                            ),
                          ),
                        ),
                      Text(
                        '${_selected.latitude.toStringAsFixed(6)} ، ${_selected.longitude.toStringAsFixed(6)}',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                          color: Colors.grey.shade800,
                          fontFeatures: const [FontFeature.tabularFigures()],
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'حرّك الخريطة حتى يقع التقاطع على الموقع المطلوب',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 11,
                          color: Colors.grey.shade600,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _resolvingPlace ? null : _confirm,
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
