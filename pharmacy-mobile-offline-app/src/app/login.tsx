import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useRef, useState, type ComponentRef } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, useWindowDimensions, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground } from '@/components/art-background';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Banner } from '@/components/ui/misc';
import { errorMessage } from '@/lib/api';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';

/**
 * Sign in on this phone: a username and password a manager gave (Users).
 * Sized to fit a phone without scrolling: the brand on the artwork, the
 * form on a white panel along the bottom; short screens shrink the brand.
 */
export default function LoginScreen() {
  const signIn = useAuth((s) => s.signIn);
  const pharmacy = useAuth((s) => s.me?.organization.name);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const insets = useSafeAreaInsets();
  const { width, height } = useWindowDimensions();
  const compact = height < 720;
  const passwordRef = useRef<ComponentRef<typeof TextInput>>(null);

  async function submit() {
    if (!username.trim() || !password) return setError('Enter your username and password.');
    setBusy(true);
    setError(null);
    try {
      await signIn(username.trim(), password);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  const clearError = () => setError(null);

  return (
    <ArtBackground>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} className="flex-1">
        {/* Fits without scrolling; it only scrolls if the keyboard leaves too little room. */}
        <ScrollView
          contentContainerStyle={{ flexGrow: 1 }}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
          bounces={false}
          overScrollMode="never">
          {/* Brand: sized by the space left over. */}
          <View
            className="flex-1 items-center justify-center px-6"
            style={{
              paddingTop: insets.top + (compact ? 12 : 24),
              paddingBottom: compact ? 16 : 28,
            }}
            accessible
            accessibilityRole="header"
            accessibilityLabel="Miles Pharmacy Offline, by Miles Softwares">
            <View className="rounded-[28px] bg-white/10 p-2">
              <Image
                source={require('../../assets/images/splash-icon.png')}
                contentFit="contain"
                style={{ width: compact ? 60 : 80, height: compact ? 60 : 80 }}
                accessibilityIgnoresInvertColors
              />
            </View>
            <Text className={`mt-3 font-bold text-white ${compact ? 'text-2xl' : 'text-3xl'}`}>Miles Pharmacy</Text>
            <Text className="text-xs font-semibold uppercase tracking-[4px] text-accent">Offline</Text>
          </View>

          {/* The form, on a white panel down to the bottom edge. */}
          <View
            className="rounded-t-[32px] bg-white px-6 pt-6"
            // Phone: the full width. Tablet: a centred column.
            style={{
              paddingBottom: insets.bottom + 16,
              ...(width > 560 ? { width: 512, alignSelf: 'center' } : { alignSelf: 'stretch' }),
            }}>
            <View className="gap-3">
              <View className="mb-1">
                <Text accessibilityRole="header" className="text-2xl font-bold text-slate-900">
                  Welcome back
                </Text>
                <Text className="text-base text-slate-500">{pharmacy ? `Sign in to ${pharmacy}.` : 'Sign in with the username a manager gave you.'}</Text>
              </View>

              {error && <Banner tone="error">{error}</Banner>}

              <Field
                icon="person-outline"
                accessibilityLabel="Username"
                value={username}
                onChangeText={(t) => {
                  setUsername(t);
                  clearError();
                }}
                autoCapitalize="none"
                autoCorrect={false}
                textContentType="username"
                autoComplete="username"
                returnKeyType="next"
                onSubmitEditing={() => passwordRef.current?.focus()}
                placeholder="Username"
              />
              <Field
                ref={passwordRef}
                icon="lock-closed-outline"
                accessibilityLabel="Password"
                value={password}
                onChangeText={(t) => {
                  setPassword(t);
                  clearError();
                }}
                secureTextEntry={!showPassword}
                autoCapitalize="none"
                autoCorrect={false}
                textContentType="password"
                autoComplete="current-password"
                returnKeyType="go"
                onSubmitEditing={submit}
                placeholder="Password"
                trailing={
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                    onPress={() => setShowPassword(!showPassword)}
                    className="h-11 w-11 items-center justify-center active:bg-slate-100">
                    <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={20} color={colors.muted} />
                  </Pressable>
                }
              />
              <Button title={busy ? 'Signing in…' : 'Sign in'} size="lg" icon="log-in-outline" onPress={submit} loading={busy} />
              <Text className="text-center text-sm text-slate-500">Forgot your password? A manager resets it under Users.</Text>
            </View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </ArtBackground>
  );
}

