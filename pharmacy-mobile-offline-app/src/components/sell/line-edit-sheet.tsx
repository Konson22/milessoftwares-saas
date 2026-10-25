import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner } from '@/components/ui/misc';
import { Sheet } from '@/components/ui/sheet';
import { money } from '@/lib/format';
import type { CartLine } from '@/lib/types';
import { useAuth } from '@/store/auth';
import { linePrice, useCart } from '@/store/cart';

/** How the line is sold (by the unit or a pack) and its discount, up to what this member may give. */
// Mounted per line (keyed by the caller) so the form starts from that line's values.
export function LineEditSheet({ line, onClose }: { line: CartLine; onClose: () => void }) {
  const maxDiscount = useAuth((s) => (s.counter?.can.discount ? s.counter.max_discount_percent : 0));
  const setPack = useCart((s) => s.setPack);
  const setDiscount = useCart((s) => s.setDiscount);
  const [packId, setPackId] = useState(line.pack_id);
  const [discount, setDiscountText] = useState(line.discount_percent ? String(line.discount_percent) : '');
  const [error, setError] = useState<string | null>(null);
  const { item } = line;

  const percent = discount.trim() === '' ? 0 : Number(discount);
  const preview = linePrice({ ...line, pack_id: packId, discount_percent: Number.isInteger(percent) ? percent : 0 });

  function save() {
    if (!Number.isInteger(percent) || percent < 0) return setError('Enter a whole percentage.');
    if (percent > maxDiscount) return setError(`You may take off at most ${maxDiscount}%.`);
    if (packId !== line.pack_id) setPack(item.id, packId);
    setDiscount(item.id, percent);
    onClose();
  }

  const options = [
    { id: null, label: `By the ${item.unit}`, price: item.price_minor, units: 1 },
    ...item.packs.map((p) => ({ id: p.id, label: `${p.name} of ${p.units}`, price: p.price_minor, units: p.units })),
  ];

  return (
    <Sheet visible onClose={onClose} title={item.name}>
      {item.packs.length > 0 && (
        <View className="gap-2" accessibilityRole="radiogroup" accessibilityLabel="Sold by">
          <Text className="text-sm font-medium text-slate-700">Sold by</Text>
          {options.map((option) => {
            const selected = option.id === packId;
            const fits = Math.floor(item.available / option.units) > 0;
            return (
              <Pressable
                key={option.id ?? 'unit'}
                accessibilityRole="radio"
                accessibilityState={{ selected, disabled: !fits }}
                disabled={!fits}
                onPress={() => setPackId(option.id)}
                className={`min-h-12 flex-row items-center justify-between border px-4 ${
                  selected ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white'
                } ${fits ? '' : 'opacity-40'}`}>
                <Text className={`text-base ${selected ? 'font-semibold text-brand-900' : 'text-slate-800'}`}>{option.label}</Text>
                <Text className="text-base font-medium text-slate-700">{money(option.price)}</Text>
              </Pressable>
            );
          })}
        </View>
      )}

      {maxDiscount > 0 ? (
        <Field
          label="Discount (%)"
          value={discount}
          onChangeText={(t) => {
            setDiscountText(t.replace(/[^\d]/g, ''));
            setError(null);
          }}
          keyboardType="number-pad"
          placeholder="0"
          hint={`Up to ${maxDiscount}%.`}
        />
      ) : (
        item.packs.length === 0 && <Banner tone="info">You may not give discounts.</Banner>
      )}

      <View className="flex-row justify-between">
        <Text className="text-base text-slate-500">Line total</Text>
        <Text className="text-base font-bold text-brand-900">
          {money(preview.total)}
          {preview.discount > 0 ? `  (−${money(preview.discount)})` : ''}
        </Text>
      </View>

      {error && <Banner tone="error">{error}</Banner>}
      <Button title="Apply" size="lg" onPress={save} />
    </Sheet>
  );
}
