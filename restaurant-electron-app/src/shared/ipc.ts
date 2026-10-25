/**
 * What the window asks of the main process (src/preload exposes it as
 * window.desktop). The main process holds the tokens and makes every call to
 * the platform: the page never sees a token, and no call is held up by CORS.
 */

export interface ApiRequest {
  method: string;
  /** Under {workspace}/restaurant/api/, e.g. "v1/me" or "v1/app/floor". */
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
  path: string;
  body?: unknown;
}

/** offline: the call never reached the restaurant. */
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

export interface DesktopBridge {
  api(request: ApiRequest): Promise<ApiResponse>;
  /** The platform itself (a new business), with the same sign-in. */
  platform(request: PlatformRequest): Promise<ApiResponse>;
  /** The code step continues the password's. */
  signIn(how: SignInWith): Promise<SignInResult>;
  signOut(): Promise<void>;
  signedIn(): Promise<boolean>;

  /** The app's own data on this PC (userData/data/<key>.json). */
  storeGet(key: string): Promise<string | null>;
  storeSet(key: string, value: string): Promise<void>;
  storeRemove(key: string): Promise<void>;

  /** Prints a full HTML page (a bill) with the system print dialog. */
  print(html: string): Promise<boolean>;
  openExternal(url: string): Promise<void>;
}
