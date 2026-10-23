import { priceForUnits } from '../../../shared/pricing';
import { FileText, Loader2, Plus, Search, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';

import { SaleDone } from '@/components/checkout-dialog';
import { Badge, Button, cn, Empty, Field, Input, Modal, Notice, PageHeader, Panel, Select } from '@/components/ui';
import { useCounter } from '@/hooks/use-counter';
import { ApiError, api, errorMessage } from '@/lib/api';
import { currencyCode, dateTime, isoDate, money, toMinor } from '@/lib/format';
import type { Counter, Item, PaymentMethod, Prescription, PrescriptionStatus, PrescriptionSummary } from '@/lib/types';
import { toast } from '@/store/ui';

const TABS: [PrescriptionStatus | null, string][] = [
  [null, 'Open'],
  ['dispensed', 'Dispensed'],
  ['rejected', 'Rejected'],
];

const tone = (status: PrescriptionStatus) =>
  status === 'recorded' ? 'amber' : status === 'verified' || status === 'partly_dispensed' ? 'blue' : status === 'dispensed' ? 'green' : 'red';

/** Prescriptions: recorded at the counter, verified by a pharmacist, then dispensed (sold). */
export function PrescriptionsPage() {
  const { counter, error: counterError } = useCounter();
  const [status, setStatus] = useState<PrescriptionStatus | null>(null);
  const [search, setSearch] = useState('');
  const [list, setList] = useState<PrescriptionSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<string | 'new' | null>(null);

  const load = useCallback(() => {
    api
      .prescriptions(status, search)
      .then((rows) => (setList(rows), setError(null)))
      .catch((e) => setError(errorMessage(e)));
  }, [status, search]);

  useEffect(() => {
    const timer = setTimeout(load, search ? 200 : 0);
    return () => clearTimeout(timer);
  }, [load, search]);

  if (!counter) return counterError ? <Notice tone="red">{counterError}</Notice> : <Loader2 className="mx-auto mt-20 animate-spin text-muted-foreground" />;

  return (
    <div className="space-y-4">
      <PageHeader
        title="Prescriptions"
        description="Prescription-only medicines are sold by dispensing a verified prescription."
        actions={
          counter.can.record_prescriptions ? (
            <Button onClick={() => setOpen('new')}>
              <Plus size={16} /> Record prescription
            </Button>
          ) : null
        }
      />
      <div className="flex items-center gap-2">
        <div className="flex border border-input bg-card">
          {TABS.map(([value, label]) => (
            <button
              key={label}
              type="button"
              onClick={() => setStatus(value)}
              className={cn('px-3 py-1.5 text-sm', status === value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}
            >
              {label}
            </button>
          ))}
        </div>
        <div className="relative flex-1">
          <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Number, patient or prescriber" className="pl-9" />
        </div>
      </div>
      {error ? <Notice tone="red">{error}</Notice> : null}

      <Panel>
        {!list ? (
          <Loader2 className="mx-auto my-8 animate-spin text-muted-foreground" />
        ) : list.length === 0 ? (
          <Empty icon={<FileText size={28} />} title="No prescriptions here" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Number</th>
                <th className="px-4 py-2 font-medium">Patient</th>
                <th className="px-4 py-2 font-medium">Prescriber</th>
                <th className="px-4 py-2 font-medium">Written</th>
                <th className="px-4 py-2 font-medium">Medicines</th>
                <th className="px-4 py-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {list.map((rx) => (
                <tr key={rx.id} onClick={() => setOpen(rx.id)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2.5 font-medium">{rx.number}</td>
                  <td className="px-4 py-2.5">{rx.patient_name}</td>
                  <td className="px-4 py-2.5">{rx.prescriber_name}</td>
                  <td className="px-4 py-2.5">{rx.issued_on}</td>
                  <td className="px-4 py-2.5 tabular">{rx.items_count}</td>
                  <td className="px-4 py-2.5">
                    <Badge tone={tone(rx.status)}>{rx.status_label}</Badge> {rx.expired && rx.status !== 'dispensed' ? <Badge tone="red">Expired</Badge> : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>

      {open === 'new' ? (
        <RecordDialog
          onClose={() => setOpen(null)}
          onDone={(rx) => {
            load();
            setOpen(rx.id);
          }}
        />
      ) : open ? (
        <PrescriptionDialog id={open} counter={counter} onClose={() => setOpen(null)} onChanged={load} />
      ) : null}
    </div>
  );
}

type Mode = 'view' | 'reject' | 'dispense';

function PrescriptionDialog({ id, counter, onClose, onChanged }: { id: string; counter: Counter; onClose: () => void; onChanged: () => void }) {
  const [rx, setRx] = useState<Prescription | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [mode, setMode] = useState<Mode>('view');
  const [busy, setBusy] = useState(false);
  const [sold, setSold] = useState<{ id: string; number: string; total_minor: number; change_minor: number | null } | null>(null);

  useEffect(() => {
    api
      .prescription(id)
      .then(setRx)
      .catch((e) => setError(errorMessage(e)));
  }, [id]);

  const verify = async () => {
    setBusy(true);
    try {
      setRx(await api.verifyPrescription(id));
      toast('Verified: it can be dispensed now.');
      onChanged();
    } catch (e) {
      toast(errorMessage(e), 'red');
    } finally {
      setBusy(false);
    }
  };

  if (sold) return <SaleDone sale={sold} counter={counter} onClose={onClose} />;

  return (
    <Modal
      title={rx ? `Prescription ${rx.number}` : 'Prescription'}
      onClose={onClose}
      wide
      footer={
        rx && mode === 'view' ? (
          <>
            {rx.can.reject ? (
              <Button variant="secondary" onClick={() => setMode('reject')}>
                Reject
              </Button>
            ) : null}
            {rx.can.verify ? (
              <Button variant="secondary" onClick={() => void verify()} disabled={busy}>
                Verify
              </Button>
            ) : null}
            {rx.can.dispense ? <Button onClick={() => setMode('dispense')}>Dispense</Button> : null}
          </>
        ) : null
      }
    >
      {error ? <Notice tone="red">{error}</Notice> : null}
      {!rx ? (
        !error && <Loader2 className="mx-auto animate-spin text-muted-foreground" />
      ) : mode === 'reject' ? (
        <RejectForm
          rx={rx}
          onCancel={() => setMode('view')}
          onDone={(next) => {
            setRx(next);
            setMode('view');
            toast('Rejected.');
            onChanged();
          }}
        />
      ) : mode === 'dispense' ? (
        <DispenseForm
          rx={rx}
          counter={counter}
          onCancel={() => setMode('view')}
          onDone={(sale) => {
            onChanged();
            setSold(sale);
          }}
        />
      ) : (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <Badge tone={tone(rx.status)}>{rx.status_label}</Badge>
            {rx.expired ? <Badge tone="red">Expired {rx.expires_on}</Badge> : <span className="text-xs text-muted-foreground">Valid until {rx.expires_on}</span>}
          </div>
          {rx.status === 'recorded' && !counter.can.verify_prescriptions ? <Notice tone="blue">A pharmacist verifies it before it is dispensed.</Notice> : null}
          {rx.rejection_reason ? <Notice tone="red">Rejected: {rx.rejection_reason}</Notice> : null}
          <div className="grid grid-cols-3 gap-3 text-sm">
            <Info label="Patient" value={`${rx.patient_name}${rx.patient_age !== null ? `, ${rx.patient_age}` : ''}`} />
            <Info label="Prescriber" value={`${rx.prescriber_name}${rx.prescriber_registration ? ` (${rx.prescriber_registration})` : ''}`} />
            <Info label="Written" value={rx.issued_on} />
            <Info label="Recorded by" value={rx.recorded_by} />
            <Info label="Verified by" value={rx.verified_by ?? '—'} />
            <Info label="Notes" value={rx.notes ?? '—'} />
          </div>
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-muted-foreground">
              <tr>
                <th className="py-1 font-medium">Medicine</th>
                <th className="py-1 font-medium">Directions</th>
                <th className="py-1 text-right font-medium">Prescribed</th>
                <th className="py-1 text-right font-medium">Given</th>
                <th className="py-1 text-right font-medium">In stock</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border border-y border-border">
              {rx.items.map((i) => (
                <tr key={i.id}>
                  <td className="py-2">
                    {i.name} {i.controlled ? <Badge tone="red">Controlled</Badge> : null}
                  </td>
                  <td className="py-2 text-muted-foreground">{i.directions}</td>
                  <td className="py-2 text-right tabular">
                    {i.quantity} {i.unit}
                  </td>
                  <td className="py-2 text-right tabular">{i.dispensed}</td>
                  <td className={cn('py-2 text-right tabular', i.available < i.remaining && 'text-red-700')}>{i.available}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {rx.sales.length ? (
            <div className="text-sm text-muted-foreground">
              Sales: {rx.sales.map((s) => `${s.number} (${money(s.total_minor)}${s.voided ? ', voided' : ''}, ${dateTime(s.sold_at)})`).join('; ')}
            </div>
          ) : null}
        </div>
      )}
    </Modal>
  );
}

function RejectForm({ rx, onCancel, onDone }: { rx: Prescription; onCancel: () => void; onDone: (rx: Prescription) => void }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    try {
      onDone(await api.rejectPrescription(rx.id, reason));
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  return (
    <form onSubmit={submit} className="space-y-3">
      {error ? <Notice tone="red">{error}</Notice> : null}
      <Field label="Why is it rejected?">
        <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} required placeholder="Dose unclear: call the prescriber" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" variant="danger">
          Reject
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function DispenseForm({
  rx,
  counter,
  onCancel,
  onDone,
}: {
  rx: Prescription;
  counter: Counter;
  onCancel: () => void;
  onDone: (sale: { id: string; number: string; total_minor: number; change_minor: number | null }) => void;
}) {
  const left = rx.items.filter((i) => i.remaining > 0);
  const [quantities, setQuantities] = useState<Record<string, string>>(Object.fromEntries(left.map((i) => [i.id, String(Math.min(i.remaining, i.available))])));
  const [method, setMethod] = useState<PaymentMethod>(counter.payment_methods[0]?.value ?? 'cash');
  const [tendered, setTendered] = useState('');
  const [reference, setReference] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // One submission for this form: pressing twice never sells twice.
  const [submissionId] = useState(() => crypto.randomUUID());

  const total = left.reduce((sum, i) => sum + priceForUnits(i.price_tiers, i.unit_price_minor, Number(quantities[i.id]) || 0), 0);
  const tenderedMinor = tendered.trim() ? toMinor(tendered) : null;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const result = await api.dispense(rx.id, {
        submission_id: submissionId,
        payment_method: method,
        payment_reference: reference.trim() || null,
        amount_tendered_minor: method === 'cash' ? (tenderedMinor ?? total) : null,
        quantities: Object.fromEntries(left.map((i) => [i.id, Number(quantities[i.id]) || 0])),
      });
      onDone(result.sale);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4">
      {error ? <Notice tone="red">{error}</Notice> : null}
      <table className="w-full text-sm">
        <thead className="text-left text-xs text-muted-foreground">
          <tr>
            <th className="py-1 font-medium">Medicine</th>
            <th className="py-1 font-medium">Left to give</th>
            <th className="py-1 font-medium">In stock</th>
            <th className="py-1 font-medium">Give now</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {left.map((i) => (
            <tr key={i.id}>
              <td className="py-2">
                {i.name} <span className="text-xs text-muted-foreground">{money(i.unit_price_minor)} a {i.unit}</span>
              </td>
              <td className="py-2 tabular">{i.remaining}</td>
              <td className="py-2 tabular">{i.available}</td>
              <td className="py-2">
                <Input type="number" min={0} max={i.remaining} value={quantities[i.id]} onChange={(e) => setQuantities({ ...quantities, [i.id]: e.target.value })} className="w-24" />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="flex items-baseline justify-between border-y border-border py-3">
        <span className="text-muted-foreground">Total</span>
        <span className="text-2xl font-semibold tabular">{money(total)}</span>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Paid by">
          <Select value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
            {counter.payment_methods.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </Select>
        </Field>
        {method === 'cash' ? (
          <Field label={`Cash given (${currencyCode()})`} hint={tenderedMinor !== null && tenderedMinor >= total ? `Change: ${money(tenderedMinor - total)}` : 'Empty: the exact amount'}>
            <Input inputMode="decimal" value={tendered} onChange={(e) => setTendered(e.target.value)} placeholder={money(total, false)} />
          </Field>
        ) : (
          <Field label="Reference (optional)">
            <Input value={reference} onChange={(e) => setReference(e.target.value)} />
          </Field>
        )}
      </div>
      <div className="flex gap-2">
        <Button type="submit" disabled={busy || total === 0}>
          {busy ? <Loader2 size={16} className="animate-spin" /> : null} Dispense and take payment
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

interface Line {
  item: Item;
  quantity: string;
  directions: string;
}

function RecordDialog({ onClose, onDone }: { onClose: () => void; onDone: (rx: Prescription) => void }) {
  const [data, setData] = useState({ patient_name: '', patient_age: '', prescriber_name: '', prescriber_registration: '', issued_on: isoDate(), notes: '' });
  const [lines, setLines] = useState<Line[]>([]);
  const [search, setSearch] = useState('');
  const [found, setFound] = useState<Item[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const set = (key: keyof typeof data, value: string) => setData((d) => ({ ...d, [key]: value }));

  useEffect(() => {
    if (!search.trim()) return setFound([]);
    const timer = setTimeout(() => void api.items(search).then(setFound, () => setFound([])), 150);
    return () => clearTimeout(timer);
  }, [search]);

  const add = (item: Item) => {
    if (!lines.some((l) => l.item.id === item.id)) setLines([...lines, { item, quantity: '1', directions: '' }]);
    setSearch('');
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      onDone(
        await api.recordPrescription({
          patient_name: data.patient_name,
          patient_age: data.patient_age ? Number(data.patient_age) : null,
          prescriber_name: data.prescriber_name,
          prescriber_registration: data.prescriber_registration || null,
          issued_on: data.issued_on,
          notes: data.notes || null,
          items: lines.map((l) => ({ medicine_id: l.item.id, quantity: Number(l.quantity), directions: l.directions })),
        }),
      );
      toast('Recorded. A pharmacist verifies it next.');
    } catch (err) {
      if (err instanceof ApiError) setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      title="Record a prescription"
      onClose={onClose}
      wide
      footer={
        <Button type="submit" form="record-rx" disabled={busy}>
          Record
        </Button>
      }
    >
      <form id="record-rx" onSubmit={submit} className="space-y-4">
        {error ? <Notice tone="red">{error}</Notice> : null}
        <div className="grid grid-cols-3 gap-3">
          <Field label="Patient" error={errors.patient_name}>
            <Input autoFocus value={data.patient_name} onChange={(e) => set('patient_name', e.target.value)} required />
          </Field>
          <Field label="Age" error={errors.patient_age}>
            <Input type="number" min={0} value={data.patient_age} onChange={(e) => set('patient_age', e.target.value)} />
          </Field>
          <Field label="Written on" error={errors.issued_on}>
            <Input type="date" value={data.issued_on} max={isoDate()} onChange={(e) => set('issued_on', e.target.value)} required />
          </Field>
          <Field label="Prescriber" error={errors.prescriber_name}>
            <Input value={data.prescriber_name} onChange={(e) => set('prescriber_name', e.target.value)} required placeholder="Dr. …" />
          </Field>
          <Field label="Registration number" error={errors.prescriber_registration}>
            <Input value={data.prescriber_registration} onChange={(e) => set('prescriber_registration', e.target.value)} />
          </Field>
          <Field label="Notes" error={errors.notes}>
            <Input value={data.notes} onChange={(e) => set('notes', e.target.value)} />
          </Field>
        </div>

        <div className="space-y-2">
          <div className="text-sm font-medium">Medicines {errors.items ? <span className="text-destructive">· {errors.items}</span> : null}</div>
          {lines.map((line, i) => (
            <div key={line.item.id} className="grid grid-cols-[1fr_100px_1.4fr_auto] items-start gap-2">
              <div className="pt-2 text-sm">
                {line.item.name} {line.item.strength}
                <span className="block text-xs text-muted-foreground">by the {line.item.unit}</span>
              </div>
              <Field label="Quantity" error={errors[`items.${i}.quantity`]}>
                <Input type="number" min={1} value={line.quantity} onChange={(e) => setLines(lines.map((l, j) => (j === i ? { ...l, quantity: e.target.value } : l)))} />
              </Field>
              <Field label="Directions" error={errors[`items.${i}.directions`]}>
                <Input value={line.directions} onChange={(e) => setLines(lines.map((l, j) => (j === i ? { ...l, directions: e.target.value } : l)))} placeholder="1 tablet three times a day for 5 days" />
              </Field>
              <button type="button" className="mt-6 p-2 text-muted-foreground hover:text-destructive" onClick={() => setLines(lines.filter((_, j) => j !== i))}>
                <Trash2 size={15} />
              </button>
            </div>
          ))}
          <div className="relative">
            <Search size={15} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Add a medicine: name, generic name or barcode" className="pl-9" />
            {found.length > 0 ? (
              <div className="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto border border-border bg-card shadow-lg">
                {found.map((item) => (
                  <button key={item.id} type="button" onClick={() => add(item)} className="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-accent">
                    <span>
                      {item.name} {item.strength} <span className="text-xs text-muted-foreground">{item.generic_name}</span>
                    </span>
                    <span className="text-xs text-muted-foreground">{item.available} in stock</span>
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </div>
      </form>
    </Modal>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div>{value}</div>
    </div>
  );
}
