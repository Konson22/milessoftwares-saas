import { useCallback, useMemo, useState } from 'react';
import { ActivityIndicator, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/misc';
import { usePage } from '@/hooks/use-page';
import { colors } from '@/lib/theme';
import { withQuery } from '@/lib/pages';

import { GenericScreen } from './generic';
import { screens } from './registry';

function parseQuery(path: string): Record<string, string> {
  const q = path.split('?')[1];
  if (!q) return {};
  return Object.fromEntries(
    q.split('&').map((pair) => {
      const [k, v = ''] = pair.split('=');
      return [decodeURIComponent(k), decodeURIComponent(v.replace(/\+/g, ' '))];
    }),
  );
}

/** A back-office page: loaded from the shop, drawn by its screen (or as data). */
export function PageView({ path: initial }: { path: string }) {
  const [path, setPath] = useState(initial);
  const { page, error, loading, reload } = usePage(path);
  const base = path.split('?')[0];
  const query = useMemo(() => parseQuery(path), [path]);
  const setQuery = useCallback(
    (next: Record<string, string | null | undefined>) => setPath(withQuery(base, { ...query, ...next })),
    [base, query],
  );

  if (!page) {
    return error ? (
      <EmptyState icon="cloud-offline-outline" title="Could not open this page" message={error}>
        <Button title="Try again" icon="refresh" onPress={reload} />
      </EmptyState>
    ) : (
      <View className="flex-1 items-center justify-center">
        <ActivityIndicator size="large" color={colors.brand[600]} />
      </View>
    );
  }

  const Screen = screens[page.component] ?? GenericScreen;
  return <Screen page={page} path={path} reload={reload} loading={loading} query={query} setQuery={setQuery} />;
}
