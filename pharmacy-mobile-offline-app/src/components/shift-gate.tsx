import { Redirect } from 'expo-router';
import type { ReactNode } from 'react';
import { ActivityIndicator, View } from 'react-native';

import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

/** Where shifts are required, selling needs one open; without it the member goes to Shift. */
export function ShiftGate({ children }: { children: ReactNode }) {
  const till = useAuth((s) => s.till);
  const loaded = useAuth((s) => s.tillLoaded);
  const required = useAuth((s) => s.counter?.counter.shifts_required ?? true);

  if (!required) return <>{children}</>;
  if (!loaded) {
    return (
      <View className="flex-1 items-center justify-center">
        <ActivityIndicator size="large" color={colors.brand[600]} />
      </View>
    );
  }
  if (!till) return <Redirect href="/shift" />;
  return <>{children}</>;
}
