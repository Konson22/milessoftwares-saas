import { router } from 'expo-router';
import { useState } from 'react';
import { FlatList, Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, EmptyState, Row } from '@/components/ui/misc';
import { QtyStepper } from '@/components/ui/qty-stepper';
import { Sheet } from '@/components/ui/sheet';
import { confirm, haptic } from '@/lib/feedback';
import { money } from '@/lib/format';
import type { CartLine } from '@/lib/types';
import { useAuth } from '@/store/auth';
import { cartTotals, holdOwner, linePrice, maxQuantity, packOf, useCart } from '@/store/cart';

import { LineEditSheet } from './line-edit-sheet';

function LineRow({ line, onEdit }: { line: CartLine; onEdit: () => void }) {
  const setQuantity = useCart((s) => s.setQuantity);
  const { item } = line;
  const pack = packOf(line);
  const price = linePrice(line);

  return (
    <View className="mx-4 mb-2 gap-2 border border-slate-200 bg-white p-3">
      <View className="flex-row items-start gap-3">
        <Pressable className="flex-1 gap-0.5" onPress={onEdit} accessibilityRole="button" accessibilityHint="Choose a pack or give a discount">
          <Text className="text-base font-semibold text-slate-900">{item.name}</Text>
          <Text className="text-sm text-slate-500">
            {pack ? `${pack.name} of ${pack.units}` : item.unit}
            {'  '}@ {money(pack?.price_minor ?? item.price_minor)}
          </Text>
          {price.discount > 0 && (
            <Text className="text-sm text-emerald-700">
              {line.discount_percent}% off −{money(price.discount)}
            </Text>
          )}
        </Pressable>
        <Text className="text-base font-bold text-brand-900">{money(price.total)}</Text>
      </View>
      <QtyStepper
        value={line.quantity}
        max={maxQuantity(line)}
        name={item.name}
        onChange={(q) => {
          haptic.tap();
          setQuantity(item.id, q);
        }}
      />
    </View>
  );
}

/** `sheet`: phone layout, where the cart is a rounded panel under the search bar. */
export function CartPanel({ sheet }: { sheet?: boolean }) {
  const canSell = useAuth((s) => s.counter?.can.sell ?? false);
  const owner = useAuth((s) => holdOwner(s.me?.user.id, s.branchId));
  const lines = useCart((s) => s.lines);
  const heldCount = useCart((s) => s.held.filter((h) => h.owner === owner).length);
  const clear = useCart((s) => s.clear);
  const [editing, setEditing] = useState<CartLine | null>(null);
  const [holding, setHolding] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const totals = cartTotals(lines);

  async function onClear() {
    if (await confirm('Clear cart?', 'All items will be removed from this sale.', 'Clear', true)) clear();
  }

  return (
    <View
      className={`flex-1 ${
        sheet ? ' border-t border-slate-200 bg-white shadow-lg shadow-brand-900/10' : ''
      }`}>
      <View className="flex-row items-center justify-between px-4 pb-2 pt-3">
        <View className="flex-row items-center gap-2">
          <Text accessibilityRole="header" className="text-lg font-bold text-slate-900">
            Cart
          </Text>
          <View className="rounded-full bg-brand-100 px-2.5 py-0.5">
            <Text className="text-sm font-bold text-brand-800">
              {lines.length} item{lines.length === 1 ? '' : 's'}
            </Text>
          </View>
        </View>
        <View className="flex-row">
          {heldCount > 0 && (
            <Button title={`Held (${heldCount})`} variant="ghost" icon="pause-circle-outline" onPress={() => router.push('/held')} />
          )}
          {lines.length > 0 && <Button title="Clear" variant="ghost" icon="trash-outline" onPress={onClear} />}
        </View>
      </View>

      {notice && (
        <View className="px-4">
          <Banner tone="success">{notice}</Banner>
        </View>
      )}

      <FlatList
        data={lines}
        keyExtractor={(l) => l.item.id}
        keyboardShouldPersistTaps="handled"
        className="mt-2 flex-1"
        renderItem={({ item }) => <LineRow line={item} onEdit={() => setEditing(item)} />}
        ListEmptyComponent={<EmptyState icon="cart-outline" title="Cart is empty" message="Scan a product or type to search" />}
      />

      {lines.length > 0 && (
        // Flat, so it reads as the cart's foot rather than a second sheet stacked on the bottom nav.
        <View className="gap-1 border-t border-slate-200 bg-white px-4 pb-3 pt-3">
          {totals.discount > 0 && (
            <>
              <Row label="Subtotal" value={money(totals.gross)} />
              <Row label="Discount" value={`−${money(totals.discount)}`} tone="text-emerald-700" />
            </>
          )}
          <Row label="Total" value={money(totals.total)} strong />
          <View className="mt-2 flex-row gap-2">
            <Button title="Hold" variant="outline" icon="pause" onPress={() => setHolding(true)} className="flex-1" />
            <Button
              title="Checkout"
              variant="accent"
              size="lg"
              icon="card"
              onPress={() => router.push('/checkout')}
              className="flex-[2]"
              disabled={!canSell}
            />
          </View>
        </View>
      )}

      {editing && <LineEditSheet key={editing.item.id} line={editing} onClose={() => setEditing(null)} />}
      <HoldSheet
        visible={holding}
        owner={owner}
        onClose={() => setHolding(false)}
        onHeld={() => {
          setHolding(false);
          setNotice('Cart held. Pick it up again from Held.');
          setTimeout(() => setNotice(null), 3000);
        }}
      />
    </View>
  );
}

function HoldSheet({ visible, owner, onClose, onHeld }: { visible: boolean; owner: string; onClose: () => void; onHeld: () => void }) {
  const hold = useCart((s) => s.hold);
  const [label, setLabel] = useState('');

  return (
    <Sheet visible={visible} onClose={onClose} title="Hold cart">
      <Text className="text-base text-slate-500">
        Park this cart to serve the next customer. It stays on this device and does not reserve stock.
      </Text>
      <Field label="Label (optional)" value={label} onChangeText={setLabel} placeholder="e.g. Lady in blue, fetching cash" />
      <Button
        title="Hold cart"
        size="lg"
        icon="pause"
        onPress={() => {
          hold(owner, label.trim() || null);
          setLabel('');
          haptic.success();
          onHeld();
        }}
      />
    </Sheet>
  );
}
