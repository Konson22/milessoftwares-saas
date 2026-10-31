import type {
  CashCounterpart,
  CashDirection,
  Counter,
  CustomerOption,
  Item,
  Me,
  ReturnPayload,
  Sale,
  SalePayload,
  SaleSummary,
  Till,
} from '../types';

export interface SetUpInput {
  shop_name: string;
  address: string;
  contact: string;
  currency: string;
  decimals: number;
  manager_name: string;
  username: string;
  password: string;
  password_confirmation: string;
  /** Sample products with stock, to try the app. */
  demo: boolean;
}

/**
 * Everything the counter asks of the shop's database on this phone
 * (src/local/db/counter.ts), one call a method.
 */
export interface PosApi {
  /** Whether the shop is set up, and who is signed in while the app runs. */
  state(): Promise<{ setUp: boolean; me: Me | null }>;
  /** First run: the shop and its first manager, signed in. */
  setUp(input: SetUpInput): Promise<Me>;
  signIn(username: string, password: string): Promise<Me>;
  signOut(): Promise<void>;
  me(): Promise<Me>;

  counter(): Promise<Counter>;

  items(search: string): Promise<Item[]>;
  itemByCode(code: string): Promise<Item | null>;

  till(): Promise<Till | null>;
  openTill(openingFloatMinor: number): Promise<Till>;
  closeTill(countedCashMinor: number, closingNote: string | null): Promise<Till>;
  /** Cash into or out of the open shift; the same submission id records it once. */
  moveCash(direction: CashDirection, counterpart: CashCounterpart, amountMinor: number, reason: string, submissionId: string, categoryId?: string | null): Promise<Till>;
  /** Expense categories the drawer may pay out for. */
  expenseCategories(): Promise<{ id: string; name: string }[]>;

  sell(payload: SalePayload): Promise<{ sale: Sale; duplicate: boolean }>;
  sales(day: string): Promise<{ everyone: boolean; sales: SaleSummary[] }>;
  sale(id: string): Promise<Sale>;
  /** Logs a print; copy 1 is the original, more is a reprint. */
  printed(id: string): Promise<{ copy: number | null }>;
  voidSale(id: string, reason: string): Promise<Sale>;
  returnSale(id: string, payload: ReturnPayload): Promise<{ number: string; total_minor: number; sale: Sale }>;

  /** Customers by name, phone or number, with what they owe and may still owe. */
  customers(search: string): Promise<CustomerOption[]>;
}
