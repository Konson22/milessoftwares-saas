import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner, EmptyState, groupedRow, IconTile } from '@/components/ui/misc';
import { operations } from '@/logbook/transport';
import type { Notice } from '@/logbook/work';
import { useAuth } from '@/store/auth';
import { useWork } from '@/store/work';
import { openRoute } from '@/lib/open-notice';
import { parseRoute, routeFromLink } from '@/logbook/notices';

/**
 * Notifications, online. Opening one marks it read and goes to the task or
 * entry on the phone when it has a screen for it.
 */
export default function NotificationsScreen() {
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const [rows, setRows] = useState<Notice[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    operations
      .notifications()
      .then((r) => {
        setRows(r.notifications);
        useWork.setState({ unread: r.unread });
        setError(null);
      })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Could not load the notifications.'))
      .finally(() => setLoading(false));
  }, []);

  useFocusEffect(load);

  const openNotice = (n: Notice) => {
    if (!n.read) {
      void operations.read({ ids: [n.id] }).then(load, () => undefined);
    }
    void openRoute(parseRoute(n.route) ?? routeFromLink(n.link, n.event));
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader
        menu
        title="Notifications"
        right={rows && rows.some((r) => !r.read) ? <Button title="Mark all read" variant="ghost" onPress={() => void operations.read({ all: true }).then(load)} /> : undefined}
      />
      <FlatList
        data={rows ?? []}
        keyExtractor={(n) => n.id}
        contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListHeaderComponent={
          <View className="gap-3 pb-2">
            {!reachable && <Banner tone="info">Notifications need a connection.</Banner>}
            {error && reachable && <Banner tone="error">{error}</Banner>}
          </View>
        }
        renderItem={({ item, index }) => (
          <Pressable
            accessibilityRole="button"
            onPress={() => openNotice(item)}
            className={`min-h-16 flex-row gap-3 px-4 py-3 ${item.read ? 'bg-white active:bg-slate-50' : 'bg-brand-50 active:bg-brand-100'} ${groupedRow(index, rows?.length ?? 0)}`}>
            <IconTile icon={item.read ? 'notifications-outline' : 'notifications'} color={item.read ? 'slate' : 'amber'} size={40} />
            <View className="flex-1 gap-0.5">
              <View className="flex-row items-center gap-2">
                <Text className={`flex-1 text-[15px] text-slate-900 ${item.read ? '' : 'font-semibold'}`}>{item.title}</Text>
                {!item.read && <View accessibilityLabel="Unread" className="h-2.5 w-2.5 rounded-full bg-brand-600" />}
              </View>
              {item.body ? (
                <Text className="text-sm text-slate-600" numberOfLines={2}>
                  {item.body}
                </Text>
              ) : null}
              <Text className="text-xs text-slate-500">{when(item.at)}</Text>
            </View>
          </Pressable>
        )}
        ListEmptyComponent={rows ? <EmptyState icon="notifications-outline" title="Nothing new" message="You are told here when a task is given to you, work is due, or an entry comes back." /> : null}
      />
    </View>
  );
}
