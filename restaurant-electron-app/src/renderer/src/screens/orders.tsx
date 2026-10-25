import { ClipboardList } from 'lucide-react';

import { Badge, Empty, PageHeader, Panel } from '@/components/ui';
import { money, time } from '@/lib/format';
import { useUi } from '@/store/ui';

import { DayPicker } from './day-picker';
import type { ScreenProps } from './types';

interface Props {
  day: string;
  orders: {
    id: string;
    number: string;
    service: 'dine_in' | 'takeaway';
    table: string | null;
    customerName: string | null;
    status: 'open' | 'paid' | 'cancelled';
    openedAt: string;
    openedBy: string;
    bill: { id: string; number: string; totalMinor: number; status: string } | null;
  }[];
}

const TONE = { open: 'blue', paid: 'green', cancelled: 'red' } as const;

/** The branch's orders of a day: open, paid and cancelled. */
export function OrdersScreen({ page }: ScreenProps<Props>) {
  const { day, orders } = page.props;
  const open = useUi((s) => s.open);

  return (
    <div className="space-y-4">
      <PageHeader title="Orders" description={`${orders.length} order${orders.length === 1 ? '' : 's'}`} actions={<DayPicker section="orders" day={day} />} />
      <Panel>
        {orders.length === 0 ? (
          <Empty icon={<ClipboardList />} title="No orders this day" />
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">Order</th>
                <th className="px-4 py-2 font-medium">Where</th>
                <th className="px-4 py-2 font-medium">Opened</th>
                <th className="px-4 py-2 font-medium">Status</th>
                <th className="px-4 py-2 text-right font-medium">Bill</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {orders.map((order) => (
                <tr key={order.id} onClick={() => open(`orders/${order.id}`)} className="cursor-pointer hover:bg-accent">
                  <td className="px-4 py-2 font-medium">{order.number}</td>
                  <td className="px-4 py-2">{order.table ? `Table ${order.table}` : `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`}</td>
                  <td className="px-4 py-2 text-muted-foreground">
                    {time(order.openedAt)} · {order.openedBy}
                  </td>
                  <td className="px-4 py-2">
                    <Badge tone={TONE[order.status]}>{order.status}</Badge>
                  </td>
                  <td className="px-4 py-2 text-right tabular">{order.bill ? `${order.bill.number} · ${money(order.bill.totalMinor)}` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Panel>
    </div>
  );
}
