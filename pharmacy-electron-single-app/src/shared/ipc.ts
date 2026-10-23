/**
 * What the window asks of the main process (src/preload exposes it as
 * window.desktop). The main process holds the pharmacy's database and the
 * person signed in; the window asks it by name (src/main/handlers.ts).
 */

export interface CallError {
  message: string;
  status: number;
  /** Per field, as a form's validation answers. */
  errors: Record<string, string[]>;
  code: string | null;
}

export type CallResult = { ok: true; value: unknown } | { ok: false; error: CallError };

export interface DesktopBridge {
  call(name: string, ...args: unknown[]): Promise<CallResult>;

  /** Small things this window keeps between runs (the cart, held carts): userData/data/<key>.json. */
  storeGet(key: string): Promise<string | null>;
  storeSet(key: string, value: string): Promise<void>;
  storeRemove(key: string): Promise<void>;

  /** Prints a full HTML page (a receipt) with the system print dialog. */
  print(html: string): Promise<boolean>;
}
