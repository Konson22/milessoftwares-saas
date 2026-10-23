import { Minus, PauseCircle, Percent, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import { money } from '@/lib/format';
import type { Counter } from '@/lib/types';
import { useAuth, useOwner } from '@/store/auth';
import { cartTotals, linePrice, maxQuantity, needsSerials, serialsOf, useCart } from '@/store/cart';

import { CheckoutDialog } from './checkout-dialog';
import { HeldDialog } from './held-dialog';
import { Button, Empty, Input, Select } from './ui';

export function CartPanel({ counter }: { counter: Counter }) {
  const lines = useCart((s) => s.lines);
  const customerName = useCart((s) => s.customerName);
  const customerPhone = useCart((s) => s.customerPhone);
  const held = useCart((s) => s.held);
  const { setQuantity, setPack, setDiscount, setSerials, remove, setCustomer, hold } = useCart.getState();
  const shop = useAuth((s) => s.product === 'retail');
  const owner = useOwner();
  const totals = useMemo(() => cartTotals(lines), [lines]);
  const [checkout, setCheckout] = useState(false);
  const [showHeld, setShowHeld] = useState(false);
  const mineHeld = held.filter((h) => h.owner === owner).length;
  const maxDiscount = counter.can.discount ? counter.max_discount_percent : 0;
  const missingSerials = lines.filter(needsSerials);

  return (
    <aside className="flex w-[420px] shrink-0 flex-col border border-border bg-card">
      <header className="flex items-center justify-between border-b border-border px-4 py-2.5">
        <h2 className="text-sm font-semibold">Cart</h2>
        <div className="flex gap-1">
          <Button size="sm" variant="ghost" onClick={() => setShowHeld(true)} disabled={mineHeld === 0}>
            Held ({mineHeld})
          </Button>
          <Button size="sm" variant="ghost" onClick={() => hold(owner, null)} disabled={lines.length === 0}>
            <PauseCircle size={14} /> Hold
          </Button>
        </div>
      </header>

      <div className="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
        {lines.length === 0 ? (
          <Empty icon={<ShoppingCart size={28} />} title="The cart is empty">
            Click a {shop ? 'product' : 'medicine'} or scan its barcode.
          </Empty>
        ) : (
          lines.map((line) => {
            const { total, discount } = linePrice(line);
            const max = maxQuantity(line);
            return (
              <div key={line.item.id} className="space-y-2 px-4 py-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="truncate font-medium">{line.item.name}</div>
                    <div className="text-xs text-muted-foreground">{line.item.strength}</div>
                    {line.item.promotion ? (
                      <div className="mt-0.5 inline-flex items-center gap-1 bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800">
                        <Percent size={11} /> {line.item.promotion.name}: {line.item.promotion.percent_off}% off
                      </div>
                    ) : null}
                  </div>
                  <div className="text-right">
                    <div className="font-medium tabular">{money(total)}</div>
                    {discount ? <div className="text-xs text-emerald-700 tabular">-{money(discount)}</div> : null}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <div className="flex items-center border border-input">
                    <button type="button" className="p-1.5 hover:bg-accent" onClick={() => setQuantity(line.item.id, line.quantity - 1)}>
                      <Minus size={14} />
                    </button>
                    <input
                      value={line.quantity}
                      onChange={(e) => {
                        const n = parseInt(e.target.value, 10);
                        if (!Number.isNaN(n)) setQuantity(line.item.id, n);
                      }}
                      className="w-10 text-center text-sm tabular outline-none"
                    />
                    <button
                      type="button"
                      className="p-1.5 hover:bg-accent disabled:opacity-40"
                      disabled={line.quantity >= max}
                      onClick={() => setQuantity(line.item.id, line.quantity + 1)}
                    >
                      <Plus size={14} />
                    </button>
                  </div>
                  {line.item.packs.length ? (
                    <Select value={line.pack_id ?? ''} onChange={(e) => setPack(line.item.id, e.target.value || null)} className="h-8 w-auto flex-1 text-xs">
                      <option value="">By the {line.item.unit}</option>
                      {line.item.packs.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name} of {p.units}
                        </option>
                      ))}
                    </Select>
                  ) : (
                    <span className="flex-1 text-xs text-muted-foreground">{line.item.unit}</span>
                  )}
                  {maxDiscount > 0 ? (
                    <Select
                      value={line.discount_percent}
                      onChange={(e) => setDiscount(line.item.id, Number(e.target.value))}
                      className="h-8 w-20 text-xs"
                      title="Discount"
                    >
                      {Array.from({ length: maxDiscount + 1 }, (_, i) => (
                        <option key={i} value={i}>
                          {i}%
                        </option>
                      ))}
                    </Select>
                  ) : null}
                  <button type="button" className="p-1.5 text-muted-foreground hover:text-destructive" onClick={() => remove(line.item.id)}>
                    <Trash2 size={15} />
                  </button>
                </div>
                {line.item.tracks_serials ? (
                  <div className="grid grid-cols-2 gap-1.5">
                    {serialsOf(line).map((serial, i) => (
                      <Input
                        key={i}
                        value={line.serials?.[i] ?? ''}
                        onChange={(e) => {
                          const next = serialsOf(line);
                          next[i] = e.target.value;
                          setSerials(line.item.id, next);
                        }}
                        placeholder={`Serial ${i + 1}`}
                        aria-label={`Serial number ${i + 1} of ${line.item.name}`}
                        aria-invalid={serial === '' || undefined}
                        className="h-8 text-xs"
                      />
                    ))}
                  </div>
                ) : null}
              </div>
            );
          })
        )}
      </div>

      <div className="space-y-3 border-t border-border p-4">
        <div className="grid grid-cols-2 gap-2">
          <Input placeholder="Customer name" value={customerName} onChange={(e) => setCustomer({ name: e.target.value })} />
          <Input placeholder="Phone" value={customerPhone} onChange={(e) => setCustomer({ phone: e.target.value })} />
        </div>
        {totals.discount ? (
          <div className="flex justify-between text-sm text-muted-foreground">
            <span>Discount</span>
            <span className="tabular">-{money(totals.discount)}</span>
          </div>
        ) : null}
        <div className="flex items-baseline justify-between">
          <span className="text-sm text-muted-foreground">{totals.count} item(s)</span>
          <span className="text-2xl font-semibold tabular">{money(totals.total)}</span>
        </div>
        {missingSerials.length ? (
          <p className="text-xs text-amber-700">Enter the serial number of each unit of {missingSerials.map((l) => l.item.name).join(', ')}.</p>
        ) : null}
        <Button size="lg" className="w-full" disabled={lines.length === 0 || missingSerials.length > 0} onClick={() => setCheckout(true)}>
          Take payment
        </Button>
      </div>

      {checkout ? <CheckoutDialog counter={counter} onClose={() => setCheckout(false)} /> : null}
      {showHeld ? <HeldDialog onClose={() => setShowHeld(false)} /> : null}
    </aside>
  );
}
