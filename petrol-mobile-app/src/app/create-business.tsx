import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useEffect, useRef, useState, type ComponentRef } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ArtBackground, BrandMark } from '@/components/art-background';
import { KeyboardScroll } from '@/components/keyboard-scroll';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { GoogleButton } from '@/components/ui/google-button';
import { Banner } from '@/components/ui/misc';
import { useKeyboardHeight } from '@/hooks/use-keyboard';
import { ApiError, errorMessage } from '@/lib/api/client';
import { platform } from '@/lib/api/platform';
import { googleAvailable, NeedsBrowser, SignInCancelled, signUpWithPassword } from '@/lib/auth/tokens';
import { workspaceUrl } from '@/lib/config';
import { colors } from '@/lib/theme';
import { useAuth, type SignInWith } from '@/store/auth';

/** ".tatook.com", as the workspace address ends. */
const ROOT = workspaceUrl('x').replace(/^https?:\/\/x/, '');

/** Every new business starts with this branch, so the app opens straight in it. More can be added on the web. */
const FIRST_BRANCH = 'Main branch';

/** As the web's "New business": the name, lowercased, dashes for the rest. */
function suggestSlug(name: string): string {
  return name
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/^[^a-z]+/, '')
    .slice(0, 32)
    .replace(/-+$/, '');
}

/**
 * Register, in the app (no browser): first a Miles Softwares account, then
 * the station. They become its owner, with the station's free trial,
 * its Main branch is made for them, and the app opens in it. Someone
 * signed in with no business yet goes straight to the station.
 */
export default function CreateBusinessScreen() {
  const insets = useSafeAreaInsets();
  const keyboard = useKeyboardHeight();
  const status = useAuth((s) => s.status);
  const signOut = useAuth((s) => s.signOut);
  /** Registered in this screen, and signed in with the new account. */
  const [accountDone, setAccountDone] = useState(false);
  const signedIn = status === 'noBusiness' || accountDone;

  async function changeAccount() {
    await signOut();
    setAccountDone(false);
    router.replace('/login');
  }

  return (
    <ArtBackground>
      <KeyboardScroll contentContainerStyle={{ flexGrow: 1 }} showsVerticalScrollIndicator={false} bounces={false}>
        {keyboard ? <View style={{ height: insets.top + 12 }} /> : <BrandMark compact />}
        <View className="w-full max-w-xl gap-4 self-center px-6" style={{ paddingBottom: (keyboard ? 0 : insets.bottom) + 16 }}>
          {signedIn ? <BusinessStep /> : <AccountStep onRegistered={() => setAccountDone(true)} />}
          <View className="flex-row justify-center border-t border-white/15 pt-4">
            {signedIn ? (
              <TextLink title="Use another account" onPress={changeAccount} />
            ) : (
              <>
                <Text className="py-1 text-sm text-brand-100">Already have an account? </Text>
                <TextLink title="Sign in" onPress={() => router.replace('/login')} />
              </>
            )}
          </View>
        </View>
      </KeyboardScroll>
    </ArtBackground>
  );
}

/**
 * A new account. Google asks for nothing more: it has proven the address, so
 * there is no link to confirm, and it is the sign-in too: someone who has a
 * station already is taken to it.
 */
