package com.milesoftwares.sunmiprinter

import android.Manifest
import android.annotation.SuppressLint
import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothClass
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import java.io.IOException
import java.util.UUID

/** Serial Port Profile: what Bluetooth receipt printers (and POS built-in "InnerPrinter"s) speak. */
private val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")

/**
 * A Bluetooth receipt printer that takes raw ESC/POS bytes over RFCOMM: an external printer
 * paired with the phone, or a POS terminal's built-in printer exposed as a paired device
 * (often named "InnerPrinter", 00:11:22:33:44:55). Only paired devices are offered; pairing
 * happens in Android's Bluetooth settings.
 *
 * Not thread-safe: the module calls it from its single worker thread only.
 */
@SuppressLint("MissingPermission") // Checked by [requirePermission] before every call that needs it.
class BluetoothPrinter(private val context: Context) {
  private var socket: BluetoothSocket? = null
  private var address: String? = null

  private val adapter: BluetoothAdapter?
    get() = (context.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter

  fun state(): Map<String, Any?> = mapOf(
    "available" to (adapter != null),
    "enabled" to (adapter?.isEnabled == true),
    "permitted" to hasPermission(),
    "connectedAddress" to if (socket?.isConnected == true) address else null
  )

  /** The phone's paired devices, printers first. */
  fun pairedDevices(): List<Map<String, Any?>> {
    val bt = requireAdapter()
    return bt.bondedDevices
      .map { device ->
        val name = device.name ?: device.address
        mapOf(
          "name" to name,
          "address" to device.address,
          "isPrinter" to looksLikePrinter(device, name)
        )
      }
      .sortedWith(compareByDescending<Map<String, Any?>> { it["isPrinter"] as Boolean }.thenBy { (it["name"] as String).lowercase() })
  }

  /**
   * Writes one job. A connection kept from the last job is reused; if it has gone stale the
   * write fails before the printer takes anything, so the job is sent once more on a fresh
   * connection. Waits after writing, as the printer is still feeding paper when the bytes are
   * handed over, and a next job sent on top of it prints garbled.
   */
  fun print(target: String, bytes: ByteArray, settleMs: Long) {
    val reused = socket?.isConnected == true && address == target
    try {
      write(connect(target), bytes)
    } catch (e: IOException) {
      close()
      if (!reused) throw PrinterException("BT_WRITE_FAILED", "Could not send to the printer: ${e.message}", e)
      try {
        write(connect(target), bytes)
      } catch (again: IOException) {
        close()
        throw PrinterException("BT_WRITE_FAILED", "Could not send to the printer: ${again.message}", again)
      }
    }
    if (settleMs > 0) Thread.sleep(settleMs)
  }

  fun close() {
    try {
      socket?.close()
    } catch (_: IOException) {
      // Already closed.
    }
    socket = null
    address = null
  }

  private fun write(s: BluetoothSocket, bytes: ByteArray) {
    val out = s.outputStream
    // Small chunks: some printers' receive buffers overflow on one large write.
    var offset = 0
    while (offset < bytes.size) {
      val size = minOf(512, bytes.size - offset)
      out.write(bytes, offset, size)
      offset += size
    }
    out.flush()
  }

  private fun connect(target: String): BluetoothSocket {
    socket?.let { if (it.isConnected && address == target) return it }
    close()
    val bt = requireAdapter()
    val device = bt.bondedDevices.firstOrNull { it.address.equals(target, ignoreCase = true) }
      ?: throw PrinterException("BT_NOT_PAIRED", "The printer $target is not paired with this phone. Pair it in Bluetooth settings.")
    // Discovery slows a connection down, and can make it fail.
    try {
      bt.cancelDiscovery()
    } catch (_: SecurityException) {
      // Needs BLUETOOTH_SCAN on some versions; connecting works without it.
    }

    val attempts = listOf<() -> BluetoothSocket>(
      { device.createRfcommSocketToServiceRecord(SPP_UUID) },
      // Many cheap printers only take an insecure connection.
      { device.createInsecureRfcommSocketToServiceRecord(SPP_UUID) }
    )
    var last: IOException? = null
    for (create in attempts) {
      val candidate = try {
        create()
      } catch (e: IOException) {
        last = e
        continue
      }
      try {
        candidate.connect()
        socket = candidate
        address = target
        return candidate
      } catch (e: IOException) {
        last = e
        try {
          candidate.close()
        } catch (_: IOException) {
        }
      }
    }
    throw PrinterException("BT_CONNECT_FAILED", "Could not connect to the printer: ${last?.message ?: "no answer"}. Is it on and in range?", last)
  }

  private fun requireAdapter(): BluetoothAdapter {
    val bt = adapter ?: throw PrinterException("BT_UNAVAILABLE", "This phone has no Bluetooth.")
    requirePermission()
    if (!bt.isEnabled) throw PrinterException("BT_OFF", "Bluetooth is off. Turn it on to print.")
    return bt
  }

  private fun hasPermission(): Boolean =
    Build.VERSION.SDK_INT < Build.VERSION_CODES.S ||
      context.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED

  private fun requirePermission() {
    if (!hasPermission()) throw PrinterException("BT_PERMISSION", "Allow this app to use nearby devices (Bluetooth) to print.")
  }

  private fun looksLikePrinter(device: BluetoothDevice, name: String): Boolean {
    val major = try {
      device.bluetoothClass?.majorDeviceClass
    } catch (_: SecurityException) {
      null
    }
    return major == BluetoothClass.Device.Major.IMAGING ||
      Regex("(?i)print|pos|thermal|receipt|inner|mpt|rpp|xp-|pt-").containsMatchIn(name)
  }
}
