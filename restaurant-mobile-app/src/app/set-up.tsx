import { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Chips, Toggle } from '@/components/pages/kit';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, SectionTitle } from '@/components/ui/misc';
import { ApiError, errorMessage } from '@/lib/api/client';
import { CURRENCIES, decimalsFor } from '@/lib/currencies';
import type { SetUpInput } from '@/local/db/session';
import { useAuth } from '@/store/auth';

/**
 * Offline, first run: the restaurant, and the manager who runs it. They are
 * signed in after, and add their staff under Staff. A sample menu with
 * tables and drinks in stock can be added to try the app.
 */
export default function SetUpScreen() {
  const setUp = useAuth((s) => s.setUp);
  const switchMode = useAuth((s) => s.switchMode);
  const insets = useSafeAreaInsets();
  const [data, setData] = useState<SetUpInput>({
    restaurant_name: '',
    address: '',
    phone: '',
    currency: 'SSP',
    decimals: 2,
    manager_name: '',
    username: '',
    password: '',
    password_confirmation: '',
    sample: true,
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof SetUpInput>(key: K, value: SetUpInput[K]) => setData((d) => ({ ...d, [key]: value }));

  async function submit() {
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      await setUp(data);
    } catch (e) {
      if (e instanceof ApiError) setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      setError(errorMessage(e));
      setBusy(false);
    }
  }

  return (
    <View className="flex-1 bg-canvas">
      <ScreenHeader title="Set up your restaurant" subtitle="Offline, on this phone" icon="arrow-back" iconLabel="Choose online or offline again" onIcon={switchMode} />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ paddingBottom: insets.bottom + 24 }} contentContainerClassName="w-full max-w-xl gap-4 self-center p-5">
          {error ? <Banner tone="error">{error}</Banner> : null}

          <SectionTitle>The restaurant</SectionTitle>
          <Field label="Name" value={data.restaurant_name} onChangeText={(v) => set('restaurant_name', v)} error={errors.restaurant_name} placeholder="Juba Grill" />
          <Field label="Address" value={data.address} onChangeText={(v) => set('address', v)} hint="Optional: on the bills." />
          <Field label="Phone" value={data.phone} onChangeText={(v) => set('phone', v)} keyboardType="phone-pad" hint="Optional." />
          <View className="gap-1.5">
            <Text className="text-sm font-medium text-slate-700">Currency</Text>
            <Chips
              options={CURRENCIES.map(([code]) => ({ value: code, label: code }))}
              value={data.currency}
              onChange={(code) => setData((d) => ({ ...d, currency: code, decimals: decimalsFor(code) }))}
            />
            <Text className="text-sm text-slate-500">
              {CURRENCIES.find(([code]) => code === data.currency)?.[2] ?? ''}. Every price is in it; it cannot change later.
            </Text>
            {errors.currency ? <Text className="text-sm text-red-600">{errors.currency}</Text> : null}
          </View>

          <SectionTitle>You, the manager</SectionTitle>
          <Field label="Your name" value={data.manager_name} onChangeText={(v) => set('manager_name', v)} error={errors.manager_name} />
          <Field
            label="Username"
            value={data.username}
            onChangeText={(v) => set('username', v)}
            autoCapitalize="none"
            autoCorrect={false}
            error={errors.username}
            hint="What you sign in with."
          />
          <Field label="Password" value={data.password} onChangeText={(v) => set('password', v)} secureTextEntry autoCapitalize="none" error={errors.password} hint="At least 6 characters." />
          <Field
            label="Password again"
            value={data.password_confirmation}
            onChangeText={(v) => set('password_confirmation', v)}
            secureTextEntry
            autoCapitalize="none"
            error={errors.password_confirmation}
          />

          <Toggle label="Add a sample menu" hint="Dishes, drinks in stock and six tables, to try it out. Change or remove them later." value={!!data.sample} onChange={(v) => set('sample', v)} />

          <Button title="Open the restaurant" icon="checkmark-circle-outline" size="lg" loading={busy} onPress={submit} />
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}
