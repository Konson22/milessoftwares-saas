import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useRef, useState, type ComponentRef } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground, BrandMark } from '@/components/art-background';
import { KeyboardScroll } from '@/components/keyboard-scroll';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { GoogleButton } from '@/components/ui/google-button';
import { Banner } from '@/components/ui/misc';
import { useKeyboardHeight } from '@/hooks/use-keyboard';
import { errorMessage } from '@/lib/api/client';
import { googleAvailable, NeedsBrowser, SignInCancelled } from '@/lib/auth/tokens';
import { colors } from '@/lib/theme';
import { useAuth, type SignInWith } from '@/store/auth';

/**
 * Online: sign in with the platform, email and password here (and a
 * two-step code when the account has one), no workspace address. The
 * person's business opens; in more than one, they choose which next; with
 * none yet, they create one, still signed in. Google signs in natively (the
 * device's account picker). The platform's own page in the browser stays for
 * accounts that need something done there first, and for Google in a build
 * without Google's client IDs.
 */
export default function LoginScreen() {
  const signIn = useAuth((s) => s.signIn);
  const insets = useSafeAreaInsets();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  /** The two-step code, after a password the platform took. */
  const [twoFactor, setTwoFactor] = useState(false);
  const [recovery, setRecovery] = useState(false);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [needsBrowser, setNeedsBrowser] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const passwordRef = useRef<ComponentRef<typeof TextInput>>(null);
  // With the keyboard up the emblem steps aside, so the heading, the
  // fields and Sign in all fit above it.
  const keyboard = useKeyboardHeight();

  async function submit(how: SignInWith) {
    if (how.with === 'password' && (!how.email || !how.password)) return setError('Enter your email and password.');
    if (how.with === 'code' && !how.code) return setError(recovery ? 'Enter a recovery code.' : 'Enter the code from your authenticator app.');
    setBusy(how.with);
    setError(null);
    setNeedsBrowser(false);
    try {
      if ((await signIn(how)) === 'twoFactor') {
        setTwoFactor(true);
        setCode('');
      }
    } catch (e) {
      if (e instanceof SignInCancelled) return;
      setError(errorMessage(e));
      if (e instanceof NeedsBrowser) setNeedsBrowser(true);
      // Starting again: the platform forgot the password step.
      if (how.with === 'code' && /start again/i.test(errorMessage(e))) setTwoFactor(false);
    } finally {
      setBusy(null);
    }
  }

  const withPassword = () => submit({ with: 'password', email: email.trim(), password });
  const withCode = () => submit({ with: 'code', code: code.trim(), recovery });

  return (
    <ArtBackground>
      <KeyboardScroll
        contentContainerStyle={{ flexGrow: 1 }}
        showsVerticalScrollIndicator={false}
        bounces={false}>
        {keyboard ? (
          <View style={{ height: insets.top + 12 }} />
        ) : (
          <View className="flex-1 justify-center">
            <BrandMark compact />
          </View>
        )}
        <View
          className={`w-full max-w-xl gap-3 self-center px-6 ${keyboard ? 'mt-auto pt-4' : 'pt-2'}`}
          style={{ paddingBottom: (keyboard ? 0 : insets.bottom) + 16 }}>
          <View className="mb-1">
            <Text accessibilityRole="header" className="text-2xl font-bold text-white">
              {twoFactor ? 'Two-step sign-in' : 'Welcome back'}
            </Text>
            <Text className="text-base text-brand-100">
              {twoFactor
                ? recovery
                  ? 'Enter one of your recovery codes.'
                  : 'Enter the code from your authenticator app.'
                : 'Sign in with your Miles Softwares account.'}
            </Text>
          </View>

          {error ? <Banner tone="error">{error}</Banner> : null}

          {twoFactor ? (
            <>
              <Field
                dark
                icon="keypad-outline"
                accessibilityLabel={recovery ? 'Recovery code' : 'Code'}
                value={code}
                onChangeText={setCode}
                autoFocus
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType={recovery ? 'default' : 'number-pad'}
                textContentType="oneTimeCode"
                maxLength={recovery ? 64 : 6}
                returnKeyType="go"
                onSubmitEditing={withCode}
                placeholder={recovery ? 'xxxxxxxxxx-xxxxxxxxxx' : '6-digit code'}
              />
              <Button title={busy ? 'Checking…' : 'Sign in'} size="lg" variant="accent" icon="log-in-outline" onPress={withCode} loading={!!busy} />
              <View className="flex-row justify-between">
                <TextLink
                  title={recovery ? 'Use an authenticator code' : 'Use a recovery code'}
                  onPress={() => {
                    setRecovery(!recovery);
                    setCode('');
                    setError(null);
                  }}
                />
                <TextLink
                  title="Back"
                  onPress={() => {
                    setTwoFactor(false);
                    setRecovery(false);
                    setError(null);
                  }}
                />
              </View>
            </>
          ) : (
            <>
              <Field
                dark
                icon="mail-outline"
                accessibilityLabel="Email"
                value={email}
                onChangeText={setEmail}
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                textContentType="username"
                autoComplete="email"
                returnKeyType="next"
                submitBehavior="submit"
                onSubmitEditing={() => passwordRef.current?.focus()}
                placeholder="Email"
              />
              <Field
                dark
                ref={passwordRef}
                icon="lock-closed-outline"
                accessibilityLabel="Password"
                value={password}
                onChangeText={setPassword}
                secureTextEntry={!showPassword}
                autoCapitalize="none"
                autoCorrect={false}
                textContentType="password"
                autoComplete="current-password"
                returnKeyType="go"
                onSubmitEditing={withPassword}
                placeholder="Password"
                trailing={
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                    onPress={() => setShowPassword(!showPassword)}
                    className="h-11 w-11 items-center justify-center active:bg-white/10">
                    <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={20} color={colors.brand[100]} />
                  </Pressable>
                }
              />
              <Button
                title={busy === 'password' ? 'Signing in…' : 'Sign in'}
                size="lg"
                variant="accent"
                icon="log-in-outline"
                onPress={withPassword}
                loading={busy === 'password'}
                disabled={!!busy}
              />
              {googleAvailable() && (
                <GoogleButton onPress={() => submit({ with: 'google' })} loading={busy === 'google'} disabled={!!busy} />
              )}
              {(needsBrowser || !googleAvailable()) && (
                <Button
                  title={needsBrowser ? 'Continue in the browser' : 'Google or the browser'}
                  variant="glass"
                  icon="globe-outline"
                  onPress={() => submit({ with: 'browser' })}
                  loading={busy === 'browser'}
                  disabled={!!busy}
                />
              )}
              <View className="flex-row flex-wrap justify-center pt-1">
                <Text className="text-sm text-brand-100">New to Miles? </Text>
                <Pressable accessibilityRole="link" hitSlop={8} disabled={!!busy} onPress={() => router.push('/create-business')}>
                  <Text className="text-sm font-semibold text-white underline">Create your business</Text>
                </Pressable>
              </View>
            </>
          )}
        </View>
      </KeyboardScroll>
    </ArtBackground>
  );
}

function TextLink({ title, onPress }: { title: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} hitSlop={8} className="py-1">
      <Text className="text-sm font-semibold text-white underline">{title}</Text>
    </Pressable>
  );
}
