import { ArrowLeft, Loader2, RefreshCw } from 'lucide-react';

import { Button, Notice } from '@/components/ui';
import { usePage } from '@/hooks/use-page';
import { useUi } from '@/store/ui';

import { GenericScreen } from './generic';
import { refreshEvery, screens } from './registry';

/** A page of the restaurant: loaded, drawn by its screen (live ones loaded again every few seconds). */
export function PageView({ path }: { path: string }) {
  const section = path.split(/[/?]/)[0];
  const { page, error, loading, reload } = usePage(path, refreshEvery[section]);
  const back = useUi((s) => s.back);
  const canGoBack = useUi((s) => s.history.length > 0);

  if (!page) {
    return error ? (
      <div className="space-y-3">
        <Notice tone="red">{error}</Notice>
        <Button variant="secondary" size="sm" onClick={() => void reload()}>
          <RefreshCw size={14} /> Try again
        </Button>
      </div>
    ) : (
      <Loader2 className="mx-auto mt-20 animate-spin text-muted-foreground" />
    );
  }

  const Screen = screens[page.component] ?? GenericScreen;
  return (
    <div className="space-y-3">
      {(canGoBack && path.includes('/')) || loading ? (
        <div className="flex items-center gap-3">
          {canGoBack && path.includes('/') ? (
            <Button variant="ghost" size="sm" onClick={back}>
              <ArrowLeft size={15} /> Back
            </Button>
          ) : null}
          {loading ? <Loader2 size={15} className="animate-spin text-muted-foreground" /> : null}
        </div>
      ) : null}
      <Screen page={page} path={path} reload={reload} stale={false} />
    </div>
  );
}
