import '../../global.css';

import * as Network from 'expo-network';
import * as Notifications from 'expo-notifications';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { AppState } from 'react-native';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { LoadingScreen } from '@/components/loading-screen';
import { Toast } from '@/components/toast';
import { openRoute } from '@/lib/open-notice';
import { configureForeground, registerPush } from '@/lib/push';
import { belongsHere, parseRoute, type PushData } from '@/logbook/notices';
import { colors } from '@/lib/theme';
import { useAuth, useScope } from '@/store/auth';
import { useLogbook } from '@/store/logbook';
import { useTrips } from '@/store/trips';
import { useWork } from '@/store/work';

void SplashScreen.preventAutoHideAsync();
configureForeground();

/**
 * Opens the signed-in person's entries and keeps it moving: a sync
 * when the network comes back, a full refresh when the app returns to the
 * front, and a check every minute while open that sends only when
 * something is due (each item waits out its own backoff), so an idle
 * phone makes no requests and spares the battery and data.
 */
function SyncRunner() {
  const scope = useScope();
  const open = useLogbook((s) => s.open);

  useEffect(() => {
    // Tasks follow the person signed in: another person on this phone sees none of them.
    useWork.setState({ tasks: [], actions: [], due: [], missed: [], toReview: null, unread: 0 });
    void open(scope).then(() => useWork.getState().refresh());
    // A trip left recording when the app was killed is found and shown as interrupted (ADR-0028).
    void useTrips.getState().open(scope);
  }, [scope, open]);

  useEffect(() => {
    if (!scope) return;
    const sync = () => {
      void useLogbook.getState().syncDue();
      // Task work kept offline (ADR-0026): sent as soon as it can be.
      void useWork.getState().flush();
      void useTrips.getState().sync();
    };
    const network = Network.addNetworkStateListener((state) => {
      if (state.isConnected && state.isInternetReachable !== false) {
        void useLogbook.getState().sync({ pull: true });
        void useWork.getState().refresh();
        void useTrips.getState().sync();
      }
    });
    const app = AppState.addEventListener('change', (state) => {
      if (state === 'active') {
        void useLogbook.getState().refresh();
        void useWork.getState().refresh();
        void useTrips.getState().check();
      }
    });
    const timer = setInterval(sync, 60_000);
    return () => {
      network.remove();
      app.remove();
      clearInterval(timer);
    };
  }, [scope]);

  return null;
}

/**
 * Phone notifications (ADR-0027): the token is told to the server for the
 * person signed in (and again when it changes), a push that arrives
 * refreshes the work, and a tap opens what it is about, only when it was
 * sent to the person and workspace signed in now.
 */
let openedPush: string | null = null;

function PushRunner() {
  const scope = useScope();
  const workspace = useAuth((s) => s.workspace);
  const userId = useAuth((s) => s.me?.user.id ?? null);
  const last = Notifications.useLastNotificationResponse();

  useEffect(() => {
    if (!scope) return;
    void registerPush(scope).catch(() => undefined);
    const token = Notifications.addPushTokenListener((t) => void registerPush(scope, String(t.data)).catch(() => undefined));
    const received = Notifications.addNotificationReceivedListener(() => void useWork.getState().refresh());
    return () => {
      token.remove();
      received.remove();
    };
  }, [scope]);

  useEffect(() => {
    if (!last || !workspace || !userId) return;
    // Each tap opens once, even when this runner mounts again after a switch.
    const id = last.notification.request.identifier;
    if (openedPush === id) return;
    openedPush = id;
    const data = last.notification.request.content.data as PushData | undefined;
    if (!belongsHere(data, { workspace, userId })) return;
    void openRoute(parseRoute(data?.route));
  }, [last, workspace, userId]);

  return null;
}

export default function RootLayout() {
  const status = useAuth((s) => s.status);
  const hydrate = useAuth((s) => s.hydrate);
  const [shown, setShown] = useState(false);

  useEffect(() => {
    void hydrate();
    const timer = setTimeout(() => setShown(true), 900);
    return () => clearTimeout(timer);
  }, [hydrate]);

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
        <StatusBar style="light" />
        <SyncRunner />
        {status === 'ready' && <PushRunner />}
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.canvas } }}>
          <Stack.Screen name="index" />
          <Stack.Screen name="oauth" />

          <Stack.Protected guard={status === 'signedOut' || status === 'noBusiness'}>
            <Stack.Screen name="login" />
          </Stack.Protected>
          <Stack.Protected guard={status === 'chooseBusiness'}>
            <Stack.Screen name="choose-business" />
          </Stack.Protected>

          <Stack.Protected guard={status === 'ready'}>
            <Stack.Screen name="(drawer)" />
            <Stack.Screen name="entry/[id]" />
            <Stack.Screen name="review/[id]" />
            <Stack.Screen name="task/[id]" />
            <Stack.Screen name="trip" />
            <Stack.Screen name="trips" />
          </Stack.Protected>
        </Stack>
        <Toast />
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
