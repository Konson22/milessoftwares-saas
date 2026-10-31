import { Ionicons } from '@expo/vector-icons';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Card } from '@/components/ui/misc';
import { api, errorMessage } from '@/lib/api';
import { money } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { CustomerOption } from '@/lib/types';

/** What a customer may still put on account, in words. */
export function creditLeft(c: CustomerOption): string {
  if (!c.on_account) return 'Does not buy on account';
  return c.available_minor === null ? 'No credit limit' : `${money(c.available_minor)} of credit left`;
}

/** The customer a sale goes on: searched by name, phone or number; then shown with what they owe. */
export function CustomerPicker({ value, onChange, error }: { value: CustomerOption | null; onChange: (c: CustomerOption | null) => void; error?: string | null }) {
  const [search, setSearch] = useState('');
  const [found, setFound] = useState<CustomerOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState<string | null>(null);

  useEffect(() => {
    if (value) return;
    let live = true;
    // A short pause while typing, then the shop's customers that match.
    const timer = setTimeout(() => {
      setLoading(true);
      api
        .customers(search)
        .then((list) => live && (setFound(list), setFailed(null)))
        .catch((e) => live && setFailed(errorMessage(e)))
        .finally(() => live && setLoading(false));
    }, 250);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [search, value]);

  if (value) {
    return (
      <Card className="flex-row items-center gap-3">
        <Ionicons name="person-circle-outline" size={32} color={colors.brand[600]} />
        <View className="flex-1">
          <Text className="text-base font-semibold text-slate-900">{value.name}</Text>
          <Text className="text-sm text-slate-500">
            {value.balance_minor > 0 ? `Owes ${money(value.balance_minor)} · ` : ''}
            {creditLeft(value)}
          </Text>
        </View>
        <Button title="Change" variant="outline" square onPress={() => onChange(null)} />
      </Card>
    );
  }

  return (
    <View className="gap-2">
      <Field label="Customer" value={search} onChangeText={setSearch} placeholder="Name, phone or number" icon="search" error={error ?? failed} autoCapitalize="words" />
      {loading && found.length === 0 && <ActivityIndicator color={colors.brand[600]} />}
      {found.slice(0, 6).map((c) => (
        <Pressable
          key={c.id}
          accessibilityRole="button"
          onPress={() => onChange(c)}
          className="flex-row items-center gap-3 border border-slate-200 bg-white px-3 py-3 active:bg-brand-50">
          <View className="flex-1">
            <Text className="text-base font-medium text-slate-900">{c.name}</Text>
            <Text className="text-xs text-slate-500">{[c.phone ?? c.number, creditLeft(c)].join(' · ')}</Text>
          </View>
          {c.balance_minor > 0 && <Text className="text-sm font-semibold text-amber-700">{money(c.balance_minor)}</Text>}
        </Pressable>
      ))}
      {!loading && search.trim() !== '' && found.length === 0 && (
        <Text className="text-sm text-slate-500">No customer matches. A new one is added under Customers in the menu.</Text>
      )}
    </View>
  );
}
