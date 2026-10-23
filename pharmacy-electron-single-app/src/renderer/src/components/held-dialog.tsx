import { dateTime, money } from '@/lib/format';
import { useOwner } from '@/store/auth';
import { cartTotals, useCart } from '@/store/cart';

import { Button, Modal } from './ui';

/** Carts parked on this PC by the person signed in. */
export function HeldDialog({ onClose }: { onClose: () => void }) {
  const owner = useOwner();
  const held = useCart((s) => s.held).filter((h) => h.owner === owner);
  const { resume, discardHeld } = useCart.getState();

  return (
    <Modal title="Held carts" onClose={onClose}>
      <div className="divide-y divide-border">
        {held.map((cart) => (
          <div key={cart.id} className="flex items-center gap-3 py-3">
            <div className="flex-1">
              <div className="font-medium">{cart.customer_name || cart.label || `${cart.lines.length} item(s)`}</div>
              <div className="text-xs text-muted-foreground">
                {dateTime(cart.held_at)} · {money(cartTotals(cart.lines).total)}
              </div>
            </div>
            <Button size="sm" variant="ghost" onClick={() => discardHeld(cart.id)}>
              Discard
            </Button>
            <Button
              size="sm"
              onClick={() => {
                resume(cart.id);
                onClose();
              }}
            >
              Resume
            </Button>
          </div>
        ))}
        {held.length === 0 ? <p className="text-sm text-muted-foreground">No carts held.</p> : null}
      </div>
    </Modal>
  );
}
