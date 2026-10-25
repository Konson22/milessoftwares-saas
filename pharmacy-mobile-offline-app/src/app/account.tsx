import { router } from 'expo-router';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { PrinterSettings } from '@/components/printer-settings';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Hero } from '@/components/ui/misc';
import { confirm } from '@/lib/feedback';
import { initials } from '@/lib/format';
import { useAuth } from '@/store/auth';

const ROLE_LABEL = { manager: 'Manager', pharmacist: 'Pharmacist', cashier: 'Cashier' } as const;

/** Who is signed in, the receipt printer, backups (managers) and signing out. */
export default function AccountScreen() {
  const me = useAuth((s) => s.me);
  const till = useAuth((s) => s.till);
  const signOut = useAuth((s) => s.signOut);
  const manager = me?.user.role === 'manager';

  async function onSignOut() {
    const message = till
      ? 'Your shift stays open: close it from the Shift tab at the end of the day.'
      : 'Whoever uses this phone next signs in with their own username.';
    if (await confirm('Sign out?', message, 'Sign out', true)) await signOut();
  }

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Account" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <SafeAreaView className="flex-1" edges={['bottom']}>
        <ScrollView contentContainerClassName="w-full max-w-xl self-center gap-4 p-4">
          <Hero>
            <View className="flex-row items-center gap-4">
              <View className="h-16 w-16 items-center justify-center rounded-full bg-white">
                <Text className="text-2xl font-bold text-brand-700">{initials(me?.user.name ?? '')}</Text>
              </View>
              <View className="flex-1">
                <Text className="text-xl font-bold text-white">{me?.user.name}</Text>
                <Text className="text-sm text-brand-100">
                  {me?.user.username}
                  {me ? ` · ${ROLE_LABEL[me.user.role]}` : ''}
                </Text>
                {me?.organization.name && <Text className="mt-1 text-sm font-semibold text-white">{me.organization.name}</Text>}
              </View>
            </View>
          </Hero>

          <PrinterSettings />

          {manager && <Button title="Backups" variant="outline" icon="save-outline" onPress={() => router.push('/backup')} />}

          <Button title="Sign out" variant="outline" icon="log-out-outline" onPress={onSignOut} />
          <Text className="text-center text-xs text-slate-400">Miles Pharmacy Offline · Miles Softwares</Text>
        </ScrollView>
      </SafeAreaView>
    </View>
  );
}
