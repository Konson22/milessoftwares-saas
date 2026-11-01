import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Chips, Detail, Panel, Toggle } from '@/components/pages/kit';
import { ScreenHeader } from '@/components/screen-header';
import { TerminalPrinterSettings } from '@/components/terminal-printer-settings';
import { Button } from '@/components/ui/button';
import { errorMessage } from '@/lib/api/client';
import { confirm } from '@/lib/feedback';
import { printsKitchenTickets, setPrintsKitchenTickets } from '@/lib/printing';
import { queuedFor, useOutbox } from '@/offline/outbox';
import { scope } from '@/offline/cache';
import { useAuth, useBranches } from '@/store/auth';
import { toast } from '@/store/toast';

/**
 * Who is signed in and where: the branch (in more than one), this phone's
 * printing, signing out.
 */
export default function AccountScreen() {
  const me = useAuth((s) => s.me);
  const workspace = useAuth((s) => s.workspace);
  const branchId = useAuth((s) => s.branchId);
  const chooseBranch = useAuth((s) => s.chooseBranch);
  const signOut = useAuth((s) => s.signOut);
  const branches = useBranches();
  const insets = useSafeAreaInsets();
  const [printKitchen, setPrintKitchen] = useState(true);

  useEffect(() => {
    void printsKitchenTickets().then(setPrintKitchen);
  }, []);

  const changePrintKitchen = (on: boolean) => {
    setPrintKitchen(on);
    void setPrintsKitchenTickets(on);
  };

  const leave = async () => {
    const waiting = queuedFor(useOutbox.getState().entries, scope()).all.length;
    if (
      waiting > 0 &&
      !(await confirm(
        'Sign out with work not sent?',
        `${waiting} ${waiting === 1 ? 'thing' : 'things'} done without a connection still wait to be sent. They are sent when you sign in here again, at this branch.`,
        'Sign out',
        true,
      ))
    ) {
      return;
    }
    router.back();
    await signOut();
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
            <Detail label="Restaurant" value={me?.organization.name ?? '—'} />
            {workspace ? <Detail label="Workspace" value={workspace} /> : null}
            <Detail label="Currency" value={me?.organization.currency ?? '—'} />
          </View>
        </Panel>

        {branches.length > 1 ? (
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

        <TerminalPrinterSettings />

        <Button title="Sign out" icon="log-out-outline" variant="danger" size="lg" onPress={leave} />
      </ScrollView>
    </View>
  );
}
