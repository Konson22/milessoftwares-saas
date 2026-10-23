import type { ComponentType } from 'react';

import type { AppPage } from '@/lib/pages';

/** A back-office screen: a page's props, drawn. */
export interface ScreenProps<P = any> {
  page: AppPage<P>;
  /** The page's path ("stock/<id>"): actions name it as where they come from. */
  path: string;
  reload: () => Promise<void>;
}

export type Screen = ComponentType<ScreenProps>;
