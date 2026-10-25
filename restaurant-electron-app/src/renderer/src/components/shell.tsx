import { Building2, CloudOff, LogOut, UtensilsCrossed, Wifi } from 'lucide-react';
import { useEffect, useMemo } from 'react';

import { loadAccess, useAccess } from '@/hooks/use-page';
import { initials } from '@/lib/format';
import { isCurrent, visibleNavigation } from '@/lib/navigation';
import { useAuth, useBranch } from '@/store/auth';
import { PageView } from '@/screens/page-view';
import { useUi } from '@/store/ui';

import { Badge, cn } from './ui';

// As in packages/ui/shell/sidebar.tsx.
const itemClass =
  'relative flex h-8 w-full items-center gap-3 px-5 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none';

/** Text that fades out while the rail is collapsed to icons. */
const labelClass = 'transition-opacity duration-150 rail-collapsed:opacity-0';

export function Shell() {
  const path = useUi((s) => s.path);
  const open = useUi((s) => s.open);
  const me = useAuth((s) => s.me);
  const branchId = useAuth((s) => s.branchId);
  const branch = useBranch();
  const signOut = useAuth((s) => s.signOut);
  const chooseBranch = useAuth((s) => s.chooseBranch);
  const shared = useAccess((s) => s.shared);
  const groups = useMemo(() => visibleNavigation(shared), [shared]);
  const keyed = useMemo(() => groups.flatMap((g) => g.items).filter((i) => i.key), [groups]);

  // The menu as the restaurant allows it for this member, in this branch.
  useEffect(() => {
    useAccess.setState({ shared: null });
    void loadAccess();
  }, [branchId]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const found = keyed.find((p) => p.key === e.key);
      if (found) {
        e.preventDefault();
        open(found.path);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, keyed]);

  return (
    <div className="flex h-full">
      {/* The rail every Miles app shares (packages/ui shell): icons only,
          widening over the page on hover or keyboard focus, so the page
          never reflows. */}
      <aside className="relative z-30 h-full w-14 shrink-0">
        <div className="shell-rail absolute inset-y-0 left-0 w-14 overflow-hidden transition-[width,box-shadow] duration-200 hover:w-64 hover:shadow-xl has-[:focus-visible]:w-64 has-[:focus-visible]:shadow-xl">
          <div className="flex h-full w-64 flex-col bg-sidebar text-sidebar-foreground">
            <div className="flex h-13 shrink-0 items-center gap-3 border-b border-sidebar-border px-3.5">
              <span className="grid size-7 shrink-0 place-items-center bg-sidebar-primary text-sidebar-primary-foreground">
                <UtensilsCrossed className="size-4" aria-hidden />
              </span>
              <span className={cn('min-w-0 leading-tight', labelClass)}>
                <span className="block truncate text-sm font-semibold text-white">Miles Restaurant</span>
                <span className="block truncate text-xs text-sidebar-foreground/70">{branch?.name}</span>
              </span>
            </div>

            <nav aria-label="Restaurant" className="flex-1 overflow-x-hidden overflow-y-auto py-2">
              {groups.map((group) => (
                <div key={group.title} className="pb-2">
                  {groups.length > 1 ? (
                    <div className={cn('px-5 pt-2 pb-1 text-[11px] font-medium tracking-wide text-sidebar-foreground/50 uppercase', labelClass)}>{group.title}</div>
                  ) : null}
                  <ul>
                    {group.items.map((item) => {
                      const current = isCurrent(item, path);
                      const Icon = item.icon;
                      return (
                        <li key={item.path}>
                          <button
                            type="button"
                            onClick={() => open(item.path)}
                            aria-current={current ? 'page' : undefined}
                            title={item.title}
                            className={cn(
                              itemClass,
                              current
                                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground before:absolute before:inset-y-0 before:left-0 before:w-[3px] before:bg-cyan-400'
                                : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
                            )}
                          >
                            <Icon className={cn('size-4 shrink-0', current ? 'text-cyan-300' : 'text-sidebar-foreground/70')} aria-hidden />
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
              <button type="button" onClick={() => void signOut()} className={cn(itemClass, 'px-3 text-sidebar-foreground hover:bg-white/5 hover:text-white')}>
                <LogOut className="size-4 shrink-0 text-sidebar-foreground/70" aria-hidden />
                <span className={labelClass}>Sign out</span>
              </button>
            </div>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar />
        <main className="min-h-0 flex-1 overflow-y-auto p-5">
          <PageView key={`${branchId}:${path}`} path={path} />
        </main>
      </div>
      <Toasts />
    </div>
  );
}

function TopBar() {
  const online = useUi((s) => s.online);
  const branch = useBranch();

  return (
    <header className="flex h-13 shrink-0 items-center gap-3 border-b border-border bg-card px-5">
      {online ? (
        <Badge tone="green">
          <Wifi size={13} /> Online
        </Badge>
      ) : (
        <Badge tone="amber">
          <CloudOff size={13} /> No connection to the restaurant: orders and bills wait until it is back
        </Badge>
      )}
      <span className="ml-auto text-xs text-muted-foreground">{branch?.name}</span>
    </header>
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
