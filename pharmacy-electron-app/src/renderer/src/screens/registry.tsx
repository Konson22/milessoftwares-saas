import { DashboardScreen } from './dashboard';
import { MedicineFormScreen, MedicinesScreen } from './medicines';
import { StockItemScreen, StockScreen } from './stock';
import { SuppliersScreen } from './suppliers';
import type { Screen } from './types';

/**
 * The web page (its Inertia component) each native screen draws. A page
 * without one here is shown by GenericScreen until it has its own.
 */
export const screens: Record<string, Screen> = {
  home: DashboardScreen,
  'medicines/index': MedicinesScreen,
  'medicines/form': MedicineFormScreen,
  'stock/index': StockScreen,
  'stock/show': StockItemScreen,
  'suppliers/index': SuppliersScreen,
};
