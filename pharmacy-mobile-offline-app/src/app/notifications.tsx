import { Ionicons } from '@expo/vector-icons';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';

import { ScreenHeader } from '@/components/screen-header';
import { EmptyState } from '@/components/ui/misc';
import { colors } from '@/lib/theme';
import { useNotices, type Notice } from '@/store/notices';

const TONE = {
  red: { box: 'bg-red-50', color: colors.dangerDark },
  amber: { box: 'bg-amber-50', color: colors.warning },
  brand: { box: 'bg-brand-50', color: colors.brand[700] },
} as const;

/** What needs attention now: expired and expiring stock, low stock, prescriptions, bills. */
export default function NotificationsScreen() {
  const notices = useNotices((s) => s.notices);
  const refresh = useNotices((s) => s.refresh);
  const [loading, setLoading] = useState(false);

  const reload = useCallback(async () => {
    setLoading(true);
    await refresh();
    setLoading(false);
  }, [refresh]);

  useFocusEffect(
    useCallback(() => {
      void refresh();
    }, [refresh]),
  );

  const open = (n: Notice) => {
    router.back();
    router.push(n.href);
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Notifications" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <FlatList
        data={notices}
        keyExtractor={(n) => n.key}
        contentContainerClassName="w-full max-w-3xl self-center flex-grow gap-3 p-4"
        refreshControl={<RefreshControl refreshing={loading} onRefresh={reload} />}
        ListEmptyComponent={<EmptyState icon="notifications-outline" title="All clear" message="Nothing needs your attention right now." />}
        renderItem={({ item }) => {
          const tone = TONE[item.tone];
          return (
            <Pressable
              accessibilityRole="button"
              onPress={() => open(item)}
              className="flex-row items-center gap-3 border border-slate-200 bg-white p-4 active:bg-brand-50">
              <View className={`h-11 w-11 items-center justify-center ${tone.box}`}>
                <Ionicons name={item.icon} size={22} color={tone.color} />
              </View>
              <View className="flex-1 gap-0.5">
                <Text className="text-base font-semibold text-slate-900">{item.title}</Text>
                <Text className="text-sm text-slate-500">{item.message}</Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={colors.faint} />
            </Pressable>
          );
        }}
      />
    </View>
  );
}
