package com.gppjo.biodev.namma_mobile

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
  override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
    super.configureFlutterEngine(flutterEngine)
    try {
      ThermalPrinterChannel.register(
        flutterEngine.dartExecutor.binaryMessenger,
        applicationContext,
      )
    } catch (_: Exception) {
      // لا تُسقط التطبيق إذا تعذر تسجيل قناة الطباعة.
    }
  }
}
