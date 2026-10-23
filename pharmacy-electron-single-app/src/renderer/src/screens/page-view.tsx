import { ArrowLeft, Loader2 } from 'lucide-react';

import { Button, Notice } from '@/components/ui';
import { usePage } from '@/hooks/use-page';
import { useUi } from '@/store/ui';

import { screens } from './registry';

/** A back-office page: loaded, drawn by its screen. */
export function PageView({ path }: { path: string }) {
  const { page, error, loading, reload } = usePage(path);
  const back = useUi((s) => s.back);
  const canGoBack = useUi((s) => s.history.length > 0);

  if (!page) {
    return error ? <Notice tone="red">{error}</Notice> : <Loader2 className="mx-auto mt-20 animate-spin text-muted-foreground" />;
  }

  const Screen = screens[page.component];
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
      {Screen ? <Screen page={page} path={path} reload={reload} /> : <Notice>This page has no screen yet ({page.component}).</Notice>}
    </div>
  );
}
