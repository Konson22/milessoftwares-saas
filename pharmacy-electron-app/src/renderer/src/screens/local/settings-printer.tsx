import { Cable, CheckCircle2, Loader2, Network, Plug, Printer as PrinterIcon, RefreshCw, XCircle } from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';

import { Button, cn, Field, Input, Notice, Panel, Select } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { toast } from '@/store/ui';

interface PrinterSettings {
  connection: 'system' | 'network';
  device: string | null;
  silent: boolean;
  host: string | null;
  port: number;
  cut: boolean;
  copies: number;
}

interface SystemPrinter {
  name: string;
  label: string;
  isDefault: boolean;
}

interface Check {
  ok: boolean;
  message: string;
  paper?: 'ok' | 'low' | 'out' | null;
}

/**
 * The receipt printer on this PC (src/main/printer.ts), set up in three
 * steps: how it is connected, which one, then a check and a test page. Kept
 * on the PC, not with the pharmacy's data.
 */
export function Printer() {
  const [data, setData] = useState<PrinterSettings | null>(null);
  const [printers, setPrinters] = useState<SystemPrinter[]>([]);
  const [busy, setBusy] = useState<'save' | 'test' | 'check' | 'list' | null>(null);
  const [check, setCheck] = useState<Check | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const list = useCallback(async () => {
    setBusy('list');
    try {
      setPrinters(await call<SystemPrinter[]>('printer.list'));
    } catch (e) {
      toast(errorMessage(e), 'red');
    } finally {
      setBusy(null);
    }
  }, []);

  useEffect(() => {
    call<PrinterSettings>('printer.get')
      .then(setData)
      .catch((e) => toast(errorMessage(e), 'red'));
    void list();
    // How the saved printer is doing, on opening.
    call<Check>('printer.check')
      .then(setCheck)
      .catch(() => {});
  }, [list]);

  const change = (patch: Partial<PrinterSettings>) => {
    setData((d) => (d ? { ...d, ...patch } : d));
    setCheck(null);
  };

  /** Runs a step with what is chosen here (saved or not); field problems show by their fields. */
  const step = async <T,>(key: NonNullable<typeof busy>, work: () => Promise<T>): Promise<T | null> => {
    setBusy(key);
    setErrors({});
    try {
      return await work();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422 && Object.keys(e.errors).length) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
      } else {
        toast(errorMessage(e), 'red');
      }
      return null;
    } finally {
      setBusy(null);
    }
  };

  if (!data) return null;
  const network = data.connection === 'network';
  const missing = !network && data.device !== null && printers.length > 0 && !printers.some((p) => p.name === data.device);

  const save = () =>
    step('save', async () => {
      setData(await call<PrinterSettings>('printer.save', data));
      toast('Printer saved.');
    });
  const runCheck = () => step('check', async () => setCheck(await call<Check>('printer.check', data)));
  const test = () =>
    step('test', async () => {
      const printed = await call<boolean>('printer.test', data);
      if (printed) toast(network ? 'Test page sent. Did it come out, and does the line of dashes fit?' : 'Test page sent to the printer.');
      else toast(network ? 'The test page did not reach the printer. Check the connection.' : 'The test page was not printed.', 'amber');
    });

  return (
    <Panel title="Receipt printer on this PC">
      <div className="space-y-5 p-4">
        <Step n={1} title="How is the printer connected?">
          <div className="grid grid-cols-2 gap-3">
            <Way
              selected={!network}
              onSelect={() => change({ connection: 'system' })}
              icon={<Cable size={18} />}
              title="USB, or installed in Windows"
            >
              A printer plugged into this PC (or shared) whose driver is installed. Most receipt printers come with one.
            </Way>
            <Way selected={network} onSelect={() => change({ connection: 'network' })} icon={<Network size={18} />} title="On the network">
              A thermal printer with a network cable or Wi-Fi, reached by its IP address. No driver needed.
            </Way>
          </div>
        </Step>

        <Step n={2} title={network ? 'Where is it on the network?' : 'Which printer?'}>
          {network ? (
            <div className="grid grid-cols-3 gap-4">
              <div className="col-span-2">
                <Field label="IP address" error={errors.host} hint="On the printer’s self-test page: hold its feed button while turning it on">
                  <Input value={data.host ?? ''} onChange={(e) => change({ host: e.target.value })} placeholder="192.168.1.50" inputMode="decimal" />
                </Field>
              </div>
              <Field label="Port" error={errors.port} hint="Usually 9100">
                <Input type="number" min={1} max={65535} value={data.port} onChange={(e) => change({ port: Number(e.target.value) })} />
              </Field>
              <label className="col-span-3 flex items-center gap-2 text-sm">
                <input type="checkbox" checked={data.cut} onChange={(e) => change({ cut: e.target.checked })} /> Cut the paper after each receipt
              </label>
            </div>
          ) : (
            <div className="space-y-3">
              <div className="flex items-end gap-2">
                <div className="flex-1">
                  <Field label="Printer" hint={printers.length === 0 ? 'No printer found. Install the printer’s driver, plug it in, then look again.' : undefined}>
                    <Select value={data.device ?? ''} onChange={(e) => change({ device: e.target.value || null })}>
                      <option value="">The system’s default printer</option>
                      {printers.map((p) => (
                        <option key={p.name} value={p.name}>
                          {p.label}
                          {p.isDefault ? ' (default)' : ''}
                        </option>
                      ))}
                      {missing ? <option value={data.device!}>{data.device} (not found)</option> : null}
                    </Select>
                  </Field>
                </div>
                <Button variant="secondary" onClick={() => void list()} disabled={busy !== null}>
                  <RefreshCw size={14} className={busy === 'list' ? 'animate-spin' : ''} /> Look again
                </Button>
              </div>
              <label className="flex items-start gap-2 text-sm">
                <input type="checkbox" className="mt-1" checked={data.silent} onChange={(e) => change({ silent: e.target.checked })} />
                <span>
                  Print straight away, without the print dialog
                  <span className="block text-xs text-muted-foreground">For a thermal printer at the counter. Off: the dialog asks each time.</span>
                </span>
              </label>
            </div>
          )}
          <div className="mt-3 w-48">
            <Field label="Copies of each receipt" error={errors.copies}>
              <Select value={data.copies} onChange={(e) => change({ copies: Number(e.target.value) })}>
                {[1, 2, 3].map((n) => (
                  <option key={n} value={n}>
                    {n}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
        </Step>

        <Step n={3} title="Check it and print a test page">
          <div className="space-y-3">
            {check ? (
              <div
                className={cn(
                  'flex items-start gap-2 border px-3 py-2 text-sm',
                  !check.ok ? 'border-red-200 bg-red-50 text-red-800' : check.paper === 'low' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-800',
                )}
              >
                {check.ok ? <CheckCircle2 size={16} className="mt-0.5 shrink-0" /> : <XCircle size={16} className="mt-0.5 shrink-0" />}
                <span>{check.message}</span>
              </div>
            ) : null}
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" onClick={() => void runCheck()} disabled={busy !== null}>
                {busy === 'check' ? <Loader2 size={16} className="animate-spin" /> : <Plug size={16} />} Check the connection
              </Button>
              <Button variant="secondary" onClick={() => void test()} disabled={busy !== null}>
                {busy === 'test' ? <Loader2 size={16} className="animate-spin" /> : <PrinterIcon size={16} />} Print a test page
              </Button>
              <Button onClick={() => void save()} disabled={busy !== null}>
                {busy === 'save' ? <Loader2 size={16} className="animate-spin" /> : null} Save printer
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              The check and the test page use what is chosen above, saved or not. Receipts use the printer once it is saved. The paper width is under The
              receipt, above.
            </p>
            {missing ? <Notice tone="amber">The printer chosen is not connected or no longer installed. Receipts go to the default printer until it is back.</Notice> : null}
          </div>
        </Step>
      </div>
    </Panel>
  );
}

function Step({ n, title, children }: { n: number; title: string; children: ReactNode }) {
  return (
    <section className="flex gap-3">
      <span className="grid size-6 shrink-0 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">{n}</span>
      <div className="min-w-0 flex-1 space-y-2">
        <h3 className="text-sm font-medium">{title}</h3>
        {children}
      </div>
    </section>
  );
}

function Way({ selected, onSelect, icon, title, children }: { selected: boolean; onSelect: () => void; icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={selected}
      className={cn('flex gap-3 border bg-card p-3 text-left transition-colors hover:bg-accent', selected ? 'border-primary ring-1 ring-primary' : 'border-border')}
    >
      <span className={cn('grid size-8 shrink-0 place-items-center', selected ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>{icon}</span>
      <span className="space-y-0.5">
        <span className="block text-sm font-semibold">{title}</span>
        <span className="block text-xs text-muted-foreground">{children}</span>
      </span>
    </button>
  );
}
