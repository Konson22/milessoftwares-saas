import type {
  CashCounterpart,
  CashDirection,
  Counter,
  DispensePayload,
  Item,
  Me,
  Organization,
  Prescription,
  PrescriptionPayload,
  PrescriptionStatus,
  PrescriptionSummary,
  ReturnPayload,
  Sale,
  SalePayload,
  SaleSummary,
  Till,
  User,
} from '../types';

export interface SignedIn {
  user: User;
  organization: Omit<Organization, 'decimals'>;
  is_owner: boolean;
}

export interface SetUpInput {
  pharmacy_name: string;
  /** Not asked at set-up; added later under Settings. */
  address?: string;
  contact?: string;
  currency: string;
  decimals: number;
  manager_name: string;
  username: string;
  password: string;
  password_confirmation: string;
  /** Sample medicines with stock, to try the app. */
  demo: boolean;
}

/**
 * Everything the counter's screens ask of the pharmacy, in either mode: the
 * workspace's (pharmacy-app routes/api.php) or the database on this PC
 * (src/main/db/counter.ts). Signing in differs, so it is on each side's own.
 */
export interface CounterApi {
  signOut(): Promise<void>;
  me(): Promise<Me>;

  counter(): Promise<Counter>;

  items(search: string): Promise<Item[]>;
  itemByCode(code: string): Promise<Item | null>;

  till(): Promise<Till | null>;
  openTill(openingFloatMinor: number): Promise<Till>;
  closeTill(countedCashMinor: number, closingNote: string | null): Promise<Till>;
  /**
   * Cash into or out of the open shift; the same submission id records it
   * once. Offline, an expense paid from the drawer names its category.
   */
  moveCash(direction: CashDirection, counterpart: CashCounterpart, amountMinor: number, reason: string, submissionId: string, categoryId?: string | null): Promise<Till>;

  sell(payload: SalePayload): Promise<{ sale: Sale; duplicate: boolean }>;
  sales(day: string): Promise<{ everyone: boolean; sales: SaleSummary[] }>;
  sale(id: string): Promise<Sale>;
  /** Logs a print; copy 1 is the original, more is a reprint. */
  printed(id: string): Promise<{ copy: number | null }>;
  voidSale(id: string, reason: string): Promise<Sale>;
  returnSale(id: string, payload: ReturnPayload): Promise<{ number: string; total_minor: number; sale: Sale }>;

  /** Open ones (waiting, verified, partly dispensed) unless a status is named. */
  prescriptions(status: PrescriptionStatus | null, search: string): Promise<PrescriptionSummary[]>;
  prescription(id: string): Promise<Prescription>;
  recordPrescription(payload: PrescriptionPayload): Promise<Prescription>;
  verifyPrescription(id: string): Promise<Prescription>;
  rejectPrescription(id: string, reason: string): Promise<Prescription>;
  dispense(id: string, payload: DispensePayload): Promise<{ sale: { id: string; number: string; total_minor: number; change_minor: number | null }; duplicate: boolean; prescription: Prescription }>;
}

/**
 * Hybrid: the workspace's pharmacy (pharmacy-app routes/api.php). `live`
 * calls it; `mock` runs the same rules on-device with demo data.
 */
export interface OnlineApi extends CounterApi {
  /**
   * Opens a session at the workspace (its address): with the platform's ID
   * token in live mode (window.desktop.signIn), the demo account in mock mode.
   */
  signIn(workspace: string, credential: string): Promise<SignedIn>;
}

/** Offline: the pharmacy's database on this PC (src/main/handlers.ts), one call a method. */
export interface LocalApi extends CounterApi {
  /** Whether the pharmacy is set up, and who is signed in while the app runs. */
  state(): Promise<{ setUp: boolean; me: Me | null }>;
  /** First run: the pharmacy and its first manager, signed in. */
  setUp(input: SetUpInput): Promise<Me>;
  signIn(username: string, password: string): Promise<Me>;
  /** Expense categories the drawer may pay out for. */
  expenseCategories(): Promise<{ id: string; name: string }[]>;
}

/** The counter's calls, to whichever pharmacy this PC works with. */
export type PosApi = CounterApi;
