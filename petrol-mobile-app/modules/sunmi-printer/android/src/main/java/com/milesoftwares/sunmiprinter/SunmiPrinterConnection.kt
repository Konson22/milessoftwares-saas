package com.milesoftwares.sunmiprinter

import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager
import com.sunmi.peripheral.printer.InnerPrinterCallback
import com.sunmi.peripheral.printer.InnerPrinterException
import com.sunmi.peripheral.printer.InnerPrinterManager
import com.sunmi.peripheral.printer.SunmiPrinterService
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/** Package of the SUNMI printer service, as documented by SUNMI and used by InnerPrinterManager. */
const val SUNMI_SERVICE_PACKAGE = "woyou.aidlservice.jiuiv5"

/**
 * Owns the binding to the SUNMI printer service.
 *
 * Callers must only use [ensureConnected] from a background thread: it blocks while the service
 * binds. Android re-delivers `onServiceConnected` after the service process restarts, so a
 * disconnect usually heals itself; if the binding dies outright we drop it and rebind on the next
 * call.
 */
class SunmiPrinterConnection(
  private val context: Context,
  private val onConnectionChange: (connected: Boolean) -> Unit
) {
  @Volatile private var service: SunmiPrinterService? = null
  @Volatile private var bound = false
  @Volatile private var connectedLatch = CountDownLatch(1)

  private val callback = object : InnerPrinterCallback() {
    override fun onConnected(service: SunmiPrinterService) {
      this@SunmiPrinterConnection.service = service
      connectedLatch.countDown()
      onConnectionChange(true)
    }

    override fun onDisconnected() {
      service = null
      connectedLatch = CountDownLatch(1)
      onConnectionChange(false)
    }

    override fun onBindingDied(name: ComponentName?) {
      service = null
      unbindQuietly()
      onConnectionChange(false)
    }

    override fun onNullBinding(name: ComponentName?) {
      unbindQuietly()
    }
  }

  val isConnected: Boolean
    get() = service != null

  fun isServiceInstalled(): Boolean = try {
    @Suppress("DEPRECATION")
    context.packageManager.getPackageInfo(SUNMI_SERVICE_PACKAGE, 0)
    true
  } catch (_: PackageManager.NameNotFoundException) {
    false
  }

  fun currentService(): SunmiPrinterService? = service

  @Synchronized
  fun ensureConnected(timeoutMs: Long): SunmiPrinterService {
    service?.let { return it }
    if (!isServiceInstalled()) {
      throw PrinterException(
        "SERVICE_NOT_INSTALLED",
        "The SUNMI printer service ($SUNMI_SERVICE_PACKAGE) is not installed on this device."
      )
    }
    if (!bound) {
      connectedLatch = CountDownLatch(1)
      bound = try {
        InnerPrinterManager.getInstance().bindService(context, callback)
      } catch (e: InnerPrinterException) {
        throw PrinterException("BIND_FAILED", "Could not bind to the SUNMI printer service: ${e.message}", e)
      } catch (e: SecurityException) {
        throw PrinterException("BIND_FAILED", "Not allowed to bind to the SUNMI printer service: ${e.message}", e)
      }
      if (!bound) {
        throw PrinterException("BIND_FAILED", "The SUNMI printer service refused the connection.")
      }
    }
    connectedLatch.await(timeoutMs, TimeUnit.MILLISECONDS)
    return service ?: throw PrinterException(
      "CONNECT_TIMEOUT",
      "The SUNMI printer service did not connect within ${timeoutMs} ms."
    )
  }

  @Synchronized
  fun release() {
    unbindQuietly()
    service = null
  }

  private fun unbindQuietly() {
    if (!bound) return
    bound = false
    try {
      InnerPrinterManager.getInstance().unBindService(context, callback)
    } catch (_: Exception) {
      // Already unbound or the service is gone; nothing to release.
    }
  }
}
