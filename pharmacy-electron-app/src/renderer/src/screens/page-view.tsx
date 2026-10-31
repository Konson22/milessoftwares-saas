import { ArrowLeft, Loader2 } from 'lucide-react';

import { Button, Notice } from '@/components/ui';
import { usePage } from '@/hooks/use-page';
import { place } from '@/lib/config';
import { dateTime } from '@/lib/format';
import { useLocal } from '@/store/auth';
import { useUi } from '@/store/ui';

import { GenericScreen } from './generic';
import { screens as localScreens } from './local/registry';
import { screens } from './registry';

/** A back-office page: loaded (or read from this PC), drawn by its screen. */
export function PageView({ path }: { path: string }) {
  const { page, staleSince, error, loading, reload } = usePage(path);
  const back = useUi((s) => s.back);
  const canGoBack = useUi((s) => s.history.length > 0);
  const local = useLocal();

  if (!page) {
    return error ? (
      <Notice>{error}</Notice>
    ) : (
      <Loader2 className="mx-auto mt-20 animate-spin text-muted-foreground" />
    );
  }

  // Offline, this PC's pages have their own screens; a workspace page without one is shown as it is.
  const Screen = local ? localScreens[page.component] : (screens[page.component] ?? GenericScreen);
  return (
    <div className="space-y-3">
      <div className="flex items-center gap-3">
        {canGoBack && path.includes('/') ? (
          <Button variant="ghost" size="sm" onClick={back}>
            <ArrowLeft size={15} /> Back
          </Button>
        ) : null}
        {loading ? <Loader2 size={15} className="animate-spin text-muted-foreground" /> : null}
      </div>
      {staleSince ? <Notice>Offline: as the {place()} showed it at {dateTime(staleSince)}. Changes need the connection.</Notice> : null}
      {Screen ? (
        <Screen page={page} path={path} reload={reload} stale={staleSince !== null} />
      ) : (
        <Notice>This page has no screen yet ({page.component}).</Notice>
      )}
    </div>
  );
}
