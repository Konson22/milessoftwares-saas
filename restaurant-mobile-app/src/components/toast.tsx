import { Ionicons } from '@expo/vector-icons';
import { Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { colors } from '@/lib/theme';
import { useToast } from '@/store/toast';

const TONE = {
  success: { box: 'bg-emerald-700', icon: 'checkmark-circle' },
  error: { box: 'bg-red-700', icon: 'alert-circle' },
  warning: { box: 'bg-amber-600', icon: 'warning' },
} as const;

/** The current toast, clear of the bottom nav and the system bar; a tap dismisses it. */
export function Toast() {
  const toast = useToast((s) => s.toast);
  const hide = useToast((s) => s.hide);
  const insets = useSafeAreaInsets();
  if (!toast) return null;
  const tone = TONE[toast.tone];

  return (
    <View pointerEvents="box-none" className="absolute inset-x-0 items-center px-4" style={{ bottom: insets.bottom + 84 }}>
      <Pressable
        key={toast.id}
        accessibilityRole="alert"
        onPress={hide}
        className={`w-full max-w-xl flex-row items-center gap-2 rounded-2xl px-4 py-3 shadow-lg ${tone.box}`}>
        <Ionicons name={tone.icon} size={20} color={colors.white} />
        <Text className="flex-1 text-base font-medium text-white">{toast.message}</Text>
      </Pressable>
    </View>
  );
}
