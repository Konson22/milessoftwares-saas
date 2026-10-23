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

/**
 * Everything the counter needs from the workspace's pharmacy
 * (pharmacy-app routes/api.php). `live` calls it; `mock` runs the same rules
 * on-device with demo data.
 */
export interface PosApi {
  /**
   * Opens a session at the workspace (its address): with the platform's ID
   * token in live mode (window.desktop.signIn), the demo account in mock mode.
   */
  signIn(workspace: string, credential: string): Promise<SignedIn>;
  signOut(): Promise<void>;
  me(): Promise<Me>;

  counter(): Promise<Counter>;

  items(search: string): Promise<Item[]>;
  itemByCode(code: string): Promise<Item | null>;

  till(): Promise<Till | null>;
  openTill(openingFloatMinor: number): Promise<Till>;
  closeTill(countedCashMinor: number, closingNote: string | null): Promise<Till>;
  /** Cash into or out of the open shift; the same submission id records it once. */
  moveCash(direction: CashDirection, counterpart: CashCounterpart, amountMinor: number, reason: string, submissionId: string): Promise<Till>;

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
