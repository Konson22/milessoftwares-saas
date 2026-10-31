import { router } from 'expo-router';
import { useState } from 'react';
import { FlatList, Pressable, RefreshControl, Text, View } from 'react-native';

import { STATUS_BADGE } from '@/components/sale-status';
import { IconButton } from '@/components/ui/button';
import { Badge, Banner, EmptyState, Hero, HeroStat, ScreenTitle } from '@/components/ui/misc';
import { useFocusedQuery } from '@/hooks/use-async';
import { api } from '@/lib/api';
import { isoDate, money, prettyDate, shiftDate, time } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { SaleSummary } from '@/lib/types';

/** The day's sales. */
export default function SalesScreen() {
  const [date, setDate] = useState(isoDate());
  const { data, error, loading, reload } = useFocusedQuery(() => api.sales(date), date);
  const isToday = date === isoDate();
  const sales = data?.sales ?? [];
  const kept = sales.filter((s) => s.status === 'completed');
  const total = kept.reduce((sum, s) => sum + s.total_minor, 0);
  const count = kept.length;

  return (
    <FlatList<SaleSummary>
      data={sales}
      keyExtractor={(s) => s.id}
      contentContainerClassName="w-full max-w-3xl self-center flex-grow gap-2 p-4"
      refreshControl={<RefreshControl refreshing={loading && !!data} onRefresh={reload} />}
      ListHeaderComponent={
        <View className="mb-2 gap-3">
          <ScreenTitle title={data?.everyone ? 'Branch sales' : 'My sales'} />
          <View className="flex-row items-center border border-slate-200 bg-white">
            <IconButton icon="chevron-back" label="Previous day" color={colors.brand[700]} onPress={() => setDate(shiftDate(date, -1))} />
            <Text className="flex-1 text-center text-base font-semibold text-slate-900" accessibilityLiveRegion="polite">
              {prettyDate(date)}
            </Text>
            <IconButton
              icon="chevron-forward"
              label="Next day"
              color={colors.brand[700]}
              disabled={isToday}
              onPress={() => setDate(shiftDate(date, 1))}
            />
          </View>
          <Hero className="gap-3">
            <Text className="text-sm font-medium text-brand-100">Total sales</Text>
            <Text className="text-3xl font-bold text-white">{money(total)}</Text>
            <View className="flex-row gap-2">
              <HeroStat label="Sales" value={String(count)} />
              <HeroStat label="Average" value={count ? money(Math.round(total / count)) : '—'} />
            </View>
          </Hero>
          {error && <Banner tone="error">{error}</Banner>}
        </View>
      }
      ListEmptyComponent={
        loading ? undefined : (
          <EmptyState icon="receipt-outline" title="No sales" message={`No sales for ${prettyDate(date).toLowerCase()}.`} />
        )
      }
      renderItem={({ item }) => {
        const status = STATUS_BADGE[item.status];
        return (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${item.number}, ${money(item.total_minor)}, ${status.label}`}
            onPress={() => router.push({ pathname: '/receipt/[id]', params: { id: item.id } })}
            className="flex-row items-center gap-3 border border-slate-200 bg-white p-4 shadow-sm shadow-brand-900/5 active:border-brand-300 active:bg-brand-50">
            <View className="w-14">
              <Text className="text-sm font-semibold text-brand-700">{time(item.sold_at)}</Text>
            </View>
            <View className="flex-1 gap-0.5">
              <Text className="text-base font-semibold text-slate-900">{item.number}</Text>
              <Text className="text-sm text-slate-500" numberOfLines={1}>
                {item.customer_name ?? 'Walk-in'} · {item.payment_method_label}
                {data?.everyone ? ` · ${item.sold_by}` : ''}
              </Text>
            </View>
            <View className="items-end gap-1">
              <Text className={`text-base font-bold ${item.status === 'voided' ? 'text-slate-400 line-through' : 'text-brand-900'}`}>
                {money(item.total_minor)}
              </Text>
              <Badge label={item.returned && item.status === 'completed' ? 'Part returned' : status.label} tone={item.returned ? 'blue' : status.tone} />
            </View>
          </Pressable>
        );
      }}
    />
  );
}

