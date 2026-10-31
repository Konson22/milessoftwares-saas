import { Ionicons } from '@expo/vector-icons';
import { Pressable, Text, View } from 'react-native';

import { Badge } from '@/components/ui/misc';
import { daysUntil, money } from '@/lib/format';
import { colors } from '@/lib/theme';
import type { Item } from '@/lib/types';

export function ProductRow({ item, onPress }: { item: Item; onPress: () => void }) {
  const out = item.available <= 0;
  const expiryDays = item.nearest_expiry ? daysUntil(item.nearest_expiry) : null;
  const expiringSoon = expiryDays !== null && expiryDays <= 90;
  const detail = [item.category, item.barcode].filter(Boolean).join(' · ');
  const dimmed = out;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${item.name}, ${money(item.price_minor)} per ${item.unit}${out ? ', out of stock' : ''}`}
      onPress={onPress}
      className={`mx-4 mb-2 flex-row items-center gap-3 border border-slate-200 bg-white p-3 shadow-sm shadow-brand-900/5 active:border-brand-300 active:bg-brand-50 ${
        dimmed ? 'opacity-70' : ''
      }`}>
      <View className="flex-1 gap-1">
        <Text className="text-base font-semibold text-slate-900">{item.name}</Text>
        <Text className="text-sm text-slate-500" numberOfLines={1}>
          {detail || item.unit}
        </Text>
        <View className="flex-row flex-wrap items-center gap-1.5">
          {out ? (
            <Badge label="Out of stock" tone="red" />
          ) : (
            <Badge label={`${item.available} ${item.unit} in stock`} tone={item.available <= 10 ? 'amber' : 'green'} />
          )}
          {expiringSoon && !out && (
            <Badge
              label={expiryDays! < 0 ? 'Expired batch' : `Exp. ${expiryDays}d`}
              tone="amber"
              a11y={`Nearest expiry in ${expiryDays} days`}
            />
          )}
          {item.packs.length > 0 && <Text className="text-xs text-slate-400">Also by the {item.packs.map((p) => p.name.toLowerCase()).join(', ')}</Text>}
        </View>
      </View>
      <View className="items-end gap-1.5">
        <Text className="text-base font-bold text-brand-900">{money(item.price_minor)}</Text>
        {!dimmed && (
          <View className="h-9 w-9 items-center justify-center bg-brand-600">
            <Ionicons name="add" size={22} color={colors.white} />
          </View>
        )}
      </View>
    </Pressable>
  );
}
