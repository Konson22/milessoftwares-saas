import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { FlatList, Text, View } from 'react-native';

import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Card, EmptyState } from '@/components/ui/misc';
import { confirm, haptic } from '@/lib/feedback';
import { money, time } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { HeldCart } from '@/lib/types';
import { useAuth } from '@/store/auth';
import { cartTotals, holdOwner, useCart } from '@/store/cart';

/** Carts parked on this device by this member at this branch. */
export default function HeldScreen() {
  const owner = useAuth((s) => holdOwner(s.me?.user.id, s.branchId));
  const all = useCart((s) => s.held);
  const held = all.filter((h) => h.owner === owner);
  const resume = useCart((s) => s.resume);
  const discardHeld = useCart((s) => s.discardHeld);

  function pickUp(cart: HeldCart) {
    // Whatever is in the cart now is parked in its place, so nothing is lost.
    resume(cart.id);
    haptic.success();
    router.back();
  }

  async function discard(cart: HeldCart) {
    if (await confirm('Discard held cart?', 'This parked cart will be deleted.', 'Discard', true)) discardHeld(cart.id);
  }

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader
        title="Held carts"
        subtitle={held.length ? `${held.length} parked on this device` : 'Parked carts wait here'}
        icon="close"
        iconLabel="Close"
        onIcon={() => router.back()}
      />
      <FlatList
        data={held}
        keyExtractor={(h) => h.id}
        contentContainerClassName="w-full max-w-3xl self-center flex-grow gap-3 p-4"
        ListEmptyComponent={<EmptyState icon="pause-circle-outline" title="No held carts" message="Use Hold on the cart to park one." />}
        renderItem={({ item }) => {
          const totals = cartTotals(item.lines);
          return (
            <Card className="gap-3">
              <View className="flex-row items-center gap-3">
                <View className="h-11 w-11 items-center justify-center rounded-2xl bg-brand-50">
                  <Ionicons name="pause" size={20} color={colors.brand[600]} />
                </View>
                <View className="flex-1">
                  <Text className="text-base font-semibold text-slate-900">{item.label || item.customer_name || 'Walk-in customer'}</Text>
                  <Text className="text-sm text-slate-500">
                    {item.lines.length} item{item.lines.length === 1 ? '' : 's'} · {money(totals.total)} · held {time(item.held_at)}
                  </Text>
                </View>
              </View>
              <View className="flex-row gap-2">
                <Button title="Discard" variant="outline" icon="trash-outline" onPress={() => discard(item)} className="flex-1" />
                <Button title="Pick up" icon="play" onPress={() => pickUp(item)} className="flex-[2]" />
              </View>
            </Card>
          );
        }}
      />
    </View>
  );
}
