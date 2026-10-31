import { createPrivateKey, createPublicKey, sign, verify, type KeyObject } from 'node:crypto';

/**
 * Activation codes: an Ed25519-signed licence, checked on this PC with the
 * public key below, so activating needs no internet. The private key never
 * ships: today a test key (scripts/licence.mjs), later central-app's.
 *
 * A code reads MP1.<licence>.<signature>, both base64url. It is pasted, not
 * typed; spaces and line breaks in it are ignored.
 */

/**
 * The public half of the key codes are signed with (SPKI, DER, base64), set
 * at build time: VITE_LICENCE_PUBLIC_KEY, from `php artisan licences:keygen
 * --show` on the server (production) or the test key (local .env). Built
 * without one, no code activates.
 */
export const PUBLIC_KEY = import.meta.env.VITE_LICENCE_PUBLIC_KEY ?? '';

const PREFIX = 'MP1';

/** single: one PC, one pharmacy. The others are carried now so they can grow later. */
export type LicenceType = 'single' | 'multi_device' | 'multi_branch';

export interface Licence {
  id: string;
  type: LicenceType;
  /** The device code it was issued for (XXXX-XXXX-XXXX). */
  device: string;
  /** The pharmacy ID it was issued for (XXXX-XXXX). */
  branch: string;
  /** The pharmacy's name, as issued: shown, not checked. */
  name: string;
  issued: string;
  /** null: never. */
  expires: string | null;
  max_devices: number;
  max_branches: number;
}

/** Why a code does not open this PC. */
export type CodeProblem = 'format' | 'signature' | 'device' | 'branch' | 'expired';

export class CodeError extends Error {
  problem: CodeProblem;

  constructor(problem: CodeProblem) {
    super(problem);
    this.problem = problem;
  }
}

function publicKey(key: string | KeyObject): KeyObject {
  if (typeof key !== 'string') return key;
  try {
    return createPublicKey({ key: Buffer.from(key, 'base64'), format: 'der', type: 'spki' });
  } catch {
    // No key built in, or a broken one: nothing verifies.
    throw new CodeError('signature');
  }
}

/** Signs a licence into a code (the issuer's side: scripts and tests). */
export function encodeLicence(licence: Licence, privateKey: string | KeyObject): string {
  const key = typeof privateKey === 'string' ? createPrivateKey(privateKey) : privateKey;
  const body = Buffer.from(JSON.stringify(licence), 'utf8');
  return `${PREFIX}.${body.toString('base64url')}.${sign(null, body, key).toString('base64url')}`;
}

/** The licence a code carries, when its signature holds. */
export function decodeLicence(code: string, key: string | KeyObject = PUBLIC_KEY): Licence {
  const parts = code.replace(/\s+/g, '').split('.');
  if (parts.length !== 3 || parts[0] !== PREFIX) throw new CodeError('format');
  const body = Buffer.from(parts[1], 'base64url');
  const signature = Buffer.from(parts[2], 'base64url');
  if (body.length === 0 || signature.length !== 64) throw new CodeError('format');
  if (!verify(null, body, publicKey(key), signature)) throw new CodeError('signature');
  let licence: Licence;
  try {
    licence = JSON.parse(body.toString('utf8')) as Licence;
  } catch {
    throw new CodeError('format');
  }
  if (typeof licence?.id !== 'string' || typeof licence.device !== 'string' || typeof licence.branch !== 'string') throw new CodeError('format');
  return licence;
}

/** Whether a licence is for this PC and this pharmacy, and still running; null when it is. */
export function checkLicence(licence: Licence, here: { device: string; branch: string; now: Date }): CodeProblem | null {
  if (normalizeCode(licence.device) !== normalizeCode(here.device)) return 'device';
  if (normalizeCode(licence.branch) !== normalizeCode(here.branch)) return 'branch';
  if (licence.expires !== null && Date.parse(licence.expires) <= here.now.getTime()) return 'expired';
  return null;
}

// Crockford's Base32: no I, L, O or U, so a code read out over the phone is not misheard.
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/** Bytes as so many Base32 characters, in groups of four: "7KQ2-M9XD-4FTA". */
export function formatCode(bytes: Uint8Array, length: number): string {
  let bits = 0;
  let value = 0;
  let out = '';
  for (const byte of bytes) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5 && out.length < length) {
      out += ALPHABET[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
    value &= (1 << bits) - 1;
    if (out.length >= length) break;
  }
  return out.match(/.{1,4}/g)!.join('-');
}

/** As typed or read out: any case, with or without dashes, O for 0 and I or L for 1. */
export function normalizeCode(code: string): string {
  return code
    .toUpperCase()
    .replace(/[^0-9A-Z]/g, '')
    .replace(/O/g, '0')
    .replace(/[IL]/g, '1');
}
