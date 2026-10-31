import { BillScreen, BillsScreen } from './screens/bills';
import { FloorScreen } from './screens/floor';
import { HomeScreen } from './screens/home';
import { KitchenScreen } from './screens/kitchen';
import { MenuFormScreen, MenuScreen } from './screens/menu';
import { OrderScreen } from './screens/order';
import { OrdersScreen } from './screens/orders';
import { SettingsScreen } from './screens/settings';
import { StockItemScreen, StockScreen } from './screens/stock';
import { TablesScreen } from './screens/tables';
import { TillScreen, TillsScreen } from './screens/tills';
import { UsersScreen } from './screens/users';
import type { Screen } from './types';

/**
 * The web page (its Inertia component) each native screen draws, online and
 * offline alike. A page without one here is shown by GenericScreen.
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
  'menu/index': MenuScreen,
  'menu/form': MenuFormScreen,
  'tables/index': TablesScreen,
  'stock/index': StockScreen,
  'stock/show': StockItemScreen,
  // Offline only: the phone's own people and details.
  'users/index': UsersScreen,
  settings: SettingsScreen,
};

/** Sections others change while they are open here: online, loaded again every so many seconds. */
export const refreshEvery: Record<string, number> = { floor: 15, kitchen: 10, orders: 15 };
