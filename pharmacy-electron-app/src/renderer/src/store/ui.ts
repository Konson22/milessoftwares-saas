import { create } from 'zustand';

/** The counter's own screens (work offline), or a back-office page by its web path. */
export type Screen = 'sell' | 'sales' | 'shift' | 'sync';
export type Page = Screen | `app:${string}`;

interface Toast {
  id: number;
  tone: 'green' | 'amber' | 'red' | 'blue';
  message: string;
}

interface UiState {
  page: Page;
  /** Pages opened before this one, for Back. */
  history: Page[];
  toasts: Toast[];
  go(page: Page): void;
  /** A back-office page by its web path ("stock/<id>"). */
  open(path: string): void;
  back(): void;
  toast(message: string, tone?: Toast['tone']): void;
  dismiss(id: number): void;
}

let seq = 0;
const MAX_HISTORY = 30;

export const appPath = (page: Page): string | null => (page.startsWith('app:') ? page.slice(4) : null);

export const useUi = create<UiState>((set, get) => ({
  page: 'sell',
  history: [],
  toasts: [],
  go: (page) => {
    if (page === get().page) return;
    set((s) => ({ page, history: [...s.history, s.page].slice(-MAX_HISTORY) }));
  },
  open: (path) => get().go(`app:${path.replace(/^\//, '')}`),
  back: () => {
    const history = get().history;
    if (history.length === 0) return;
    set({ page: history[history.length - 1], history: history.slice(0, -1) });
  },
  toast(message, tone = 'green') {
    const id = ++seq;
    set((s) => ({ toasts: [...s.toasts, { id, tone, message }] }));
    setTimeout(() => get().dismiss(id), 4500);
  },
  dismiss: (id) => set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),
}));

export const toast = (message: string, tone?: Toast['tone']) => useUi.getState().toast(message, tone);
