import { Loader2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Button, Field, Input, Notice, Select } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import type { SetUpInput } from '@/lib/api/types';
import { useAuth } from '@/store/auth';

import { CURRENCIES } from '../../../shared/currencies';

import { Welcome } from './login';

/** First run on this PC: the pharmacy, and the manager who runs it. */
export function SetUpPage() {
  const setUp = useAuth((s) => s.setUp);
  const [data, setData] = useState<SetUpInput>({
    pharmacy_name: '',
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

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      await setUp(data);
    } catch (err) {
      if (err instanceof ApiError) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Welcome>
      <form onSubmit={submit} className="w-full max-w-lg space-y-5">
        <div>
          <h1 className="text-2xl font-semibold">Set up your pharmacy</h1>
          <p className="text-sm text-muted-foreground">Once, on this PC. Everything can be changed later under Settings, except the currency’s decimals.</p>
        </div>
        {error ? <Notice tone="red">{error}</Notice> : null}

        <fieldset className="space-y-3">
          <legend className="mb-1 text-sm font-medium">The pharmacy</legend>
          <Field label="Name" error={errors.pharmacy_name} hint="On receipts">
            <Input autoFocus value={data.pharmacy_name} onChange={(e) => set('pharmacy_name', e.target.value)} required />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Address" error={errors.address}>
              <Input value={data.address} onChange={(e) => set('address', e.target.value)} placeholder="Street, town" />
            </Field>
            <Field label="Phone" error={errors.contact}>
              <Input value={data.contact} onChange={(e) => set('contact', e.target.value)} />
            </Field>
          </div>
          <Field label="Currency" error={errors.currency ?? errors.decimals}>
            <Select
              value={data.currency}
              onChange={(e) => {
                const found = CURRENCIES.find(([code]) => code === e.target.value);
                setData((d) => ({ ...d, currency: e.target.value, decimals: found ? found[1] : 2 }));
              }}
            >
              {CURRENCIES.map(([code, , name]) => (
                <option key={code} value={code}>
                  {code} · {name}
                </option>
              ))}
            </Select>
          </Field>
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="mb-1 text-sm font-medium">You, the manager</legend>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Your name" error={errors.manager_name}>
              <Input value={data.manager_name} onChange={(e) => set('manager_name', e.target.value)} required />
            </Field>
            <Field label="Username" error={errors.username} hint="To sign in with">
              <Input value={data.username} onChange={(e) => set('username', e.target.value)} autoComplete="username" required />
            </Field>
            <Field label="Password" error={errors.password} hint="At least 6 characters">
              <Input type="password" value={data.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" required />
            </Field>
            <Field label="Password again" error={errors.password_confirmation}>
              <Input type="password" value={data.password_confirmation} onChange={(e) => set('password_confirmation', e.target.value)} autoComplete="new-password" required />
            </Field>
          </div>
        </fieldset>

        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={data.demo} onChange={(e) => set('demo', e.target.checked)} />
          <span>
            Add sample medicines with stock, to try the app first.
            <span className="block text-xs text-muted-foreground">They can be switched off later under Medicines.</span>
          </span>
        </label>

        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null} Set up and sign in
        </Button>
      </form>
    </Welcome>
  );
}
