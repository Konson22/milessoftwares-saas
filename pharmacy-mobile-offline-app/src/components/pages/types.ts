import type { ComponentType } from 'react';

import type { AppPage } from '@/lib/pages';

/** A back-office screen: the web page's props, drawn natively. */
export interface ScreenProps<P = any> {
  page: AppPage<P>;
  /** The page's web path ("stock/<id>"): actions name it as where they come from. */
  path: string;
  reload: () => Promise<void>;
  loading: boolean;
  /** The page's filters (its query string), and the page again with others. */
  query: Record<string, string>;
  setQuery: (query: Record<string, string | null | undefined>) => void;
}

export type Screen = ComponentType<ScreenProps>;

/** Sections whose records open as pages of their own ("<section>/<id>"). */
export const RECORD_PAGES = new Set([
  'stock',
  'orders',
  'customers',
  'tills',
  'sales',
  'prescriptions',
  'locations',
  'counts',
  'transfers',
  'purchase-orders',
  'receipts',
  'supplier-returns',
]);

/** The page a record of this page opens, if it has one. */
export function recordPath(path: string, id: string): string | null {
  const section = path.split('?')[0].split('/')[0];
  return RECORD_PAGES.has(section) && !path.split('?')[0].includes('/') ? `${section}/${id}` : null;
}
