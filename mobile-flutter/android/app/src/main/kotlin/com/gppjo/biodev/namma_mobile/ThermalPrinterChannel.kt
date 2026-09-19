package com.gppjo.biodev.namma_mobile

import android.Manifest
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
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
import java.io.IOException
import java.io.OutputStream
import java.util.UUID
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference

/**
 * قناة طباعة حرارية داخل التطبيق.
 *
 * لا تعتمد على print_bluetooth_thermal في الاتصال/الإرسال لأن تلك الإضافة:
 * - ترجع false إن وُجد outputStream قديم
 * - تستدعي BluetoothSocket.connect على نحو قد يعلّق الواجهة
 * - لا تجرّب insecure RFCOMM الذي تحتاجه طابعات Xprinter مثل XP-P810
 */
class ThermalPrinterChannel(private val context: Context) : MethodChannel.MethodCallHandler {
  companion object {
    private const val CHANNEL = "com.gppjo.biodev.namma_mobile/thermal_printer"
    private const val TAG = "ThermalPrinter"
    private const val CONNECT_TIMEOUT_MS = 6000L
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
  private val lock = Any()
  private var socket: BluetoothSocket? = null
  private var output: OutputStream? = null
  private var connectedMac: String? = null

  private fun replySuccess(result: MethodChannel.Result, value: Any?) {
    mainHandler.post { result.success(value) }
  }

  private fun replyError(result: MethodChannel.Result, code: String, message: String?) {
    mainHandler.post { result.error(code, message, null) }
  }

  override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
    when (call.method) {
      "isBluetoothOn" -> {
        executor.execute { replySuccess(result, isBluetoothOn()) }
      }
      "hasPermission" -> result.success(hasBluetoothPermission())
      "pairedDevices" -> {
        executor.execute {
          if (!hasBluetoothPermission()) {
            replySuccess(result, emptyList<String>())
          } else {
            replySuccess(result, pairedDeviceStrings())
          }
        }
      }
      "connectionStatus" -> {
        executor.execute { replySuccess(result, isSocketAlive()) }
      }
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
          try {
            replySuccess(result, connectInternal(mac))
          } catch (e: Exception) {
            Log.e(TAG, "connect crashed", e)
            closeQuietly()
            replySuccess(result, false)
          }
        }
      }
      "writeBytes" -> {
        val bytes = toByteArray(call.arguments)
        if (bytes == null) {
          result.success(false)
          return
        }
        executor.execute {
          try {
            replySuccess(result, writeInternal(bytes))
          } catch (e: Exception) {
            Log.e(TAG, "write crashed", e)
            closeQuietly()
            replySuccess(result, false)
          }
        }
      }
      else -> result.notImplemented()
    }
  }

  private fun adapter(): BluetoothAdapter? {
    val manager = context.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager
    if (manager?.adapter != null) return manager.adapter
    @Suppress("DEPRECATION")
    return BluetoothAdapter.getDefaultAdapter()
  }

  private fun isBluetoothOn(): Boolean {
    return try {
      adapter()?.isEnabled == true
    } catch (_: Exception) {
      false
    }
  }

  private fun hasBluetoothPermission(): Boolean {
    if (Build.VERSION.SDK_INT < 31) return true
    return ContextCompat.checkSelfPermission(
      context,
      Manifest.permission.BLUETOOTH_CONNECT,
    ) == PackageManager.PERMISSION_GRANTED
  }

  private fun pairedDeviceStrings(): List<String> {
    return try {
      adapter()?.bondedDevices?.map { "${it.name ?: ""}#${it.address}" } ?: emptyList()
    } catch (e: SecurityException) {
      Log.w(TAG, "pairedDevices denied", e)
      emptyList()
    } catch (e: Exception) {
      Log.w(TAG, "pairedDevices failed", e)
      emptyList()
    }
  }

  private fun isSocketAlive(): Boolean {
    synchronized(lock) {
      val s = socket ?: return false
      val out = output
      if (out == null || !s.isConnected) {
        closeQuietlyLocked()
        return false
      }
      return true
    }
  }

  private fun connectInternal(macRaw: String): Boolean {
    val mac = macRaw.trim().uppercase()
    synchronized(lock) {
      if (isSocketAliveLocked() && connectedMac.equals(mac, ignoreCase = true)) {
        return true
      }
      closeQuietlyLocked()
    }

    val bluetoothAdapter = adapter() ?: return false
    if (bluetoothAdapter.isEnabled != true) return false

    return try {
      try {
        bluetoothAdapter.cancelDiscovery()
      } catch (_: Exception) {
      }

      val device = bluetoothAdapter.getRemoteDevice(normalizeMac(mac))
      val opened = openSocketWithFallbacks(device, bluetoothAdapter) ?: return false
      synchronized(lock) {
        socket = opened
        output = opened.outputStream
        connectedMac = opened.remoteDevice?.address?.uppercase() ?: mac
      }
      // استقرار قناة SPP قبل إرسال ESC/POS (مهم لـ XP-P810).
      Thread.sleep(350)
      true
    } catch (e: Exception) {
      Log.e(TAG, "connect failed: ${e.message}", e)
      closeQuietly()
      false
    }
  }

  private fun isSocketAliveLocked(): Boolean {
    val s = socket ?: return false
    val out = output
    if (out == null || !s.isConnected) {
      closeQuietlyLocked()
      return false
    }
    return true
  }

  private fun normalizeMac(mac: String): String {
    val cleaned = mac.replace("-", ":").uppercase()
    try {
      adapter()?.bondedDevices?.forEach { d ->
        if (d.address.equals(cleaned, ignoreCase = true)) {
          return d.address
        }
      }
    } catch (_: Exception) {
    }
    return cleaned
  }

  private fun openSocketWithFallbacks(
    device: BluetoothDevice,
    adapter: BluetoothAdapter,
  ): BluetoothSocket? {
    // insecure أولاً: طابعات Xprinter/XP-P810 غالباً لا تقبل secure SPP.
    val attempts = listOf(
      "insecure_spp" to {
        device.createInsecureRfcommSocketToServiceRecord(SPP_UUID)
      },
      "secure_spp" to {
        device.createRfcommSocketToServiceRecord(SPP_UUID)
      },
      "reflection_ch1" to {
        val m = device.javaClass.getMethod(
          "createRfcommSocket",
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
          adapter.cancelDiscovery()
        } catch (_: Exception) {
        }
        connectWithTimeout(candidate, CONNECT_TIMEOUT_MS)
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
          Thread.sleep(250)
        } catch (_: InterruptedException) {
        }
      }
    }
    if (lastError != null) {
      Log.e(TAG, "all connect attempts failed", lastError)
    }
    return null
  }

  /**
   * BluetoothSocket.connect() قد يعلق أكثر من 12 ثانية على بعض أجهزة أندرويد.
   * إغلاق المقبس من خيط آخر يفك الحظر بعد المهلة.
   */
  private fun connectWithTimeout(socket: BluetoothSocket, timeoutMs: Long) {
    val error = AtomicReference<Exception?>(null)
    val latch = CountDownLatch(1)
    val worker = Thread({
      try {
        socket.connect()
      } catch (e: Exception) {
        error.set(e)
      } finally {
        latch.countDown()
      }
    }, "thermal-bt-connect")
    worker.start()
    val finished = latch.await(timeoutMs, TimeUnit.MILLISECONDS)
    if (!finished) {
      try {
        socket.close()
      } catch (_: Exception) {
      }
      latch.await(1500, TimeUnit.MILLISECONDS)
      throw IOException("انتهت مهلة الاتصال بالطابعة")
    }
    val failed = error.get()
    if (failed != null) throw failed
  }

  private fun toByteArray(raw: Any?): ByteArray? {
    val list = raw as? List<*> ?: return null
    val bytes = ByteArray(list.size)
    for (i in list.indices) {
      val n = list[i] as? Number ?: return null
      bytes[i] = n.toByte()
    }
    return bytes
  }

  private fun writeInternal(bytes: ByteArray): Boolean {
    val out = synchronized(lock) {
      if (!isSocketAliveLocked()) return false
      output
    } ?: return false
    return try {
      val chunkSize = 1024
      var offset = 0
      while (offset < bytes.size) {
        val end = minOf(offset + chunkSize, bytes.size)
        out.write(bytes, offset, end - offset)
        out.flush()
        offset = end
      }
      true
    } catch (e: Exception) {
      Log.e(TAG, "write failed: ${e.message}", e)
      closeQuietly()
      false
    }
  }

  private fun closeQuietly() {
    synchronized(lock) {
      closeQuietlyLocked()
    }
  }

  private fun closeQuietlyLocked() {
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
