import { create } from 'zustand';

import { call } from '@/lib/api';

/** Why a code does not open this PC (src/main/licence/codes.ts). */
export type CodeProblem = 'format' | 'signature' | 'device' | 'branch' | 'expired';

/** Offline: whether this PC may sell (src/main/licence/index.ts). */
export type LicenceState = { deviceCode: string; pharmacyId: string; activateUrl: string } & (
  | { status: 'trial'; daysLeft: number; endsAt: string; problem: CodeProblem | null }
  | {
      status: 'licensed';
      licence: { id: string; type: string; name: string; issued: string; expires: string | null };
    }
  | { status: 'expired'; endedAt: string; what: 'trial' | 'licence' }
  | { status: 'invalid'; problem: CodeProblem }
  | { status: 'clock'; lastSeenAt: string }
);

interface LicenceStore {
  state: LicenceState | null;
  load(): Promise<void>;
  /** An activation code, pasted. */
  activate(code: string): Promise<LicenceState>;
  /** A licence key, sent to Miles Softwares for this PC's code. */
  activateKey(key: string): Promise<LicenceState>;
  /** A licence file; null when none was chosen. */
  openFile(): Promise<LicenceState | null>;
}

export const useLicence = create<LicenceStore>((set) => ({
  state: null,
  async load() {
    try {
      set({ state: await call<LicenceState>('licence.state') });
    } catch {
      // Shown again on the next look.
    }
  },
  async activate(code) {
    const state = await call<LicenceState>('licence.activate', code);
    set({ state });
    return state;
  },
  async activateKey(key) {
    const state = await call<LicenceState>('licence.activateKey', key);
    set({ state });
    return state;
  },
  async openFile() {
    const state = await call<LicenceState | null>('licence.openFile');
    if (state) set({ state });
    return state;
  },
}));

/** Selling works: a trial running, or a licence for this PC. */
export const canSell = (state: LicenceState | null) => state === null || state.status === 'trial' || state.status === 'licensed';
