import { BatchesScreen } from './screens/batches';
import { CustomerScreen, CustomersScreen } from './screens/customers';
import { ExpenseCategoriesScreen, ExpensesScreen } from './screens/expenses';
import { InvoicesScreen } from './screens/invoices';
import { ProductFormScreen, ProductsScreen } from './screens/products';
import { QuotationFormScreen, QuotationScreen, QuotationsScreen } from './screens/quotations';
import { ReceiptFormScreen, ReceiptScreen, ReceiptsScreen } from './screens/receipts';
import { ReportsScreen } from './screens/reports';
import { SettingsScreen } from './screens/settings';
import { StockItemScreen, StockScreen } from './screens/stock';
import { SuppliersScreen } from './screens/suppliers';
import { UsersScreen } from './screens/users';
import type { Screen } from './types';

/**
 * The page (its component, src/local/db/pages.ts) each native screen draws.
 * The dashboard ("home") is the Home tab. A page without one here is shown
 * by GenericScreen, as data.
 */
export const screens: Record<string, Screen> = {
  'products/index': ProductsScreen,
  'products/form': ProductFormScreen,
  'stock/index': StockScreen,
  'stock/show': StockItemScreen,
  'batches/index': BatchesScreen,
  'suppliers/index': SuppliersScreen,
  'customers/index': CustomersScreen,
  'customers/show': CustomerScreen,
  'invoices/index': InvoicesScreen,
  'quotations/index': QuotationsScreen,
  'quotations/form': QuotationFormScreen,
  'quotations/show': QuotationScreen,
  'receipts/index': ReceiptsScreen,
  'receipts/form': ReceiptFormScreen,
  'receipts/show': ReceiptScreen,
  'expenses/index': ExpensesScreen,
  'expenses/categories': ExpenseCategoriesScreen,
  'reports/index': ReportsScreen,
  'users/index': UsersScreen,
  'settings/index': SettingsScreen,
};
