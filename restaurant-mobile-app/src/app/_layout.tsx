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
  const mode = useAuth((s) => s.mode);
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
        {/* Every screen starts with a red header or backdrop, so the status bar is always light. */}
        <StatusBar style="light" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
          <Stack.Screen name="index" />
          <Stack.Screen name="oauth" />

          <Stack.Protected guard={status === 'chooseMode'}>
            <Stack.Screen name="welcome" />
          </Stack.Protected>

          {/* Offline: the restaurant on this phone. */}
          <Stack.Protected guard={status === 'setUp'}>
            <Stack.Screen name="set-up" />
          </Stack.Protected>
          <Stack.Protected guard={status === 'signedOut' && mode === 'offline'}>
            <Stack.Screen name="sign-in" />
          </Stack.Protected>

          {/* Online: the workspace's restaurant. */}
          <Stack.Protected guard={status === 'signedOut' && mode === 'online'}>
            <Stack.Screen name="login" />
          </Stack.Protected>
          <Stack.Protected guard={status === 'chooseRestaurant'}>
            <Stack.Screen name="choose-restaurant" />
          </Stack.Protected>
          <Stack.Protected guard={status === 'needsBranch'}>
            <Stack.Screen name="select-branch" />
          </Stack.Protected>

          <Stack.Protected guard={status === 'ready'}>
            <Stack.Screen name="(drawer)" />
            <Stack.Screen name="page/[...path]" />
            <Stack.Screen name="account" options={{ presentation: 'modal' }} />
            <Stack.Screen name="backup" options={{ presentation: 'modal' }} />
          </Stack.Protected>
        </Stack>
        <Toast />
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
