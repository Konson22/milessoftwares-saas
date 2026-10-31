import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { FlatList, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground, BrandMark } from '@/components/art-background';
import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api/client';
import { colors } from '@/lib/theme';
import { useAuth, useBranches } from '@/store/auth';

/** Online: the branch this phone works in, among those where the member has a restaurant role. */
export default function SelectBranchScreen() {
  const branches = useBranches();
  const reason = useAuth((s) => s.me?.reason);
  const organization = useAuth((s) => s.me?.organization.name);
  const chooseBranch = useAuth((s) => s.chooseBranch);
  const refreshMe = useAuth((s) => s.refreshMe);
  const signOut = useAuth((s) => s.signOut);
  const [selected, setSelected] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const insets = useSafeAreaInsets();

  async function submit() {
    if (!selected) return;
    setBusy(true);
    setError(null);
    try {
      await chooseBranch(selected);
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  }

  async function reload() {
    setError(null);
    try {
      await refreshMe();
    } catch (e) {
      setError(errorMessage(e));
    }
  }

  return (
    <ArtBackground>
      <BrandMark compact />
      <View className="w-full max-w-xl flex-1 self-center px-5" style={{ paddingBottom: insets.bottom + 16 }}>
        <Text accessibilityRole="header" className="text-2xl font-bold text-white">
          Choose the branch
        </Text>
        <Text className="mb-4 mt-1 text-base text-brand-100">{organization ? `${organization}: the` : 'The'} floor, kitchen and tills of this branch, until you switch.</Text>
        {error ? (
          <Banner tone="error" className="mb-3">
            {error}
          </Banner>
        ) : null}
        {branches.length === 0 ? (
          <Banner tone="warning">
            {reason === 'no_branches' ? 'This restaurant has no branches yet. Add one on the web first.' : 'You have no role at any branch yet. Ask the owner to give you one.'}
          </Banner>
        ) : null}
        <FlatList
          data={branches}
          keyExtractor={(b) => b.id}
          contentContainerClassName="gap-3"
          renderItem={({ item }) => {
            const active = item.id === selected;
            return (
              <Pressable
                accessibilityRole="radio"
                accessibilityState={{ selected: active }}
                onPress={() => setSelected(item.id)}
                className={`flex-row items-center gap-3 border-2 p-4 ${active ? 'border-accent bg-white' : 'border-transparent bg-white/95'}`}>
                <View className={`h-11 w-11 items-center justify-center ${active ? 'bg-brand-600' : 'bg-brand-50'}`}>
                  <Ionicons name="storefront-outline" size={22} color={active ? colors.white : colors.brand[700]} />
                </View>
                <View className="flex-1">
                  <Text className="text-base font-semibold text-slate-900">{item.name}</Text>
                  <Text className="text-sm text-slate-500">{[item.code, item.city].filter(Boolean).join(' · ') || '—'}</Text>
                </View>
                <Ionicons name={active ? 'radio-button-on' : 'radio-button-off'} size={22} color={active ? colors.brand[600] : colors.line} />
              </Pressable>
            );
          }}
        />
        <View className="gap-2 pt-4">
          {branches.length > 0 ? (
            <Button title="Continue" size="lg" disabled={!selected} loading={busy} onPress={submit} />
          ) : (
            <Button title="Check again" size="lg" icon="refresh" onPress={reload} />
          )}
          <Button title="Sign out" variant="glass" onPress={signOut} />
        </View>
      </View>
    </ArtBackground>
  );
}
