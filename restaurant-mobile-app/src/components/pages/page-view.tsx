import { useState } from 'react';
import { ActivityIndicator, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/misc';
import { usePage } from '@/hooks/use-page';
import { colors } from '@/lib/theme';

import { GenericScreen } from './generic';
import { refreshEvery, screens } from './registry';

/**
 * A page of the restaurant: loaded, and drawn by its screen. A screen may
 * show another view of itself in place (another day, a search) with `go`.
 */
export function PageView({ path: start }: { path: string }) {
  const [path, go] = useState(start);
  const section = path.split(/[/?]/)[0];
  const { page, error, reload } = usePage(path, path.includes('/') ? undefined : refreshEvery[section]);

  if (!page) {
    return error ? (
      <EmptyState icon="alert-circle-outline" title="Could not open this" message={error}>
        <Button title="Try again" icon="refresh" onPress={() => void reload()} />
      </EmptyState>
    ) : (
      <View className="flex-1 items-center justify-center">
        <ActivityIndicator size="large" color={colors.brand[600]} />
      </View>
    );
  }

  const Screen = screens[page.component] ?? GenericScreen;
  return <Screen page={page} path={path} reload={reload} go={go} />;
}
