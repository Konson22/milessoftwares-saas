import { haptic } from '@/lib/feedback';
import type { Item } from '@/lib/types';
import { useCart } from '@/store/cart';

export type AddResult = { ok: boolean; message: string };

/** Why an item cannot go in the cart, if it cannot. */
export function blockedReason(item: Item): string | null {
  if (item.available <= 0) return item.nearest_expiry === null ? 'Out of stock here.' : 'No sellable stock here.';
  return null;
}

/** Shared add-to-cart rules for search, the barcode wedge and the camera. */
export function useAddProduct() {
  const add = useCart((s) => s.add);

  return (item: Item): AddResult => {
    const reason = blockedReason(item);
    if (reason || add(item) !== 'added') {
      haptic.error();
      return { ok: false, message: reason ?? `Only ${item.available} ${item.unit} of ${item.name} in stock.` };
    }
    haptic.success();
    return { ok: true, message: `Added ${item.name}` };
  };
}
