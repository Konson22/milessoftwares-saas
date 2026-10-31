import { Building2, Check, HardDrive, Loader2, Pill } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { Button, cn, Notice } from '@/components/ui';
import { errorMessage } from '@/lib/api';
import type { Mode } from '@/lib/mode';
import { useAuth } from '@/store/auth';

/**
 * The first opening: how this PC runs the pharmacy. Kept on the PC and
 * changed from the sign-in screen; each mode keeps its own data.
 */
export function ChooseModePage() {
  const chooseMode = useAuth((s) => s.chooseMode);
  const [mode, setMode] = useState<Mode | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const go = async () => {
    if (!mode) return;
    setBusy(true);
    setError(null);
    try {
      await chooseMode(mode);
    } catch (e) {
      setError(errorMessage(e));
      setBusy(false);
    }
  };

  return (
    <div className="flex h-full">
      <aside className="hidden w-[38%] flex-col justify-between bg-sidebar p-10 text-sidebar-foreground lg:flex">
        <div className="flex items-center gap-2 text-white">
          <Pill />
          <span className="text-lg font-semibold">Miles Pharmacy</span>
        </div>
        <div className="space-y-3">
          <h2 className="text-3xl font-semibold text-white">One app, the way your pharmacy works.</h2>
          <p>Run the pharmacy on this PC alone, or as the counter of your Miles workspace. You can change this later from the sign-in screen.</p>
        </div>
        <p className="text-xs">Miles Softwares</p>
      </aside>
      <main className="flex flex-1 items-center justify-center overflow-y-auto p-8">
        <div className="w-full max-w-lg space-y-5">
          <div>
            <h1 className="text-2xl font-semibold">How does this PC run the pharmacy?</h1>
            <p className="text-sm text-muted-foreground">Each way keeps its own data on this PC.</p>
          </div>
          {error ? <Notice tone="red">{error}</Notice> : null}
          <div className="space-y-3">
            <Choice
              selected={mode === 'offline'}
              onSelect={() => setMode('offline')}
              icon={<HardDrive size={20} />}
              title="On this PC only"
              summary="Offline: one PC, one branch."
            >
              The whole pharmacy lives on this computer: sales, stock, prescriptions, expenses and reports. No internet or Miles
              account needed. People sign in with a username and password a manager gives them. A copy is made every day.
            </Choice>
            <Choice
              selected={mode === 'hybrid'}
              onSelect={() => setMode('hybrid')}
              icon={<Building2 size={20} />}
              title="With a Miles workspace"
              summary="Hybrid: online, and keeps selling offline."
            >
              The counter of your workspace’s pharmacy (or shop): branches, the web back office and every device stay in step. When
              the internet drops, sales are kept on this PC and sent when it is back. Sign in with your Miles account.
            </Choice>
          </div>
          <Button size="lg" className="w-full" disabled={!mode || busy} onClick={() => void go()}>
            {busy ? <Loader2 size={16} className="animate-spin" /> : null} Continue
          </Button>
        </div>
      </main>
    </div>
  );
}

function Choice({
  selected,
  onSelect,
  icon,
  title,
  summary,
  children,
}: {
  selected: boolean;
  onSelect: () => void;
  icon: ReactNode;
  title: string;
  summary: string;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={selected}
      className={cn(
        'flex w-full gap-4 border bg-card p-4 text-left transition-colors hover:bg-accent',
        selected ? 'border-primary ring-1 ring-primary' : 'border-border',
      )}
    >
      <span className={cn('grid size-10 shrink-0 place-items-center', selected ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>
        {icon}
      </span>
      <span className="min-w-0 flex-1 space-y-1">
        <span className="flex items-center justify-between gap-2">
          <span className="font-semibold">{title}</span>
          {selected ? <Check size={16} className="text-primary" /> : null}
        </span>
        <span className="block text-sm font-medium text-muted-foreground">{summary}</span>
        <span className="block text-sm text-muted-foreground">{children}</span>
      </span>
    </button>
  );
}
