import 'package:flutter/widgets.dart';
import 'package:flutter_map/flutter_map.dart';

/// بلاطات مجانية — Esri فوق Carto لتجنب «Map data not yet available» عند التكبير.
class GpsMapTiles {
  GpsMapTiles._();

  static const cartoUrl =
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
  static const esriUrl =
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';

  static List<Widget> layers({String? mapProvider, String? tileUrl}) {
    final provider = (mapProvider ?? 'esri').toLowerCase();
    const pkg = 'com.gppjo.biodev.mobile';

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
      return [
        TileLayer(
          urlTemplate: cartoUrl,
          subdomains: const ['a', 'b', 'c', 'd'],
          maxZoom: 20,
          userAgentPackageName: pkg,
        ),
        TileLayer(
          urlTemplate: tileUrl ?? esriUrl,
          maxNativeZoom: 17,
          maxZoom: 17,
          userAgentPackageName: pkg,
        ),
      ];
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
