import type { Counter, Item, Me, Prescription, PrescriptionSummary, Sale, SaleSummary, Till } from '../types';
import { place } from '../config';
import { ApiError, request, setRequestContext } from './client';
import type { PosApi, SignedIn } from './types';

type Data<T> = { data: T };

const q = (params: Record<string, string | null | undefined>) =>
  Object.entries(params)
    .filter(([, v]) => v)
    .map(([k, v]) => `${k}=${encodeURIComponent(v as string)}`)
    .join('&');

/**
 * The workspace's pharmacy, or its shop (pharmacy-app routes/api.php), signed
 * in with the platform: the sign-in yields the tokens, then `session` tells
 * the pharmacy (or shop) who this is.
 */
export const liveApi: PosApi = {
  async signIn(workspace, idToken) {
    setRequestContext({ workspace, branchId: null });
    try {
      return await request<SignedIn>('POST', 'session', { id_token: idToken });
    } catch (error) {
      await window.desktop.signOut();
      if (error instanceof ApiError && error.status === 404) {
        throw new ApiError(`No ${place()} at “${workspace}”. Check your workspace address.`, 404);
      }
      if (error instanceof ApiError && error.status === 403) {
        throw new ApiError(`Your account has no access to this ${place()}. Ask the owner to add you.`, 403);
      }
      throw error;
    }
  },
  signOut: () => window.desktop.signOut(),
  me: () => request<Me>('GET', 'v1/me'),

  counter: () => request<Counter>('GET', 'v1/counter'),

  items: async (search) => (await request<Data<Item[]>>('GET', `v1/items?${q({ search })}`)).data,
  itemByCode: async (code) => {
    try {
      return (await request<Data<Item>>('GET', `v1/items/lookup?${q({ code })}`)).data;
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) return null;
      throw error;
    }
  },

  till: async () => (await request<Data<Till | null>>('GET', 'v1/till')).data,
  openTill: async (opening_float_minor) => (await request<Data<Till>>('POST', 'v1/till', { opening_float_minor })).data,
  closeTill: async (counted_cash_minor, closing_note) =>
    (await request<Data<Till>>('POST', 'v1/till/close', { counted_cash_minor, closing_note })).data,
  moveCash: async (direction, counterpart, amount_minor, reason, submission_id) =>
    (await request<Data<Till>>('POST', 'v1/till/movements', { direction, counterpart, amount_minor, reason, submission_id })).data,

  sell: async (payload) => {
    const res = await request<Data<Sale> & { duplicate: boolean }>('POST', 'v1/sales', payload);
    return { sale: res.data, duplicate: res.duplicate };
  },
  sales: async (day) => {
    const res = await request<Data<SaleSummary[]> & { everyone: boolean }>('GET', `v1/sales?${q({ day })}`);
    return { everyone: res.everyone, sales: res.data };
  },
  sale: async (id) => (await request<Data<Sale>>('GET', `v1/sales/${id}`)).data,
  printed: (id) => request<{ copy: number | null }>('POST', `v1/sales/${id}/printed`),
  voidSale: async (id, reason) => (await request<Data<Sale>>('POST', `v1/sales/${id}/void`, { reason })).data,
  returnSale: async (id, payload) => {
    const res = await request<Data<Sale> & { return: { number: string; total_minor: number } }>(
      'POST',
      `v1/sales/${id}/returns`,
      payload,
    );
    return { number: res.return.number, total_minor: res.return.total_minor, sale: res.data };
  },

  prescriptions: async (status, search) =>
    (await request<Data<PrescriptionSummary[]>>('GET', `v1/prescriptions?${q({ status, search })}`)).data,
  prescription: async (id) => (await request<Data<Prescription>>('GET', `v1/prescriptions/${id}`)).data,
  recordPrescription: async (payload) => (await request<Data<Prescription>>('POST', 'v1/prescriptions', payload)).data,
  verifyPrescription: async (id) => (await request<Data<Prescription>>('POST', `v1/prescriptions/${id}/verify`)).data,
  rejectPrescription: async (id, reason) =>
    (await request<Data<Prescription>>('POST', `v1/prescriptions/${id}/reject`, { reason })).data,
  dispense: async (id, payload) => {
    const res = await request<
      Data<Prescription> & { sale: { id: string; number: string; total_minor: number; change_minor: number | null }; duplicate: boolean }
    >('POST', `v1/prescriptions/${id}/dispense`, payload);
    return { sale: res.sale, duplicate: res.duplicate, prescription: res.data };
  },
};
