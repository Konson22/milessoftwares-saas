import { useLocalSearchParams } from 'expo-router';
import { Text, View } from 'react-native';

import { PageView } from '@/components/pages/page-view';
import { sectionTitle } from '@/lib/navigation';

/** A section opened from the drawer (menu, tables, drinks stock, orders…), under the app bar. */
export default function SectionScreen() {
  const { section } = useLocalSearchParams<{ section: string }>();

  return (
    <View className="flex-1">
      <View className="border-b border-slate-200 bg-white px-4 py-3">
        <Text accessibilityRole="header" className="text-xl font-bold text-slate-900">
          {sectionTitle(section) ?? 'Restaurant'}
        </Text>
      </View>
      <PageView key={section} path={section} />
    </View>
  );
}
