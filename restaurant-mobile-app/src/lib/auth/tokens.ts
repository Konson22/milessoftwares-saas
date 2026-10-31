import * as Crypto from 'expo-crypto';
import * as Linking from 'expo-linking';
import * as WebBrowser from 'expo-web-browser';

import { AUTH_URL, CLIENT_ID, reach } from '../config';
import { secureStorage } from '../storage';

/**
 * Sign-in on the platform from the device (docs/architecture.md: authorization
 * code + PKCE for mobile clients). The system browser shows the platform's own
 * sign-in (with MFA, Google…), comes back to the app's redirect, and the code
 * is redeemed here for short-lived access tokens (ten minutes) and a refresh
 * token (thirty days). Nothing secret ships in the app.
 */

export interface Tokens {
  access: string;
  refresh: string | null;
  /** Epoch ms. */
  expiresAt: number;
}

export interface SignInResult extends Tokens {
  idToken: string;
}

const STORE_ACCESS = 'miles-restaurant.access';
const STORE_REFRESH = 'miles-restaurant.refresh';
const STORE_EXPIRES = 'miles-restaurant.expires';

/** Where the platform sends the browser back: must be registered for the client. */
export const redirectUri = () => Linking.createURL('oauth');

export class SignInCancelled extends Error {}

/** The account needs something done on the platform's own pages first (a new password, a verified address). */
export class NeedsBrowser extends Error {}

interface Authorization {
  url: string;
  redirect: string;
  verifier: string;
  state: string;
  nonce: string;
}

async function authorization(extra: Record<string, string> = {}): Promise<Authorization> {
  if (!CLIENT_ID) throw new Error('This app is not set up for sign-in yet (EXPO_PUBLIC_CLIENT_ID).');

  const verifier = randomString(64);
  const state = randomString(32);
  const nonce = randomString(32);
  const challenge = base64Url(
    await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, verifier, { encoding: Crypto.CryptoEncoding.BASE64 }),
  );
  const redirect = redirectUri();

  const url = `${AUTH_URL}/oauth/authorize?${query({
    client_id: CLIENT_ID,
    redirect_uri: redirect,
    response_type: 'code',
    scope: 'openid profile email',
    state,
    nonce,
    code_challenge: challenge,
    code_challenge_method: 'S256',
    ...extra,
  })}`;
  return { url, redirect, verifier, state, nonce };
}

/** The reply on the redirect URI, redeemed for the tokens. */
async function redeem(auth: Authorization, replyUrl: string): Promise<SignInResult> {
  const params = Linking.parse(replyUrl).queryParams ?? {};
  if (params.state !== auth.state) throw new Error('Sign-in failed: the reply did not match. Try again.');
  if (typeof params.code !== 'string') {
    throw new Error(typeof params.error_description === 'string' ? params.error_description : 'Sign-in failed. Try again.');
  }

  const tokens = await tokenRequest({
    grant_type: 'authorization_code',
    client_id: CLIENT_ID,
    code: params.code,
    redirect_uri: auth.redirect,
    code_verifier: auth.verifier,
  });
  if (!tokens.id_token || claims(tokens.id_token).nonce !== auth.nonce) {
    throw new Error('Sign-in failed: the reply did not match. Try again.');
  }

  const signedIn = { ...fromResponse(tokens), idToken: tokens.id_token };
  await saveTokens(signedIn);
  return signedIn;
}

/** The platform's own sign-in page in the browser (Google, or to finish what the account needs). */
export async function signInOnPlatform(): Promise<SignInResult> {
  // Phones at the pass are shared: whoever signs in proves it is them.
  const auth = await authorization({ prompt: 'login' });
  const result = await WebBrowser.openAuthSessionAsync(auth.url, auth.redirect, { preferEphemeralSession: true });
  if (result.type !== 'success') throw new SignInCancelled('Sign-in was cancelled.');
  return redeem(auth, result.url);
}

/**
 * Email and password typed in the app, signed in through the platform's own
 * web sign-in (as the desktop counter does) in a session of this sign-in
 * alone: the same checks as the web (wrong details, suspended accounts,
 * attempts per minute, two-step codes), then the authorization request,
 * whose redirect to the app carries the code.
 */
