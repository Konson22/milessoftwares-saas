import { BillScreen, BillsScreen } from './bills';
import { FloorScreen } from './floor';
import { HomeScreen } from './home';
import { KitchenScreen } from './kitchen';
import { OrderScreen } from './order';
import { OrdersScreen } from './orders';
import { TillScreen, TillsScreen } from './tills';
import type { Screen } from './types';

/**
 * The web page (its Inertia component) each native screen draws. A page
 * without one here (the menu, tables, drinks stock: set-up done on the web)
 * is shown by GenericScreen.
 */
export const screens: Record<string, Screen> = {
  home: HomeScreen,
  floor: FloorScreen,
  'orders/index': OrdersScreen,
  'orders/show': OrderScreen,
  kitchen: KitchenScreen,
  'bills/index': BillsScreen,
  'bills/show': BillScreen,
  'tills/index': TillsScreen,
  'tills/show': TillScreen,
};

/** Sections others change while they are open here: loaded again every so many seconds. */
export const refreshEvery: Record<string, number> = { floor: 15, kitchen: 10, orders: 15 };
