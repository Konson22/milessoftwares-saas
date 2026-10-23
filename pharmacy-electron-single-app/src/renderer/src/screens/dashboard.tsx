import { AlertTriangle, Boxes, CalendarClock, FileCheck2, FileText, PackageX, ReceiptText } from 'lucide-react';
import type { ReactNode } from 'react';

import { PageHeader } from '@/components/ui';
import { money } from '@/lib/format';
import { useAuth } from '@/store/auth';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

interface Props {
  sales: { count: number; totalMinor: number; everyone: boolean };
  stock: { low: number; expiring: number; expired: number } | null;
  awaitingVerification: number | null;
  toDispense: number | null;
  billsDue: number | null;
  expiringDays: number;
}

/** The pharmacy today. */
export function DashboardScreen({ page }: ScreenProps<Props>) {
  const p = page.props;
  const me = useAuth((s) => s.me);
  const go = useUi((s) => s.go);
  const open = useUi((s) => s.open);

  return (
    <div className="space-y-4">
      <PageHeader title={`Good day, ${me?.user.name.split(' ')[0] ?? ''}`} description={`${p.branch?.name ?? ''} · today`} />
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
        <Tile
          icon={<ReceiptText />}
          label={p.sales.everyone ? 'Sales today' : 'Your sales today'}
          value={money(p.sales.totalMinor)}
          note={`${p.sales.count} sale(s), less returns`}
          onClick={() => go('sales')}
        />
        {p.stock ? (
          <>
            <Tile icon={<Boxes />} label="Low on stock" value={String(p.stock.low)} note="At or below the reorder level" onClick={() => open('stock?filter=low')} tone={p.stock.low ? 'amber' : undefined} />
            <Tile
              icon={<AlertTriangle />}
              label="Expiring soon"
              value={String(p.stock.expiring)}
              note={`Within ${p.expiringDays} days`}
              onClick={() => open('stock?filter=expiring')}
              tone={p.stock.expiring ? 'amber' : undefined}
            />
            <Tile
              icon={<PackageX />}
              label="Expired on the shelf"
              value={String(p.stock.expired)}
              note="Dispose of them under Stock"
              onClick={() => open('stock?filter=expired')}
              tone={p.stock.expired ? 'red' : undefined}
            />
          </>
        ) : null}
        {p.awaitingVerification !== null ? (
          <Tile icon={<FileText />} label="Prescriptions to verify" value={String(p.awaitingVerification)} note="Waiting for a pharmacist" onClick={() => go('prescriptions')} tone={p.awaitingVerification ? 'amber' : undefined} />
        ) : null}
        {p.billsDue !== null ? (
          <Tile
            icon={<CalendarClock />}
            label="Bills due"
            value={String(p.billsDue)}
            note="Recurring expenses due this week or overdue"
            onClick={() => open('expenses')}
            tone={p.billsDue ? 'amber' : undefined}
          />
        ) : null}
        {p.toDispense !== null ? (
          <Tile icon={<FileCheck2 />} label="Prescriptions to dispense" value={String(p.toDispense)} note="Verified, not all given yet" onClick={() => go('prescriptions')} />
        ) : null}
      </div>
    </div>
  );
}

function Tile({ icon, label, value, note, onClick, tone }: { icon: ReactNode; label: string; value: string; note: string; onClick: () => void; tone?: 'amber' | 'red' }) {
  return (
    <button type="button" onClick={onClick} className="flex items-start gap-3 border border-border bg-card p-4 text-left hover:bg-accent">
      <span className={tone === 'red' ? 'text-red-600' : tone === 'amber' ? 'text-amber-600' : 'text-muted-foreground'}>{icon}</span>
      <span>
        <span className="block text-xs text-muted-foreground">{label}</span>
        <span className="block text-2xl font-semibold tabular">{value}</span>
        <span className="block text-xs text-muted-foreground">{note}</span>
      </span>
    </button>
  );
}
