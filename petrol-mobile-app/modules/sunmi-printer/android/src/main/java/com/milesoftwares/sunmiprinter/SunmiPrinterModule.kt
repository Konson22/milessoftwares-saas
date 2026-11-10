package com.milesoftwares.sunmiprinter

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.Build
import android.os.RemoteException
import android.util.Base64
import com.sunmi.peripheral.printer.InnerResultCallback
import com.sunmi.peripheral.printer.SunmiPrinterService
import expo.modules.kotlin.Promise
import expo.modules.kotlin.exception.CodedException
import expo.modules.kotlin.exception.Exceptions
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import java.util.Collections
import java.util.concurrent.CountDownLatch
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

private const val CONNECT_TIMEOUT_MS = 5_000L
private const val STATUS_CONNECT_TIMEOUT_MS = 3_000L
private const val STATE_READY = 1
// 80 mm paper is 576 dots wide; wider bitmaps are clipped or refused by the service.
private const val MAX_IMAGE_WIDTH = 576

// Bold on/off — the method SUNMI documents for bold text ("1.2.4 Set and cancel bold").
private val ESC_BOLD_ON = byteArrayOf(0x1B, 0x45, 0x01)
private val ESC_BOLD_OFF = byteArrayOf(0x1B, 0x45, 0x00)

/**
 * Expo module for the SUNMI built-in printer (V2s and other SUNMI devices exposing
 * `woyou.aidlservice.jiuiv5`).
 *
 * Every call runs on one dedicated worker thread, so print jobs are serialized natively and the JS
 * and main threads never block on the binder. Jobs are printed in SUNMI "transaction" mode: the
 * service buffers the whole receipt and reports the real print result through `onPrintResult`
 * (0 = printed, 1 = failed) — `onRunResult` only means a command was accepted.
 */
class SunmiPrinterModule : Module() {
  private val worker: ExecutorService = Executors.newSingleThreadExecutor { runnable ->
    Thread(runnable, "SunmiPrinter").apply { isDaemon = true }
  }
  private var connection: SunmiPrinterConnection? = null

  private val context: Context
    get() = appContext.reactContext?.applicationContext ?: throw Exceptions.ReactContextLost()

  override fun definition() = ModuleDefinition {
    Name("SunmiPrinter")

    Events("onConnectionChange")

    Function("getDeviceInfo") {
      mapOf(
        "manufacturer" to Build.MANUFACTURER,
        "brand" to Build.BRAND,
        "model" to Build.MODEL,
        "device" to Build.DEVICE,
        "isSunmi" to (Build.MANUFACTURER.equals("SUNMI", ignoreCase = true) ||
          Build.BRAND.equals("SUNMI", ignoreCase = true)),
        "serviceInstalled" to connection().isServiceInstalled()
      )
    }

    AsyncFunction("initialize") { promise: Promise ->
      onWorker(promise) {
        connection().ensureConnected(CONNECT_TIMEOUT_MS)
        null
      }
    }

    AsyncFunction("getStatus") { promise: Promise ->
      onWorker(promise) { readStatus() }
    }

    AsyncFunction("print") { commands: List<PrintCommand>, timeoutMs: Int, promise: Promise ->
      onWorker(promise) { print(commands, timeoutMs.toLong()) }
    }

    OnDestroy {
      worker.shutdownNow()
      connection?.release()
      connection = null
    }
  }

  private fun connection(): SunmiPrinterConnection =
    connection ?: synchronized(this) {
      connection ?: SunmiPrinterConnection(context) { connected ->
        sendEvent("onConnectionChange", mapOf("connected" to connected))
      }.also { connection = it }
    }

  private fun onWorker(promise: Promise, block: () -> Any?) {
    worker.execute {
      try {
        promise.resolve(block())
      } catch (e: CodedException) {
        promise.reject(e)
      } catch (e: RemoteException) {
        promise.reject(PrinterException("REMOTE_ERROR", e.message ?: "The printer service call failed.", e))
      } catch (e: Throwable) {
        promise.reject(PrinterException("NATIVE_ERROR", e.message ?: e.javaClass.simpleName, e))
      }
    }
  }

  private fun readStatus(): Map<String, Any?> {
    val conn = connection()
    val installed = conn.isServiceInstalled()
    var connectError: String? = null
    val service: SunmiPrinterService? = if (installed) {
      try {
        conn.ensureConnected(STATUS_CONNECT_TIMEOUT_MS)
      } catch (e: CodedException) {
        connectError = e.message
        null
      }
    } else {
      null
    }

    // Individual getters can throw on models/firmware that lack them; report them as unknown.
    return mapOf(
      "serviceInstalled" to installed,
      "connected" to (service != null),
      "connectError" to connectError,
      "stateCode" to service?.let { s -> attempt { s.updatePrinterState() } },
      "paper" to service?.let { s -> attempt { s.printerPaper } },
      "serviceVersion" to service?.let { s -> attempt { s.serviceVersion } },
      "printerVersion" to service?.let { s -> attempt { s.printerVersion } },
      "printerModel" to service?.let { s -> attempt { s.printerModal } },
      "printerSerial" to service?.let { s -> attempt { s.printerSerialNo } }
    )
  }

