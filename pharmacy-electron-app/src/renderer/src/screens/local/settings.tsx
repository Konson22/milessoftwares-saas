import { FolderOpen, HardDriveDownload, Loader2, RotateCcw, Usb } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';

import { Badge, Button, cn, Field, Input, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { call, errorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';
import type { PaymentMethod } from '@/lib/types';
import { useAuth } from '@/store/auth';
import { toast } from '@/store/ui';

import { CURRENCIES } from '../../../../shared/currencies';
import type { ScreenProps } from '../types';
import { Activation } from './settings-activation';
import { Printer } from './settings-printer';
import { System } from './settings-system';

interface Settings {
  name: string;
  address: string | null;
  contact: string | null;
  currency: string;
  decimals: number;
  second_currency: string;
  exchange_rate: string;
  receipt_header: string | null;
  receipt_footer: string | null;
  paper_size: '80mm' | '58mm';
  show_customer: boolean;
  show_staff: boolean;
  payment_methods: PaymentMethod[];
  max_discount_percent: number;
  shifts_required: boolean;
  default_float: string;
  blind_close: boolean;
  require_customer_always: boolean;
  require_customer_above: string;
  expiring_days: number;
  prescription_valid_days: number;
}

interface Props {
  settings: Settings;
  paymentMethods: { value: PaymentMethod; label: string }[];
}

const TABS = [
  { key: 'pharmacy', label: 'Pharmacy' },
  { key: 'counter', label: 'Counter' },
  { key: 'receipt', label: 'Receipt and printer' },
  { key: 'backups', label: 'Backups' },
  { key: 'activation', label: 'Activation' },
  { key: 'system', label: 'System' },
] as const;

type Tab = (typeof TABS)[number]['key'];

/** The tab a field is on, to mark it when saving finds a problem there. */
const FIELD_TABS: Record<string, Tab> = {
  name: 'pharmacy',
  currency: 'pharmacy',
  address: 'pharmacy',
  contact: 'pharmacy',
  second_currency: 'pharmacy',
  exchange_rate: 'pharmacy',
  receipt_header: 'receipt',
  receipt_footer: 'receipt',
  paper_size: 'receipt',
};

/** Pharmacy, Counter and Receipt are one form, saved together. */
const FORM_TABS: Tab[] = ['pharmacy', 'counter', 'receipt'];

/** The pharmacy, its counter's rules, its receipt and printer, its backups, its activation and the app itself. */
export function SettingsScreen({ page, path, reload }: ScreenProps<Props>) {
  const { settings, paymentMethods } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const asked = new URLSearchParams(path.split('?')[1] ?? '').get('tab');
  const [tab, setTab] = useState<Tab>(TABS.some((t) => t.key === asked) ? (asked as Tab) : 'pharmacy');
  const troubled = new Set(Object.keys(errors).map((field) => FIELD_TABS[field] ?? 'counter'));
  const [data, setData] = useState({
    ...settings,
    address: settings.address ?? '',
    contact: settings.contact ?? '',
    receipt_header: settings.receipt_header ?? '',
    receipt_footer: settings.receipt_footer ?? '',
  });
  const set = <K extends keyof typeof data>(key: K, value: (typeof data)[K]) => setData((d) => ({ ...d, [key]: value }));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (await run('PUT', 'settings', data)) {
      // The name and currency show everywhere.
      await useAuth.getState().refreshMe();
    }
  };

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title="Settings" />
      <nav className="flex flex-wrap gap-1 border-b border-border" aria-label="Settings">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            aria-current={tab === t.key ? 'page' : undefined}
            className={cn(
              '-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm',
              tab === t.key ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
          >
            {t.label}
            {troubled.has(t.key) ? <span className="size-1.5 rounded-full bg-destructive" aria-label="Has a problem" /> : null}
          </button>
        ))}
      </nav>

      {tab === 'backups' ? <Backups /> : null}
      {tab === 'activation' ? <Activation /> : null}
      {tab === 'system' ? <System /> : null}

      {FORM_TABS.includes(tab) ? (
        <form onSubmit={submit} className="space-y-4">
          {tab === 'pharmacy' ? (
            <>
              <Panel title="The pharmacy">
                <div className="grid grid-cols-2 gap-4 p-4">
                  <Field label="Name" error={errors.name}>
                    <Input value={data.name} onChange={(e) => set('name', e.target.value)} required />
                  </Field>
                  <Field label="Currency" error={errors.currency} hint={`${data.decimals} decimal place(s), set at setup`}>
                    <Input value={data.currency} onChange={(e) => set('currency', e.target.value.toUpperCase())} maxLength={3} required />
                  </Field>
                  <Field label="Address" error={errors.address}>
                    <Input value={data.address} onChange={(e) => set('address', e.target.value)} />
                  </Field>
                  <Field label="Phone" error={errors.contact}>
                    <Input value={data.contact} onChange={(e) => set('contact', e.target.value)} />
                  </Field>
                </div>
              </Panel>

              <Panel title="Second currency and exchange rate">
                <div className="grid grid-cols-2 gap-4 p-4">
                  <Notice tone="blue">
                    Suppliers may bill in {data.second_currency || 'USD'}: buying prices are entered in it and turned into {data.currency} at this rate. Prices at the
                    counter also show in it. Change the rate when it moves; stock already received keeps the rate it was bought at.
                  </Notice>
                  <div />
                  <Field label="Second currency" error={errors.second_currency} hint="Empty: none">
                    <Select value={data.second_currency} onChange={(e) => set('second_currency', e.target.value)}>
                      <option value="">None</option>
                      {CURRENCIES.filter(([code]) => code !== data.currency).map(([code, , name]) => (
                        <option key={code} value={code}>
                          {code} · {name}
                        </option>
                      ))}
                    </Select>
                  </Field>
                  {data.second_currency ? (
                    <Field
                      label={`1 ${data.second_currency} in ${data.currency}`}
                      error={errors.exchange_rate}
                      hint={Number(data.exchange_rate) > 0 ? `${data.currency} 1,000 = ${data.second_currency} ${(1000 / Number(data.exchange_rate)).toFixed(2)}` : 'The rate today, as 4500'}
                    >
                      <Input inputMode="decimal" value={data.exchange_rate} onChange={(e) => set('exchange_rate', e.target.value)} />
                    </Field>
                  ) : null}
                </div>
              </Panel>
            </>
          ) : null}

          {tab === 'counter' ? (
            <>
              <Panel title="The counter">
                <div className="grid grid-cols-2 gap-4 p-4">
                  <div className="col-span-2">
                    <div className="mb-1.5 text-sm font-medium">Ways of paying taken {errors.payment_methods ? <span className="text-destructive">· {errors.payment_methods}</span> : null}</div>
                    <div className="flex flex-wrap gap-4">
                      {paymentMethods.map((m) => (
                        <Check
                          key={m.value}
                          checked={data.payment_methods.includes(m.value)}
                          onChange={(on) => set('payment_methods', on ? [...data.payment_methods, m.value] : data.payment_methods.filter((v) => v !== m.value))}
                        >
                          {m.label}
                        </Check>
                      ))}
                    </div>
                  </div>
                  <Field label="Most discount a line (%)" error={errors.max_discount_percent} hint="Pharmacists and managers; cashiers give none">
                    <Input type="number" min={0} max={100} value={data.max_discount_percent} onChange={(e) => set('max_discount_percent', Number(e.target.value))} />
                  </Field>
                  <Field label="Name the customer on sales over" error={errors.require_customer_above} hint="Empty: never needed">
                    <Input inputMode="decimal" value={data.require_customer_above} onChange={(e) => set('require_customer_above', e.target.value)} />
                  </Field>
                  <Check checked={data.require_customer_always} onChange={(on) => set('require_customer_always', on)}>
                    Name the customer on every sale
                  </Check>
                  <div />
                  <Check checked={data.shifts_required} onChange={(on) => set('shifts_required', on)} error={errors.shifts_required}>
                    Selling needs an open shift (a counted cash drawer)
                  </Check>
                  <Check checked={data.blind_close} onChange={(on) => set('blind_close', on)}>
                    Cashiers count the drawer blind (they do not see what it should hold)
                  </Check>
                  <Field label="Usual float" error={errors.default_float} hint="Offered when a shift opens">
                    <Input inputMode="decimal" value={data.default_float} onChange={(e) => set('default_float', e.target.value)} />
                  </Field>
                </div>
              </Panel>

              <Panel title="Stock and prescriptions">
                <div className="grid grid-cols-2 gap-4 p-4">
                  <Field label="Expiring soon is within (days)" error={errors.expiring_days}>
                    <Input type="number" min={1} value={data.expiring_days} onChange={(e) => set('expiring_days', Number(e.target.value))} />
                  </Field>
                  <Field label="A prescription is valid for (days)" error={errors.prescription_valid_days}>
                    <Input type="number" min={1} value={data.prescription_valid_days} onChange={(e) => set('prescription_valid_days', Number(e.target.value))} />
                  </Field>
                </div>
              </Panel>
            </>
          ) : null}

          {tab === 'receipt' ? (
            <Panel title="The receipt">
              <div className="grid grid-cols-2 gap-4 p-4">
                <Field label="Line at the top" error={errors.receipt_header}>
                  <Input value={data.receipt_header} onChange={(e) => set('receipt_header', e.target.value)} placeholder="TIN 123456" />
                </Field>
                <Field label="Line at the bottom" error={errors.receipt_footer}>
                  <Input value={data.receipt_footer} onChange={(e) => set('receipt_footer', e.target.value)} />
                </Field>
                <Field label="Paper" error={errors.paper_size}>
                  <Select value={data.paper_size} onChange={(e) => set('paper_size', e.target.value as '80mm' | '58mm')}>
                    <option value="80mm">80 mm roll</option>
                    <option value="58mm">58 mm roll</option>
                  </Select>
                </Field>
                <div className="flex flex-col justify-end gap-2">
                  <Check checked={data.show_customer} onChange={(on) => set('show_customer', on)}>
                    Show the customer
                  </Check>
                  <Check checked={data.show_staff} onChange={(on) => set('show_staff', on)}>
                    Show who served
                  </Check>
                </div>
              </div>
            </Panel>
          ) : null}

          <Button type="submit" disabled={busy}>
            Save settings
          </Button>
        </form>
      ) : null}

      {tab === 'receipt' ? <Printer /> : null}
    </div>
  );
}

