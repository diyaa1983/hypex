package com.gppjo.biodev.namma_mobile

import android.content.pm.ActivityInfo
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_SENSOR_LANDSCAPE
    hideSystemUi()
  }

  override fun onWindowFocusChanged(hasFocus: Boolean) {
    super.onWindowFocusChanged(hasFocus)
    if (!hasFocus) return
    // تأخير بسيط حتى لا يُغلق الكيبورد أثناء فتحه (النافذة تُعيد التركيز قبل ظهور IME).
    window.decorView.postDelayed({
      if (hasWindowFocus() && !isImeVisible()) hideSystemUi()
    }, 400)
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

  private fun isImeVisible(): Boolean {
    val insets = window.decorView.rootWindowInsets ?: return false
    return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
      insets.isVisible(WindowInsets.Type.ime())
    } else {
      @Suppress("DEPRECATION")
      insets.systemWindowInsetBottom > 80
    }
  }

  private fun hideSystemUi() {
    if (isImeVisible()) return
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
      window.setDecorFitsSystemWindows(false)
      window.insetsController?.let { controller ->
        controller.hide(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
        controller.systemBarsBehavior =
          WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
      }
    } else {
      @Suppress("DEPRECATION")
      window.setFlags(
        WindowManager.LayoutParams.FLAG_FULLSCREEN,
        WindowManager.LayoutParams.FLAG_FULLSCREEN,
      )
      @Suppress("DEPRECATION")
      window.decorView.systemUiVisibility = (
        View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
          or View.SYSTEM_UI_FLAG_FULLSCREEN
          or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
          or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
          or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
          or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
        )
    }
  }
}
