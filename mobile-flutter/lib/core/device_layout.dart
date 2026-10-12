import 'package:flutter/material.dart';

/// تابلت مقابل هاتف. العرض الأفقي للهاتف لا يُعامل كتابلت.
class DeviceLayout {
  DeviceLayout._();

  static const double tabletShortestSide = 600;

  static bool isTabletSize(Size size) => size.shortestSide >= tabletShortestSide;

  static bool isTablet(BuildContext context) =>
      isTabletSize(MediaQuery.sizeOf(context));
}
