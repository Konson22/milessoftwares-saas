import type { ComponentType } from 'react';

import type { AppPage } from '@/lib/pages';

/** A back-office screen: the web page's props, drawn natively. */
export interface ScreenProps<P = any> {
  page: AppPage<P>;
  /** The page's web path ("stock/<id>"): actions name it as where they come from. */
  path: string;
  reload: () => Promise<void>;
  /** The page is from this PC (offline): actions wait for the connection. */
  stale: boolean;
}

export type Screen = ComponentType<ScreenProps>;
