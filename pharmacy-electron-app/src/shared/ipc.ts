/**
 * What the window asks of the main process (src/preload exposes it as
 * window.desktop). The main process holds the tokens and makes every call to
 * the platform: the page never sees a token, and no call is held up by CORS.
 */

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

/** offline: the call never reached the pharmacy. */
export type ApiResponse = { offline: true } | { offline: false; status: number; json: any };

/**
 * How the member signs in on the platform: email and password on the app's
 * own screen (then the two-factor code, when the account has it), or Google
 * or the platform's sign-in page in the system browser.
 */
export type SignInWith =
  | { with: 'password'; email: string; password: string }
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

export interface DesktopBridge {
  api(request: ApiRequest): Promise<ApiResponse>;
  /** As a member of this product (its client); the code step continues the password's. */
  signIn(how: SignInWith, product: Product): Promise<SignInResult>;
  signOut(): Promise<void>;
  signedIn(): Promise<boolean>;

  /** The app's own data on this PC (userData/data/<key>.json). */
  storeGet(key: string): Promise<string | null>;
  storeSet(key: string, value: string): Promise<void>;
  storeRemove(key: string): Promise<void>;

  /** Prints a full HTML page (a receipt) with the system print dialog. */
  print(html: string): Promise<boolean>;
  openExternal(url: string): Promise<void>;
  /** The OS's view of the network: false means certainly offline. */
  netOnline(): Promise<boolean>;
}
