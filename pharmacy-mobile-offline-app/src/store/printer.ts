import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

/** The receipt printer this device prints to: a counter device's own setting, kept across sign-ins. */
interface PrinterState {
  /** A paired Bluetooth printer; null prints through the system dialog. */
  printer: { address: string; name: string } | null;
  /** Open the cash drawer wired to the printer on a cash sale. */
  openDrawer: boolean;
  choose(printer: { address: string; name: string } | null): void;
  setOpenDrawer(on: boolean): void;
}

export const usePrinter = create<PrinterState>()(
  persist(
    (set) => ({
      printer: null,
      openDrawer: false,
      choose: (printer) => set({ printer }),
      setOpenDrawer: (openDrawer) => set({ openDrawer }),
    }),
    {
      name: 'miles-pos.printer',
      version: 1,
      storage: createJSONStorage(() => AsyncStorage),
      partialize: (s) => ({ printer: s.printer, openDrawer: s.openDrawer }),
    },
  ),
);
