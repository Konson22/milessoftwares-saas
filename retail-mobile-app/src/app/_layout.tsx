import '../../global.css';

import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { Toast } from '@/components/toast';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

void SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const status = useAuth((s) => s.status);
  const hydrate = useAuth((s) => s.hydrate);

  useEffect(() => {
    void hydrate();
  }, [hydrate]);

  useEffect(() => {
    if (status !== 'loading') void SplashScreen.hideAsync();
  }, [status]);

  if (status === 'loading') return null;

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        {/* Every screen starts with a navy header or backdrop, so the status bar is always light. */}
        <StatusBar style="light" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
          <Stack.Screen name="index" />
          <Stack.Protected guard={status === 'setUp'}>
            <Stack.Screen name="set-up" />
          </Stack.Protected>

          <Stack.Protected guard={status === 'signedOut'}>
            <Stack.Screen name="login" />
          </Stack.Protected>

          <Stack.Protected guard={status === 'ready'}>
            <Stack.Screen name="(drawer)" />
            <Stack.Screen name="page/[...path]" />
            <Stack.Screen name="checkout" options={{ presentation: 'modal' }} />
            <Stack.Screen name="scan" options={{ presentation: 'fullScreenModal', animation: 'fade' }} />
            <Stack.Screen name="receipt/[id]" />
            <Stack.Screen name="return/[id]" />
            <Stack.Screen name="held" options={{ presentation: 'modal' }} />
            <Stack.Screen name="account" options={{ presentation: 'modal' }} />
            <Stack.Screen name="notifications" options={{ presentation: 'modal' }} />
            <Stack.Screen name="backup" options={{ presentation: 'modal' }} />
          </Stack.Protected>
        </Stack>
        <Toast />
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