let pending: { auth: Authorization; web: WebSession } | null = null;

export async function signInWithPassword(email: string, password: string): Promise<SignInResult | 'twoFactor'> {
  pending = null;
  const auth = await authorization();
  const web = new WebSession();

  await reachable(web.get(`${AUTH_URL}/login`));
  const response = await reachable(web.post('/login', { email, password }));
  await refusal(response);

  const body = (await response.json().catch(() => null)) as { two_factor?: boolean } | null;
  if (body?.two_factor === true) {
    pending = { auth, web };
    return 'twoFactor';
  }
  return finish(auth, web);
}

/**
 * A new account, made through the platform's own web registration (the same
 * rules as the web: password strength, an address used once), then signed in
 * as with a password. The platform emails a link to confirm the address; a
 * business can be created once it is confirmed.
 */
export async function signUpWithPassword(name: string, email: string, password: string): Promise<SignInResult> {
  pending = null;
  const auth = await authorization();
  const web = new WebSession();

  await reachable(web.get(`${AUTH_URL}/register`));
  const response = await reachable(web.post('/register', { name, email, password, password_confirmation: password }));
  await refusal(response);

  return finish(auth, web);
}

/** The two-step code (or a recovery code) after a password the platform took. */
export async function signInWithCode(code: string, recovery = false): Promise<SignInResult> {
  if (!pending) throw new Error('Start again with your email and password.');
  const { auth, web } = pending;

  const response = await reachable(web.post('/two-factor-challenge', recovery ? { recovery_code: code } : { code }));
  await refusal(response);

  pending = null;
  return finish(auth, web);
}

/** Signed in: the authorization request again, now answered with a redirect to the app. */
async function finish(auth: Authorization, web: WebSession): Promise<SignInResult> {
  let location = '';
  try {
    location = (await web.get(auth.url)).headers.get('location') ?? '';
  } catch {
    // A redirect to the platform's own pages is followed, and may not resolve here.
  }
  if (location.startsWith(auth.redirect)) return redeem(auth, location);
  throw new NeedsBrowser('Your account needs something done on the web first. Sign in with the browser to finish.');
}

async function reachable(response: Promise<Response>): Promise<Response> {
  try {
    return await response;
  } catch {
    throw new Error('Cannot reach the sign-in service. Check the connection.');
  }
}

