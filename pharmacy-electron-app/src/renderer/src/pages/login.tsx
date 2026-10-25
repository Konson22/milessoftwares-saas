import { ArrowLeft, Eye, EyeOff, Loader2, Pill, Store } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import type { Product, SignInWith } from '../../../shared/ipc';
import { Button, Field, Input, Notice } from '@/components/ui';
import { API_MODE, errorMessage } from '@/lib/api';
import { DEMO_ACCOUNTS } from '@/lib/api/mock';
import { normalizeWorkspace, PRODUCTS, RETAIL_AVAILABLE } from '@/lib/config';
import { useAuth } from '@/store/auth';

import { CreateBusiness } from './create-business';

export function LoginPage() {
  const savedWorkspace = useAuth((s) => s.workspace);
  const signIn = useAuth((s) => s.signIn);
  const savedProduct = useAuth((s) => s.product);
  /** The pharmacy or the shop: each signs in with its own client, so it is chosen first. */
  const [product, setProduct] = useState<Product>(RETAIL_AVAILABLE ? savedProduct : 'pharmacy');
  const [workspace, setWorkspace] = useState(savedWorkspace ?? '');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  /** The two-factor step, after a password the platform took. */
  const [code, setCode] = useState<{ value: string; recovery: boolean } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<{ message: string; browser?: boolean } | null>(null);
  /** Open an existing workspace, or create a business (signed in on the platform first). */
  const [intent, setIntent] = useState<'open' | 'create'>('open');
  /** Creating: a new account made here, rather than one that exists. */
  const [newAccount, setNewAccount] = useState(true);
  const [name, setName] = useState('');
  /** Signed in to create a business: its form, with the ID token to open it after. */
  const [creating, setCreating] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const creatingNow = intent === 'create';

  const run = async (key: string, task: () => Promise<void>) => {
    setBusy(key);
    setError(null);
    try {
      await task();
    } catch (e) {
      setError({ message: errorMessage(e) });
    } finally {
      setBusy(null);
    }
  };

  /** Signs in on the platform, then opens the session at the workspace (or the new business's form). */
  const platform = (key: string, how: SignInWith) => {
    const slug = normalizeWorkspace(workspace);
    if (!slug && !creatingNow) return setError({ message: 'Enter your workspace address.' });
    setNotice(null);
    void run(key, async () => {
      const result = await window.desktop.signIn(how, product);
      if (result.status === 'twoFactor') {
        setCode({ value: '', recovery: false });
      } else if (result.status === 'refused') {
        setError({ message: result.message, browser: result.browser });
      } else if (creatingNow) {
        setCode(null);
        setCreating(result.idToken);
      } else {
        await signIn(slug, result.idToken, product);
      }
    });
  };

  const switchIntent = (next: 'open' | 'create') => {
    setIntent(next);
    setNewAccount(true);
    setCreating(null);
    setCode(null);
    setError(null);
    setNotice(null);
  };

  const submitPassword = (e: FormEvent) => {
    e.preventDefault();
    platform(
      'password',
      creatingNow && newAccount ? { with: 'register', name: name.trim(), email: email.trim(), password } : { with: 'password', email: email.trim(), password },
    );
  };

  const submitCode = (e: FormEvent) => {
    e.preventDefault();
    if (code) platform('code', { with: 'code', code: code.value.trim(), recovery: code.recovery });
  };

  const inBrowser = busy === 'google' || busy === 'browser';

  return (
    <div className="flex h-full">
      <aside className="hidden w-[42%] flex-col justify-between bg-sidebar p-10 text-sidebar-foreground lg:flex">
        <div className="flex items-center gap-2 text-white">
          {product === 'retail' ? <Store /> : <Pill />}
          <span className="text-lg font-semibold">{PRODUCTS[product].name}</span>
        </div>
        <div className="space-y-3">
          <h2 className="text-3xl font-semibold text-white">The counter keeps selling when the internet stops.</h2>
          <p>
            Sales, cash in and out, and the {product === 'retail' ? 'product' : 'medicine'} list are kept on this PC. When the connection is
            back, everything is sent to the {PRODUCTS[product].place} on its own, never twice.
          </p>
        </div>
        <p className="text-xs">Miles Softwares</p>
      </aside>

      <main className="flex flex-1 items-center justify-center p-8">
        <div className="w-full max-w-sm space-y-6">
          <div>
            <h1 className="text-2xl font-semibold">{creatingNow ? `Create your ${PRODUCTS[product].place}` : 'Sign in'}</h1>
            <p className="text-sm text-muted-foreground">
              {API_MODE === 'mock'
                ? 'Demo pharmacy: choose who works the counter.'
                : creating
                  ? 'You become its owner, and the counter opens in it.'
                  : code
                  ? code.recovery
                    ? 'Enter one of your recovery codes.'
                    : 'Enter the 6-digit code from your authenticator app.'
                  : creatingNow
                    ? newAccount
                      ? 'First, your Miles Softwares account.'
                      : 'First, sign in with your Miles Softwares account.'
                    : 'With your Miles Softwares account.'}
            </p>
          </div>

          {notice ? <Notice tone="green">{notice}</Notice> : null}

          {error ? (
            <Notice tone="red">
              {error.message}
              {error.browser ? (
                <button type="button" className="mt-1 block font-medium underline" onClick={() => platform('browser', { with: 'browser' })}>
                  Sign in with the browser
                </button>
              ) : null}
            </Notice>
          ) : null}

          {API_MODE === 'mock' ? (
            <div className="space-y-2">
              {DEMO_ACCOUNTS.map((account) => (
                <button
                  key={account.id}
                  type="button"
                  disabled={busy !== null}
                  onClick={() => void run(account.id, () => signIn('demo', account.id, 'pharmacy'))}
                  className="flex w-full items-center justify-between border border-border bg-card px-4 py-3 text-left hover:bg-accent disabled:opacity-60"
                >
                  <span>
                    <span className="block font-medium">{account.name}</span>
                    <span className="block text-xs text-muted-foreground capitalize">{account.role}</span>
                  </span>
                  {busy === account.id ? <Loader2 size={16} className="animate-spin" /> : null}
                </button>
              ))}
            </div>
          ) : creating ? (
            <CreateBusiness
              product={product}
              idToken={creating}
              onBack={() => switchIntent('open')}
              onCreated={(slug, message) => {
                switchIntent('open');
                setWorkspace(slug);
                setNotice(message);
              }}
            />
          ) : code ? (
            <form onSubmit={submitCode} className="space-y-4">
              <Field label={code.recovery ? 'Recovery code' : 'Code'}>
                <Input
                  key={String(code.recovery)}
                  autoFocus
                  value={code.value}
                  onChange={(e) => setCode({ ...code, value: e.target.value })}
                  inputMode={code.recovery ? 'text' : 'numeric'}
                  autoComplete="one-time-code"
                  required
                />
              </Field>
              <Button type="submit" className="w-full" disabled={busy !== null}>
                {busy ? <Loader2 size={16} className="animate-spin" /> : null}
                Continue
              </Button>
              <div className="flex items-center justify-between text-sm">
                <button type="button" className="flex items-center gap-1 text-muted-foreground hover:text-foreground" onClick={() => setCode(null)}>
                  <ArrowLeft size={14} /> Back
                </button>
                <button type="button" className="text-primary hover:underline" onClick={() => setCode({ value: '', recovery: !code.recovery })}>
                  {code.recovery ? 'Use the authenticator code' : 'Use a recovery code'}
                </button>
              </div>
            </form>
          ) : (
            <div className="space-y-4">
              <form onSubmit={submitPassword} className="space-y-4">
                {RETAIL_AVAILABLE ? (
                  <div role="radiogroup" aria-label="Sell for" className="grid grid-cols-2 border border-border">
                    {(['pharmacy', 'retail'] as const).map((p) => (
                      <button
                        key={p}
                        type="button"
                        role="radio"
                        aria-checked={product === p}
                        disabled={busy !== null}
                        onClick={() => setProduct(p)}
                        className={`flex items-center justify-center gap-2 px-3 py-2 text-sm ${product === p ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'}`}
                      >
                        {p === 'retail' ? <Store size={16} /> : <Pill size={16} />}
                        {PRODUCTS[p].label}
                      </button>
                    ))}
                  </div>
                ) : null}
                {creatingNow ? (
                  newAccount ? (
                    <Field label="Your name">
                      <Input autoFocus autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} required />
                    </Field>
                  ) : null
                ) : (
                  <Field label="Workspace" hint={`As in your${PRODUCTS[product].place}.milesoftwares.com`}>
                    <Input
                      autoFocus={!savedWorkspace}
                      value={workspace}
                      onChange={(e) => setWorkspace(e.target.value)}
                      placeholder={`your${PRODUCTS[product].place}`}
                      required
                    />
                  </Field>
                )}
                <Field label="Email">
                  <Input type="email" autoFocus={!!savedWorkspace || (creatingNow && !newAccount)} autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
                </Field>
                <Field label="Password">
                  <div className="relative">
                    <Input
                      type={showPassword ? 'text' : 'password'}
                      autoComplete={creatingNow && newAccount ? 'new-password' : 'current-password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      className="pr-10"
                      required
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      className="absolute top-1/2 right-2 -translate-y-1/2 p-1 text-muted-foreground hover:text-foreground"
                    >
                      {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
                    </button>
                  </div>
                </Field>
                <Button type="submit" className="w-full" disabled={busy !== null}>
                  {busy === 'password' ? <Loader2 size={16} className="animate-spin" /> : null}
                  {creatingNow ? (newAccount ? 'Create account' : 'Continue') : 'Sign in'}
                </Button>
              </form>
              {creatingNow ? (
                <p className="text-center text-sm text-muted-foreground">
                  {newAccount ? 'Already have an account? ' : 'No account yet? '}
                  <button type="button" className="text-primary hover:underline" disabled={busy !== null} onClick={() => setNewAccount(!newAccount)}>
                    {newAccount ? 'Sign in instead' : 'Create one'}
                  </button>
                </p>
              ) : null}

              <div className="flex items-center gap-3 text-xs text-muted-foreground">
                <span className="h-px flex-1 bg-border" /> or <span className="h-px flex-1 bg-border" />
              </div>

              <Button variant="secondary" className="w-full" disabled={busy !== null} onClick={() => platform('google', { with: 'google' })}>
                {busy === 'google' ? <Loader2 size={16} className="animate-spin" /> : <GoogleMark />}
                Continue with Google
              </Button>
              {inBrowser ? (
                <p className="text-center text-xs text-muted-foreground">Finish signing in in your browser, then come back here.</p>
              ) : (
                <p className="text-center text-xs text-muted-foreground">
                  Passkey or another way?{' '}
                  <button type="button" className="text-primary hover:underline" disabled={busy !== null} onClick={() => platform('browser', { with: 'browser' })}>
                    Sign in with the browser
                  </button>
                </p>
              )}
            </div>
          )}

          {API_MODE === 'live' && !creating && !code ? (
            <p className="border-t border-border pt-4 text-center text-sm text-muted-foreground">
              {creatingNow ? 'Your business is already on Miles? ' : 'New to Miles? '}
              <button
                type="button"
                className="font-medium text-primary hover:underline"
                disabled={busy !== null}
                onClick={() => switchIntent(creatingNow ? 'open' : 'create')}
              >
                {creatingNow ? 'Sign in to it' : `Create your ${PRODUCTS[product].place}`}
              </button>
            </p>
          ) : null}
        </div>
      </main>
    </div>
  );
}

/** The Google "G", as on Google's own sign-in buttons. */
function GoogleMark() {
  return (
    <svg viewBox="0 0 48 48" className="size-4" aria-hidden>
      <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
      <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
      <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
      <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
    </svg>
  );
}
