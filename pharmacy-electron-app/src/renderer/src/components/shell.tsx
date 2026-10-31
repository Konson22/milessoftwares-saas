import { Building2, ChevronDown, CloudOff, HardDriveDownload, LogOut, RefreshCw, Store, Wifi } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import pharmacyLogo from '@/assets/pharmacy-logo.png';
import { loadAccess, useAccess } from '@/hooks/use-page';
import { API_MODE, call } from '@/lib/api';
import { PRODUCTS } from '@/lib/config';
import { dateTime, initials, isoDate, prettyDate, time } from '@/lib/format';
import { visibleNavigation, type NavItem } from '@/lib/navigation';
import { pagesAvailable } from '@/lib/pages';
import { useConnectivity } from '@/offline/connectivity';
import { queuedFor, useOutbox } from '@/offline/outbox';
import { syncNow, useSync, useSyncEngine } from '@/offline/sync';
import { PrescriptionsPage } from '@/pages/local/prescriptions';
import { SalesPage } from '@/pages/sales';
import { SellPage } from '@/pages/sell';
import { ShiftPage } from '@/pages/shift';
import { SyncPage } from '@/pages/sync';
import { useAuth, useBranch, useLocal, useOwner } from '@/store/auth';
import { PageView } from '@/screens/page-view';
import { appPath, useUi, type Page } from '@/store/ui';

import { LicenceBanner } from './licence-banner';
import { Badge, cn } from './ui';

