import '../../global.css';

import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { LoadingScreen } from '@/components/loading-screen';
import { Toast } from '@/components/toast';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

void SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  const status = useAuth((s) => s.status);
  const hydrate = useAuth((s) => s.hydrate);

  // The loader stays a moment even when opening is quick, so it does not flash.
  const [shown, setShown] = useState(false);

  useEffect(() => {
    void hydrate();
    const timer = setTimeout(() => setShown(true), 900);
    return () => clearTimeout(timer);
  }, [hydrate]);

  // The native splash hands over to the loader as soon as it can draw.
  useEffect(() => {
    void SplashScreen.hideAsync();
  }, []);

  if (status === 'loading' || !shown) {
    return (
      <SafeAreaProvider>
        <StatusBar style="light" />
        <LoadingScreen />
      </SafeAreaProvider>
    );
  }

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        {/* Every screen starts with a red header or backdrop, so the status bar is always light. */}
        <StatusBar style="light" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
          <Stack.Screen name="index" />
          <Stack.Screen name="oauth" />

          <Stack.Protected guard={status === 'signedOut'}>
            <Stack.Screen name="login" />
          </Stack.Protected>
          {/* Registering, or signed in with no restaurant yet. */}
          <Stack.Protected guard={status === 'signedOut' || status === 'noRestaurant'}>
            <Stack.Screen name="create-business" />
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
            <Stack.Screen name="outbox" options={{ presentation: 'modal' }} />
          </Stack.Protected>
        </Stack>
        <Toast />
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
