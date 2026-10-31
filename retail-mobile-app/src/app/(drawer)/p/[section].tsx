import { useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { PageView } from '@/components/pages/page-view';
import { ScreenTitle } from '@/components/ui/misc';
import { pageOf, sectionTitle } from '@/lib/navigation';

/** A section of the shop from the drawer: /p/stock is the page "stock"; /p/receipts~create is "receipts/create". */
export default function SectionScreen() {
  const { section } = useLocalSearchParams<{ section: string }>();
  const path = pageOf(section);
  return (
    <View className="flex-1">
      <View className="w-full max-w-3xl self-center px-4 pt-4">
        <ScreenTitle title={sectionTitle(path) ?? 'Shop'} />
      </View>
      <PageView key={path} path={path} />
    </View>
  );
}
