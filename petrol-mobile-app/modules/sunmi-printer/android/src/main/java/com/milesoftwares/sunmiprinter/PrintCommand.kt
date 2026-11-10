package com.milesoftwares.sunmiprinter

import expo.modules.kotlin.exception.CodedException
import expo.modules.kotlin.records.Field
import expo.modules.kotlin.records.Record

/** Error surfaced to JavaScript with a stable `code` (see src/SunmiPrinter.types.ts). */
class PrinterException(code: String, message: String, cause: Throwable? = null) :
  CodedException(code, message, cause)

/**
 * One step of a print job. Only these structured steps are accepted from JavaScript: the module
 * never forwards arbitrary raw ESC/POS bytes.
 *
 * type = "text"    → [text] with [align], [bold], [fontSize]
 * type = "feed"    → [lines] blank lines
 * type = "qr"      → [text] as a QR code, [moduleSize] 1..16, [errorLevel] 0..3
 * type = "barcode" → [text] with [symbology] 0..8, [height] 1..255, [width] 2..6, [textPosition] 0..3
 * type = "image"   → [base64] PNG/JPEG printed as a bitmap with [align], at most 576 dots wide
 */
class PrintCommand : Record {
  @Field var type: String = ""
  @Field var text: String? = null
  @Field var align: Int = 0
  @Field var bold: Boolean = false
  @Field var fontSize: Double? = null
  @Field var lines: Int = 1
  @Field var moduleSize: Int = 6
  @Field var errorLevel: Int = 1
  @Field var symbology: Int = 8
  @Field var height: Int = 80
  @Field var width: Int = 2
  @Field var textPosition: Int = 2
  @Field var base64: String? = null
}
