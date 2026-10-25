import { Ionicons } from '@expo/vector-icons';
import { Pressable, Text, View } from 'react-native';

import { colors } from '@/lib/theme';

export function QtyStepper({
  value,
  max,
  onChange,
  name,
}: {
  value: number;
  max: number;
  onChange: (qty: number) => void;
  name: string;
}) {
  return (
    <View className="flex-row items-center border border-brand-100 bg-brand-50">
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={value === 1 ? `Remove ${name}` : `Decrease ${name}`}
        onPress={() => onChange(value - 1)}
        className="h-11 w-11 items-center justify-center active:bg-brand-100">
        <Ionicons name={value === 1 ? 'trash-outline' : 'remove'} size={20} color={value === 1 ? colors.danger : colors.brand[700]} />
      </Pressable>
      <Text accessibilityLabel={`Quantity ${value}`} className="min-w-10 text-center text-lg font-bold text-slate-900">
        {value}
      </Text>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={`Increase ${name}`}
        disabled={value >= max}
        onPress={() => onChange(value + 1)}
        className={`h-11 w-11 items-center justify-center active:bg-brand-100 ${value >= max ? 'opacity-30' : ''}`}>
        <Ionicons name="add" size={20} color={colors.brand[700]} />
      </Pressable>
    </View>
  );
}
