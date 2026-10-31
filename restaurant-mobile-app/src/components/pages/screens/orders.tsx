import { Text } from 'react-native';

import { Badge, EmptyState } from '@/components/ui/misc';
import { openPage } from '@/hooks/use-page';
import { money, plural, time } from '@/lib/format';
import { withQuery } from '@/lib/pages';

import { DayPicker, ListRow, Panel, Scroll } from '../kit';
import type { ScreenProps } from '../types';

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
export function OrdersScreen({ page, reload, go }: ScreenProps<Props>) {
  const { day, orders } = page.props;

  return (
    <Scroll onRefresh={reload}>
      <Panel title={plural(orders.length, 'order')} right={<DayPicker day={day} onChange={(next) => go(withQuery('orders', { day: next }))} />}>
        {orders.length === 0 ? (
          <EmptyState icon="list-outline" title="No orders this day" />
        ) : (
          orders.map((order) => (
            <ListRow
              key={order.id}
              title={order.table ? `Table ${order.table}` : `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`}
              subtitle={`${order.number} · ${time(order.openedAt)} · ${order.openedBy}${order.bill ? ` · ${order.bill.number}` : ''}`}
              right={
                <>
                  {order.bill ? <Text className="text-base font-semibold text-slate-900">{money(order.bill.totalMinor)}</Text> : null}
                  <Badge label={order.status} tone={TONE[order.status]} />
                </>
              }
              onPress={() => openPage(`orders/${order.id}`)}
            />
          ))
        )}
      </Panel>
    </Scroll>
  );
}
