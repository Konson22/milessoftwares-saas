import { HardDriveDownload, LogOut, Pill } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { loadAccess, useAccess } from '@/hooks/use-page';
import { call } from '@/lib/api';
import { dateTime, initials, prettyDate, isoDate } from '@/lib/format';
import { visibleNavigation, type NavItem } from '@/lib/navigation';
import { PrescriptionsPage } from '@/pages/prescriptions';
import { SalesPage } from '@/pages/sales';
import { SellPage } from '@/pages/sell';
import { ShiftPage } from '@/pages/shift';
import { useAuth, useBranch } from '@/store/auth';
import { PageView } from '@/screens/page-view';
import { appPath, useUi, type Page } from '@/store/ui';

import { cn } from './ui';

/** The menu item a page belongs to: its own, or the section it sits under ("stock/<id>" under Inventory). */
function currentItem(items: NavItem[], page: Page): NavItem | null {
  const exact = items.find((item) => item.page === page);
  if (exact) return exact;
  const path = appPath(page);
  if (path === null) return null;
  // The longest section the page sits under: "receipts/<id>" under Goods received.
  return (
    items
      .filter((item) => {
        const section = appPath(item.page);
        return section !== null && section !== '' && (path.startsWith(`${section}/`) || path.startsWith(`${section}?`));
      })
      .sort((a, b) => b.page.length - a.page.length)[0] ?? null
  );
}

// As in packages/ui/shell/sidebar.tsx.
const itemClass =
  'relative flex h-8 w-full items-center gap-3 px-5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none';

/** Text that fades out while the rail is collapsed to icons. */
const labelClass = 'transition-opacity duration-150 rail-collapsed:opacity-0';

