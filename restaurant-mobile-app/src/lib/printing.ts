import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * This phone's printing choices, kept per phone in either mode:
 * - a kitchen ticket printed each time lines go to the kitchen (on by default);
 * - after a payment, the customer's receipt asked for, printed straight away, or left.
 */

export type ReceiptAfterPay = 'ask' | 'print' | 'none';

export interface PrintChoices {
  kitchenTickets: boolean;
  receiptAfterPay: ReceiptAfterPay;
}

const KITCHEN_KEY = 'miles-restaurant.print-kitchen';
const RECEIPT_KEY = 'miles-restaurant.print-receipt';

let choices: PrintChoices = { kitchenTickets: true, receiptAfterPay: 'ask' };
const listeners = new Set<() => void>();

const loaded = Promise.all([AsyncStorage.getItem(KITCHEN_KEY), AsyncStorage.getItem(RECEIPT_KEY)])
  .then(([kitchen, receipt]) => {
    choices = {
      kitchenTickets: kitchen !== 'off',
      receiptAfterPay: receipt === 'print' || receipt === 'none' ? receipt : 'ask',
    };
    listeners.forEach((l) => l());
  })
  .catch(() => {});

export const getPrintChoices = () => choices;

export function subscribePrintChoices(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function updatePrintChoices(patch: Partial<PrintChoices>) {
  choices = { ...choices, ...patch };
  if (patch.kitchenTickets !== undefined) AsyncStorage.setItem(KITCHEN_KEY, patch.kitchenTickets ? 'on' : 'off').catch(() => {});
  if (patch.receiptAfterPay !== undefined) AsyncStorage.setItem(RECEIPT_KEY, patch.receiptAfterPay).catch(() => {});
  listeners.forEach((l) => l());
}

export async function printsKitchenTickets(): Promise<boolean> {
  await loaded;
  return choices.kitchenTickets;
}

export async function receiptAfterPay(): Promise<ReceiptAfterPay> {
  await loaded;
  return choices.receiptAfterPay;
}

/** Kept for older call sites. */
export const setPrintsKitchenTickets = async (on: boolean) => updatePrintChoices({ kitchenTickets: on });