/** The menu item a page belongs to: its own, or the section it sits under ("stock/<id>" under Stock). */
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
  useSyncEngine();
  const page = useUi((s) => s.page);
  const go = useUi((s) => s.go);
  const owner = useOwner();
  const entries = useOutbox((s) => s.entries);
  const queue = useMemo(() => queuedFor(entries, owner), [entries, owner]);
  const me = useAuth((s) => s.me);
  const branch = useBranch();
  const signOut = useAuth((s) => s.signOut);
  const chooseBranch = useAuth((s) => s.chooseBranch);
  const shared = useAccess((s) => s.shared);
  const product = useAuth((s) => s.product);
  const local = useLocal();
  const groups = useMemo(() => visibleNavigation(product, shared, pagesAvailable(), local), [product, shared, local]);
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

  // The menu as the pharmacy (or shop) allows it for this member, in this branch (offline: as their role does).
  useEffect(() => {
    useAccess.setState({ shared: null });
    if (pagesAvailable()) void loadAccess(owner);
  }, [owner]);

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

  const leave = () => {
    if (queue.all.length && !window.confirm(`${queue.all.length} item(s) are still waiting to be sent from this PC. They stay here and are sent the next time you sign in. Sign out?`)) return;
    void signOut();
  };

  return (
    <div className="flex h-full">
      {/* The rail every Miles app shares (packages/ui shell): icons only,
          widening over the page on hover or keyboard focus, so the page
          never reflows. */}
      <aside className="relative z-30 h-full w-14 shrink-0">
        <div className="shell-rail absolute inset-y-0 left-0 w-14 overflow-hidden transition-[width,box-shadow] duration-200 hover:w-64 hover:shadow-xl has-[:focus-visible]:w-64 has-[:focus-visible]:shadow-xl">
          <div className="flex h-full w-64 flex-col bg-sidebar text-sidebar-foreground">
            {/* Same height as the top bar, so the two borders line up. */}
            <div className="flex h-13 shrink-0 items-center gap-3 border-b border-sidebar-border px-3.5">
              {product === 'retail' ? (
                <span className="grid size-7 shrink-0 place-items-center bg-sidebar-primary text-sidebar-primary-foreground">
                  <Store className="size-4" aria-hidden />
                </span>
              ) : (
                <img src={pharmacyLogo} alt="" className="size-7 shrink-0 object-contain" />
              )}
              <span className={cn('min-w-0 leading-tight', labelClass)}>
                <span className="block truncate text-sm font-semibold text-white">{PRODUCTS[product].name}</span>
                <span className="block truncate text-xs text-sidebar-foreground/70">{branch?.name}</span>
              </span>
            </div>

            <nav aria-label={PRODUCTS[product].label} className="sidebar-scroll flex-1 overflow-x-hidden overflow-y-auto py-2">
              {groups.map((group) => (
                <div key={group.title} className="pb-2">
                  {groups.length > 1 ? (
                    <div className={cn('px-5 pt-2 pb-1 text-[11px] font-medium tracking-wide text-sidebar-foreground/50 uppercase', labelClass)}>{group.title}</div>
                  ) : null}
                  <ul>
                    {group.items.map((item) => {
                      const here = item === current;
                      const count = item.page === 'sync' ? queue.all.length : 0;
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
                              {/* Seen while the rail is collapsed. */}
                              {count > 0 ? (
                                <span className={cn('absolute -top-1 -right-1 size-2 rounded-full', queue.refused.length ? 'bg-red-500' : 'bg-amber-400')} />
                              ) : null}
                            </span>
                            <span className={cn('min-w-0 flex-1 truncate text-left', labelClass)}>{item.title}</span>
                            {count > 0 ? (
                              <span
                                className={cn(
                                  'min-w-5 rounded-full px-1.5 text-center text-xs text-white',
                                  queue.refused.length ? 'bg-red-500' : 'bg-amber-500',
                                  labelClass,
                                )}
                              >
                                {count}
                              </span>
                            ) : item.key ? (
                              <span className={cn('text-[11px] text-sidebar-foreground/50', labelClass)}>{item.key}</span>
                            ) : null}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ))}
            </nav>

            {/* Offline, who is signed in and signing out are in the top bar, where people at a shared counter look. */}
            {local ? null : (
              <div className="space-y-1 border-t border-sidebar-border p-2">
                <div className="flex items-center gap-3 px-1.5 py-1">
                  <span className="grid size-7 shrink-0 place-items-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">
                    {initials(me?.user.name ?? '')}
                  </span>
                  <span className={cn('min-w-0 leading-tight', labelClass)}>
                    <span className="block truncate text-sm font-medium text-white">{me?.user.name}</span>
                    <span className="block truncate text-xs text-sidebar-foreground/70">{me?.organization.name}</span>
                  </span>
                </div>
                {me && me.branches.length > 1 ? (
                  <label className="flex h-8 items-center gap-3 px-3">
                    <Building2 className="size-4 shrink-0 text-sidebar-foreground/70" aria-hidden />
                    <select
                      value={branch?.id ?? ''}
                      onChange={(e) => void chooseBranch(e.target.value)}
                      aria-label="Branch"
                      className={cn('min-w-0 flex-1 border border-sidebar-border bg-transparent px-2 py-1 text-xs text-sidebar-foreground', labelClass)}
                    >
                      {me.branches.map((b) => (
                        <option key={b.id} value={b.id} className="text-black">
                          {b.name}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : null}
                <button type="button" onClick={leave} className={cn(itemClass, 'px-3 text-sidebar-foreground hover:bg-white/5 hover:text-white')}>
                  <LogOut className="size-4 shrink-0 text-sidebar-foreground/70" aria-hidden />
                  <span className={labelClass}>Sign out</span>
                </button>
              </div>
            )}
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        {local ? <LocalTopBar /> : <TopBar />}
        {local ? <LicenceBanner /> : null}
        <main className="min-h-0 flex-1 overflow-y-auto p-5">
          {page === 'sell' && <SellPage />}
          {page === 'sales' && <SalesPage />}
          {page === 'shift' && <ShiftPage />}
          {page === 'sync' && !local && <SyncPage />}
          {page === 'prescriptions' && local && <PrescriptionsPage />}
          {path !== null && <PageView key={path} path={path} />}
        </main>
      </div>
      <Toasts />
    </div>
  );
}

function TopBar() {
  const online = useConnectivity((s) => s.online);
  const lastSeenAt = useConnectivity((s) => s.lastSeenAt);
  const simulated = useConnectivity((s) => s.simulated);
  const simulateOffline = useConnectivity((s) => s.simulateOffline);
  const running = useSync((s) => s.running);
  const lastSyncAt = useSync((s) => s.lastSyncAt);
  const owner = useOwner();
  const waiting = useOutbox((s) => queuedFor(s.entries, owner).all.length);
  const go = useUi((s) => s.go);

  return (
    <header className="flex h-13 shrink-0 items-center gap-3 border-b border-border bg-card px-5">
      {online ? (
        <Badge tone="green">
          <Wifi size={13} /> Online
        </Badge>
      ) : (
        <Badge tone="amber">
          <CloudOff size={13} /> Offline{lastSeenAt ? ` since ${time(lastSeenAt)}` : ''}: sales are kept on this PC
        </Badge>
      )}
      {waiting > 0 ? (
        <button type="button" onClick={() => go('sync')} className="text-xs text-muted-foreground hover:underline">
          {waiting} waiting to send
        </button>
      ) : null}

      <div className="ml-auto flex items-center gap-3 text-xs text-muted-foreground">
        {API_MODE === 'mock' ? (
          <label className="flex items-center gap-1.5">
            <input type="checkbox" checked={simulated} onChange={(e) => simulateOffline(e.target.checked)} />
            Simulate offline (demo)
          </label>
        ) : null}
        {lastSyncAt ? <span>Synced {time(lastSyncAt)}</span> : null}
        <button
          type="button"
          onClick={() => void syncNow({ catalog: true })}
          disabled={running}
          className="flex items-center gap-1.5 border border-input px-2 py-1 hover:bg-accent disabled:opacity-60"
        >
          <RefreshCw size={13} className={running ? 'animate-spin' : ''} /> Sync now
        </button>
      </div>
    </header>
  );
}

/** The pharmacy and the day; who is signed in; for a manager, when the data was last copied. */
function LocalTopBar() {
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
        <div className="border-l border-border pl-4">
          <ProfileMenu name={me?.user.name ?? ''} role={me?.user.role ?? ''} onSignOut={() => void signOut()} />
        </div>
      </div>
    </header>
  );
}

/** Who is signed in; opens to sign out. Closes on a click elsewhere or Escape. */
function ProfileMenu({ name, role, onSignOut }: { name: string; role: string; onSignOut: () => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="menu"
        aria-expanded={open}
        className="flex items-center gap-2 px-1.5 py-1 text-left hover:bg-accent"
      >
        <span className="grid size-7 shrink-0 place-items-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">{initials(name)}</span>
        <span className="leading-tight">
          <span className="block text-sm font-medium">{name}</span>
          <span className="block text-xs text-muted-foreground capitalize">{role}</span>
        </span>
        <ChevronDown size={14} className={cn('text-muted-foreground transition-transform', open && 'rotate-180')} />
      </button>
      {open ? (
        <div role="menu" className="absolute right-0 z-40 mt-1 w-44 border border-border bg-card py-1 shadow-lg">
          <button type="button" role="menuitem" onClick={onSignOut} className="flex w-full items-center gap-2 px-3 py-2 text-sm hover:bg-accent">
            <LogOut size={15} /> Sign out
          </button>
        </div>
      ) : null}
    </div>
  );
}

function Toasts() {
  const toasts = useUi((s) => s.toasts);
  const dismiss = useUi((s) => s.dismiss);
  const tones = { green: 'border-emerald-300', amber: 'border-amber-300', red: 'border-red-300', blue: 'border-blue-300' };
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
