import type { ComponentType } from 'react';

import type { AppPage } from '@/lib/pages';

/** A screen of the app: a web page's props, drawn natively. */
export interface ScreenProps<P = any> {
  page: AppPage<P>;
  /** The page's web path ("orders/<id>"): actions name it as where they come from. */
  path: string;
  reload: () => Promise<void>;
  /** Shows another view of the same page in place: another day, a search ("bills?day=…"). */
  go: (path: string) => void;
}

export type Screen = ComponentType<ScreenProps>;
