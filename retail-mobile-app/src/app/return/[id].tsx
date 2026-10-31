import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, Card, Row } from '@/components/ui/misc';
import { QtyStepper } from '@/components/ui/qty-stepper';
import { useFocusedQuery } from '@/hooks/use-async';
import { api, errorMessage } from '@/lib/api';
import { haptic, notify } from '@/lib/feedback';
import { money } from '@/lib/format';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

/**
 * Some of a sale comes back, batch by batch: refunded the way it was paid,
 * and back on the shelf only when it can be sold again.
 */
export default function ReturnScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const refreshTill = useAuth((s) => s.refreshTill);
  const { data: sale, loading, error: loadError } = useFocusedQuery(() => api.sale(id), id);
  const [qty, setQty] = useState<Record<string, number>>({});
  const [restock, setRestock] = useState<Record<string, boolean>>({});
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!sale) {
    return (
      <SafeAreaView className="flex-1 items-center justify-center bg-canvas p-8">
        {loading ? <ActivityIndicator size="large" color={colors.brand[600]} /> : <Banner tone="error">{loadError ?? 'Sale not found.'}</Banner>}
      </SafeAreaView>
    );
  }

  // An estimate: the shop works out the refund line by line.
  const refund = sale.lines.reduce((sum, l) => sum + Math.floor(((qty[l.id] ?? 0) * l.line_total_minor) / l.quantity), 0);
  const lines = sale.lines
    .filter((l) => (qty[l.id] ?? 0) > 0)
    .map((l) => ({ sale_line_id: l.id, quantity: qty[l.id], restock: restock[l.id] ?? true }));

  async function submit() {
    if (lines.length === 0) return setError('Choose what comes back.');
    if (!reason.trim()) return setError('Say why it comes back.');
    setBusy(true);
    setError(null);
    try {
      const result = await api.returnSale(sale!.id, { reason: reason.trim(), lines });
      haptic.success();
      refreshTill().catch(() => {});
      notify(
        `Return ${result.number}`,
        sale!.invoice
          ? `${money(result.total_minor)} comes off ${sale!.customer?.name ?? 'the customer'}’s account.`
          : `Refund ${money(result.total_minor)} by ${sale!.payment_method_label.toLowerCase()}.`,
      );
      router.back();
    } catch (e) {
      haptic.error();
      setError(errorMessage(e));
      setBusy(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-canvas" edges={['bottom']}>
      <ScreenHeader title="Take a return" subtitle={sale.number} icon="arrow-back" onIcon={() => router.back()} />
      <ScrollView contentContainerClassName="w-full max-w-xl self-center gap-3 p-4" keyboardShouldPersistTaps="handled">
        <Text className="text-base text-slate-500">Counted in units, batch by batch. Untick “Back on the shelf” for anything that cannot be sold again.</Text>
        {sale.lines.map((l) => {
          const remaining = l.quantity - l.returned;
          const chosen = qty[l.id] ?? 0;
          const shelf = restock[l.id] ?? true;
          return (
            <Card key={l.id} className="gap-3">
              <View className="flex-row items-center gap-3">
                <View className="flex-1">
                  <Text className="text-base font-semibold text-slate-900">{l.name}</Text>
                  <Text className="text-sm text-slate-500">
                    Batch {l.batch_number} · sold {l.quantity}
                    {l.returned ? ` · ${l.returned} back already` : ''}
                  </Text>
                </View>
                {remaining > 0 ? (
                  chosen > 0 ? (
                    <QtyStepper value={chosen} max={remaining} name={l.name} onChange={(q) => setQty((s) => ({ ...s, [l.id]: Math.max(0, q) }))} />
                  ) : (
                    <Button title="Return" variant="outline" onPress={() => setQty((s) => ({ ...s, [l.id]: 1 }))} />
                  )
                ) : (
                  <Text className="text-sm text-slate-400">All returned</Text>
                )}
              </View>
              {chosen > 0 && (
                <Pressable
                  accessibilityRole="checkbox"
                  accessibilityState={{ checked: shelf }}
                  onPress={() => setRestock((s) => ({ ...s, [l.id]: !shelf }))}
                  className="min-h-11 flex-row items-center gap-2">
                  <View className={`h-6 w-6 items-center justify-center rounded-md border-2 ${shelf ? 'border-brand-600 bg-brand-600' : 'border-slate-300'}`}>
                    {shelf && <Text className="text-sm font-bold text-white">✓</Text>}
                  </View>
                  <Text className="text-base text-slate-700">Back on the shelf</Text>
                </Pressable>
              )}
            </Card>
          );
        })}
        <Field label="Reason" value={reason} onChangeText={setReason} placeholder="e.g. Unopened, the doctor changed the therapy" multiline maxLength={255} />
        <Card>
          <Row label="Refund (about)" value={money(refund)} strong />
          <Text className="text-sm text-slate-500">Paid back by {sale.payment_method_label.toLowerCase()}.</Text>
        </Card>
        {error && <Banner tone="error">{error}</Banner>}
      </ScrollView>
      <View className="w-full max-w-xl self-center border-t border-slate-200 bg-white p-4 shadow-lg shadow-brand-900/10">
        <Button title="Take the return" size="lg" icon="return-down-back" loading={busy} onPress={submit} disabled={lines.length === 0} />
      </View>
    </SafeAreaView>
  );
}
