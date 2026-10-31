import { CloudOff, Loader2, PackageSearch, ScanBarcode, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import { CartPanel } from '@/components/cart-panel';
import { Badge, Button, cn, Empty, Input, Notice } from '@/components/ui';
import { useCounter } from '@/hooks/use-counter';
import { api, errorMessage } from '@/lib/api';
import { PRODUCTS } from '@/lib/config';
import { dateTime, daysUntil, inSecond, money } from '@/lib/format';
import type { Item } from '@/lib/types';
import { findByCode, searchCatalog, useCatalog, withPending } from '@/offline/catalog';
import { useConnectivity } from '@/offline/connectivity';
import { pendingUnits, useOutbox } from '@/offline/outbox';
import { useAuth, useLocal, useOwner } from '@/store/auth';
import { useCart } from '@/store/cart';
import { toast, useUi } from '@/store/ui';

export function SellPage() {
  const { counter, till, tillLoaded, staleSince, error } = useCounter();
  const go = useUi((s) => s.go);
  const product = useAuth((s) => s.product);
  const local = useLocal();

  if (!counter) {
    if (!error) return <Loader2 className="mx-auto mt-20 animate-spin text-muted-foreground" />;
    return local ? (
      <Notice tone="red">{error}</Notice>
    ) : (
      <Notice tone="amber">
        {error} This branch's counter has not been loaded on this PC yet: connect once and it is kept for working offline.
      </Notice>
    );
  }

  if (!counter.can.sell) {
    return <Notice>{local ? 'You cannot sell. Ask a manager for a selling role.' : 'You cannot sell at this branch. Ask the owner for a selling role.'}</Notice>;
  }
  if (counter.counter.sells_direct === false) {
    return <Notice>This branch takes an order first or hands over unpaid ({counter.counter.workflow_label}): sell on the web counter.</Notice>;
  }
  if (counter.counter.shifts_required && tillLoaded && !till) {
    return (
      <Empty icon={<ScanBarcode size={32} />} title="Open your shift to start selling">
        <p className="mb-3">Count the float into the drawer, then sell.</p>
        <Button onClick={() => go('shift')}>Open shift</Button>
      </Empty>
    );
  }

  return (
    <div className="flex h-full gap-5">
      <div className="flex min-w-0 flex-1 flex-col gap-3">
        {staleSince ? (
          <Notice>
            <CloudOff size={14} className="mr-1 inline" /> Working offline with what the {PRODUCTS[product].place} said at {dateTime(staleSince)}. Sales are kept
            on this PC and sent when the connection is back.
          </Notice>
        ) : null}
        {local ? <LocalSearch /> : <CatalogSearch />}
      </div>
      <CartPanel counter={counter} />
    </div>
  );
}

/** Offline: the database on this PC answers each search, with stock and prices as they are now. */
function LocalSearch() {
  const [search, setSearch] = useState('');
  const [results, setResults] = useState<Item[] | null>(null);
  const refreshItem = useCart((s) => s.refreshItem);
  // Again after each sale: stock has changed.
  const submissionId = useCart((s) => s.submissionId);

  const find = useCallback(
    (q: string) =>
      api
        .items(q)
        .then((found) => {
          setResults(found);
          // Prices and stock as they are now, in the cart too.
          for (const line of useCart.getState().lines) {
            const fresh = found.find((i) => i.id === line.item.id);
            if (fresh) refreshItem(fresh);
          }
        })
        .catch((e) => toast(errorMessage(e), 'red')),
    [refreshItem],
  );

  useEffect(() => {
    const timer = setTimeout(() => void find(search), search ? 120 : 0);
    return () => clearTimeout(timer);
  }, [search, find, submissionId]);

  return (
    <SearchPanel
      search={search}
      setSearch={setSearch}
      results={results}
      byCode={(code) => api.itemByCode(code).catch(() => null)}
      shop={false}
      empty={
        <Empty icon={<PackageSearch size={32} />} title={search ? 'Nothing found' : 'No medicines yet'}>
          {search ? 'Check the spelling, or scan the barcode.' : 'Add them under Medicines, then their stock under Receive stock.'}
        </Empty>
      }
    />
  );
}

/**
 * Hybrid: the branch's catalog saved on this PC, so the counter finds what it
 * sells with no connection; online, the pharmacy's own search tops it up.
 */
function CatalogSearch() {
  const [search, setSearch] = useState('');
  const items = useCatalog((s) => s.items);
  const syncedAt = useCatalog((s) => s.syncedAt);
  const syncing = useCatalog((s) => s.syncing);
  const merge = useCatalog((s) => s.merge);
  const online = useConnectivity((s) => s.online);
  const owner = useOwner();
  const entries = useOutbox((s) => s.entries);
  const pending = useMemo(() => pendingUnits(entries, owner), [entries, owner]);
  const shop = useAuth((s) => s.product === 'retail');
  const things = shop ? 'products' : 'medicines';

  const results = useMemo(() => searchCatalog(items, search).map((i) => withPending(i, pending)), [items, search, pending]);

  // Online, the pharmacy's (or shop's) own search adds what the catalog lacks and the latest stock.
  useEffect(() => {
    if (!online || !search.trim()) return;
    const timer = setTimeout(() => void api.items(search).then(merge, () => {}), 300);
    return () => clearTimeout(timer);
  }, [search, online, merge]);

  const byCode = async (code: string) => {
    let item = findByCode(items, code);
    if (!item && online) {
      item = await api.itemByCode(code).catch(() => null);
      if (item) merge([item]);
    }
    return item ? withPending(item, pending) : null;
  };

  return (
    <SearchPanel
      search={search}
      setSearch={setSearch}
      results={results}
      byCode={byCode}
      shop={shop}
      status={
        syncing
          ? `Updating ${things}…`
          : syncedAt
            ? `${Object.keys(items).length} ${things} · ${dateTime(syncedAt)}`
            : `${shop ? 'Products' : 'Medicines'} not loaded yet`
      }
      empty={
        <Empty icon={<PackageSearch size={32} />} title={search ? 'Nothing found' : `No ${things} on this PC yet`}>
          {search ? 'Check the spelling, or scan the barcode.' : 'They load the first time the counter is online.'}
        </Empty>
      }
    />
  );
}

interface SearchPanelProps {
  search: string;
  setSearch: (search: string) => void;
  /** null: still loading. */
  results: Item[] | null;
  /** The item a barcode names, if any. */
  byCode: (code: string) => Promise<Item | null>;
  shop: boolean;
  status?: string;
  empty: ReactNode;
}

function SearchPanel({ search, setSearch, results, byCode, shop, status, empty }: SearchPanelProps) {
  const input = useRef<HTMLInputElement>(null);
  const add = useCart((s) => s.add);
  const local = useLocal();

  useEffect(() => {
    const focus = (e: KeyboardEvent) => {
      if (e.key === '/' && document.activeElement?.tagName !== 'INPUT') {
        e.preventDefault();
        input.current?.focus();
      }
    };
    window.addEventListener('keydown', focus);
    return () => window.removeEventListener('keydown', focus);
  }, []);

  const addItem = (item: Item) => {
    const result = add(item);
    if (result === 'restricted') {
      toast(`${item.name} is prescription-only: dispense it from a verified prescription ${local ? '(Prescriptions, F4)' : 'on the web'}.`, 'amber');
    } else if (result === 'blocked') {
      toast(`No more ${item.name} in stock here.`, 'red');
    }
  };

  // A scanner types the barcode and Enter.
  const onEnter = async () => {
    const code = search.trim();
    if (!code) return;
    const item = await byCode(code);
    if (item) {
      addItem(item);
      setSearch('');
    } else if (results?.length === 1) {
      addItem(results[0]);
      setSearch('');
    }
  };

  return (
    <div className="flex min-h-0 flex-1 flex-col border border-border bg-card">
      <div className="flex items-center gap-3 border-b border-border p-3">
        <div className="relative flex-1">
          <Search size={16} className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground" />
          <Input
            ref={input}
            autoFocus
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && void onEnter()}
            placeholder={shop ? 'Search by name or scan a barcode  ( / )' : 'Search by name, generic name or scan a barcode  ( / )'}
            className="h-11 pl-9 text-base"
          />
        </div>
        {status ? <span className="text-xs text-muted-foreground">{status}</span> : null}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        {results === null ? (
          <Loader2 className="mx-auto mt-10 animate-spin text-muted-foreground" />
        ) : results.length === 0 ? (
          empty
        ) : (
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-muted text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-4 py-2 font-medium">{shop ? 'Product' : 'Medicine'}</th>
                <th className="px-4 py-2 font-medium">Stock</th>
                {shop ? null : <th className="px-4 py-2 font-medium">Expiry</th>}
                <th className="px-4 py-2 text-right font-medium">Price</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {results.map((item) => (
                <ProductRow key={item.id} item={item} shop={shop} onAdd={() => addItem(item)} />
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function ProductRow({ item, shop, onAdd }: { item: Item; shop: boolean; onAdd: () => void }) {
  const out = item.available <= 0;
  const expiresIn = item.nearest_expiry ? daysUntil(item.nearest_expiry) : null;
  return (
    <tr onClick={onAdd} className={cn('cursor-pointer hover:bg-accent', out && 'opacity-50')}>
      <td className="px-4 py-2.5">
        <div className="flex items-center gap-2 font-medium">
          {item.name} {item.strength ? <span className="font-normal text-muted-foreground">{item.strength}</span> : null}
          {item.restricted ? <Badge tone="blue">Rx</Badge> : null}
          {item.controlled ? <Badge tone="red">Controlled</Badge> : null}
          {item.promotion ? <Badge tone="green">{item.promotion.percent_off}% off</Badge> : null}
          {item.tracks_serials ? <Badge>Serial</Badge> : null}
        </div>
        <div className="text-xs text-muted-foreground">
          {[item.generic_name, item.form, item.storage].filter(Boolean).join(' · ')}
          {item.packs.length ? ` · ${item.packs.map((p) => `${p.name} of ${p.units}`).join(', ')}` : ''}
        </div>
      </td>
      <td className="px-4 py-2.5 tabular">
        {out ? <Badge tone="red">Out</Badge> : `${item.available} ${item.unit}${item.available === 1 ? '' : 's'}`}
      </td>
      {shop ? null : (
        <td className="px-4 py-2.5 text-xs">
          {expiresIn === null ? '—' : <span className={cn(expiresIn < 60 && 'font-medium text-amber-700')}>{item.nearest_expiry}</span>}
        </td>
      )}
      <td className="px-4 py-2.5 text-right tabular">
        <UnitPrice item={item} />
      </td>
    </tr>
  );
}

/**
 * A unit's price now: offline, the batch selling first, with a later batch at
 * another price named; and in the second currency when there is one.
 */
function UnitPrice({ item }: { item: Item }) {
  const tiers = item.price_tiers ?? [];
  const now = tiers[0]?.unit_price ?? item.price_minor;
  const next = tiers.find((t) => t.unit_price !== now);
  const other = inSecond(now);
  return (
    <>
      <div className="font-medium">{money(now)}</div>
      {other ? <div className="text-xs text-muted-foreground">{other}</div> : null}
      {next ? (
        <div className="text-xs text-amber-700">
          after {tiers[0].units}: {money(next.unit_price)}
        </div>
      ) : null}
    </>
  );
}
