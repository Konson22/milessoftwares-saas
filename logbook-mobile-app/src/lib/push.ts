import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Crypto from 'expo-crypto';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import { request } from '@/lib/api/client';
import { secureStorage } from '@/lib/storage';
import { shouldRegister } from '@/logbook/notices';

/**
 * Phone notifications (ADR-0027). The server sends them through Expo's
 * push service; the phone only registers its token for the person signed
 * in, refreshes it, and removes it on sign-out. Nothing here runs in the
 * background: reminders are timed on the server.
 */

const INSTALL_KEY = 'tatook-logbook.install';
const LAST_KEY = 'tatook-logbook.push.last';
export const ANDROID_CHANNEL = 'work';

/** One id per installation, so a new token replaces this phone's old one. */
export async function installId(): Promise<string> {
  const saved = await secureStorage.get(INSTALL_KEY);
  if (saved) return saved;
  const id = Crypto.randomUUID();
  await secureStorage.set(INSTALL_KEY, id);
  return id;
}

/** Shown while the app is open as well, quietly. */
export function configureForeground() {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: false, shouldSetBadge: false }),
  });
}

async function ensureChannel() {
  if (Platform.OS !== 'android') return;
  await Notifications.setNotificationChannelAsync(ANDROID_CHANNEL, {
    name: 'Work: tasks, reminders and reviews',
    importance: Notifications.AndroidImportance.HIGH,
    lockscreenVisibility: Notifications.AndroidNotificationVisibility.PRIVATE,
  });
}

/**
 * Asks once for permission, then tells the server this phone's token.
 * Quiet on refusal, on an emulator, or offline: it tries again next time.
 */
export async function registerPush(scope: string, token?: string): Promise<'registered' | 'skipped' | 'denied'> {
  if (Platform.OS === 'web' || !Device.isDevice) return 'skipped';
  await ensureChannel();
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
  if (status !== 'granted') return 'denied';

  const projectId = Constants.expoConfig?.extra?.eas?.projectId as string | undefined;
  const pushToken = token ?? (await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined)).data;
  const key = `${scope}|${pushToken}`;
  const last = await AsyncStorage.getItem(LAST_KEY).then((v) => (v ? (JSON.parse(v) as { key: string; at: string }) : null));
  if (!shouldRegister(last, { key, at: new Date() })) return 'skipped';

  await request('POST', 'v1/push-devices', {
    token: pushToken,
    install_id: await installId(),
    platform: Platform.OS === 'ios' ? 'ios' : 'android',
    app_version: Constants.expoConfig?.version ?? null,
  });
  await AsyncStorage.setItem(LAST_KEY, JSON.stringify({ key, at: new Date().toISOString() }));
  return 'registered';
}

/**
 * Before the sign-in is dropped: the server stops sending to this phone,
 * and notices already shown are cleared so the next person sees none.
 */
export async function unregisterPush(): Promise<void> {
  await AsyncStorage.removeItem(LAST_KEY).catch(() => undefined);
  if (Platform.OS === 'web') return;
  await Notifications.dismissAllNotificationsAsync().catch(() => undefined);
  await request('POST', 'v1/push-devices/remove', { install_id: await installId() }, { timeoutMs: 5_000 }).catch(() => undefined);
}
