import 'package:geolocator/geolocator.dart';

/// خدمة الموقع الجغرافي (GPS) — طلب الإذن وقراءة الموقع.
class LocationService {
  LocationService._();

  /// محاولة الحصول على الموقع الحالي؛ تُرجع null بصمت عند التعذر.
  static Future<Position?> tryGetPosition() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        return null;
      }
      var perm = await Geolocator.checkPermission();
      if (perm == LocationPermission.denied) {
        perm = await Geolocator.requestPermission();
      }
      if (perm == LocationPermission.denied ||
          perm == LocationPermission.deniedForever) {
        return null;
      }
      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 12),
        ),
      );
    } catch (_) {
      return null;
    }
  }

  /// طلب الإذن صراحةً وإرجاع الموقع أو رمي رسالة.
  static Future<Position> requirePosition() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw 'خدمة الموقع غير مفعّلة على الجهاز.';
    }
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
    }
    if (perm == LocationPermission.denied ||
        perm == LocationPermission.deniedForever) {
      throw 'لم يُمنح إذن الوصول للموقع.';
    }
    return Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 15),
      ),
    );
  }
}
