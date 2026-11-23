import Constants from 'expo-constants';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useState } from 'react';
import { ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner } from '@/components/ui/misc';
import { unsent } from '@/collect/sync';
import { BRAND } from '@/lib/brand';
import { colors, webNoOutline } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { useCollect } from '@/store/collect';

const CONFIRM_WORD = 'SIGN OUT';

/**
 * The person and workspace, and signing out. Unsent records are never
 * deleted by signing out: they stay encrypted on the phone, scoped to this
 * person, and are sent when they sign in again. Signing out with unsent
 * work asks for a typed confirmation, so it is never done by accident.
 */
export default function AccountScreen() {
  const insets = useSafeAreaInsets();
  const me = useAuth((s) => s.me);
  const workspace = useAuth((s) => s.workspace);
  const signOut = useAuth((s) => s.signOut);
  const items = useCollect((s) => s.items);
  const [typed, setTyped] = useState('');
  const waiting = unsent(items);

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Account" icon="close" iconLabel="Close" onIcon={() => router.back()} />
      <ScrollView contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}>
        <View className="gap-1 border border-slate-200 bg-white p-4">
          <Text className="text-lg font-semibold text-slate-800">{me?.user.name}</Text>
          <Text className="text-sm text-slate-500">{me?.user.email}</Text>
          <Text className="pt-2 text-sm text-slate-700">
            {me?.organization.name} ({workspace})
          </Text>
          {me && !me.canCollect && <Text className="pt-2 text-sm text-amber-800">You may not collect data in this workspace.</Text>}
        </View>

        {waiting.length > 0 ? (
          <View className="gap-3">
            <Banner tone="warning">
              {waiting.length} {waiting.length === 1 ? 'record is' : 'records are'} not sent yet. They stay on this phone after you sign out, and are sent when you sign in
              again. Another person signing in here cannot see them.
            </Banner>
            <Text className="text-sm text-slate-700">Type {CONFIRM_WORD} to sign out anyway.</Text>
            <TextInput
              value={typed}
              onChangeText={setTyped}
              autoCapitalize="characters"
              placeholderTextColor={colors.faint}
              className="min-h-12 border border-slate-300 bg-white px-3 text-base"
              style={webNoOutline}
            />
            <Button title="Sign out" variant="danger" disabled={typed.trim().toUpperCase() !== CONFIRM_WORD} onPress={() => void signOut()} />
          </View>
        ) : (
          <Button title="Sign out" variant="outline" icon="log-out-outline" size="lg" onPress={() => void signOut()} />
        )}

        <View className="gap-2 border border-slate-200 bg-white p-4">
          <View className="flex-row items-center gap-3">
            <Image source={require('../../assets/images/icon.png')} style={{ width: 48, height: 48, borderRadius: 10 }} accessibilityIgnoresInvertColors />
            <View className="flex-1">
              <Text className="text-lg font-bold text-slate-900">{BRAND.name}</Text>
              <Text className="text-sm text-slate-600">{BRAND.subtitle}</Text>
            </View>
          </View>
          <Text className="text-base font-semibold text-brand-800">{BRAND.slogan}</Text>
          <Text className="text-sm text-slate-600">{BRAND.description}</Text>
          <Text className="pt-1 text-xs text-slate-500">
            Version {Constants.expoConfig?.version ?? '?'} · Part of Tatook, by {BRAND.maker}
          </Text>
        </View>
      </ScrollView>
    </View>
  );
}
