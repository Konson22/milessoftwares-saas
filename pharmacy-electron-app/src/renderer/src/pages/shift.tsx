import { ArrowDownLeft, ArrowUpRight, CloudOff, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';

import { Badge, Button, Field, Input, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useCounter } from '@/hooks/use-counter';
import { api, errorMessage, isOffline, localApi } from '@/lib/api';
import { place } from '@/lib/config';
import { currencyCode, dateTime, money, time, toMajorInput, toMinor } from '@/lib/format';
import type { CashCounterpart, CashDirection, Till } from '@/lib/types';
import { useConnectivity } from '@/offline/connectivity';
import { queuedFor, useOutbox, type QueuedCash } from '@/offline/outbox';
import { useAuth, useLocal, useOwner } from '@/store/auth';
import { toast } from '@/store/ui';

export function ShiftPage() {
  const { counter, till, tillLoaded, staleSince, error } = useCounter();
  const local = useLocal();

  // Offline: the figures as they are now (sales were made since they last loaded).
  useEffect(() => {
    if (local) void useAuth.getState().refreshTill().catch(() => {});
  }, [local]);

  if (!counter || !tillLoaded) {
    return error ? <Notice>{error}</Notice> : <Loader2 className="mx-auto mt-20 animate-spin text-muted-foreground" />;
  }

  return (
    <div className="space-y-4">
      <PageHeader title="Shift" description={till ? `${till.number} · opened ${dateTime(till.opened_at)}` : 'No shift open'} />
      {staleSince ? <Notice>Offline: the shift as the {place()} showed it at {dateTime(staleSince)}.</Notice> : null}
      {local && !counter.counter.shifts_required && !till ? <Notice tone="blue">Shifts are optional here (Settings): open one to count the drawer.</Notice> : null}
      {till ? <OpenShift till={till} /> : <OpenForm defaultFloat={counter.counter.default_float_minor} />}
    </div>
  );
}

function OpenForm({ defaultFloat }: { defaultFloat: number }) {
  const online = useConnectivity((s) => s.online);
  const setTill = useAuth((s) => s.setTill);
  const [float, setFloat] = useState(toMajorInput(defaultFloat));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const minor = toMinor(float);
    if (minor === null) return setError('Enter the float as an amount.');
    setBusy(true);
    setError(null);
    try {
      setTill(await api.openTill(minor));
      toast('Shift opened.');
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Panel title="Open your shift" className="max-w-md">
      <form onSubmit={submit} className="space-y-4 p-4">
        {!online ? <Notice>A shift is opened with the {place()}: connect to open one. Sales made offline need a shift opened before.</Notice> : null}
        {error ? <Notice tone="red">{error}</Notice> : null}
        <Field label={`Float in the drawer (${currencyCode()})`}>
          <Input value={float} onChange={(e) => setFloat(e.target.value)} inputMode="decimal" />
        </Field>
        <Button type="submit" disabled={busy || !online}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null} Open shift
        </Button>
      </form>
    </Panel>
  );
}

