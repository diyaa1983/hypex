package com.gppjo.biodev.namma_mobile

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.content.ContextCompat
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import java.io.OutputStream
import java.util.UUID
import java.util.concurrent.Executors

/**
 * قناة طباعة حرارية خاصة بالتطبيق.
 *
 * السبب: إضافة print_bluetooth_thermal تحتفظ بـ outputStream stale وترجع
 * false من connect دون إعادة اتصال، كما تعتمد فقط على secure SPP بينما
 * طابعات Xprinter (مثل XP-P810) غالباً تحتاج insecure RFCOMM أو channel 1.
 */
class ThermalPrinterChannel(private val context: Context) : MethodChannel.MethodCallHandler {
  companion object {
    private const val CHANNEL = "com.gppjo.biodev.namma_mobile/thermal_printer"
    private const val TAG = "ThermalPrinter"
    private val SPP_UUID: UUID =
      UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

    fun register(messenger: BinaryMessenger, context: Context): ThermalPrinterChannel {
      val handler = ThermalPrinterChannel(context.applicationContext)
      MethodChannel(messenger, CHANNEL).setMethodCallHandler(handler)
      return handler
    }
  }

  private val executor = Executors.newSingleThreadExecutor()
  private val mainHandler = Handler(Looper.getMainLooper())
  private var socket: BluetoothSocket? = null
  private var output: OutputStream? = null
  private var connectedMac: String? = null

  private fun replySuccess(result: MethodChannel.Result, value: Any?) {
    mainHandler.post { result.success(value) }
  }