function Check({ checked, onChange, error, children }: { checked: boolean; onChange: (on: boolean) => void; error?: string; children: ReactNode }) {
  return (
    <label className="flex items-center gap-2 text-sm">
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} /> {children}
      {error ? <span className="text-destructive">· {error}</span> : null}
    </label>
  );
}

interface BackupFile {
  name: string;
  path: string;
  size: number;
  at: string;
  kind: 'auto' | 'manual' | 'before-restore';
}

const KIND_LABELS: Record<BackupFile['kind'], string> = { auto: 'Daily', manual: 'Made by hand', 'before-restore': 'Before a restore' };

const size = (bytes: number) => (bytes > 1_048_576 ? `${(bytes / 1_048_576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

/**
 * Everything lives on this PC: a copy is made each day, and a copy on a USB
 * stick or another drive survives the PC itself.
 */
function Backups() {
  const [state, setState] = useState<{ folder: string; lastBackupAt: string | null; files: BackupFile[] } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(() => {
    call<NonNullable<typeof state>>('backup.list')
      .then(setState)
      .catch((e) => toast(errorMessage(e), 'red'));
  }, []);

  useEffect(load, [load]);

  const task = async (key: string, work: () => Promise<void>) => {
    setBusy(key);
    try {
      await work();
    } catch (e) {
      toast(errorMessage(e), 'red');
    } finally {
      setBusy(null);
      load();
    }
  };

  const restore = (path?: string) =>
    task(`restore:${path ?? ''}`, async () => {
      if (!window.confirm('Put this backup in place of the pharmacy’s data now? What is here now is kept as a “before a restore” backup. Everyone is signed out.')) return;
      if (await call<boolean>('backup.restore', path)) window.location.reload();
    });

  return (
    <Panel title="Backups" actions={state ? <span className="text-xs text-muted-foreground">{state.lastBackupAt ? `Last copy ${dateTime(state.lastBackupAt)}` : 'No copy yet'}</span> : null}>
      <div className="space-y-3 p-4">
        <Notice tone="blue">
          The pharmacy’s data is on this PC only. A copy is made here every day (the last 14 are kept). Save one on a USB stick or another drive
          every week: it is what survives a broken or stolen PC.
        </Notice>
        <div className="flex flex-wrap gap-2">
          <Button
            onClick={() =>
              void task('now', async () => {
                await call('backup.now');
                toast('Backed up.');
              })
            }
            disabled={busy !== null}
          >
            {busy === 'now' ? <Loader2 size={16} className="animate-spin" /> : <HardDriveDownload size={16} />} Back up now
          </Button>
          <Button
            variant="secondary"
            onClick={() =>
              void task('export', async () => {
                const saved = await call<string | null>('backup.export');
                if (saved) toast(`Saved to ${saved}.`);
              })
            }
            disabled={busy !== null}
          >
            <Usb size={16} /> Save a copy to a USB stick…
          </Button>
          <Button variant="secondary" onClick={() => void restore()} disabled={busy !== null}>
            <RotateCcw size={16} /> Restore from a file…
          </Button>
          <Button variant="ghost" onClick={() => void call('backup.openFolder').catch(() => {})}>
            <FolderOpen size={16} /> Open the backups folder
          </Button>
        </div>
        {state && state.files.length > 0 ? (
          <table className="w-full text-sm">
            <tbody className="divide-y divide-border border-y border-border">
              {state.files.slice(0, 20).map((f) => (
                <tr key={f.path}>
                  <td className="py-2">{dateTime(f.at)}</td>
                  <td className="py-2">
                    <Badge tone={f.kind === 'auto' ? 'neutral' : f.kind === 'manual' ? 'green' : 'amber'}>{KIND_LABELS[f.kind]}</Badge>
                  </td>
                  <td className="py-2 text-muted-foreground tabular">{size(f.size)}</td>
                  <td className="py-2 text-right">
                    <Button size="sm" variant="ghost" onClick={() => void restore(f.path)} disabled={busy !== null}>
                      Restore
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    </Panel>
  );
}
