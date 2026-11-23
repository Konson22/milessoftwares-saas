import Constants from 'expo-constants';
import { Image } from 'expo-image';
import { useState } from 'react';
import { ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Banner, Card, ListGroup, ListItem } from '@/components/ui/misc';
import { unsent } from '@/logbook/sync';
import { BRAND } from '@/lib/brand';
import { colors, webNoOutline } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { useLogbook } from '@/store/logbook';

const CONFIRM_WORD = 'SIGN OUT';

/**
 * The person and workspace, and signing out. Unsent entries are never
 * deleted by signing out: they stay encrypted on the phone, scoped to this
 * person, and are sent when they sign in again. Signing out with unsent
 * work asks for a typed confirmation, so it is never done by accident.
 */
export default function AccountScreen() {
  const insets = useSafeAreaInsets();
  const me = useAuth((s) => s.me);
  const workspace = useAuth((s) => s.workspace);
  const signOut = useAuth((s) => s.signOut);
  const items = useLogbook((s) => s.items);
  const [typed, setTyped] = useState('');
  const waiting = unsent(items);

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader menu title="Account" />
      <ScrollView contentContainerStyle={{ padding: 16, gap: 16, paddingBottom: insets.bottom + 24 }}>
        <Card className="items-center gap-2 py-6">
          <View className="h-20 w-20 items-center justify-center rounded-full bg-brand-600">
            <Text className="text-3xl font-bold text-white">{(me?.user.name ?? '?').trim().charAt(0).toUpperCase()}</Text>
          </View>
          <Text className="text-xl font-bold text-slate-900">{me?.user.name}</Text>
          {me?.user.email ? <Text className="text-sm text-slate-500">{me.user.email}</Text> : null}
        </Card>

        <ListGroup title="Workspace">
          <ListItem icon="business" color="blue" title={me?.organization.name ?? 'No workspace'} detail={workspace ?? undefined} />
          <ListItem
            icon="create"
            color={me?.can.write ? 'green' : 'slate'}
            title="Write entries"
            right={<Text className={`text-sm font-semibold ${me?.can.write ? 'text-emerald-700' : 'text-slate-400'}`}>{me?.can.write ? 'Allowed' : 'No'}</Text>}
          />
          <ListItem
            icon="checkmark-done"
            color={me?.can.review ? 'violet' : 'slate'}
            title="Review entries"
            right={<Text className={`text-sm font-semibold ${me?.can.review ? 'text-violet-700' : 'text-slate-400'}`}>{me?.can.review ? 'Allowed' : 'No'}</Text>}
          />
        </ListGroup>
        {me && !me.can.write && !me.can.review && <Banner tone="warning">You have no Logbook role in this workspace yet. Ask its administrator.</Banner>}

        {waiting.length > 0 ? (
          <View className="gap-3">
            <Banner tone="warning">
              {waiting.length} {waiting.length === 1 ? 'entry is' : 'entries are'} not sent yet. They stay on this phone after you sign out, and are sent when you sign in
              again. Another person signing in here cannot see them.
            </Banner>
            <Text className="text-sm text-slate-700">Type {CONFIRM_WORD} to sign out anyway.</Text>
            <TextInput
              value={typed}
              onChangeText={setTyped}
              autoCapitalize="characters"
              placeholderTextColor={colors.faint}
              className="min-h-12 rounded-xl border border-slate-300 bg-white px-3 text-base"
              style={webNoOutline}
            />
            <Button title="Sign out" variant="danger" disabled={typed.trim().toUpperCase() !== CONFIRM_WORD} onPress={() => void signOut()} />
          </View>
        ) : (
          <Button title="Sign out" variant="outline" icon="log-out-outline" size="lg" onPress={() => void signOut()} />
        )}

        <Card className="gap-2">
          <View className="flex-row items-center gap-3">
            <Image source={require('../../../assets/images/icon.png')} style={{ width: 48, height: 48, borderRadius: 10 }} accessibilityIgnoresInvertColors />
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
        </Card>
      </ScrollView>
    </View>
  );
}