function OpenShift({ till }: { till: Till }) {
  const owner = useOwner();
  const entries = useOutbox((s) => s.entries);
  const queue = useMemo(() => queuedFor(entries, owner), [entries, owner]);
  const pendingCash = queue.all.filter((e): e is QueuedCash => e.kind === 'cash');
  const s = till.summary;

  return (
    <div className="grid grid-cols-[1fr_380px] gap-4">
      <div className="space-y-4">
        <div className="grid grid-cols-4 gap-3">
          <Stat label="Opening float" value={money(till.opening_float_minor)} />
          <Stat label="Sales" value={String(s.sales_count)} />
          <Stat label="Cash in" value={money(s.cash_in_minor)} />
          <Stat label="Cash out" value={money(s.cash_out_minor)} />
        </div>
        {till.expected_cash_minor !== null ? (
          <Stat label="The drawer should hold" value={money(till.expected_cash_minor)} big />
        ) : (
          <Notice tone="blue">Counted blind: you count the drawer at close without seeing what it should hold.</Notice>
        )}
        {s.taken_minor ? (
          <Panel title="Taken this shift">
            <div className="divide-y divide-border text-sm">
              {Object.entries(s.taken_minor).map(([method, amount]) => (
                <div key={method} className="flex justify-between px-4 py-2">
                  <span className="capitalize">{method.replace('_', ' ')}</span>
                  <span className="tabular">{money(amount)}</span>
                </div>
              ))}
            </div>
          </Panel>
        ) : null}

        <Panel title="Cash in and out">
          <div className="divide-y divide-border text-sm">
            {pendingCash.map((m) => (
              <div key={m.id} className="flex items-center gap-3 px-4 py-2">
                {m.direction === 'in' ? <ArrowDownLeft size={16} className="text-emerald-600" /> : <ArrowUpRight size={16} className="text-amber-600" />}
                <span className="flex-1">
                  {m.reason} <Badge tone={m.error ? 'red' : 'amber'}>{m.error ? 'Refused' : 'Waiting to send'}</Badge>
                </span>
                <span className="text-xs text-muted-foreground">{time(m.queued_at)}</span>
                <span className="w-28 text-right tabular">{money(m.amount_minor)}</span>
              </div>
            ))}
            {till.movements.map((m) => (
              <div key={m.id} className="flex items-center gap-3 px-4 py-2">
                {m.direction === 'in' ? <ArrowDownLeft size={16} className="text-emerald-600" /> : <ArrowUpRight size={16} className="text-amber-600" />}
                <span className="flex-1">
                  {m.label} · {m.reason}
                </span>
                <span className="text-xs text-muted-foreground">
                  {m.recorded_by}, {time(m.recorded_at)}
                </span>
                <span className="w-28 text-right tabular">{money(m.amount_minor)}</span>
              </div>
            ))}
            {pendingCash.length + till.movements.length === 0 ? <p className="px-4 py-3 text-muted-foreground">None yet.</p> : null}
          </div>
        </Panel>
      </div>

      <div className="space-y-4">
        <CashForm />
        <CloseForm till={till} waiting={queue.all.length} />
      </div>
    </div>
  );
}

function Stat({ label, value, big }: { label: string; value: string; big?: boolean }) {
  return (
    <div className="border border-border bg-card px-4 py-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className={big ? 'text-2xl font-semibold tabular' : 'text-lg font-semibold tabular'}>{value}</div>
    </div>
  );
}

const COUNTERPARTS: { value: CashCounterpart; label: string; directions: CashDirection[] }[] = [
  { value: 'safe', label: 'The safe', directions: ['in', 'out'] },
  { value: 'bank', label: 'The bank', directions: ['in', 'out'] },
  { value: 'expense', label: 'An expense', directions: ['out'] },
];

/**
 * Cash into or out of the drawer: sent now, or kept and sent when the
 * connection is back. Offline, an expense paid from the drawer names its
 * category (Expenses, Categories, "Paid from the till").
 */
