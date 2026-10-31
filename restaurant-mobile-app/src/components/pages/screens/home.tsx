import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import { Hero } from '@/components/ui/misc';
import type { IconName } from '@/components/ui/button';
import { openPage, useAccess } from '@/hooks/use-page';
import { money, plural } from '@/lib/format';
import { navItem, allowed } from '@/lib/navigation';
import { colors } from '@/lib/theme';
import { useAuth, useBranch } from '@/store/auth';

import { Scroll, Stat } from '../kit';
import type { ScreenProps } from '../types';

interface Props {
  openOrders: number | null;
  takings: { count: number; totalMinor: number } | null;
  lowStock: number | null;
}

const SHORTCUTS: { path: string; title: string; icon: IconName; text: string }[] = [
  { path: 'floor', title: 'Floor', icon: 'grid-outline', text: 'Seat a table or open a takeaway, and take the order.' },
  { path: 'kitchen', title: 'Kitchen', icon: 'flame-outline', text: 'Tickets to cook, oldest first; mark them ready.' },
  { path: 'tills', title: 'My shift', icon: 'wallet-outline', text: 'Start the shift with a float, close it with the count.' },
  { path: 'bills', title: 'Bills paid', icon: 'receipt-outline', text: "Today's bills; print or void one." },
];

/** Go to a section: its tab, or its page. */
export function goTo(path: string) {
  const item = navItem(path);
  if (item?.tab) router.navigate(item.tab as never);
  else openPage(path);
}

/** Today at this branch, for what the member may see, and the way in to the rest. */
export function HomeScreen({ page, reload }: ScreenProps<Props>) {
  const { openOrders, takings, lowStock } = page.props;
  const user = useAuth((s) => s.me?.user);
  const organization = useAuth((s) => s.me?.organization.name);
  const mode = useAuth((s) => s.mode);
  const branch = useBranch();
  const shared = useAccess((s) => s.shared);
  const shortcuts = SHORTCUTS.filter((s) => {
    const item = navItem(s.path);
    return item !== null && allowed(item, shared, mode);
  });

  return (
    <Scroll onRefresh={reload}>
      <Hero>
        <Text className="text-2xl font-bold text-white">Hello, {user?.name.split(' ')[0] ?? ''}</Text>
        <Text className="mt-0.5 text-base text-brand-100">
          {[organization, branch && branch.name !== organization ? branch.name : null].filter(Boolean).join(' · ')}
        </Text>
        <View className="mt-3 flex-row items-center gap-1.5">
          <Ionicons name={mode === 'offline' ? 'phone-portrait-outline' : 'cloud-done-outline'} size={14} color={colors.brand[100]} />
          <Text className="text-xs font-semibold uppercase tracking-wider text-brand-100">
            {mode === 'offline' ? 'Offline · on this phone' : 'Online'}
          </Text>
        </View>
      </Hero>

      <View className="flex-row flex-wrap gap-3">
        {openOrders !== null ? <Stat icon="list-outline" label="Open orders" value={String(openOrders)} onPress={() => goTo('floor')} /> : null}
        {takings !== null ? (
          <Stat icon="receipt-outline" label={`Takings today · ${plural(takings.count, 'bill')}`} value={money(takings.totalMinor)} onPress={() => goTo('bills')} />
        ) : null}
        {lowStock !== null ? (
          <Stat icon="beer-outline" label="Drinks running low" value={String(lowStock)} tone={lowStock > 0 ? 'amber' : undefined} onPress={() => goTo('stock')} />
        ) : null}
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
