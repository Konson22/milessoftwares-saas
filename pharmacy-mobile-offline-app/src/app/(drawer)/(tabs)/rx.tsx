import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { FlatList, Pressable, RefreshControl, ScrollView, Text, TextInput, View } from 'react-native';

import { RX_BADGE } from '@/components/sale-status';
import { Button } from '@/components/ui/button';
import { Badge, Banner, EmptyState, ScreenTitle } from '@/components/ui/misc';
import { useDebounced, useFocusedQuery } from '@/hooks/use-async';
import { api } from '@/lib/api';
import { prettyDate } from '@/lib/format';
import { colors, webNoOutline } from '@/lib/theme';
import type { PrescriptionStatus } from '@/lib/types';
import { useAuth } from '@/store/auth';

const FILTERS: { status: PrescriptionStatus | null; label: string }[] = [
  { status: null, label: 'Open' },
  { status: 'recorded', label: 'Waiting' },
  { status: 'verified', label: 'Verified' },
  { status: 'dispensed', label: 'Dispensed' },
  { status: 'rejected', label: 'Rejected' },
];

/** Prescriptions at this branch: recorded at the counter, verified by a pharmacist, then dispensed. */
export default function RxScreen() {
  const canRecord = useAuth((s) => s.counter?.can.record_prescriptions ?? false);
  const [status, setStatus] = useState<PrescriptionStatus | null>(null);
  const [search, setSearch] = useState('');
  const query = useDebounced(search.trim(), 300);
  const { data, error, loading, reload } = useFocusedQuery(() => api.prescriptions(status, query), `${status}:${query}`);

  return (
    <FlatList
      data={data ?? []}
      keyExtractor={(p) => p.id}
      contentContainerClassName="w-full max-w-3xl self-center flex-grow gap-2 p-4"
      refreshControl={<RefreshControl refreshing={loading && !!data} onRefresh={reload} />}
      keyboardShouldPersistTaps="handled"
      ListHeaderComponent={
        <View className="mb-2 gap-3">
          <ScreenTitle
            title="Prescriptions"
            right={canRecord ? <Button title="Record" icon="add" onPress={() => router.push('/prescription/new')} /> : undefined}
          />
          <View className="min-h-12 flex-row items-center rounded-2xl border border-slate-200 bg-white px-3">
            <Ionicons name="search" size={20} color={colors.muted} />
            <TextInput
              value={search}
              onChangeText={setSearch}
              placeholder="Patient, prescriber or number"
              placeholderTextColor={colors.faint}
              autoCorrect={false}
              accessibilityLabel="Search prescriptions"
              style={webNoOutline}
              className="flex-1 px-2 py-3 text-base text-slate-900"
            />
          </View>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerClassName="gap-2">
            {FILTERS.map((f) => {
              const active = f.status === status;
              return (
                <Pressable
                  key={f.label}
                  accessibilityRole="radio"
                  accessibilityState={{ selected: active }}
                  onPress={() => setStatus(f.status)}
                  className={`min-h-9 justify-center border px-3 ${active ? 'border-brand-600 bg-brand-600' : 'border-slate-300 bg-white'}`}>
                  <Text className={`font-medium ${active ? 'text-white' : 'text-slate-700'}`}>{f.label}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
          {error && <Banner tone="error">{error}</Banner>}
        </View>
      }
      ListEmptyComponent={
        loading ? undefined : (
          <EmptyState
            icon="document-text-outline"
            title="No prescriptions"
            message={status === null ? 'Nothing is waiting or ready to dispense.' : 'None match.'}
          />
        )
      }
      renderItem={({ item }) => (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={`${item.number}, ${item.patient_name}, ${item.status_label}`}
          onPress={() => router.push({ pathname: '/prescription/[id]', params: { id: item.id } })}
          className="flex-row items-center gap-3 border border-slate-200 bg-white p-4 shadow-sm shadow-brand-900/5 active:border-brand-300 active:bg-brand-50">
          <View className="h-11 w-11 items-center justify-center rounded-2xl bg-brand-50">
            <Ionicons name="document-text-outline" size={22} color={colors.brand[700]} />
          </View>
          <View className="flex-1 gap-0.5">
            <Text className="text-base font-semibold text-slate-900">{item.patient_name}</Text>
            <Text className="text-sm text-slate-500" numberOfLines={1}>
              {item.number} · {item.prescriber_name} · {prettyDate(item.issued_on)}
            </Text>
            <Text className="text-xs text-slate-400">
              {item.items_count} medicine{item.items_count === 1 ? '' : 's'}
            </Text>
          </View>
          <View className="items-end gap-1">
            <Badge label={item.status_label} tone={RX_BADGE[item.status]} />
            {item.expired && <Badge label="Expired" tone="red" />}
          </View>
        </Pressable>
      )}
    />
  );
}
