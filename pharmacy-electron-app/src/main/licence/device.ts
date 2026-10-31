import { execFileSync } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { app } from 'electron';

import { formatCode } from './codes';

/**
 * This PC, as a short device code a customer can read out over the phone
 * (7KQ2-M9XD-4FTA). It comes from the OS's own machine id, hashed: the raw id
 * never leaves the PC. Reinstalling the app keeps it; reinstalling the OS
 * does not.
 */

function machineId(): string | null {
  try {
    if (process.platform === 'win32') {
      const out = execFileSync('reg', ['query', 'HKLM\\SOFTWARE\\Microsoft\\Cryptography', '/v', 'MachineGuid'], { encoding: 'utf8', windowsHide: true });
      return /MachineGuid\s+REG_SZ\s+(\S+)/.exec(out)?.[1] ?? null;
    }
    if (process.platform === 'darwin') {
      const out = execFileSync('ioreg', ['-rd1', '-c', 'IOPlatformExpertDevice'], { encoding: 'utf8' });
      return /"IOPlatformUUID"\s*=\s*"([^"]+)"/.exec(out)?.[1] ?? null;
    }
    for (const path of ['/etc/machine-id', '/var/lib/dbus/machine-id']) {
      try {
        const id = readFileSync(path, 'utf8').trim();
        if (id) return id;
      } catch {
        // The next one.
      }
    }
  } catch {
    // Below: one of the app's own.
  }
  return null;
}

/** Where the OS gives none: an id of the app's own, kept in userData. */
function ownId(): string {
  const file = join(app.getPath('userData'), 'device.json');
  try {
    const id = JSON.parse(readFileSync(file, 'utf8')).id;
    if (typeof id === 'string' && id) return id;
  } catch {
    // Made below.
  }
  const id = randomUUID();
  writeFileSync(file, JSON.stringify({ id }));
  return id;
}

let code: string | null = null;

export function deviceCode(): string {
  if (!code) {
    const hash = createHash('sha256').update(`miles-pharmacy-device:${machineId() ?? ownId()}`).digest();
    code = formatCode(hash, 12);
  }
  return code;
}
