import * as counter from './db/counter';
import { act, getPage } from './db/pages';
import { hasUsers, me, setUp, signedInUser, signIn, signOut } from './db/session';
import { LocalError } from './db/util';

/**
 * What the app may ask of the shop's database, by name (lib/api call).
 * The same calls as the desktop offline app's main process; here they run
 * in the app itself.
 */
export const handlers: Record<string, (...args: any[]) => unknown> = {
  /** On opening: whether the shop is set up, and who is signed in (kept while the app runs). */
  state: () => {
    const user = signedInUser();
    return { setUp: hasUsers(), me: user ? me(user) : null };
  },
  setUp,
  signIn,
  signOut,
  me: () => me(),

  counter: counter.counter,
  items: counter.items,
  itemByCode: counter.findByCode,
  till: counter.till,
  openTill: counter.openTill,
  closeTill: counter.closeTill,
  moveCash: counter.moveCash,
  expenseCategories: counter.expenseCategories,
  sell: counter.sell,
  sales: counter.sales,
  sale: counter.sale,
  printed: counter.printed,
  voidSale: counter.voidSale,
  returnSale: counter.returnSale,
  customers: counter.customers,

  page: getPage,
  act,
};

export { LocalError };
