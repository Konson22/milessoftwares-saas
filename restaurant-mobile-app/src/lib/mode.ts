import AsyncStorage from '@react-native-async-storage/async-storage';

import type { Mode } from './types';

/**
 * The way this phone works, chosen on first opening and changed from the
 * account screen: online with the workspace's restaurant, or offline with
 * the restaurant kept on the phone. Switching keeps the other's data: the
 * phone's database stays, and so does the workspace.
 */

const MODE_KEY = 'miles-restaurant.mode';

let current: Mode | null = null;

export const mode = () => current;

export async function loadMode(): Promise<Mode | null> {
  const saved = await AsyncStorage.getItem(MODE_KEY);
  current = saved === 'online' || saved === 'offline' ? saved : null;
  return current;
}

export async function saveMode(next: Mode | null) {
  current = next;
  if (next) await AsyncStorage.setItem(MODE_KEY, next);
  else await AsyncStorage.removeItem(MODE_KEY);
}
