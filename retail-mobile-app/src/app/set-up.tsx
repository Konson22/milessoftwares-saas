import { Image } from 'expo-image';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Switch, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner, SectionTitle } from '@/components/ui/misc';
import { ApiError, errorMessage } from '@/lib/api';
import type { SetUpInput } from '@/lib/api/types';
import { CURRENCIES } from '@/lib/currencies';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

/**
 * First run on this phone: the shop, and the manager who runs it. They
 * are signed in after, and add their staff under Users. Sample products
 * with stock can be added to try the app.
 */
export default function SetUpScreen() {
  const setUp = useAuth((s) => s.setUp);
  const insets = useSafeAreaInsets();
  const [data, setData] = useState<SetUpInput>({
    shop_name: '',
    address: '',
    contact: '',
    currency: 'SSP',
    decimals: 2,
    manager_name: '',
    username: '',
    password: '',
    password_confirmation: '',
    demo: false,
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
    } finally {
      setBusy(false);
    }
  }

  return (
    <View className="flex-1 bg-white">
      <View className="flex-row items-center gap-3 bg-brand-600 px-4 pb-3" style={{ paddingTop: insets.top + 10 }}>
        <Image
          source={require('../../assets/images/logo-mark.png')}
          contentFit="contain"
          style={{ width: 34, height: 34 }}
          accessibilityIgnoresInvertColors
          accessible={false}
        />
        <View>
          <Text className="text-base font-bold text-white">Miles Retail</Text>
          <Text className="text-xs font-semibold uppercase tracking-[2px] text-brand-100">Offline</Text>
        </View>
      </View>

      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <ScrollView
          contentContainerClassName="w-full max-w-xl self-center gap-4 p-5"
          contentContainerStyle={{ paddingBottom: insets.bottom + 24 }}
          keyboardShouldPersistTaps="handled">
          <View>
            <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
              Set up your shop
            </Text>
            <Text className="text-base text-slate-500">
              Once, on this phone. Everything stays here, with no internet needed. All of it can be changed later under Settings, except the
              currency.
            </Text>
          </View>

          {error && <Banner tone="error">{error}</Banner>}

          <SectionTitle>The shop</SectionTitle>
          <Field label="Name (on receipts)" value={data.shop_name} onChangeText={(t) => set('shop_name', t)} error={errors.shop_name} autoCapitalize="words" />
          <Field label="Address (optional)" value={data.address} onChangeText={(t) => set('address', t)} placeholder="Street, town" error={errors.address} />
          <Field label="Phone (optional)" value={data.contact} onChangeText={(t) => set('contact', t)} keyboardType="phone-pad" error={errors.contact} />

          <View className="gap-2">
            <Text className="text-sm font-semibold text-slate-700">Currency</Text>
            <View accessibilityRole="radiogroup" className="flex-row flex-wrap gap-2">
              {CURRENCIES.map(([code, decimals, name]) => {
                const on = data.currency === code;
                return (
                  <Pressable
                    key={code}
                    accessibilityRole="radio"
                    accessibilityState={{ selected: on }}
                    accessibilityLabel={name}
                    onPress={() => setData((d) => ({ ...d, currency: code, decimals }))}
                    className={`min-h-11 justify-center border px-3 ${on ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-white active:bg-slate-50'}`}>
                    <Text className={`text-base ${on ? 'font-bold text-brand-800' : 'font-medium text-slate-700'}`}>{code}</Text>
                  </Pressable>
                );
              })}
            </View>
            <Text className="text-sm text-slate-500">{CURRENCIES.find(([code]) => code === data.currency)?.[2]}</Text>
            {(errors.currency || errors.decimals) && <Text className="text-sm text-red-700">{errors.currency ?? errors.decimals}</Text>}
          </View>

          <SectionTitle>You, the manager</SectionTitle>
          <Field label="Your name" value={data.manager_name} onChangeText={(t) => set('manager_name', t)} error={errors.manager_name} autoCapitalize="words" />
          <Field
            label="Username"
            value={data.username}
            onChangeText={(t) => set('username', t)}
            error={errors.username}
            autoCapitalize="none"
            autoCorrect={false}
            placeholder="e.g. amina"
          />
          <Field
            label="Password"
            value={data.password}
            onChangeText={(t) => set('password', t)}
            error={errors.password}
            secureTextEntry
            autoCapitalize="none"
            placeholder="At least 6 characters"
          />
          <Field
            label="Password again"
            value={data.password_confirmation}
            onChangeText={(t) => set('password_confirmation', t)}
            error={errors.password_confirmation}
            secureTextEntry
            autoCapitalize="none"
          />

          <View className="flex-row items-center gap-3 border border-slate-200 bg-slate-50 p-4">
            <View className="flex-1">
              <Text className="text-base font-semibold text-slate-900">Add sample products</Text>
              <Text className="text-sm text-slate-500">A few products with stock, to try selling. Remove them later under Inventory.</Text>
            </View>
            <Switch
              accessibilityLabel="Add sample products"
              value={data.demo}
              onValueChange={(on) => set('demo', on)}
              trackColor={{ true: colors.brand[600] }}
            />
          </View>

          <Button title="Set up and sign in" size="lg" icon="checkmark-circle" loading={busy} onPress={submit} />
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}
