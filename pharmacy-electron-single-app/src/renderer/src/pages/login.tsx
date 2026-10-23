import { Loader2, Pill } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

import { Button, cn, Field, Input, Notice } from '@/components/ui';
import { errorMessage } from '@/lib/api';
import { useAuth } from '@/store/auth';

/**
 * The pharmacy's side panel on the sign-in and set-up screens. Signing in
 * keeps the form to a narrow 35% column; set-up's longer form gets more room.
 */
export function Welcome({ children, narrow = false }: { children: ReactNode; narrow?: boolean }) {
  return (
    <div className="flex h-full">
      <aside className={cn('hidden flex-col justify-between bg-sidebar p-10 text-sidebar-foreground lg:flex', narrow ? 'flex-1' : 'w-[42%]')}>
        <div className="flex items-center gap-2 text-white">
          <Pill />
          <span className="text-lg font-semibold">Miles Pharmacy</span>
        </div>
        <div className="space-y-3">
          <h2 className="text-3xl font-semibold text-white">Your pharmacy, on this PC.</h2>
          <p>
            Sales, stock, prescriptions and reports are kept on this computer and need no internet. A copy is made every day: keep one
            on a USB stick too (Settings, Backups).
          </p>
        </div>
        <p className="text-xs">Miles Softwares</p>
      </aside>
      <main className={cn('flex items-center justify-center overflow-y-auto p-8', narrow ? 'w-full lg:w-[35%] lg:min-w-[26rem]' : 'flex-1')}>{children}</main>
    </div>
  );
}

export function LoginPage() {
  const signIn = useAuth((s) => s.signIn);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await signIn(username.trim(), password);
    } catch (err) {
      setError(errorMessage(err));
      setPassword('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Welcome narrow>
      <form onSubmit={submit} className="w-full max-w-sm space-y-5">
        <div>
          <h1 className="text-2xl font-semibold">Sign in</h1>
          <p className="text-sm text-muted-foreground">With the username and password a manager gave you.</p>
        </div>
        {error ? <Notice tone="red">{error}</Notice> : null}
        <Field label="Username">
          <Input autoFocus value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" required />
        </Field>
        <Field label="Password">
          <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required />
        </Field>
        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null} Sign in
        </Button>
        <p className="text-xs text-muted-foreground">Forgot your password? A manager sets a new one under Users.</p>
      </form>
    </Welcome>
  );
}