  private fun print(commands: List<PrintCommand>, timeoutMs: Long): Map<String, Any?> {
    commands.forEach(::validate)
    val service = connection().ensureConnected(CONNECT_TIMEOUT_MS)

    val stateBefore = service.updatePrinterState()
    if (stateBefore != STATE_READY) {
      return mapOf("outcome" to "rejected", "stateCode" to stateBefore, "message" to null, "commandErrors" to emptyList<String>())
    }

    val commandErrors = Collections.synchronizedList(mutableListOf<String>())
    val step = StepCallback(commandErrors)
    val result = TransactionCallback()

    service.printerInit(step)
    service.enterPrinterBuffer(true)
    try {
      commands.forEach { apply(service, it, step) }
      service.exitPrinterBufferWithCallback(true, result)
    } catch (e: RemoteException) {
      attempt { service.exitPrinterBuffer(false) }
      throw PrinterException("REMOTE_ERROR", "The printer service failed while receiving the job: ${e.message}", e)
    }

    val finished = result.await(timeoutMs)
    val stateAfter = attempt { service.updatePrinterState() }
    val outcome = when {
      !finished -> "unknown"
      result.code == 0 -> "completed"
      else -> "failed"
    }
    return mapOf(
      "outcome" to outcome,
      "stateCode" to stateAfter,
      "message" to result.message,
      "commandErrors" to commandErrors.toList()
    )
  }

  private fun validate(cmd: PrintCommand) {
    fun invalid(reason: String): Nothing = throw PrinterException("INVALID_COMMAND", "Invalid ${cmd.type} command: $reason")
    when (cmd.type) {
      "text" -> {
        if (cmd.text == null) invalid("text is required")
        if (cmd.align !in 0..2) invalid("align must be 0..2")
        cmd.fontSize?.let { if (it < 8 || it > 96) invalid("fontSize must be 8..96") }
      }
      "feed" -> if (cmd.lines !in 1..20) invalid("lines must be 1..20")
      "qr" -> {
        if (cmd.text.isNullOrEmpty()) invalid("text is required")
        if (cmd.moduleSize !in 4..16) invalid("moduleSize must be 4..16")
        if (cmd.errorLevel !in 0..3) invalid("errorLevel must be 0..3")
      }
      "barcode" -> {
        if (cmd.text.isNullOrEmpty()) invalid("text is required")
        if (cmd.symbology !in 0..8) invalid("symbology must be 0..8")
        if (cmd.height !in 1..255) invalid("height must be 1..255")
        if (cmd.width !in 2..6) invalid("width must be 2..6")
        if (cmd.textPosition !in 0..3) invalid("textPosition must be 0..3")
      }
      "image" -> {
        val bitmap = decodeImage(cmd) ?: invalid("base64 is not a PNG or JPEG image")
        if (bitmap.width > MAX_IMAGE_WIDTH) invalid("image is wider than $MAX_IMAGE_WIDTH dots")
      }
      else -> invalid("unknown command type")
    }
  }

  private fun apply(service: SunmiPrinterService, cmd: PrintCommand, step: InnerResultCallback) {
    when (cmd.type) {
      "text" -> {
        val text = cmd.text!!
        service.setAlignment(cmd.align, step)
        cmd.fontSize?.let { service.setFontSize(it.toFloat(), step) }
        if (cmd.bold) service.sendRAWData(ESC_BOLD_ON, step)
        // printText only flushes a line once it sees "\n".
        service.printText(if (text.endsWith("\n")) text else "$text\n", step)
        if (cmd.bold) service.sendRAWData(ESC_BOLD_OFF, step)
      }
      "feed" -> service.lineWrap(cmd.lines, step)
      "qr" -> {
        service.setAlignment(cmd.align, step)
        service.printQRCode(cmd.text!!, cmd.moduleSize, cmd.errorLevel, step)
        service.lineWrap(1, step)
      }
      "barcode" -> {
        service.setAlignment(cmd.align, step)
        service.printBarCode(cmd.text!!, cmd.symbology, cmd.height, cmd.width, cmd.textPosition, step)
        service.lineWrap(1, step)
      }
      "image" -> {
        service.setAlignment(cmd.align, step)
        service.printBitmap(decodeImage(cmd)!!, step)
        service.lineWrap(1, step)
      }
    }
  }

  private fun decodeImage(cmd: PrintCommand): Bitmap? = attempt {
    val bytes = Base64.decode(cmd.base64 ?: return@attempt null, Base64.DEFAULT)
    BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
  }

  private inline fun <T> attempt(block: () -> T): T? = try {
    block()
  } catch (_: Exception) {
    null
  }
}

/** Collects per-command exceptions (e.g. unsupported method on this model) for diagnostics. */
private class StepCallback(private val errors: MutableList<String>) : InnerResultCallback() {
  override fun onRunResult(isSuccess: Boolean) {}
  override fun onReturnString(result: String?) {}
  override fun onRaiseException(code: Int, msg: String?) {
    errors.add("$code: ${msg ?: "unknown"}")
  }
  override fun onPrintResult(code: Int, msg: String?) {}
}

/** Waits for the transaction result (`onPrintResult`), or an exception raised for the commit. */
private class TransactionCallback : InnerResultCallback() {
  private val done = CountDownLatch(1)
  @Volatile var code: Int = -1
    private set
  @Volatile var message: String? = null
    private set

  fun await(timeoutMs: Long): Boolean = done.await(timeoutMs, TimeUnit.MILLISECONDS)

  override fun onRunResult(isSuccess: Boolean) {}
  override fun onReturnString(result: String?) {}
  override fun onRaiseException(code: Int, msg: String?) {
    this.code = if (code == 0) -1 else code
    message = msg
    done.countDown()
  }
  override fun onPrintResult(code: Int, msg: String?) {
    this.code = code
    message = msg
    done.countDown()
  }
}
