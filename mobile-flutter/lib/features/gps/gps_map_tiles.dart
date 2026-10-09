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
    final provider = (mapProvider ?? 'osm').toLowerCase();
    final showEsri = zoom == null || zoom <= esriVisibleMaxZoom;
    final custom = (tileUrl != null &&
            tileUrl.contains('{z}') &&
            !tileUrl.contains('basemaps.cartocdn.com'))
        ? tileUrl
        : null;

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
    if (provider == 'osm' || provider == 'carto') {
      return [_osm()];
    }

    // Esri شوارع + OSM عند التكبير العالي.
    if (provider == 'esri') {
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
