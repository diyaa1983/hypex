import 'package:flutter/widgets.dart';
import 'package:flutter_map/flutter_map.dart';

/// بلاطات مجانية بدون مفتاح — OSM / Esri.
/// (CARTO أوقف البلاطات المجانية ويظهر «API KEY REQUIRED»).
class GpsMapTiles {
  GpsMapTiles._();

  /// OpenStreetMap — أفضل تغطية لأسماء الشوارع والأحياء المحلية.
  static const osmUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  static const esriUrl =
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';
  static const esriImageryUrl =
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';

  static const esriVisibleMaxZoom = 14;
  static const pkg = 'com.gppjo.biodev.mobile';

  static bool isCartoUrl(String? url) {
    final u = (url ?? '').toLowerCase();
    if (u.isEmpty) return false;
    return u.contains('basemaps.cartocdn.com') ||
        u.contains('cartocdn.com') ||
        u.contains('carto.com') ||
        u.contains('cartodb.com');
  }

  /// تنظيف إعدادات الخادم — لا تمرّر CARTO / Google بلا مفتاح أبداً.
  static ({String provider, String? tileUrl}) sanitize({
    String? mapProvider,
    String? tileUrl,
  }) {
    var provider = (mapProvider ?? 'osm').toLowerCase().trim();
    if (provider == 'carto' ||
        provider == 'google' ||
        provider.isEmpty ||
        provider == 'api_key' ||
        provider.contains('key')) {
      provider = 'osm';
    }
    var tile = (tileUrl ?? '').trim();
    // نتجاهل أي رابط خادم قديم (CARTO / مفتاح) — البلاطات المحلية آمنة.
    if (tile.isEmpty ||
        isCartoUrl(tile) ||
        !tile.contains('{z}') ||
        tile.toLowerCase().contains('apikey') ||
        tile.toLowerCase().contains('api_key') ||
        tile.toLowerCase().contains('key=')) {
      tile = '';
    }
    return (provider: provider, tileUrl: tile.isEmpty ? null : tile);
  }

  /// طبقات آمنة للموبايل — تتجاهل tileUrl من السيرفر إن كان مشبوهاً.
  static List<Widget> safeLayers({
    String? mapProvider,
    String? tileUrl,
    double? zoom,
  }) {
    final clean = sanitize(mapProvider: mapProvider, tileUrl: tileUrl);
    // على الموبايل: OSM دائماً عند الشك — يمنع بلاطات «API KEY REQUIRED».
    final provider =
        (clean.provider == 'esri' || clean.provider == 'natgeo')
            ? clean.provider
            : 'osm';
    return layers(
      mapProvider: provider,
      tileUrl: null, // لا نمرّر روابط السيرفر — مصادر ثابتة فقط
      zoom: zoom,
    );
  }

  static TileLayer _osm() {
    return TileLayer(
      urlTemplate: osmUrl,
      maxNativeZoom: 19,
      maxZoom: 20,
      retinaMode: false,
      userAgentPackageName: pkg,
    );
  }

  static List<Widget> layers({
    String? mapProvider,
    String? tileUrl,
    double? zoom,
  }) {
    final clean = sanitize(mapProvider: mapProvider, tileUrl: tileUrl);
    final provider = clean.provider;
    final custom = clean.tileUrl;
    final showEsri = zoom == null || zoom <= esriVisibleMaxZoom;

    // أقمار صناعية.
    if (provider == 'imagery' || provider == 'satellite') {
      return [
        TileLayer(
          urlTemplate: custom ?? esriImageryUrl,
          maxNativeZoom: 19,
          maxZoom: 20,
          userAgentPackageName: pkg,
        ),
      ];
    }

    // OpenStreetMap — الافتراضي / بديل CARTO بدون مفتاح.
    if (provider == 'osm') {
      return [_osm()];
    }

    // Esri شوارع + OSM عند التكبير العالي.
    if (provider == 'esri' || provider == 'natgeo') {
      final layers = <Widget>[_osm()];
      if (showEsri) {
        layers.add(
          TileLayer(
            urlTemplate: custom ?? esriUrl,
            maxNativeZoom: 17,
            maxZoom: 17,
            userAgentPackageName: pkg,
          ),
        );
      }
      return layers;
    }

    return [_osm()];
  }
}
