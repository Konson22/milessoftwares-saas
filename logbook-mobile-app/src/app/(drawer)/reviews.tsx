import { useFocusEffect, router } from 'expo-router';
import { useCallback, useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { when } from '@/components/entries';
import { ScreenHeader } from '@/components/screen-header';
import { Badge, Banner, EmptyState, groupedRow, IconTile } from '@/components/ui/misc';
import { reviews, type ReviewRow } from '@/logbook/transport';
import { useAuth } from '@/store/auth';

/** Entries waiting for this reviewer. Online only: a decision is made on the entry as the server has it. */
export default function ReviewsScreen() {
  const insets = useSafeAreaInsets();
  const reachable = useAuth((s) => s.reachable);
  const [rows, setRows] = useState<ReviewRow[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    reviews
      .list()
      .then((r) => {
        setRows(r.entries);
        setError(null);
      })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : 'Could not load the reviews.'))
      .finally(() => setLoading(false));
  }, []);

  useFocusEffect(load);

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader menu title="Reviews" subtitle={rows ? `${rows.length} waiting` : undefined} />
      <FlatList
        data={rows ?? []}
        keyExtractor={(r) => r.id}
        contentContainerStyle={{ padding: 16, paddingBottom: insets.bottom + 24 }}
        refreshControl={<RefreshControl refreshing={loading} onRefresh={load} />}
        ListHeaderComponent={
          <View className="gap-3 pb-2">
            {!reachable && <Banner tone="info">Reviews need a connection. Entries you write keep working offline.</Banner>}
            {error && reachable && <Banner tone="error">{error}</Banner>}
          </View>
        }
        renderItem={({ item, index }) => (
          <Pressable
            accessibilityRole="button"
            onPress={() => router.push({ pathname: '/review/[id]', params: { id: item.id } })}
            className={`min-h-16 flex-row gap-3 px-4 py-3 bg-white active:bg-slate-50 ${groupedRow(index, rows?.length ?? 0)}`}>
            <IconTile icon={item.revision > 1 ? 'repeat' : 'document-text'} color={item.revision > 1 ? 'amber' : 'violet'} size={40} />
            <View className="flex-1 gap-1">
            <View className="flex-row items-center gap-2">
              <Text className="flex-1 text-[15px] font-semibold text-slate-900">{item.reference ?? item.sheet.name}</Text>
              <Badge label={item.revision > 1 ? `Revision ${item.revision}` : 'New'} tone={item.revision > 1 ? 'amber' : 'blue'} />
            </View>
            <Text className="text-xs text-slate-500" numberOfLines={1}>
              {item.sheet.code} · {item.logbook} · {item.creator} · {when(item.occurredAt)}
            </Text>
            {item.summary ? (
              <Text className="text-sm text-slate-700" numberOfLines={2}>
                {item.summary}
              </Text>
            ) : null}
            </View>
          </Pressable>
        )}
        ListEmptyComponent={rows ? <EmptyState icon="checkmark-done-outline" title="Nothing to review" message="Entries submitted on your sheets appear here." /> : null}
      />
    </View>
  );
}
