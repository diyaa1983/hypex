/// نسخة التطبيق: التابلت الأفقي، أو هاتف مدير المبيعات بالطول.
class AppProfile {
  AppProfile._();

  static const String name = String.fromEnvironment(
    'APP_PROFILE',
    defaultValue: 'tablet',
  );

  static bool get managerPhone => name == 'manager';
}
