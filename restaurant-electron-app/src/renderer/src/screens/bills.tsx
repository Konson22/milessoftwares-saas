import { Ban, Printer, ReceiptText } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import { Badge, Button, Empty, Field, Input, Modal, Notice, PageHeader, Panel } from '@/components/ui';
import { useAction } from '@/hooks/use-page';
import { dateTime, money, time } from '@/lib/format';
import { billHtml } from '@/lib/receipt';
import { useAuth, useBranch } from '@/store/auth';
import { useUi } from '@/store/ui';

import { DayPicker } from './day-picker';
import type { ScreenProps } from './types';

interface ListProps {
  day: string;
  bills: {
    id: string;
    number: string;
    paidAt: string;
    paidBy: string;
    table: string | null;
    customerName: string | null;
    totalMinor: number;
    paymentMethod: string;
    status: 'paid' | 'voided';
  }[];
  takings: { count: number; totalMinor: number };
}

/** The bills paid at this branch on a day, and what was kept. */
export function BillsScreen({ page }: ScreenProps<ListProps>) {
  const { day, bills, takings } = page.props;
  const open = useUi((s) => s.open);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Bills paid"
        description={`${takings.count} bill${takings.count === 1 ? '' : 's'} · ${money(takings.totalMinor)} kept`}
        actions={<DayPicker section="bills" day={day} />}
      />
      <Panel>
        {bills.length === 0 ? (
          <Empty icon={<ReceiptText />} title="No bills this day" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Bill</th>
                <th className="px-4 py-2 font-medium">Where</th>
                <th className="px-4 py-2 font-medium">Paid</th>
                <th className="px-4 py-2 font-medium">By</th>
                <th className="px-4 py-2 text-right font-medium">Total</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {bills.map((bill) => (
                <tr key={bill.id} onClick={() => open(`bills/${bill.id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2 font-medium">
                    {bill.number} {bill.status === 'voided' ? <Badge tone="red">voided</Badge> : null}
                  </td>
                  <td className="px-4 py-2">{bill.table ? `Table ${bill.table}` : (bill.customerName ?? 'Takeaway')}</td>
                  <td className="px-4 py-2 text-muted-foreground">
                    {time(bill.paidAt)} · {bill.paymentMethod}
                  </td>
                  <td className="px-4 py-2 text-muted-foreground">{bill.paidBy}</td>
                  <td className="px-4 py-2 text-right tabular">{money(bill.totalMinor)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
  );
}

interface BillProps {
  bill: {
    id: string;
    number: string;
    status: 'paid' | 'voided';
    paidAt: string;
    paidBy: string;
    order: { id: string; number: string; table: string | null };
    customerName: string | null;
    totalMinor: number;
    paymentMethod: string;
    paymentReference: string | null;
    tenderedMinor: number | null;
    changeMinor: number | null;
    voidedAt: string | null;
    voidedBy: string | null;
    voidReason: string | null;
    lines: { id: string; name: string; quantity: number; unitPriceMinor: number; totalMinor: number }[];
  };
  canVoid: boolean;
}

/** One paid bill: printed again, or voided whole with the money given back. */
export function BillScreen({ page, path, reload }: ScreenProps<BillProps>) {
  const { bill, canVoid } = page.props;
  const { run, busy, errors } = useAction(path, reload);
  const workspaceName = useAuth((s) => s.me?.organization.name ?? '');
  const branch = useBranch();
  const open = useUi((s) => s.open);
  const [voiding, setVoiding] = useState(false);
  const [reason, setReason] = useState('');
  const where = bill.order.table ? `Table ${bill.order.table}` : (bill.customerName ?? 'Takeaway');

  const print = () =>
    void window.desktop.print(
      billHtml({
        business: workspaceName,
        branch: branch?.name ?? null,
        title: `Bill ${bill.number}${bill.status === 'voided' ? ' (voided)' : ''}`,
        details: [`${where} · ${bill.order.number}`, dateTime(bill.paidAt)],
        lines: bill.lines,
        totalMinor: bill.totalMinor,
        footer: [
          `Paid by ${bill.paymentMethod}${bill.paymentReference ? ` (${bill.paymentReference})` : ''}`,
          ...(bill.tenderedMinor !== null ? [`Given ${money(bill.tenderedMinor)} · change ${money(bill.changeMinor ?? 0)}`] : []),
          `Served by ${bill.paidBy}. Thank you!`,
        ],
      }),
    );

  const submitVoid = async (e: FormEvent) => {
    e.preventDefault();
    if (await run('POST', `bills/${bill.id}/void`, { reason: reason.trim() })) setVoiding(false);
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title={`Bill ${bill.number}`}
        description={`${where} · ${dateTime(bill.paidAt)} · ${bill.paidBy}`}
        actions={
          <>
            <Badge tone={bill.status === 'paid' ? 'green' : 'red'}>{bill.status}</Badge>
            <Button variant="secondary" size="sm" onClick={print}>
              <Printer size={14} /> Print
            </Button>
            {canVoid && bill.status === 'paid' ? (
              <Button variant="secondary" size="sm" onClick={() => (setReason(''), setVoiding(true))}>
                <Ban size={14} /> Void
              </Button>
            ) : null}
          </>
        }
      />

      {bill.status === 'voided' ? (
        <Notice tone="red">
          Voided {bill.voidedAt ? dateTime(bill.voidedAt) : ''} by {bill.voidedBy}: {bill.voidReason}
        </Notice>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="Lines" className="lg:col-span-2">
          <table className="w-full text-sm">
            <tbody className="divide-y divide-border">
              {bill.lines.map((line) => (
                <tr key={line.id}>
                  <td className="px-4 py-2">
                    {line.quantity} × {line.name}
                  </td>
                  <td className="px-4 py-2 text-right text-muted-foreground tabular">{money(line.unitPriceMinor)} each</td>
                  <td className="px-4 py-2 text-right tabular">{money(line.totalMinor)}</td>
                </tr>
              ))}
              <tr className="font-semibold">
                <td className="px-4 py-2" colSpan={2}>
                  Total
                </td>
                <td className="px-4 py-2 text-right tabular">{money(bill.totalMinor)}</td>
              </tr>
            </tbody>
          </table>
        </Panel>
        <Panel title="Payment">
          <dl className="space-y-2 p-4 text-sm">
            <div className="flex justify-between">
              <dt className="text-muted-foreground">Method</dt>
              <dd>{bill.paymentMethod}</dd>
            </div>
            {bill.paymentReference ? (
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Reference</dt>
                <dd>{bill.paymentReference}</dd>
              </div>
            ) : null}
            {bill.tenderedMinor !== null ? (
              <>
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Given</dt>
                  <dd className="tabular">{money(bill.tenderedMinor)}</dd>
                </div>
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Change</dt>
                  <dd className="tabular">{money(bill.changeMinor ?? 0)}</dd>
                </div>
              </>
            ) : null}
            <div className="flex justify-between">
              <dt className="text-muted-foreground">Order</dt>
              <dd>
                <button type="button" className="text-primary hover:underline" onClick={() => open(`orders/${bill.order.id}`)}>
                  {bill.order.number}
                </button>
              </dd>
            </div>
          </dl>
        </Panel>
      </div>

      {voiding ? (
        <Modal
          title={`Void bill ${bill.number}`}
          onClose={() => setVoiding(false)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setVoiding(false)}>
                Keep it
              </Button>
              <Button variant="danger" type="submit" form="void" disabled={busy || reason.trim() === ''}>
                Void and refund {money(bill.totalMinor)}
              </Button>
            </>
          }
        >
          <form id="void" onSubmit={submitVoid} className="space-y-3">
            <p className="text-sm text-muted-foreground">The money is given back from your till, and the drinks go back into stock.</p>
            <Field label="Reason" error={errors.reason}>
              <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
            </Field>
          </form>
        </Modal>
      ) : null}
    </div>
  );
}
