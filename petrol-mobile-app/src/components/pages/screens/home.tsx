import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import type { IconName } from '@/components/ui/button';
import { Banner, Hero } from '@/components/ui/misc';
import { openPage, useAccess } from '@/hooks/use-page';
import { litres, money, plural } from '@/lib/format';
import { allowed, navItem } from '@/lib/navigation';
import { colors } from '@/lib/theme';
import { useAuth, useBranch } from '@/store/auth';

import { Scroll, Stat } from '../kit';
import type { ScreenProps } from '../types';

interface Props {
  station: { code: string; hours: string | null; collectionMode: string; active: boolean; main: boolean } | null;
  shift: { id: string; number: string; status: string } | null;
  today: { count: number; total_minor: number; milli: number } | null;
  lowTanks: number | null;
  pendingApprovals: number | null;
  creditMinor: number | null;
  canSetup: boolean;
}

const SHORTCUTS: { path: string; title: string; icon: IconName; text: string }[] = [
  { path: 'sales/create', title: 'Sell fuel', icon: 'add-circle-outline', text: 'Pick the nozzle, the litres or the amount, and the payment.' },
  { path: 'shifts', title: 'Shifts', icon: 'time-outline', text: 'Open the shift with meter readings; close it with the count.' },
  { path: 'tanks', title: 'Tanks', icon: 'water-outline', text: 'What each tank holds; dips and water checks.' },
  { path: 'deliveries', title: 'Deliveries', icon: 'bus-outline', text: 'Receive a tanker into a tank.' },
  { path: 'cash', title: 'Cash', icon: 'wallet-outline', text: 'Hand in cash, count the drawer, bank it.' },
];

/** Go to a section: its tab, or its page. */
export function goTo(path: string) {
  const item = navItem(path);
  if (item?.tab) router.navigate(item.tab as never);
  else openPage(path);
}

/** Today at this branch, for what the member may see, and the way in to the rest. */
export function HomeScreen({ page, reload }: ScreenProps<Props>) {
  const { station, shift, today, lowTanks, pendingApprovals, creditMinor } = page.props;
  const user = useAuth((s) => s.me?.user);
  const organization = useAuth((s) => s.me?.organization.name);
  const reachable = useAuth((s) => s.reachable);
  const branch = useBranch();
  const shared = useAccess((s) => s.shared);
  const shortcuts = SHORTCUTS.filter((s) => {
    const item = navItem(s.path);
    return item !== null && allowed(item, shared);
  });

  return (
    <Scroll onRefresh={reload}>
      <Hero>
        <Text className="text-2xl font-bold text-white">Hello, {user?.name.split(' ')[0] ?? ''}</Text>
        <Text className="mt-0.5 text-base text-brand-100">
          {[organization, branch && branch.name !== organization ? branch.name : null, station?.code].filter(Boolean).join(' · ')}
        </Text>
        <View className="mt-3 flex-row items-center gap-1.5">
          <Ionicons name={reachable ? 'cloud-done-outline' : 'cloud-offline-outline'} size={14} color={colors.brand[100]} />
          <Text className="text-xs font-semibold uppercase tracking-wider text-brand-100">{reachable ? 'Online' : 'No connection'}</Text>
        </View>
      </Hero>

      {station === null ? (
        <Banner tone="warning">This branch has no station set up yet. An owner sets it up on the web.</Banner>
      ) : shift === null ? (
        <Banner tone="warning">No shift is open. Sales start once a shift is opened.</Banner>
      ) : (
        <Pressable accessibilityRole="button" onPress={() => openPage(`shifts/${shift.id}`)} className="flex-row items-center gap-2 border border-emerald-200 bg-emerald-50 px-4 py-3">
          <Ionicons name="time-outline" size={18} color={colors.successDark} />
          <Text className="flex-1 text-base font-semibold text-emerald-900">Shift {shift.number} is open</Text>
          <Ionicons name="chevron-forward" size={18} color={colors.successDark} />
        </Pressable>
      )}

      <View className="flex-row flex-wrap gap-3">
        {today !== null ? (
          <>
            <Stat icon="receipt-outline" label={`Sales today · ${plural(today.count, 'sale')}`} value={money(today.total_minor)} onPress={() => goTo('sales')} />
            <Stat icon="water-outline" label="Litres today" value={litres(today.milli / 1000)} onPress={() => goTo('sales')} />
          </>
        ) : null}
        {lowTanks !== null ? (
          <Stat icon="alert-circle-outline" label="Tanks running low" value={String(lowTanks)} tone={lowTanks > 0 ? 'amber' : undefined} onPress={() => goTo('tanks')} />
        ) : null}
        {pendingApprovals !== null ? (
          <Stat icon="checkmark-done-outline" label="Shifts to approve" value={String(pendingApprovals)} tone={pendingApprovals > 0 ? 'amber' : undefined} onPress={() => goTo('shifts')} />
        ) : null}
        {creditMinor !== null ? <Stat icon="people-outline" label="Owed by customers" value={money(creditMinor)} onPress={() => goTo('customers')} /> : null}
      </View>

      <View className="gap-2">
        {shortcuts.map((s) => (
          <Pressable
            key={s.path}
            accessibilityRole="button"
            onPress={() => goTo(s.path)}
            className="flex-row items-center gap-3 border border-slate-200 bg-white p-4 active:bg-brand-50">
            <View className="h-11 w-11 items-center justify-center bg-brand-50">
              <Ionicons name={s.icon} size={22} color={colors.brand[700]} />
            </View>
            <View className="flex-1">
              <Text className="text-base font-semibold text-slate-900">{s.title}</Text>
              <Text className="text-sm text-slate-500">{s.text}</Text>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.faint} />
          </Pressable>
        ))}
      </View>
    </Scroll>
  );
}