function AccountStep({ onRegistered }: { onRegistered: () => void }) {
  const signIn = useAuth((s) => s.signIn);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [code, setCode] = useState<{ value: string; recovery: boolean } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [needsBrowser, setNeedsBrowser] = useState(false);
  const emailRef = useRef<ComponentRef<typeof TextInput>>(null);
  const passwordRef = useRef<ComponentRef<typeof TextInput>>(null);

  async function run(key: string, task: () => Promise<void>) {
    setBusy(key);
    setError(null);
    setNeedsBrowser(false);
    try {
      await task();
    } catch (e) {
      if (e instanceof SignInCancelled) return;
      setError(errorMessage(e));
      if (e instanceof NeedsBrowser) setNeedsBrowser(true);
    } finally {
      setBusy(null);
    }
  }

  /** With no business yet the status moves on and this screen shows the station; with one, it opens. */
  const withPlatform = (key: string, how: SignInWith) =>
    run(key, async () => {
      if ((await signIn(how)) === 'twoFactor') setCode({ value: '', recovery: false });
    });

  function submit() {
    if (!name.trim()) return setError('Enter your name.');
    if (!email.trim() || !password) return setError('Enter your email and password.');
    void run('password', async () => {
      await signUpWithPassword(name.trim(), email.trim(), password);
      onRegistered();
    });
  }

  if (code) {
    const withCode = () => withPlatform('code', { with: 'code', code: code.value.trim(), recovery: code.recovery });
    return (
      <>
        <Heading title="Two-step sign-in" subtitle={code.recovery ? 'Enter one of your recovery codes.' : 'Enter the code from your authenticator app.'} />
        {error ? <Banner tone="error">{error}</Banner> : null}
        <Field
          key={String(code.recovery)}
          dark
          icon="keypad-outline"
          accessibilityLabel={code.recovery ? 'Recovery code' : 'Code'}
          value={code.value}
          onChangeText={(t) => setCode({ ...code, value: t })}
          autoFocus
          autoCapitalize="none"
          autoCorrect={false}
          keyboardType={code.recovery ? 'default' : 'number-pad'}
          textContentType="oneTimeCode"
          autoComplete="one-time-code"
          maxLength={code.recovery ? 64 : 6}
          returnKeyType="go"
          onSubmitEditing={withCode}
          placeholder={code.recovery ? 'xxxxxxxxxx-xxxxxxxxxx' : '6-digit code'}
        />
        <Button title="Continue" size="lg" variant="accent" icon="arrow-forward" loading={busy === 'code'} onPress={withCode} />
        <View className="flex-row justify-between">
          <TextLink title={code.recovery ? 'Use an authenticator code' : 'Use a recovery code'} onPress={() => setCode({ value: '', recovery: !code.recovery })} />
          <TextLink title="Back" onPress={() => setCode(null)} />
        </View>
      </>
    );
  }

  return (
    <>
      <Heading title="Create your business" subtitle="First, your Miles Softwares account." />
      {error ? <Banner tone="error">{error}</Banner> : null}
      <Field
        dark
        label="Your name"
        icon="person-outline"
        value={name}
        onChangeText={setName}
        autoComplete="name"
        textContentType="name"
        returnKeyType="next"
        onSubmitEditing={() => emailRef.current?.focus()}
      />
      <Field
        ref={emailRef}
        dark
        label="Email"
        icon="mail-outline"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="email-address"
        textContentType="username"
        autoComplete="email"
        returnKeyType="next"
        onSubmitEditing={() => passwordRef.current?.focus()}
        placeholder="you@example.com"
      />
      <Field
        ref={passwordRef}
        dark
        label="Password"
        icon="lock-closed-outline"
        value={password}
        onChangeText={setPassword}
        secureTextEntry={!showPassword}
        autoCapitalize="none"
        autoCorrect={false}
        textContentType="newPassword"
        autoComplete="new-password"
        returnKeyType="go"
        onSubmitEditing={submit}
        hint="At least 8 characters."
        trailing={
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
            onPress={() => setShowPassword(!showPassword)}
            className="h-11 w-11 items-center justify-center active:bg-white/10">
            <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={20} color={colors.brand[200]} />
          </Pressable>
        }
      />
      <Button
        title="Create account"
        size="lg"
        variant="accent"
        icon="person-add-outline"
        onPress={submit}
        loading={busy === 'password'}
        disabled={!!busy}
      />
      {googleAvailable() && <GoogleButton onPress={() => withPlatform('google', { with: 'google' })} loading={busy === 'google'} disabled={!!busy} />}
      {(needsBrowser || !googleAvailable()) && (
        <Button
          title={needsBrowser ? 'Continue in the browser' : 'Google or the browser'}
          variant="glass"
          icon="globe-outline"
          onPress={() => withPlatform('browser', { with: 'browser' })}
          loading={busy === 'browser'}
          disabled={!!busy}
        />
      )}
    </>
  );
}

