import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Chips, Detail, Panel, Toggle } from '@/components/pages/kit';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api/client';
import { confirm } from '@/lib/feedback';
import { printsKitchenTickets, setPrintsKitchenTickets } from '@/lib/printing';
import { ROLES } from '@/local/db/session';
import { useAuth, useBranches } from '@/store/auth';
import { toast } from '@/store/toast';

/**
 * Who is signed in, where, and how the app works: the branch (online, in
 * more than one), switching between online and offline, signing out.
 */
export default function AccountScreen() {
  const me = useAuth((s) => s.me);
  const mode = useAuth((s) => s.mode);
  const workspace = useAuth((s) => s.workspace);
  const branchId = useAuth((s) => s.branchId);
  const chooseBranch = useAuth((s) => s.chooseBranch);
  const signOut = useAuth((s) => s.signOut);
  const switchMode = useAuth((s) => s.switchMode);
  const branches = useBranches();
  const insets = useSafeAreaInsets();
  const offline = mode === 'offline';
  const [printKitchen, setPrintKitchen] = useState(true);

  useEffect(() => {
    void printsKitchenTickets().then(setPrintKitchen);
  }, []);

  const changePrintKitchen = (on: boolean) => {
    setPrintKitchen(on);
    void setPrintsKitchenTickets(on);
  };

  const leave = async () => {
    router.back();
    await signOut();
  };

  const switchTo = async () => {
    const ok = await confirm(
      offline ? 'Use it online instead?' : 'Use it offline instead?',
      offline
        ? 'You sign in with your Miles Softwares account and work with your workspace. The restaurant on this phone stays here, for when you switch back.'
        : 'The restaurant then lives on this phone only, apart from your workspace: nothing is shared between them. You are signed out of the workspace.',
      'Switch',
    );
    if (!ok) return;
    router.back();
    await switchMode();
  };

  const changeBranch = async (id: string) => {
    try {
      await chooseBranch(id);
      toast(`Working at ${branches.find((b) => b.id === id)?.name ?? 'the branch'}.`);
    } catch (e) {
      toast(errorMessage(e), 'error');
    }
  };

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Account" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ paddingBottom: insets.bottom + 24 }} contentContainerClassName="w-full max-w-xl gap-4 self-center p-4">
        <Panel title="Signed in">
          <View className="px-4 py-2">
            <Detail label="Name" value={me?.user.name ?? '—'} />
            {me?.user.email ? <Detail label="Email" value={me.user.email} /> : null}
            {me?.user.username ? <Detail label="Username" value={me.user.username} /> : null}
            {me?.user.role ? <Detail label="Role" value={ROLES.find((r) => r.value === me.user.role)?.label ?? me.user.role} /> : null}
            <Detail label="Restaurant" value={me?.organization.name ?? '—'} />
            {workspace ? <Detail label="Workspace" value={workspace} /> : null}
            <Detail label="Currency" value={me?.organization.currency ?? '—'} />
          </View>
        </Panel>

        {!offline && branches.length > 1 ? (
          <Panel title="Branch">
            <View className="gap-2 p-4">
              <Text className="text-sm text-slate-500">The floor, kitchen and tills of the branch you work at.</Text>
              <Chips options={branches.map((b) => ({ value: b.id, label: b.name }))} value={branchId} onChange={changeBranch} />
            </View>
          </Panel>
        ) : null}

        <Panel title="Printing">
          <View className="p-4">
            <Toggle
              label="Print a kitchen ticket on send"
              hint="Each time lines go to the kitchen, this phone prints their ticket. Tickets can always be printed again from the order or the kitchen."
              value={printKitchen}
              onChange={changePrintKitchen}
            />
          </View>
        </Panel>

        <Panel title="How the app works">
          <View className="gap-3 p-4">
            <Banner tone={offline ? 'warning' : 'info'}>
              {offline
                ? 'Offline: the restaurant lives on this phone. Back it up now and then, and share the copy off the phone.'
                : 'Online: with your workspace, shared by every phone, tablet and PC of the restaurant.'}
            </Banner>
            {offline && me?.user.role === 'manager' ? (
              <Button
                title="Backups"
                icon="cloud-download-outline"
                variant="outline"
                onPress={() => {
                  router.back();
                  router.push('/backup');
                }}
              />
            ) : null}
            <Button title={offline ? 'Switch to online' : 'Switch to offline, on this phone'} icon="swap-horizontal-outline" variant="outline" onPress={switchTo} />
          </View>
        </Panel>

        <Button title={offline ? 'Sign out: someone else works next' : 'Sign out'} icon="log-out-outline" variant="danger" size="lg" onPress={leave} />
      </ScrollView>
    </View>
  );
}
