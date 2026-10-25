import { ArrowLeft, Check, Loader2, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

import type { Product } from '../../../shared/ipc';
import { Button, Field, Input, Notice } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { createBusiness, slugAvailability } from '@/lib/api/platform';
import { PRODUCTS, workspaceUrl } from '@/lib/config';
import { useAuth } from '@/store/auth';

/** ".milesoftwares.com", as the workspace address ends. */
const ROOT = new URL(workspaceUrl('x')).host.slice(1);

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

type Availability = { state: 'idle' | 'checking' | 'available' } | { state: 'unavailable'; errors: string[] };

function useSlugAvailability(product: Product, slug: string): Availability {
  const [availability, setAvailability] = useState<Availability>({ state: 'idle' });

  useEffect(() => {
    if (slug === '') return;
    let cancelled = false;
    const timer = window.setTimeout(() => {
      setAvailability({ state: 'checking' });
      slugAvailability(product, slug)
        .then((body) => !cancelled && setAvailability(body.available ? { state: 'available' } : { state: 'unavailable', errors: body.errors }))
        .catch(() => !cancelled && setAvailability({ state: 'idle' }));
    }, 350);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [product, slug]);

  return slug === '' ? { state: 'idle' } : availability;
}

/**
 * A new business, after signing in on the platform: its name and workspace
 * address, where it is, and its first branch. The person becomes its owner,
 * with the free trial of this app's product, and the counter opens in it.
 * Should the workspace not open yet, sign-in comes back with its address.
 */
export function CreateBusiness({
  product,
  idToken,
  onBack,
  onCreated,
}: {
  product: Product;
  /** From the platform sign-in just done, to open the new workspace. */
  idToken: string;
  onBack: () => void;
  /** Created, but the workspace did not open: sign in to it. */
  onCreated: (slug: string, message: string) => void;
}) {
  const signIn = useAuth((s) => s.signIn);
  const [displayName, setDisplayName] = useState('');
  const [legalName, setLegalName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugEdited, setSlugEdited] = useState(false);
  const [branch, setBranch] = useState('Main branch');
  const [city, setCity] = useState('');
  const [country, setCountry] = useState('SS');
  const [currency, setCurrency] = useState('SSP');
  const [timezone, setTimezone] = useState(() => Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Juba');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | Error | null>(null);
  const availability = useSlugAvailability(product, slug);
  const { place } = PRODUCTS[product];

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    let created: string | null = null;
    try {
      const result = await createBusiness(product, {
        display_name: displayName.trim(),
        legal_name: legalName.trim() || displayName.trim(),
        slug,
        country_code: country.trim(),
        default_currency: currency.trim(),
        timezone: timezone.trim(),
        branch_name: branch.trim(),
        branch_city: city.trim() || null,
      });
      created = result.organization.slug;
      await signIn(created, idToken, product);
    } catch (e) {
      if (created) {
        onCreated(created, `${displayName.trim()} is ready. Sign in to open it.`);
      } else {
        setError(e instanceof Error ? e : new Error(errorMessage(e)));
        setBusy(false);
      }
    }
  };

  const field = (name: string) => (error instanceof ApiError ? error.field(name) : undefined);
  const unverified = error instanceof ApiError && error.code === 'email_unverified';

  return (
    <form onSubmit={submit} className="space-y-4">
      {error && !(error instanceof ApiError && Object.keys(error.errors).length > 0) ? (
        <Notice tone={unverified ? 'amber' : 'red'}>
          {error.message}
          {unverified ? <span className="mt-1 block">Then press Create again.</span> : null}
        </Notice>
      ) : null}

      <Field label="Business name" error={field('display_name')}>
        <Input
          autoFocus
          value={displayName}
          onChange={(e) => {
            setDisplayName(e.target.value);
            if (!slugEdited) setSlug(suggestSlug(e.target.value));
          }}
          required
        />
      </Field>
      <Field label="Registered legal name" hint="As on your licence. Leave empty if it is the same." error={field('legal_name')}>
        <Input value={legalName} onChange={(e) => setLegalName(e.target.value)} placeholder={displayName} />
      </Field>
      <Field label="Workspace address" error={field('slug')} hint={<SlugStatus availability={availability} host={`${slug}${ROOT}`} />}>
        <div className="flex items-center gap-2">
          <Input
            value={slug}
            autoComplete="off"
            spellCheck={false}
            onChange={(e) => {
              setSlugEdited(true);
              setSlug(e.target.value.toLowerCase());
            }}
            required
          />
          <span className="shrink-0 text-sm text-muted-foreground">{ROOT}</span>
        </div>
      </Field>

      <div className="grid grid-cols-2 gap-3">
        <Field label={`First ${place === 'shop' ? 'shop' : 'branch'}`} error={field('branch_name')}>
          <Input value={branch} onChange={(e) => setBranch(e.target.value)} required />
        </Field>
        <Field label="City" error={field('branch_city')}>
          <Input value={city} onChange={(e) => setCity(e.target.value)} />
        </Field>
      </div>

      <div className="grid grid-cols-[4rem_5rem_1fr] gap-3">
        <Field label="Country" error={field('country_code')}>
          <Input value={country} onChange={(e) => setCountry(e.target.value.toUpperCase())} maxLength={2} required />
        </Field>
        <Field label="Currency" error={field('default_currency')}>
          <Input value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} maxLength={3} required />
        </Field>
        <Field label="Time zone" error={field('timezone')}>
          <Input value={timezone} onChange={(e) => setTimezone(e.target.value)} required />
        </Field>
      </div>

      <Button type="submit" className="w-full" disabled={busy || availability.state === 'unavailable'}>
        {busy ? <Loader2 size={16} className="animate-spin" /> : null}
        Create {place === 'shop' ? 'shop' : 'pharmacy'}
      </Button>
      <button type="button" className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground" disabled={busy} onClick={onBack}>
        <ArrowLeft size={14} /> Back to sign in
      </button>
    </form>
  );
}

function SlugStatus({ availability, host }: { availability: Availability; host: string }) {
  switch (availability.state) {
    case 'checking':
      return <span>Checking {host}…</span>;
    case 'available':
      return (
        <span className="flex items-center gap-1 text-emerald-700">
          <Check size={12} /> {host} is available
        </span>
      );
    case 'unavailable':
      return (
        <span className="flex items-center gap-1 text-destructive">
          <X size={12} /> {availability.errors[0] ?? `${host} is not available`}
        </span>
      );
    default:
      return <span>Lowercase letters, digits and dashes.</span>;
  }
}
