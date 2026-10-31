import { useRef, useState, type ComponentRef } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground, BrandMark } from '@/components/art-background';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api/client';
import { settings } from '@/local/db/settings';
import { useAuth } from '@/store/auth';

/** Offline: whoever works next signs in with their username on this phone. */
export default function SignInScreen() {
  const signInLocal = useAuth((s) => s.signInLocal);
  const switchMode = useAuth((s) => s.switchMode);
  const insets = useSafeAreaInsets();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const passwordRef = useRef<ComponentRef<typeof TextInput>>(null);

  async function submit() {
    if (!username.trim() || !password) return setError('Enter your username and password.');
    setBusy(true);
    setError(null);
    try {
      await signInLocal(username.trim(), password);
    } catch (e) {
      setError(errorMessage(e));
      setPassword('');
      setBusy(false);
    }
  }

  return (
    <ArtBackground>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1, paddingBottom: insets.bottom + 16 }}>
          <BrandMark />
          <View className="w-full max-w-xl flex-1 gap-3 self-center px-5">
            <Text accessibilityRole="header" className="text-2xl font-bold text-white">
              {settings().name}
            </Text>
            <Text className="text-base text-brand-100">Sign in to work. Offline, on this phone.</Text>
            {error ? <Banner tone="error">{error}</Banner> : null}
            <Field
              dark
              icon="person-outline"
              accessibilityLabel="Username"
              placeholder="Username"
              value={username}
              onChangeText={setUsername}
              autoCapitalize="none"
              autoCorrect={false}
              textContentType="username"
              returnKeyType="next"
              onSubmitEditing={() => passwordRef.current?.focus()}
            />
            <Field
              ref={passwordRef}
              dark
              icon="lock-closed-outline"
              accessibilityLabel="Password"
              placeholder="Password"
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              autoCapitalize="none"
              textContentType="password"
              returnKeyType="go"
              onSubmitEditing={submit}
            />
            <Button title={busy ? 'Signing in…' : 'Sign in'} icon="log-in-outline" size="lg" variant="accent" loading={busy} onPress={submit} />
            <Text className="text-center text-sm text-brand-100">Forgot your password? A manager sets a new one under Staff.</Text>
            <View className="flex-1" />
            <Button title="Choose online or offline again" icon="swap-horizontal-outline" variant="glass" onPress={switchMode} />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </ArtBackground>
  );
}