type Availability = { state: 'idle' | 'checking' | 'available' } | { state: 'unavailable'; error: string };

function useSlugAvailability(slug: string): Availability {
  const [availability, setAvailability] = useState<Availability>({ state: 'idle' });

  useEffect(() => {
    if (slug === '') return;
    let cancelled = false;
    const timer = setTimeout(() => {
      setAvailability({ state: 'checking' });
      platform
        .slugAvailability(slug)
        .then((body) => {
          if (cancelled) return;
          setAvailability(body.available ? { state: 'available' } : { state: 'unavailable', error: body.errors[0] ?? 'That address is taken.' });
        })
        .catch(() => !cancelled && setAvailability({ state: 'idle' }));
    }, 350);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [slug]);

  return slug === '' ? { state: 'idle' } : availability;
}

/** The business: its name and workspace address. Its Main branch comes with it. */
function BusinessStep() {
  const chooseBusiness = useAuth((s) => s.chooseBusiness);
  const signOut = useAuth((s) => s.signOut);
  const noBusinessYet = useAuth((s) => s.status === 'noBusiness');
  const [displayName, setDisplayName] = useState('');
  const [legalName, setLegalName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugEdited, setSlugEdited] = useState(false);
  const [country, setCountry] = useState('SS');
  const [currency, setCurrency] = useState('SSP');
  const [timezone, setTimezone] = useState(() => Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Juba');
  const [more, setMore] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  /** Created, but the station would not open it (no trial, or still being set up). */
  const [created, setCreated] = useState<string | null>(null);
  const availability = useSlugAvailability(slug);

  async function submit() {
    if (!displayName.trim()) return setError(new Error('Enter the business’s name.'));
    setBusy(true);
    setError(null);
    let made: string | null = null;
    try {
      const result = await platform.createBusiness({
        display_name: displayName.trim(),
        legal_name: legalName.trim() || displayName.trim(),
        slug,
        country_code: country.trim(),
        default_currency: currency.trim(),
        timezone: timezone.trim(),
        branch_name: FIRST_BRANCH,
        branch_city: null,
      });
      made = result.organization.slug;
      // Signed in: the app moves on to home by itself.
      await chooseBusiness(made);
    } catch (e) {
      if (made) setCreated(made);
      else setError(e);
      setBusy(false);
    }
  }

  if (created) {
    return (
      <>
        <Heading title={`${displayName.trim()} is created`} subtitle={`Its workspace is ${created}${ROOT}.`} />
        <Banner tone="info">
          Miles Petrol is not open in this workspace yet: it is still being set up, or it is not offered as a free trial yet. Sign in again in
          a moment; if it still does not open, start Miles Petrol from the workspace on the web.
        </Banner>
        <Button
          title="Back to sign in"
          size="lg"
          variant="accent"
          icon="log-in-outline"
          onPress={async () => {
            await signOut();
            router.replace('/login');
          }}
        />
      </>
    );
  }

  const field = (name: string) => (error instanceof ApiError ? error.field(name) : undefined);
  const unverified = error instanceof ApiError && error.code === 'email_unverified';
  const general = error && !(error instanceof ApiError && Object.keys(error.errors).length > 0) ? errorMessage(error) : null;
  const slugHint =
    availability.state === 'checking'
      ? `Checking ${slug}${ROOT}…`
      : availability.state === 'available'
        ? `✓ ${slug}${ROOT} is available`
        : availability.state === 'unavailable'
          ? undefined
          : 'Lowercase letters, digits and dashes.';

  return (
    <>
      <Heading title="Your business" subtitle="You become its owner, with the free trial, and its Main branch opens." />
      {noBusinessYet && !general ? (
        <Banner tone="info">No petrol station on this account yet. Create one, or ask the owner to invite you.</Banner>
      ) : null}
      {general ? (
        <Banner tone={unverified ? 'warning' : 'error'}>
          {general}
          {unverified ? ' Then tap Create business again.' : ''}
        </Banner>
      ) : null}
      <Field
        dark
        label="Business name"
        icon="business-outline"
        value={displayName}
        onChangeText={(t) => {
          setDisplayName(t);
          if (!slugEdited) setSlug(suggestSlug(t));
        }}
        error={field('display_name')}
        autoFocus
        placeholder="e.g. Nile Grill"
      />
      <Field
        dark
        label="Workspace address"
        icon="globe-outline"
        value={slug}
        onChangeText={(t) => {
          setSlugEdited(true);
          setSlug(t.toLowerCase().replace(/[^a-z0-9-]/g, ''));
        }}
        autoCapitalize="none"
        autoCorrect={false}
        trailing={
          <Text className="max-w-[45%] shrink pr-3 text-sm text-brand-200" numberOfLines={1}>
            {ROOT}
          </Text>
        }
        error={field('slug') ?? (availability.state === 'unavailable' ? availability.error : undefined)}
        hint={slugHint}
      />

      <Pressable
        accessibilityRole="button"
        accessibilityState={{ expanded: more }}
        onPress={() => setMore(!more)}
        className="flex-row items-center justify-between border border-white/20 bg-white/10 px-4 py-3 active:bg-white/15">
        <View className="flex-1">
          <Text className="text-sm font-semibold text-white">Legal name, country and currency</Text>
          <Text className="text-xs text-brand-100" numberOfLines={1}>
            {[legalName.trim() || 'Same as the name', country, currency, timezone].join(' · ')}
          </Text>
        </View>
        <Ionicons name={more ? 'chevron-up' : 'chevron-down'} size={18} color={colors.brand[200]} />
      </Pressable>
      {more && (
        <View className="gap-4">
          <Field
            dark
            label="Registered legal name"
            value={legalName}
            onChangeText={setLegalName}
            placeholder={displayName || 'As registered'}
            hint="As registered. Leave empty if it is the same."
            error={field('legal_name')}
          />
          <View className="flex-row gap-3">
            <Field
              dark
              className="w-24"
              label="Country"
              value={country}
              onChangeText={(t) => setCountry(t.toUpperCase())}
              maxLength={2}
              autoCapitalize="characters"
              error={field('country_code')}
            />
            <Field
              dark
              className="w-28"
              label="Currency"
              value={currency}
              onChangeText={(t) => setCurrency(t.toUpperCase())}
              maxLength={3}
              autoCapitalize="characters"
              error={field('default_currency')}
            />
            <Field
              dark
              className="flex-1"
              label="Time zone"
              value={timezone}
              onChangeText={setTimezone}
              autoCapitalize="none"
              autoCorrect={false}
              error={field('timezone')}
            />
          </View>
        </View>
      )}

      <Button
        title={busy ? 'Creating…' : 'Create business'}
        size="lg"
        variant="accent"
        icon="storefront-outline"
        onPress={submit}
        loading={busy}
        disabled={busy || availability.state === 'unavailable'}
      />
    </>
  );
}

function Heading({ title, subtitle }: { title: string; subtitle: string }) {
  return (
    <View className="gap-1">
      <Text accessibilityRole="header" className="text-2xl font-bold text-white">
        {title}
      </Text>
      <Text className="text-base text-brand-100">{subtitle}</Text>
    </View>
  );
}

function TextLink({ title, onPress }: { title: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} hitSlop={8} className="py-1">
      <Text className="text-sm font-semibold text-white underline">{title}</Text>
    </Pressable>
  );
}
