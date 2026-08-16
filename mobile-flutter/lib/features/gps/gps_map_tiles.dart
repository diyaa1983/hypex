import 'package:flutter/widgets.dart';
import 'package:flutter_map/flutter_map.dart';

/// بلاطات مجانية — OSM لأسماء الشوارع والمناطق، Esri للأقمار الصناعية.
class GpsMapTiles {
  GpsMapTiles._();

  /// OpenStreetMap — أفضل تغطية لأسماء الشوارع والأحياء المحلية.
  static const osmUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  static const cartoUrl =
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
  static const cartoLabelsUrl =
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png';
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

  static TileLayer _carto() {
    return TileLayer(
      urlTemplate: cartoUrl,
      subdomains: const ['a', 'b', 'c', 'd'],
      maxNativeZoom: 20,
      maxZoom: 20,
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

    // أقمار صناعية + أسماء الشوارع والمناطق فوقها.
    if (provider == 'imagery' || provider == 'satellite') {
      return [
        TileLayer(
          urlTemplate: tileUrl ?? esriImageryUrl,
          maxNativeZoom: 19,
          maxZoom: 20,
          userAgentPackageName: pkg,
        ),
        TileLayer(
          urlTemplate: cartoLabelsUrl,
          subdomains: const ['a', 'b', 'c', 'd'],
          maxNativeZoom: 20,
          maxZoom: 20,
          userAgentPackageName: pkg,
        ),
      ];
    }

    // OpenStreetMap — الافتراضي: أسماء شوارع وأحياء واضحة.
    if (provider == 'osm') {
      return [_osm()];
    }

    if (provider == 'carto') {
      return [_carto()];
    }

    // Esri شوارع + Carto احتياط عند التكبير العالي.
    if (provider == 'esri') {
      final layers = <Widget>[_carto()];
      if (showEsri) {
        layers.add(
          TileLayer(
            urlTemplate: tileUrl ?? esriUrl,
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