async function refusal(response: Response) {
  if (response.ok) return;
  const json = (await response.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
  if (response.status === 422) {
    const first = Object.values(json?.errors ?? {})[0]?.[0];
    throw new Error(first ?? json?.message ?? 'Those details were not accepted.');
  }
  if (response.status === 429) throw new Error('Too many attempts. Wait a minute, then try again.');
  // 419: the sign-in session ran out (a code entered long after the password).
  if (response.status === 419) {
    pending = null;
    throw new Error('That took too long. Start again with your email and password.');
  }
  throw new Error('Sign-in failed. Try again.');
}

/**
 * The cookies of one sign-in, kept here rather than in the app's shared
 * cookie store, so each sign-in starts fresh. Laravel's CSRF check takes the
 * XSRF-TOKEN cookie back as a header. A redirect to the app is not followed:
 * it comes back as the response, its Location carrying the code.
 */
class WebSession {
  private readonly cookies = new Map<string, string>();

  get(url: string): Promise<Response> {
    return this.send(url, {});
  }

  post(path: string, body: unknown): Promise<Response> {
    return this.send(`${AUTH_URL}${path}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(this.cookies.get('XSRF-TOKEN') ?? ''),
      },
      body: JSON.stringify(body),
    });
  }

  private async send(url: string, init: RequestInit): Promise<Response> {
    const target = reach(url);
    const response = await fetch(target.url, {
      ...init,
      credentials: 'omit',
      headers: {
        Origin: AUTH_URL,
        Referer: `${AUTH_URL}/login`,
        Cookie: [...this.cookies].map(([name, value]) => `${name}=${value}`).join('; '),
        ...(init.headers as Record<string, string> | undefined),
        ...target.headers,
      },
    });
    // Several Set-Cookie headers arrive joined by commas; dates hold commas too.
    for (const header of (response.headers.get('set-cookie') ?? '').split(/,(?=\s*[A-Za-z0-9_.-]+=)/)) {
      const pair = header.split(';')[0].trim();
      const at = pair.indexOf('=');
      if (at > 0) this.cookies.set(pair.slice(0, at), pair.slice(at + 1));
    }
    return response;
  }
}

let current: Tokens | null = null;
let refreshing: Promise<Tokens | null> | null = null;

export async function loadTokens(): Promise<Tokens | null> {
  const [access, refresh, expires] = await Promise.all([
    secureStorage.get(STORE_ACCESS),
    secureStorage.get(STORE_REFRESH),
    secureStorage.get(STORE_EXPIRES),
  ]);
  current = access ? { access, refresh, expiresAt: Number(expires ?? 0) } : null;
  return current;
}

export async function saveTokens(tokens: Tokens | null) {
  current = tokens;
  if (!tokens) {
    await Promise.all([secureStorage.remove(STORE_ACCESS), secureStorage.remove(STORE_REFRESH), secureStorage.remove(STORE_EXPIRES)]);
    return;
  }
  // Kept apart: each fits the secure store's per-item limit.
  await Promise.all([
    secureStorage.set(STORE_ACCESS, tokens.access),
    tokens.refresh ? secureStorage.set(STORE_REFRESH, tokens.refresh) : secureStorage.remove(STORE_REFRESH),
    secureStorage.set(STORE_EXPIRES, String(tokens.expiresAt)),
  ]);
}

/**
 * A usable access token: refreshed when it expires within half a minute, or
 * when the server just refused it. Concurrent callers share one refresh.
 */
export async function accessToken(force = false): Promise<string | null> {
  if (!current) return null;
  if (!force && current.expiresAt - Date.now() > 30_000) return current.access;

  refreshing ??= refresh().finally(() => {
    refreshing = null;
  });
  return (await refreshing)?.access ?? null;
}

async function refresh(): Promise<Tokens | null> {
  if (!current?.refresh) return null;
  try {
    const next = fromResponse(
      await tokenRequest({ grant_type: 'refresh_token', client_id: CLIENT_ID, refresh_token: current.refresh, scope: 'openid profile email' }),
    );
    await saveTokens(next);
    return next;
  } catch (error) {
    // Offline: keep the tokens, the next call tries again. Refused: signed out.
    if (error instanceof TokenRefused) await saveTokens(null);
    return null;
  }
}

class TokenRefused extends Error {}

interface TokenResponse {
  access_token: string;
  refresh_token?: string;
  id_token?: string;
  expires_in: number;
}

async function tokenRequest(body: Record<string, string>): Promise<TokenResponse> {
  const target = reach(`${AUTH_URL}/oauth/token`);
  const response = await fetch(target.url, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', ...target.headers },
    body: query(body),
  });
  const json = await response.json().catch(() => null);
  if (!response.ok || typeof json?.access_token !== 'string') {
    throw new TokenRefused(json?.error_description ?? json?.message ?? 'Sign-in failed. Try again.');
  }
  return json as TokenResponse;
}

function fromResponse(tokens: TokenResponse): Tokens {
  return {
    access: tokens.access_token,
    refresh: tokens.refresh_token ?? current?.refresh ?? null,
    expiresAt: Date.now() + tokens.expires_in * 1000,
  };
}

/** Unverified read of a token's claims (the server verifies it). */
function claims(jwt: string): Record<string, unknown> {
  try {
    const payload = jwt.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    return JSON.parse(decodeURIComponent(escape(atob(payload.padEnd(Math.ceil(payload.length / 4) * 4, '=')))));
  } catch {
    return {};
  }
}

function randomString(length: number): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
  return Array.from(Crypto.getRandomBytes(length), (b) => alphabet[b % alphabet.length]).join('');
}

function base64Url(base64: string): string {
  return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function query(params: Record<string, string>): string {
  return Object.entries(params)
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join('&');
}
