import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { FlatList, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground, BrandMark } from '@/components/art-background';
import { Button } from '@/components/ui/button';
import { Badge, Banner } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api/client';
import { workspaceUrl } from '@/lib/config';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

/** Online, signed in with more than one restaurant: which to open. */
export default function ChooseRestaurantScreen() {
  const choices = useAuth((s) => s.choices);
  const chooseRestaurant = useAuth((s) => s.chooseRestaurant);
  const signOut = useAuth((s) => s.signOut);
  const insets = useSafeAreaInsets();
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function choose(slug: string) {
    setBusy(slug);
    setError(null);
    try {
      await chooseRestaurant(slug);
    } catch (e) {
      setError(errorMessage(e));
      setBusy(null);
    }
  }

  return (
    <ArtBackground>
      <BrandMark compact />
      <View className="w-full max-w-xl flex-1 self-center px-5" style={{ paddingBottom: insets.bottom + 16 }}>
        <Text accessibilityRole="header" className="text-2xl font-bold text-white">
          Choose the restaurant
        </Text>
        <Text className="mb-4 mt-1 text-base text-brand-100">You are in more than one.</Text>
        {error ? (
          <Banner tone="error" className="mb-3">
            {error}
          </Banner>
        ) : null}
        <FlatList
          data={choices}
          keyExtractor={(c) => c.id}
          contentContainerClassName="gap-3"
          renderItem={({ item }) => (
            <Pressable
              accessibilityRole="button"
              disabled={busy !== null || item.status !== 'active'}
              onPress={() => choose(item.slug)}
              className={`flex-row items-center gap-3 bg-white p-4 active:bg-brand-50 ${item.status !== 'active' ? 'opacity-60' : ''}`}>
              <View className="h-11 w-11 items-center justify-center bg-brand-50">
                <Ionicons name="restaurant-outline" size={22} color={colors.brand[700]} />
              </View>
              <View className="flex-1">
                <Text className="text-base font-semibold text-slate-900">{item.name}</Text>
                <Text className="text-sm text-slate-500">{workspaceUrl(item.slug).replace(/^https?:\/\//, '')}</Text>
              </View>
              {item.status !== 'active' ? <Badge label="suspended" tone="red" /> : item.is_owner ? <Badge label="owner" tone="blue" /> : null}
              <Ionicons name={busy === item.slug ? 'hourglass-outline' : 'chevron-forward'} size={20} color={colors.muted} />
            </Pressable>
          )}
        />
        <Button title="Sign out" variant="glass" onPress={signOut} className="mt-4" />
      </View>
    </ArtBackground>
  );
}
