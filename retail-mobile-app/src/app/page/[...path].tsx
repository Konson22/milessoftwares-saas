import { router, useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { PageView } from '@/components/pages/page-view';
import { ScreenHeader } from '@/components/screen-header';
import { sectionTitle } from '@/lib/navigation';

const RECORD_TITLES: Record<string, string> = {
  stock: 'Stock item',
  orders: 'Order',
  customers: 'Customer',
  quotations: 'Quotation',
  tills: 'Shift',
  sales: 'Sale',
  locations: 'Location',
  counts: 'Stock count',
  transfers: 'Transfer',
  'purchase-orders': 'Purchase order',
  receipts: 'Goods received',
  'supplier-returns': 'Supplier return',
};

/** A record (or any page) opened from a section: the web's "<section>/<id>", over the drawer. */
export default function RecordScreen() {
  const { path } = useLocalSearchParams<{ path: string[] }>();
  const parts = Array.isArray(path) ? path : [path];
  const joined = parts.join('/');
  const section = parts[0]?.split('?')[0] ?? '';
  const title = parts.length > 1 ? (RECORD_TITLES[section] ?? sectionTitle(section) ?? 'Details') : (sectionTitle(section) ?? 'Shop');

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title={title} icon="arrow-back" iconLabel="Back" onIcon={() => router.back()} />
      <PageView key={joined} path={joined} />
    </View>
  );
}
