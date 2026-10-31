import { create } from 'zustand';

type Tone = 'success' | 'error' | 'warning';

interface ToastState {
  toast: { id: number; message: string; tone: Tone } | null;
  show(message: string, tone?: Tone): void;
  hide(): void;
}

let timer: ReturnType<typeof setTimeout> | null = null;

/** One short message at a time, over every screen (components/toast.tsx). */
export const useToast = create<ToastState>((set) => ({
  toast: null,
  show(message, tone = 'success') {
    if (timer) clearTimeout(timer);
    set({ toast: { id: Date.now(), message, tone } });
    timer = setTimeout(() => set({ toast: null }), tone === 'success' ? 2600 : 4500);
  },
  hide() {
    if (timer) clearTimeout(timer);
    set({ toast: null });
  },
}));

export const toast = (message: string, tone?: Tone) => useToast.getState().show(message, tone);
