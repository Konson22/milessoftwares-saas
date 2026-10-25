import { create } from 'zustand';

/** Where the app is, as the restaurant's web path ("floor", "orders/<id>"; "" is home). */
interface Toast {
  id: number;
  tone: 'green' | 'amber' | 'red' | 'blue';
  message: string;
}

interface UiState {
  path: string;
  /** Pages opened before this one, for Back. */
  history: string[];
  /** Whether the restaurant answered the last call. */
  online: boolean;
  toasts: Toast[];
  open(path: string): void;
  back(): void;
  setOnline(online: boolean): void;
  toast(message: string, tone?: Toast['tone']): void;
  dismiss(id: number): void;
}

let seq = 0;
const MAX_HISTORY = 30;

export const useUi = create<UiState>((set, get) => ({
  path: '',
  history: [],
  online: true,
  toasts: [],
  open: (raw) => {
    const path = raw.replace(/^\//, '');
    if (path === get().path) return;
    set((s) => ({ path, history: [...s.history, s.path].slice(-MAX_HISTORY) }));
  },
  back: () => {
    const history = get().history;
    if (history.length === 0) return;
    set({ path: history[history.length - 1], history: history.slice(0, -1) });
  },
  setOnline: (online) => online !== get().online && set({ online }),
  toast(message, tone = 'green') {
    const id = ++seq;
    set((s) => ({ toasts: [...s.toasts, { id, tone, message }] }));
    setTimeout(() => get().dismiss(id), 4500);
  },
  dismiss: (id) => set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),
}));

export const toast = (message: string, tone?: Toast['tone']) => useUi.getState().toast(message, tone);
