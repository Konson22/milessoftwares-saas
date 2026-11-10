import { HomeScreen } from './screens/home';
import { ShiftsScreen, TanksScreen } from './screens/operations';
import { SaleScreen, SalesScreen, SellScreen } from './screens/sales';
import type { Screen } from './types';

/**
 * The web page (its Inertia component) each native screen draws. A page
 * without one here is shown by GenericScreen.
 */
export const screens: Record<string, Screen> = {
  home: HomeScreen,
  'sales/create': SellScreen,
  'sales/index': SalesScreen,
  'sales/show': SaleScreen,
  'shifts/index': ShiftsScreen,
  'tanks/index': TanksScreen,
};

/** Sections others change while they are open here: online, loaded again every so many seconds. */
export const refreshEvery: Record<string, number> = { sales: 30 };
