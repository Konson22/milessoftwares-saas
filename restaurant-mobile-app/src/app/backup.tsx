import { Ionicons } from '@expo/vector-icons';
import * as DocumentPicker from 'expo-document-picker';
import { router } from 'expo-router';
import * as Sharing from 'expo-sharing';
import { useCallback, useState } from 'react';
import { FlatList, Text, View } from 'react-native';

import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Badge, Banner, Card, EmptyState } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api/client';
import { confirm, haptic } from '@/lib/feedback';
import { dateTime } from '@/lib/format';
import { colors } from '@/lib/theme';
import { backupNow, deleteBackup, listBackups, restoreBackup, type Backup } from '@/local/backup';
import { useAuth } from '@/store/auth';
import { toast } from '@/store/toast';

const sizeOf = (bytes: number) => (bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

/**
 * Backups (managers): the copies on this phone, a copy now, sharing one off
 * the phone, and putting one back. Everything the offline restaurant has lives only
 * here, so a copy kept elsewhere is what survives a lost or broken phone.
 */
export default function BackupScreen() {
  const manager = useAuth((s) => s.mode === 'offline' && s.me?.user.role === 'manager');
  const [backups, setBackups] = useState<Backup[]>(() => safeList());
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const refresh = useCallback(() => setBackups(safeList()), []);

  async function run(key: string, task: () => Promise<void> | void) {
    setBusy(key);
    setError(null);
    try {
      await task();
    } catch (e) {
      haptic.error();
      setError(errorMessage(e));
    } finally {
      setBusy(null);
      refresh();
    }
  }

  const makeBackup = () =>
    run('now', () => {
      backupNow('manual');
      haptic.success();
      toast('Backup made on this phone. Share it to keep a copy elsewhere.');
    });

  const share = (b: Backup) =>
    run(`share:${b.uri}`, async () => {
      if (!(await Sharing.isAvailableAsync())) throw new Error('Sharing is not available on this phone.');
      await Sharing.shareAsync(b.uri, { mimeType: 'application/octet-stream', dialogTitle: 'Keep this backup somewhere safe' });
    });

  async function restore(uri: string, label: string) {
    const ok = await confirm(
      'Put this backup back?',
      `Everything now on this phone is replaced by ${label}. A copy of it is kept first, and everyone signs in again.`,
      'Restore',
      true,
    );
    if (!ok) return;
    await run(`restore:${uri}`, async () => {
      await restoreBackup(uri);
      haptic.success();
      toast('Backup restored. Sign in again.');
      await useAuth.getState().hydrate();
    });
  }

  const restoreFromFile = () =>
    run('pick', async () => {
      const picked = await DocumentPicker.getDocumentAsync({ copyToCacheDirectory: true, multiple: false });
      if (picked.canceled || !picked.assets?.[0]) return;
      setBusy(null);
      await restore(picked.assets[0].uri, picked.assets[0].name);
    });

  async function remove(b: Backup) {
    if (await confirm('Delete this backup?', `${b.name} is deleted from this phone.`, 'Delete', true)) {
      await run(`delete:${b.uri}`, () => deleteBackup(b.uri));
    }
  }

  if (!manager) {
    return (
      <View className="flex-1 bg-canvas">
        <ScreenHeader title="Backups" icon="close" iconLabel="Close" onIcon={() => router.back()} />
        <EmptyState icon="lock-closed-outline" title="Managers only" message="A manager makes and restores backups." />
      </View>
    );
  }

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Backups" subtitle="A copy a day is kept on its own" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <FlatList
        data={backups}
        keyExtractor={(b) => b.uri}
        contentContainerClassName="w-full max-w-3xl self-center flex-grow gap-3 p-4"
        ListHeaderComponent={
          <View className="gap-3">
            <Banner tone="info">
              Everything lives on this phone only. Share a backup to a computer, a USB stick or your email now and then, so a lost or broken
              phone loses nothing.
            </Banner>
            <Button title="Back up now" icon="save" loading={busy === 'now'} onPress={makeBackup} />
            <Button title="Restore from a file" variant="outline" icon="folder-open-outline" loading={busy === 'pick'} onPress={restoreFromFile} />
            {error && <Banner tone="error">{error}</Banner>}
          </View>
        }
        ListEmptyComponent={<EmptyState icon="save-outline" title="No backups yet" message="Back up now, or wait for tomorrow's own copy." />}
        renderItem={({ item }) => (
          <Card className="gap-3">
            <View className="flex-row items-center gap-3">
              <View className="h-11 w-11 items-center justify-center bg-brand-50">
                <Ionicons name="server-outline" size={20} color={colors.brand[600]} />
              </View>
              <View className="flex-1">
                <Text className="text-base font-semibold text-slate-900">{dateTime(item.at)}</Text>
                <Text className="text-sm text-slate-500">
                  {sizeOf(item.size)} · {item.name}
                </Text>
              </View>
              {item.auto && <Badge label="Daily" tone="blue" />}
              {item.name.includes('before-restore') && <Badge label="Before restore" tone="amber" />}
            </View>
            <View className="flex-row gap-2">
              <Button title="Share" variant="outline" icon="share-outline" className="flex-1" loading={busy === `share:${item.uri}`} onPress={() => share(item)} />
              <Button title="Restore" variant="outline" icon="refresh" className="flex-1" onPress={() => restore(item.uri, `the backup of ${dateTime(item.at)}`)} />
              <Button title="Delete" variant="ghost" icon="trash-outline" onPress={() => remove(item)} />
            </View>
          </Card>
        )}
      />
    </View>
  );
}

function safeList(): Backup[] {
  try {
    return listBackups();
  } catch {
    return [];
  }
}
