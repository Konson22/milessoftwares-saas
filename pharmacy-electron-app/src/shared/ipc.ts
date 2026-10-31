/**
 * What the window asks of the main process (src/preload exposes it as
 * window.desktop). Offline, the main process holds the pharmacy's database
 * and the person signed in, and the window asks it by name
 * (src/main/handlers.ts). Hybrid, it holds the tokens and makes every call to
 * the platform: the page never sees a token, and no call is held up by CORS.
 */

/** How this PC runs the pharmacy (src/main/mode.ts). */
export type Mode = 'offline' | 'hybrid';

export interface CallError {
  message: string;
  status: number;
  /** Per field, as a form's validation answers. */
  errors: Record<string, string[]>;
  code: string | null;
}

export type CallResult = { ok: true; value: unknown } | { ok: false; error: CallError };

/** What the counter sells for: the pharmacy, or a shop (Retail). */
export type Product = 'pharmacy' | 'retail';

export interface ApiRequest {
  method: string;
  /** Its API: {workspace}/<product's path>/api/. */
  product: Product;
  /** Under that, e.g. "v1/items?search=pan". */
  path: string;
  body?: unknown;
  workspace: string;
  branchId: string | null;
  /** Extra headers (X-App-Page). */
  headers?: Record<string, string>;
}

/**
 * A call to the platform as the person signed in: {auth host}/api/v1/account/<path>,
 * e.g. creating their business.
 */
export interface PlatformRequest {
  method: string;
  /** Whose client's token is sent. */
  product: Product;
  path: string;
  body?: unknown;
}

/** offline: the call never reached the pharmacy. */
export type ApiResponse = { offline: true } | { offline: false; status: number; json: any };

/**
 * How the member signs in on the platform: email and password on the app's
 * own screen (then the two-factor code, when the account has it), a new
 * account made there, or Google or the platform's sign-in page in the
 * system browser.
 */
export type SignInWith =
  | { with: 'password'; email: string; password: string }
  | { with: 'register'; name: string; email: string; password: string }
  | { with: 'code'; code: string; recovery?: boolean }
  | { with: 'google' }
  | { with: 'browser' };

/**
 * signedIn: the ID token, for POST session. twoFactor: send the code next.
 * refused: wrong details, shown as given; browser: true when the account must
 * finish something on the web first (a new password, say).
 */
export type SignInResult =
  | { status: 'signedIn'; idToken: string }
  | { status: 'twoFactor' }
  | { status: 'refused'; message: string; browser?: boolean };

/** A line of a receipt for a thermal printer, already fitted to its width. */
export interface PrintLine {
  text: string;
  align?: 'left' | 'center';
  bold?: boolean;
  /** Twice the height (the pharmacy's name, the total). */
  big?: boolean;
}

/** Characters per line on a thermal roll, in the printer's usual font (12×24 dots). */
export const RECEIPT_COLUMNS = { '80mm': 48, '58mm': 32 } as const;

export interface DesktopBridge {
  /** The mode chosen on this PC; null until it is. */
  getMode(): Promise<Mode | null>;
  /** Chosen (or null: ask again). Leaving offline signs out whoever was signed in on this PC. */
  setMode(mode: Mode | null): Promise<void>;

  /** Offline: the pharmacy on this PC, by name (src/main/handlers.ts). */
  call(name: string, ...args: unknown[]): Promise<CallResult>;

  /** Hybrid: the workspace's pharmacy or shop. */
  api(request: ApiRequest): Promise<ApiResponse>;
  /** The platform itself (a new business), with the same sign-in. */
  platform(request: PlatformRequest): Promise<ApiResponse>;
  /** As a member of this product (its client); the code step continues the password's. */
  signIn(how: SignInWith, product: Product): Promise<SignInResult>;
  signOut(): Promise<void>;
  signedIn(): Promise<boolean>;

  /** The app's own data on this PC (userData/data/<key>.json). */
  storeGet(key: string): Promise<string | null>;
  storeSet(key: string, value: string): Promise<void>;
  storeRemove(key: string): Promise<void>;

  /**
   * Prints a receipt to the printer set up on this PC (src/main/printer.ts):
   * the HTML page through the system, or the same receipt as lines of text
   * straight to a network thermal printer.
   */
  print(html: string, lines?: PrintLine[]): Promise<boolean>;
  openExternal(url: string): Promise<void>;
  /** The OS's view of the network: false means certainly offline. */
  netOnline(): Promise<boolean>;
}
