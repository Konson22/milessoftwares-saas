import { Lock, Play, Wallet } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

import { Badge, Button, Empty, Field, Input, Notice, PageHeader, Panel } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { dateTime, money, time, toMinor } from '@/lib/format';
import { useUi } from '@/store/ui';

import { DayPicker } from './day-picker';
import type { ScreenProps } from './types';

interface Till {
  id: string;
  number: string;
  status: 'open' | 'closed';
  openedBy: string;
  openedAt: string;
  openingFloatMinor: number;
  closedBy: string | null;
  closedAt: string | null;
  expectedCashMinor: number | null;
  countedCashMinor: number | null;
  differenceMinor: number | null;
  closingNote: string | null;
}

interface ListProps {
  day: string;
  managing: boolean;
  mine: Till | null;
  tills: Till[];
}

/** Cash shifts at this branch: the member's own to start, and the day's (everyone's for managers). */
export function TillsScreen({ page, path, reload }: ScreenProps<ListProps>) {
  const { day, managing, mine, tills } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const open = useUi((s) => s.open);
  const [float, setFloat] = useState('');

  const start = async (e: FormEvent) => {
    e.preventDefault();
    await run('POST', 'tills', { opening_float: float.trim() || null });
  };

  return (
    <div className="space-y-4">
      <PageHeader title={managing ? 'Shifts' : 'My shifts'} description="A shift starts with a float and closes with the cash counted." actions={<DayPicker section="tills" day={day} />} />

      {mine ? (
        <Panel title={`Your shift ${mine.number} is open`}>
          <div className="flex flex-wrap items-center gap-4 p-4">
            <span className="text-sm text-muted-foreground">
              Since {time(mine.openedAt)} · float {money(mine.openingFloatMinor)}
            </span>
            <Button className="ml-auto" onClick={() => open(`tills/${mine.id}`)}>
              <Lock size={15} /> Count and close
            </Button>
          </div>
        </Panel>
      ) : (
        <Panel title="Start your shift">
          <form onSubmit={start} className="flex flex-wrap items-end gap-3 p-4">
            <div className="w-48">
              <Field label="Float in the drawer" error={errors.opening_float}>
                <Input autoFocus inputMode="decimal" value={float} onChange={(e) => setFloat(e.target.value)} placeholder="0.00" />
              </Field>
            </div>
            <Button type="submit" disabled={busy}>
              <Play size={15} /> Start shift
            </Button>
          </form>
        </Panel>
      )}

      <Panel title="This day">
        {tills.length === 0 ? (
          <Empty icon={<Wallet />} title="No shifts this day" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Shift</th>
                <th className="px-4 py-2 font-medium">Cashier</th>
                <th className="px-4 py-2 font-medium">Opened</th>
                <th className="px-4 py-2 font-medium">Closed</th>
                <th className="px-4 py-2 text-right font-medium">Over / short</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {tills.map((till) => (
                <tr key={till.id} onClick={() => open(`tills/${till.id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2 font-medium">
                    {till.number} {till.status === 'open' ? <Badge tone="blue">open</Badge> : null}
                  </td>
                  <td className="px-4 py-2">{till.openedBy}</td>
                  <td className="px-4 py-2 text-muted-foreground">{time(till.openedAt)}</td>
                  <td className="px-4 py-2 text-muted-foreground">{till.closedAt ? `${time(till.closedAt)} · ${till.closedBy}` : '—'}</td>
                  <td className="px-4 py-2 text-right tabular">
                    {till.differenceMinor === null ? '—' : <Difference minor={till.differenceMinor} />}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
  );
}

interface ShowProps {
  till: Till;
  summary: { billsCount: number; takenMinor: Record<string, number>; cashRefundsMinor: number; refundsCount: number; expectedCashMinor: number };
  methods: { value: string; label: string }[];
}

/** One shift: what went through it and, while open, the count that closes it. */
export function TillScreen({ page, path, reload }: ScreenProps<ShowProps>) {
  const { till, summary, methods } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const [counted, setCounted] = useState('');
  const [note, setNote] = useState('');
  const expected = till.status === 'closed' && till.expectedCashMinor !== null ? till.expectedCashMinor : summary.expectedCashMinor;
  const countedMinor = counted.trim() === '' ? null : toMinor(counted);
  const difference = countedMinor === null ? null : countedMinor - expected;

  const close = async (e: FormEvent) => {
    e.preventDefault();
    await run('POST', `tills/${till.id}/close`, { counted_cash: counted.trim(), closing_note: note.trim() || null });
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title={`Shift ${till.number}`}
        description={`${till.openedBy} · ${dateTime(till.openedAt)}${till.closedAt ? ` to ${time(till.closedAt)}` : ''}`}
        actions={<Badge tone={till.status === 'open' ? 'blue' : 'neutral'}>{till.status}</Badge>}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title={`Taken · ${summary.billsCount} bill${summary.billsCount === 1 ? '' : 's'}`}>
          <dl className="space-y-2 p-4 text-sm">
            <Row label="Float" value={money(till.openingFloatMinor)} />
            {methods.map((m) => (
              <Row key={m.value} label={m.label} value={money(summary.takenMinor[m.value] ?? 0)} />
            ))}
            {summary.refundsCount > 0 ? <Row label={`Cash refunds (${summary.refundsCount})`} value={`− ${money(summary.cashRefundsMinor)}`} /> : null}
            <div className="border-t border-border pt-2 font-semibold">
              <Row label="Cash expected in the drawer" value={money(expected)} />
            </div>
            {till.status === 'closed' ? (
              <>
                <Row label="Counted" value={money(till.countedCashMinor ?? 0)} />
                <Row label="Over / short" value={<Difference minor={till.differenceMinor ?? 0} />} />
                {till.closingNote ? <p className="text-muted-foreground">“{till.closingNote}”</p> : null}
              </>
            ) : null}
          </dl>
        </Panel>

        {till.status === 'open' ? (
          <Panel title="Count and close">
            <form onSubmit={close} className="space-y-3 p-4">
              <Field
                label="Cash counted in the drawer"
                error={errors.counted_cash}
                hint={difference === null ? undefined : difference === 0 ? 'Exactly as expected.' : <Difference minor={difference} />}
              >
                <Input autoFocus inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} required />
              </Field>
              {difference !== null && difference !== 0 ? <Notice>The count differs from what is expected: say why below.</Notice> : null}
              <Field label="Note" error={errors.closing_note}>
                <Input value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
              </Field>
              <Button type="submit" className="w-full" disabled={busy}>
                <Lock size={15} /> Close shift
              </Button>
            </form>
          </Panel>
        ) : null}
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="tabular">{value}</dd>
    </div>
  );
}

function Difference({ minor }: { minor: number }) {
  if (minor === 0) return <span>{money(0)}</span>;
  return <span className={minor > 0 ? 'text-emerald-700' : 'text-red-700'}>{minor > 0 ? `${money(minor)} over` : `${money(-minor)} short`}</span>;
}
