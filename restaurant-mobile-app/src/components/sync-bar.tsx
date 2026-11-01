import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { Pressable, Text } from 'react-native';

import { plural } from '@/lib/format';
import { colors } from '@/lib/theme';
import { queuedFor, useOutbox } from '@/offline/outbox';
import { useAuth } from '@/store/auth';

/**
 * Under the app bar: no connection (work goes on, kept on the phone), what
 * waits to be sent, and what the restaurant refused. Tapping it opens the
 * list (/outbox).
 */
export function SyncBar() {
  const reachable = useAuth((s) => s.reachable);
  const owner = useAuth((s) => (s.workspace && s.branchId && s.me ? `${s.workspace}|${s.branchId}|${s.me.user.id}` : null));
  const waiting = useOutbox((s) => queuedFor(s.entries, owner).waiting.length);
  const refused = useOutbox((s) => queuedFor(s.entries, owner).refused.length);

  if (reachable && waiting === 0 && refused === 0) return null;

  const tone = refused > 0 ? 'bg-red-600' : reachable ? 'bg-sky-700' : 'bg-amber-500';
  const icon = refused > 0 ? 'alert-circle-outline' : reachable ? 'cloud-upload-outline' : 'cloud-offline-outline';
  const text =
    refused > 0
      ? `${plural(refused, 'thing')} not sent: tap to see why.`
      : !reachable
        ? `No connection: orders and payments are kept on this phone${waiting ? ` (${waiting} waiting)` : ''}.`
        : `Sending ${plural(waiting, 'thing')} to the restaurant…`;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={text}
      onPress={() => router.push('/outbox')}
      className={`flex-row items-center gap-2 px-4 py-1.5 ${tone}`}>
      <Ionicons name={icon} size={14} color={colors.white} />
      <Text className="flex-1 text-xs font-semibold text-white">{text}</Text>
      <Ionicons name="chevron-forward" size={14} color={colors.white} />
    </Pressable>
  );
}
