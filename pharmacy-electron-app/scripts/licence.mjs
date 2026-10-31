#!/usr/bin/env node
/**
 * Issues an activation code for one PC and one pharmacy, signed with the
 * licence key (src/main/licence/codes.ts checks it with the public half).
 * Until central-app issues them (phase 3), the test key lives in
 * licence-keys/test-private.pem, out of git.
 *
 *   node scripts/licence.mjs --device 7KQ2-M9XD-4FTA --pharmacy AB12-CD34 --name "Juba Pharmacy" [--expires 2027-12-31] [--key path.pem]
 */
import { createPrivateKey, randomUUID, sign } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from 'node:util';

const { values } = parseArgs({
  options: {
    device: { type: 'string' },
    pharmacy: { type: 'string' },
    name: { type: 'string', default: '' },
    expires: { type: 'string' },
    key: { type: 'string', default: join(import.meta.dirname, '..', 'licence-keys', 'test-private.pem') },
  },
});

if (!values.device || !values.pharmacy) {
  console.error('Usage: node scripts/licence.mjs --device XXXX-XXXX-XXXX --pharmacy XXXX-XXXX --name "Pharmacy" [--expires YYYY-MM-DD] [--key file.pem]');
  process.exit(1);
}

const expires = values.expires ? new Date(`${values.expires}T23:59:59Z`) : null;
if (expires && Number.isNaN(expires.getTime())) {
  console.error('--expires: a date as 2027-12-31.');
  process.exit(1);
}

const licence = {
  id: randomUUID(),
  type: 'single',
  device: values.device.toUpperCase(),
  branch: values.pharmacy.toUpperCase(),
  name: values.name,
  issued: new Date().toISOString(),
  expires: expires ? expires.toISOString() : null,
  max_devices: 1,
  max_branches: 1,
};

const key = createPrivateKey(readFileSync(values.key));
const body = Buffer.from(JSON.stringify(licence), 'utf8');
console.log(`MP1.${body.toString('base64url')}.${sign(null, body, key).toString('base64url')}`);
