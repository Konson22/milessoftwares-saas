import { router, useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { PageView } from '@/components/pages/page-view';
import { ScreenHeader } from '@/components/screen-header';
import { SyncBar } from '@/components/sync-bar';
import { sectionTitle } from '@/lib/navigation';

const RECORD_TITLES: Record<string, string> = {
  sales: 'Sale',
  shifts: 'Shift',
  tanks: 'Tank',
  deliveries: 'Delivery',
  purchases: 'Purchase',
  customers: 'Customer',
  suppliers: 'Supplier',
  attendants: 'Attendant',
  pumps: 'Pump',
  stores: 'Store',
};

/** A record (or any page) opened from a section: the web's "<section>/<id>", over the tabs. */
export default function RecordScreen() {
  const { path } = useLocalSearchParams<{ path: string[] }>();
  const parts = Array.isArray(path) ? path : [path];
  const joined = parts.join('/');
  const section = parts[0]?.split('?')[0] ?? '';
  const title = parts.length > 1 ? (RECORD_TITLES[section] ?? sectionTitle(section) ?? 'Details') : (sectionTitle(section) ?? 'Miles Petrol');

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title={title} icon="arrow-back" iconLabel="Back" onIcon={() => (router.canGoBack() ? router.back() : router.navigate('/home'))} />
      <SyncBar />
      <PageView key={joined} path={joined} />
    </View>
  );
}
