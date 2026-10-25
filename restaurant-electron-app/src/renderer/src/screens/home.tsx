import { Boxes, ChefHat, ClipboardList, LayoutGrid, ReceiptText, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';

import { PageHeader } from '@/components/ui';
import { useAccess } from '@/hooks/use-page';
import { money } from '@/lib/format';
import { visibleNavigation } from '@/lib/navigation';
import { useAuth, useBranch } from '@/store/auth';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

interface Props {
  openOrders: number | null;
  takings: { count: number; totalMinor: number } | null;
  lowStock: number | null;
}

/** Today at this branch, for what the member may see, and the way in to the rest. */
export function HomeScreen({ page }: ScreenProps<Props>) {
  const { openOrders, takings, lowStock } = page.props;
  const me = useAuth((s) => s.me);
  const branch = useBranch();
  const open = useUi((s) => s.open);
  const shared = useAccess((s) => s.shared);
  const allowed = new Set(visibleNavigation(shared).flatMap((g) => g.items.map((i) => i.path)));

  const shortcuts: { path: string; title: string; icon: ReactNode; text: string }[] = [
    { path: 'floor', title: 'Floor', icon: <LayoutGrid />, text: 'Seat a table or open a takeaway, and take the order.' },
    { path: 'kitchen', title: 'Kitchen', icon: <ChefHat />, text: 'Tickets to cook, oldest first; mark them ready.' },
    { path: 'tills', title: 'My shift', icon: <Wallet />, text: 'Start the shift with a float, close it with the count.' },
    { path: 'bills', title: 'Bills paid', icon: <ReceiptText />, text: "Today's bills; print or void one." },
  ];

  return (
    <div className="space-y-5">
      <PageHeader title={`Hello, ${me?.user.name.split(' ')[0] ?? ''}`} description={`${me?.organization.name} · ${branch?.name ?? ''}`} />

      <div className="grid gap-3 sm:grid-cols-3">
        {openOrders !== null ? (
          <Tile icon={<ClipboardList size={18} />} label="Open orders" value={String(openOrders)} onClick={() => open('floor')} />
        ) : null}
        {takings !== null ? (
          <Tile icon={<ReceiptText size={18} />} label={`Takings today · ${takings.count} bill${takings.count === 1 ? '' : 's'}`} value={money(takings.totalMinor)} onClick={() => open('bills')} />
        ) : null}
        {lowStock !== null ? (
          <Tile icon={<Boxes size={18} />} label="Drinks running low" value={String(lowStock)} tone={lowStock > 0 ? 'amber' : undefined} onClick={() => open('stock')} />
        ) : null}
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {shortcuts
          .filter((s) => allowed.has(s.path))
          .map((s) => (
            <button key={s.path} type="button" onClick={() => open(s.path)} className="flex gap-3 border border-border bg-card p-4 text-left hover:bg-accent">
              <span className="text-primary">{s.icon}</span>
              <span>
                <span className="block font-semibold">{s.title}</span>
                <span className="block text-sm text-muted-foreground">{s.text}</span>
              </span>
            </button>
          ))}
      </div>
    </div>
  );
}

function Tile({ icon, label, value, tone, onClick }: { icon: ReactNode; label: string; value: string; tone?: 'amber'; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="border border-border bg-card p-4 text-left hover:bg-accent">
      <span className="flex items-center gap-2 text-xs text-muted-foreground">
        {icon} {label}
      </span>
      <span className={`mt-1 block text-2xl font-semibold tabular ${tone === 'amber' ? 'text-amber-700' : ''}`}>{value}</span>
    </button>
  );
}
