import { AlertTriangle, Boxes, FileText, ReceiptText } from 'lucide-react';
import type { ReactNode } from 'react';

import { PageHeader } from '@/components/ui';
import { money } from '@/lib/format';
import { useAuth } from '@/store/auth';
import { useUi } from '@/store/ui';

import type { ScreenProps } from './types';

interface Props {
  sales: { count: number; totalMinor: number } | null;
  stock: { low: number; expiring: number } | null;
  awaitingVerification: number | null;
  expiringDays: number;
}

/** The branch today (web: home). */
export function DashboardScreen({ page }: ScreenProps<Props>) {
  const p = page.props;
  const me = useAuth((s) => s.me);
  const go = useUi((s) => s.go);
  const open = useUi((s) => s.open);

  return (
    <div className="space-y-4">
      <PageHeader title={`Good day, ${me?.user.name.split(' ')[0] ?? ''}`} description={`${p.branch?.name ?? ''} · today`} />
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {p.sales ? (
          <Tile icon={<ReceiptText />} label="Sales today" value={money(p.sales.totalMinor)} note={`${p.sales.count} sale(s)`} onClick={() => go('sales')} />
        ) : null}
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
          </>
        ) : null}
        {p.awaitingVerification !== null ? (
          <Tile icon={<FileText />} label="Prescriptions to verify" value={String(p.awaitingVerification)} note="Waiting for a pharmacist" onClick={() => open('prescriptions')} />
        ) : null}
      </div>
    </div>
  );
}

function Tile({ icon, label, value, note, onClick, tone }: { icon: ReactNode; label: string; value: string; note: string; onClick: () => void; tone?: 'amber' }) {
  return (
    <button type="button" onClick={onClick} className="flex items-start gap-3 border border-border bg-card p-4 text-left hover:bg-accent">
      <span className={tone === 'amber' ? 'text-amber-600' : 'text-muted-foreground'}>{icon}</span>
      <span>
        <span className="block text-xs text-muted-foreground">{label}</span>
        <span className="block text-2xl font-semibold tabular">{value}</span>
        <span className="block text-xs text-muted-foreground">{note}</span>
      </span>
    </button>
  );
}
