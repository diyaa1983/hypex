import 'package:flutter/material.dart';

/// ألوان وثيم التطبيق (يطابق واجهة /m: أزرق #0572ce).
class AppTheme {
  AppTheme._();

  static const Color primary = Color(0xFF0572CE);
  static const Color primaryDark = Color(0xFF045BA3);
  static const Color bg = Color(0xFFE8EAED);
  static const Color surface = Colors.white;
  static const Color success = Color(0xFF1E7E34);
  static const Color warn = Color(0xFFB26A00);
  static const Color danger = Color(0xFFC0392B);

  static ThemeData build() {
    final scheme = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
    );
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: bg,
      appBarTheme: const AppBarTheme(
        backgroundColor: primary,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: true,
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 1,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
        ),
        margin: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: Color(0xFFD5DBE1)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: Color(0xFFD5DBE1)),
        ),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(50),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
    );
  }
}