export function Shell() {
  const page = useUi((s) => s.page);
  const go = useUi((s) => s.go);
  const me = useAuth((s) => s.me);
  const branch = useBranch();
  const shared = useAccess((s) => s.shared);
  const groups = useMemo(() => visibleNavigation(shared), [shared]);
  const keyed = useMemo(() => groups.flatMap((g) => g.items).filter((i) => i.key), [groups]);
  const current = useMemo(
    () =>
      currentItem(
        groups.flatMap((g) => g.items),
        page,
      ),
    [groups, page],
  );
  const path = appPath(page);

  // The menu as this member's role allows it.
  useEffect(() => {
    useAccess.setState({ shared: null });
    void loadAccess();
  }, [me?.user.id]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const found = keyed.find((p) => p.key === e.key);
      if (found) {
        e.preventDefault();
        go(found.page);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [go, keyed]);

  return (
    <div className="flex h-full">
      {/* The rail every Miles app shares (packages/ui shell): icons only,
          widening over the page on hover or keyboard focus, so the page
          never reflows. */}
      <aside className="relative z-30 h-full w-14 shrink-0">
        <div className="shell-rail absolute inset-y-0 left-0 w-14 overflow-hidden transition-[width,box-shadow] duration-200 hover:w-60 hover:shadow-xl has-[:focus-visible]:w-60 has-[:focus-visible]:shadow-xl">
          <div className="flex h-full w-60 flex-col bg-sidebar text-sidebar-foreground">
            {/* Same height as the top bar, so the two borders line up. */}
            <div className="flex h-13 shrink-0 items-center gap-3 border-b border-sidebar-border px-3.5">
              <span className="grid size-7 shrink-0 place-items-center bg-sidebar-primary text-sidebar-primary-foreground">
                <Pill className="size-4" aria-hidden />
              </span>
              <span className={cn('min-w-0 leading-tight', labelClass)}>
                <span className="block truncate text-sm font-semibold text-white">Miles Pharmacy</span>
                <span className="block truncate text-xs text-sidebar-foreground/70">{branch?.name}</span>
              </span>
            </div>

            <nav aria-label="Pharmacy" className="sidebar-scroll flex-1 overflow-x-hidden overflow-y-auto py-2">
              {groups.map((group) => (
                <div key={group.title} className="pb-2">
                  {groups.length > 1 ? (
                    <div className={cn('px-5 pt-2 pb-1 text-[11px] font-medium tracking-wide text-sidebar-foreground/50 uppercase', labelClass)}>
                      {group.title}
                    </div>
                  ) : null}
                  <ul>
                    {group.items.map((item) => {
                      const here = item === current;
                      const Icon = item.icon;
                      return (
                        <li key={item.page}>
                          <button
                            type="button"
                            onClick={() => go(item.page)}
                            aria-current={here ? 'page' : undefined}
                            title={item.title}
                            className={cn(
                              itemClass,
                              here
                                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground before:absolute before:inset-y-0 before:left-0 before:w-[3px] before:bg-cyan-400'
                                : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
                            )}
                          >
                            <span className={cn('relative shrink-0', here ? 'text-cyan-300' : 'text-sidebar-foreground/70')}>
                              <Icon className="size-4" aria-hidden />
                            </span>
                            <span className={cn('min-w-0 flex-1 truncate text-left', labelClass)}>{item.title}</span>
                            {item.key ? <span className={cn('text-[11px] text-sidebar-foreground/50', labelClass)}>{item.key}</span> : null}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ))}
            </nav>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar />
        <main className="min-h-0 flex-1 overflow-y-auto p-5">
          {page === 'sell' && <SellPage />}
          {page === 'sales' && <SalesPage />}
          {page === 'shift' && <ShiftPage />}
          {page === 'prescriptions' && <PrescriptionsPage />}
          {path !== null && <PageView key={path} path={path} />}
        </main>
      </div>
      <Toasts />
    </div>
  );
}

/** The pharmacy and the day; who is signed in; for a manager, when the data was last copied. */
function TopBar() {
  const me = useAuth((s) => s.me);
  const signOut = useAuth((s) => s.signOut);
  const page = useUi((s) => s.page);
  const open = useUi((s) => s.open);
  const manager = me?.user.role === 'manager';
  const [lastBackupAt, setLastBackupAt] = useState<string | null>(null);

  useEffect(() => {
    if (!manager) return;
    call<{ lastBackupAt: string | null }>('backup.list')
      .then((r) => setLastBackupAt(r.lastBackupAt))
      .catch(() => {});
  }, [manager, page]);

  return (
    <header className="flex h-13 shrink-0 items-center gap-3 border-b border-border bg-card px-5">
      <span className="text-sm font-medium">{me?.organization.name}</span>
      <span className="text-xs text-muted-foreground">{prettyDate(isoDate())}</span>
      <div className="ml-auto flex items-center gap-4">
        {manager ? (
          <button type="button" onClick={() => open('settings')} className="flex items-center gap-1.5 text-xs text-muted-foreground hover:underline">
            <HardDriveDownload size={13} /> {lastBackupAt ? `Backed up ${dateTime(lastBackupAt)}` : 'Not backed up yet'}
          </button>
        ) : null}
        <div className="flex items-center gap-2 border-l border-border pl-4">
          <span className="grid size-7 shrink-0 place-items-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">
            {initials(me?.user.name ?? '')}
          </span>
          <span className="leading-tight">
            <span className="block text-sm font-medium">{me?.user.name}</span>
            <span className="block text-xs text-muted-foreground capitalize">{me?.user.role}</span>
          </span>
        </div>
        <button type="button" onClick={() => void signOut()} className="flex items-center gap-1.5 border border-input px-2.5 py-1.5 text-sm hover:bg-accent">
          <LogOut size={15} /> Sign out
        </button>
      </div>
    </header>
  );
}

function Toasts() {
  const toasts = useUi((s) => s.toasts);
  const dismiss = useUi((s) => s.dismiss);
  const tones = {
    green: 'border-emerald-300',
    amber: 'border-amber-300',
    red: 'border-red-300',
    blue: 'border-blue-300',
  };
  return (
    <div className="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-80 flex-col gap-2">
      {toasts.map((t) => (
        <button
          key={t.id}
          type="button"
          onClick={() => dismiss(t.id)}
          className={cn('pointer-events-auto border-l-4 bg-card px-4 py-3 text-left text-sm shadow-lg', tones[t.tone])}
        >
          {t.message}
        </button>
      ))}
    </div>
  );
}
