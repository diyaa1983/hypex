package com.gppjo.biodev.namma_mobile

import android.content.pm.ActivityInfo
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    val smallest = resources.configuration.smallestScreenWidthDp
    requestedOrientation = if (smallest >= 600) {
      ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE
    } else {
      ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
    }
    super.onCreate(savedInstanceState)
  }

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
