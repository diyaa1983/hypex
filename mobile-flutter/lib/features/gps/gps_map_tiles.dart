import 'package:flutter/widgets.dart';
import 'package:flutter_map/flutter_map.dart';

/// بلاطات مجانية — Esri فوق Carto لتجنب «Map data not yet available» عند التكبير.
class GpsMapTiles {
  GpsMapTiles._();

  static const cartoUrl =
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
  static const esriUrl =
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';

  static const esriVisibleMaxZoom = 14;

  static List<Widget> layers({
    String? mapProvider,
    String? tileUrl,
    double? zoom,
  }) {
    final provider = (mapProvider ?? 'esri').toLowerCase();
    const pkg = 'com.gppjo.biodev.mobile';
    final showEsri =
        zoom == null || zoom <= esriVisibleMaxZoom;

    if (provider == 'carto') {
      return [
        TileLayer(
          urlTemplate: tileUrl ?? cartoUrl,
          subdomains: const ['a', 'b', 'c', 'd'],
          maxZoom: 20,
          userAgentPackageName: pkg,
        ),
      ];
    }

    if (provider == 'esri') {
      final layers = <Widget>[
        TileLayer(
          urlTemplate: cartoUrl,
          subdomains: const ['a', 'b', 'c', 'd'],
          maxZoom: 20,
          userAgentPackageName: pkg,
        ),
      ];
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

    return [
      TileLayer(
        urlTemplate: tileUrl ?? cartoUrl,
        subdomains: const ['a', 'b', 'c', 'd'],
        maxZoom: 20,
        userAgentPackageName: pkg,
      ),
    ];
  }
}
