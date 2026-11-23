/**
 * UUIDv7 (RFC 9562): 48-bit Unix milliseconds, then random bits. Made on
 * the phone, so the id exists before the server has heard of it and a
 * resend carries the same idempotency key.
 */
export function uuidv7(now: number, random: (n: number) => Uint8Array): string {
  const bytes = random(16);
  let ms = Math.floor(now);
  for (let i = 5; i >= 0; i--) {
    bytes[i] = ms % 256;
    ms = Math.floor(ms / 256);
  }
  bytes[6] = 0x70 | (bytes[6] & 0x0f);
  bytes[8] = 0x80 | (bytes[8] & 0x3f);
  const hex = toHex(bytes);
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function toHex(buffer: ArrayBuffer | Uint8Array): string {
  return Array.from(buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');
}
