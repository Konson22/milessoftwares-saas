import { generateKeyPairSync } from 'node:crypto';

import { describe, expect, it } from 'vitest';

import { checkLicence, CodeError, decodeLicence, encodeLicence, formatCode, normalizeCode, PUBLIC_KEY, type Licence } from './codes';
import { mergeTrial, seen, trialStatus, TRIAL_DAYS } from './trial';

const DAY = 24 * 60 * 60 * 1000;
const { publicKey, privateKey } = generateKeyPairSync('ed25519');
const other = generateKeyPairSync('ed25519');

const licence = (patch: Partial<Licence> = {}): Licence => ({
  id: 'lic-1',
  type: 'single',
  device: '7KQ2-M9XD-4FTA',
  branch: 'AB12-CD34',
  name: 'Juba Pharmacy',
  issued: '2026-10-31T00:00:00.000Z',
  expires: null,
  max_devices: 1,
  max_branches: 1,
  ...patch,
});

const problem = (fn: () => unknown) => {
  try {
    fn();
  } catch (error) {
    return error instanceof CodeError ? error.problem : 'other';
  }
  return null;
};

describe('activation codes', () => {
  it('round-trips a signed licence', () => {
    const code = encodeLicence(licence(), privateKey);
    expect(code.startsWith('MP1.')).toBe(true);
    expect(decodeLicence(code, publicKey)).toEqual(licence());
  });

  it('ignores spaces and line breaks in a pasted code', () => {
    const code = encodeLicence(licence(), privateKey);
    const pasted = `  ${code.slice(0, 40)}\n${code.slice(40, 90)} ${code.slice(90)}  `;
    expect(decodeLicence(pasted, publicKey).id).toBe('lic-1');
  });

  it('refuses a code signed with another key', () => {
    expect(problem(() => decodeLicence(encodeLicence(licence(), other.privateKey), publicKey))).toBe('signature');
  });

  it('refuses a licence changed after signing', () => {
    const [prefix, , signature] = encodeLicence(licence(), privateKey).split('.');
    const forged = Buffer.from(JSON.stringify(licence({ expires: null, device: 'ZZZZ-ZZZZ-ZZZZ' }))).toString('base64url');
    expect(problem(() => decodeLicence(`${prefix}.${forged}.${signature}`, publicKey))).toBe('signature');
  });

  it('refuses what is not a code', () => {
    expect(problem(() => decodeLicence('hello', publicKey))).toBe('format');
    expect(problem(() => decodeLicence('MP1.abc.def', publicKey))).toBe('format');
    expect(problem(() => decodeLicence('', publicKey))).toBe('format');
  });

  it('ships a usable public key', () => {
    expect(problem(() => decodeLicence(encodeLicence(licence(), privateKey), PUBLIC_KEY))).toBe('signature');
  });

  it('checks the device, the pharmacy and the end date', () => {
    const now = new Date('2026-11-01T00:00:00Z');
    const here = { device: '7kq2m9xd4fta', branch: 'ab12-cd34', now };
    expect(checkLicence(licence(), here)).toBeNull();
    expect(checkLicence(licence(), { ...here, device: 'AAAA-BBBB-CCCC' })).toBe('device');
    expect(checkLicence(licence(), { ...here, branch: 'AAAA-BBBB' })).toBe('branch');
    expect(checkLicence(licence({ expires: '2026-10-31T23:59:59Z' }), here)).toBe('expired');
    expect(checkLicence(licence({ expires: '2027-10-31T23:59:59Z' }), here)).toBeNull();
  });
});

describe('codes read out', () => {
  it('formats bytes as Base32 groups of four', () => {
    const code = formatCode(new Uint8Array([0xff, 0x00, 0xab, 0xcd, 0xef, 0x12, 0x34, 0x56]), 12);
    expect(code).toMatch(/^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/);
  });

  it('is the same for the same bytes', () => {
    const bytes = new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8]);
    expect(formatCode(bytes, 8)).toBe(formatCode(bytes, 8));
  });

  it('reads O as 0 and I or L as 1, in any case', () => {
    expect(normalizeCode('o1il-abcd')).toBe('0111ABCD');
  });
});

describe('the trial', () => {
  const start = new Date('2026-01-01T00:00:00Z');
  const record = { started_at: start.toISOString(), last_seen_at: start.toISOString() };
  const at = (days: number) => new Date(start.getTime() + days * DAY);

  it(`runs ${TRIAL_DAYS} days`, () => {
    expect(trialStatus(record, at(0))).toMatchObject({ kind: 'running', daysLeft: TRIAL_DAYS });
    expect(trialStatus(record, at(TRIAL_DAYS - 0.5))).toMatchObject({ kind: 'running', daysLeft: 1 });
    expect(trialStatus(record, at(TRIAL_DAYS))).toMatchObject({ kind: 'ended' });
    expect(trialStatus(record, at(TRIAL_DAYS + 30))).toMatchObject({ kind: 'ended' });
  });

  it('keeps the earliest start of the two copies', () => {
    const later = { started_at: at(40).toISOString(), last_seen_at: at(45).toISOString() };
    expect(mergeTrial(later, record)).toEqual({ started_at: record.started_at, last_seen_at: later.last_seen_at });
  });

  it('starts nothing from unreadable copies', () => {
    expect(mergeTrial(null, undefined, { started_at: 'nonsense', last_seen_at: 'x' })).toBeNull();
  });

  it('spots a clock turned back more than a day', () => {
    const used = seen(record, at(50));
    expect(trialStatus(used, at(49.5))).toMatchObject({ kind: 'running' });
    expect(trialStatus(used, at(45))).toEqual({ kind: 'clock', lastSeenAt: used.last_seen_at });
  });

  it('never moves the last time seen backwards', () => {
    const used = seen(record, at(50));
    expect(seen(used, at(10))).toEqual(used);
  });
});