function CashForm() {
  const owner = useOwner();
  const local = useLocal();
  const online = useConnectivity((s) => s.online);
  const setTill = useAuth((s) => s.setTill);
  const [direction, setDirection] = useState<CashDirection>('in');
  const [counterpart, setCounterpart] = useState<CashCounterpart>('safe');
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [categoryId, setCategoryId] = useState('');
  const expense = counterpart === 'expense';
  const withCategory = local && expense;

  useEffect(() => {
    if (!local) return;
    localApi
      .expenseCategories()
      .then(setCategories)
      .catch(() => {});
  }, [local]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const minor = toMinor(amount);
    if (!minor) return setError('Enter an amount above zero.');
    if (withCategory && !categoryId) return setError('Choose what the expense was for.');
    if (!reason.trim()) return setError(withCategory ? 'Say what was bought or paid for.' : 'Say why the cash goes in or comes out.');
    const id = crypto.randomUUID();
    setBusy(true);
    setError(null);
    try {
      if (online) {
        try {
          setTill(await api.moveCash(direction, counterpart, minor, reason.trim(), id, withCategory ? categoryId : null));
          toast(withCategory ? 'Expense recorded against this shift.' : 'Recorded.');
          setAmount('');
          setReason('');
          return;
        } catch (e) {
          if (!isOffline(e)) return setError(errorMessage(e));
        }
      }
      useOutbox.getState().enqueue({ kind: 'cash', id, owner, direction, counterpart, amount_minor: minor, reason: reason.trim() });
      toast('Kept on this PC: sent when the connection is back.', 'amber');
      setAmount('');
      setReason('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Panel title="Cash in or out">
      <form onSubmit={submit} className="space-y-3 p-4">
        {error ? <Notice tone="red">{error}</Notice> : null}
        <div className="grid grid-cols-2 gap-2">
          <Button
            variant={direction === 'in' ? 'primary' : 'secondary'}
            onClick={() => {
              setDirection('in');
              // An expense only takes cash out.
              if (counterpart === 'expense') setCounterpart('safe');
            }}
          >
            Into the drawer
          </Button>
          <Button variant={direction === 'out' ? 'primary' : 'secondary'} onClick={() => setDirection('out')}>
            Out of it
          </Button>
        </div>
        <Field label={direction === 'in' ? 'From' : 'To'}>
          <Select value={counterpart} onChange={(e) => setCounterpart(e.target.value as CashCounterpart)}>
            {COUNTERPARTS.filter((c) => c.directions.includes(direction)).map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </Select>
        </Field>
        {withCategory ? (
          <Field label="Expense for" hint={categories.length ? undefined : 'No category is paid from the till: a manager sets one under Expenses, Categories.'}>
            <Select value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
              <option value="">Choose…</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}
        <Field label={`Amount (${currencyCode()})`}>
          <Input value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" />
        </Field>
        <Field label={withCategory ? 'What for' : 'Why'}>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={withCategory ? 'Boda boda to the supplier' : 'Change from the safe'} />
        </Field>
        <Button type="submit" disabled={busy}>
          {online ? 'Record' : 'Record offline'}
        </Button>
      </form>
    </Panel>
  );
}

function CloseForm({ till, waiting }: { till: Till; waiting: number }) {
  const online = useConnectivity((s) => s.online);
  const setTill = useAuth((s) => s.setTill);
  const [counted, setCounted] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const minor = toMinor(counted);
    if (minor === null) return setError('Enter what you counted in the drawer.');
    setBusy(true);
    setError(null);
    try {
      const closed = await api.closeTill(minor, note.trim() || null);
      setTill(closed);
      toast(
        closed.difference_minor
          ? `Shift ${closed.number} closed, ${money(Math.abs(closed.difference_minor))} ${closed.difference_minor > 0 ? 'over' : 'short'}.`
          : `Shift ${closed.number} closed.`,
      );
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const blocked = !online || waiting > 0;

  return (
    <Panel title="Close the shift">
      <form onSubmit={submit} className="space-y-3 p-4">
        {!online ? (
          <Notice>
            <CloudOff size={14} className="mr-1 inline" /> A shift is closed with the {place()}: connect to close it.
          </Notice>
        ) : waiting > 0 ? (
          <Notice>{waiting} sale(s) or cash movement(s) are waiting to be sent. They go first: see Sync.</Notice>
        ) : null}
        {error ? <Notice tone="red">{error}</Notice> : null}
        <Field label={`Counted in the drawer (${currencyCode()})`}>
          <Input value={counted} onChange={(e) => setCounted(e.target.value)} inputMode="decimal" />
        </Field>
        <Field label="Note" hint={till.blind ? undefined : 'Needed when the count differs.'}>
          <Input value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>
        <Button type="submit" variant="danger" disabled={busy || blocked}>
          Close shift
        </Button>
      </form>
    </Panel>
  );
}