  override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
    when (call.method) {
      "isBluetoothOn" -> result.success(isBluetoothOn())
      "hasPermission" -> result.success(hasBluetoothPermission())
      "pairedDevices" -> {
        if (!hasBluetoothPermission()) {
          result.success(emptyList<String>())
          return
        }
        result.success(pairedDeviceStrings())
      }
      "connectionStatus" -> result.success(isSocketAlive())
      "disconnect" -> {
        executor.execute {
          closeQuietly()
          replySuccess(result, true)
        }
      }
      "connect" -> {
        val mac = (call.arguments as? String)?.trim().orEmpty()
        if (mac.isEmpty()) {
          result.success(false)
          return
        }
        if (!hasBluetoothPermission()) {
          result.error(
            "PERMISSION",
            "يلزم منح إذن الأجهزة القريبة/البلوتوث",
            null,
          )
          return
        }
        if (!isBluetoothOn()) {
          result.error("BT_OFF", "البلوتوث مغلق", null)
          return
        }
        executor.execute {
          val ok = connectInternal(mac)
          replySuccess(result, ok)
        }
      }
      "writeBytes" -> {
        @Suppress("UNCHECKED_CAST")
        val list = call.arguments as? List<Int>
        if (list == null) {
          result.success(false)
          return
        }
        executor.execute {
          val ok = writeInternal(list)
          replySuccess(result, ok)
        }
      }
      else -> result.notImplemented()
    }
  }

  private fun isBluetoothOn(): Boolean {
    val adapter = BluetoothAdapter.getDefaultAdapter() ?: return false
    return adapter.isEnabled
  }

  private fun hasBluetoothPermission(): Boolean {
    if (Build.VERSION.SDK_INT < 31) return true
    return ContextCompat.checkSelfPermission(
      context,
      Manifest.permission.BLUETOOTH_CONNECT,
    ) == PackageManager.PERMISSION_GRANTED
  }

  private fun pairedDeviceStrings(): List<String> {
    val adapter = BluetoothAdapter.getDefaultAdapter() ?: return emptyList()
    return try {
      adapter.bondedDevices?.map { "${it.name ?: ""}#${it.address}" } ?: emptyList()
    } catch (e: SecurityException) {
      Log.w(TAG, "pairedDevices denied", e)
      emptyList()
    }
  }

  private fun isSocketAlive(): Boolean {
    val s = socket
    val out = output
    if (s == null || out == null || !s.isConnected) return false
    return try {
      // لا نكتب بايتات حقيقية هنا؛ مجرد تحقق من أن المقبس ما زال مفتوحاً.
      out.flush()
      true
    } catch (_: Exception) {
      closeQuietly()
      false
    }
  }

  private fun connectInternal(macRaw: String): Boolean {
    val mac = macRaw.trim().uppercase()
    if (isSocketAlive() && connectedMac.equals(mac, ignoreCase = true)) {
      return true
    }
    closeQuietly()

    val adapter = BluetoothAdapter.getDefaultAdapter() ?: return false
    if (!adapter.isEnabled) return false

    return try {
      try {
        adapter.cancelDiscovery()
      } catch (_: Exception) {
      }

      val device: BluetoothDevice = adapter.getRemoteDevice(normalizeMac(mac))
      val opened = openSocketWithFallbacks(device) ?: return false
      socket = opened
      output = opened.outputStream
      connectedMac = opened.remoteDevice?.address?.uppercase() ?: mac
      // استقرار قناة SPP قبل إرسال ESC/POS (مهم لـ XP-P810).
      Thread.sleep(400)
      true
    } catch (e: Exception) {
      Log.e(TAG, "connect failed: ${e.message}", e)
      closeQuietly()
      false
    }
  }

  private fun normalizeMac(mac: String): String {
    val cleaned = mac.replace("-", ":").uppercase()
    // إن وُجد الجهاز في القائمة المقترنة نستخدم عنوانه كما هو مخزّن.
    try {
      val adapter = BluetoothAdapter.getDefaultAdapter()
      adapter?.bondedDevices?.forEach { d ->
        if (d.address.equals(cleaned, ignoreCase = true)) {
          return d.address
        }
      }
    } catch (_: Exception) {
    }
    return cleaned
  }

  private fun openSocketWithFallbacks(device: BluetoothDevice): BluetoothSocket? {
    val attempts = listOf(
      "secure_spp" to {
        device.createRfcommSocketToServiceRecord(SPP_UUID)
      },
      "insecure_spp" to {
        device.createInsecureRfcommSocketToServiceRecord(SPP_UUID)
      },
      "reflection_ch1" to {
        val m = device.javaClass.getMethod(
          "createRfcommSocket",
          Int::class.javaPrimitiveType,
        )
        m.invoke(device, 1) as BluetoothSocket
      },
      "reflection_insecure_ch1" to {
        val m = device.javaClass.getMethod(
          "createInsecureRfcommSocket",
          Int::class.javaPrimitiveType,
        )
        m.invoke(device, 1) as BluetoothSocket
      },
    )

    var lastError: Exception? = null
    for ((label, factory) in attempts) {
      var candidate: BluetoothSocket? = null
      try {
        candidate = factory()
        try {
          BluetoothAdapter.getDefaultAdapter()?.cancelDiscovery()
        } catch (_: Exception) {
        }
        candidate.connect()
        if (candidate.isConnected) {
          Log.i(TAG, "connected via $label")
          return candidate
        }
        candidate.close()
      } catch (e: Exception) {
        lastError = e
        Log.w(TAG, "attempt $label failed: ${e.message}")
        try {
          candidate?.close()
        } catch (_: Exception) {
        }
        try {
          Thread.sleep(350)
        } catch (_: InterruptedException) {
        }
      }
    }
    if (lastError != null) {
      Log.e(TAG, "all connect attempts failed", lastError)
    }
    return null
  }

  private fun writeInternal(list: List<Int>): Boolean {
    if (!isSocketAlive()) return false
    val out = output ?: return false
    return try {
      val bytes = ByteArray(list.size) { i -> list[i].toByte() }
      val chunkSize = 512 // دفعات صغيرة أنسب لطابعات Xprinter
      var offset = 0
      while (offset < bytes.size) {
        val end = minOf(offset + chunkSize, bytes.size)
        out.write(bytes, offset, end - offset)
        out.flush()
        offset = end
        if (offset < bytes.size) {
          Thread.sleep(20)
        }
      }
      true
    } catch (e: Exception) {
      Log.e(TAG, "write failed: ${e.message}", e)
      closeQuietly()
      false
    }
  }

  private fun closeQuietly() {
    try {
      output?.close()
    } catch (_: Exception) {
    }
    try {
      socket?.close()
    } catch (_: Exception) {
    }
    output = null
    socket = null
    connectedMac = null
  }
}
