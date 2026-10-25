import { createHash, randomBytes } from 'node:crypto';
import { readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { join } from 'node:path';

import { app, safeStorage, shell } from 'electron';

import type { SignInResult, SignInWith } from '../shared/ipc';
import { AUTH_URL, CLIENT_ID, OAUTH_PORT } from './config';

/**
 * Sign-in on the platform from the PC (authorization code + PKCE, as the
 * mobile app does), with email and password on the app's own screen, or
 * with Google or the platform's page in the system browser, which comes back
 * to a loopback address this process listens on for the one reply (RFC 8252).
 * The code is redeemed here for a ten-minute access token and a thirty-day
 * refresh token, kept encrypted by the OS (safeStorage).
 */

interface Tokens {
  access: string;
  refresh: string | null;
  /** Epoch ms. */
  expiresAt: number;
}

const NAME = 'Miles Restaurant';

const SIGN_IN_TIMEOUT_MS = 5 * 60_000;
const redirectUri = () => `http://127.0.0.1:${OAUTH_PORT}/oauth`;
const tokensFile = () => join(app.getPath('userData'), 'tokens.bin');

let current: Tokens | null = null;
let loaded = false;
let refreshing: Promise<Tokens | null> | null = null;

export class TokenRefused extends Error {}

/**
 * Never throws: what went wrong comes back as `refused`, worded for the
 * member (errors crossing IPC lose their class and gain a prefix).
 */
export async function signIn(how: SignInWith): Promise<SignInResult> {
  if (!CLIENT_ID) {
    return { status: 'refused', message: `This app is not set up for sign-in to ${NAME} yet (VITE_CLIENT_ID).` };
  }
  try {
    switch (how.with) {
      case 'password':
        return await withPassword(how.email, how.password);
      case 'register':
        return await withNewAccount(how.name, how.email, how.password);
      case 'code':
        return await withCode(how.code, how.recovery ?? false);
      case 'google':
        return await inBrowser('google');
      case 'browser':
        return await inBrowser(null);
    }
  } catch (error) {
    // fetch fails with a TypeError when the auth host cannot be reached.
    const message = error instanceof TypeError ? 'Cannot reach the sign-in service. Check the connection.' : (error as Error).message;
    return { status: 'refused', message: message || 'Sign-in failed. Try again.' };
  }
}

interface Authorization {
  url: string;
  verifier: string;
  state: string;
  nonce: string;
}

function authorization(extra: Record<string, string> = {}): Authorization {
  const verifier = base64Url(randomBytes(48));
  const state = base64Url(randomBytes(24));
  const nonce = base64Url(randomBytes(24));
  const url = `${AUTH_URL}/oauth/authorize?${query({
    client_id: CLIENT_ID,
    redirect_uri: redirectUri(),
    response_type: 'code',
    scope: 'openid profile email',
    state,
    nonce,
    code_challenge: base64Url(createHash('sha256').update(verifier).digest()),
    code_challenge_method: 'S256',
    ...extra,
  })}`;
  return { url, verifier, state, nonce };
}

/** The reply on the redirect URI, redeemed for the tokens. */
async function redeem(auth: Authorization, params: URLSearchParams): Promise<SignInResult> {
  if (params.get('state') !== auth.state) throw new Error('Sign-in failed: the reply did not match. Try again.');
  const code = params.get('code');
  if (!code) throw new Error(params.get('error_description') ?? 'Sign-in was cancelled.');

  const tokens = await tokenRequest({
    grant_type: 'authorization_code',
    client_id: CLIENT_ID,
    code,
    redirect_uri: redirectUri(),
    code_verifier: auth.verifier,
  });
  if (!tokens.id_token || claims(tokens.id_token).nonce !== auth.nonce) {
    throw new Error('Sign-in failed: the reply did not match. Try again.');
  }

  await saveTokens(fromResponse(tokens));
  return { status: 'signedIn', idToken: tokens.id_token };
}

/**
 * Google, or the platform's own page, in the system browser. Google refuses
 * sign-in inside app windows, so it is never shown in this one. Counter PCs
 * are shared: whoever signs in proves it is them (prompt=login).
 */
async function inBrowser(provider: 'google' | null): Promise<SignInResult> {
  const auth = authorization({ prompt: 'login', ...(provider ? { idp_hint: provider } : {}) });
  return redeem(auth, await waitForRedirect(auth.url, NAME));
}

/**
 * Email and password typed here, signed in through the platform's own web
 * sign-in (Fortify) in a session of this process alone: the same checks as
 * the web (wrong details, suspended accounts, attempts per minute, two-factor),
 * then the same authorization request, whose reply is read here instead of
 * on the loopback port. A fresh session each time, so it is always this
 * member who signs in.
 */
let pending: { auth: Authorization; web: WebSession } | null = null;

async function withPassword(email: string, password: string): Promise<SignInResult> {
  pending = null;
  const auth = authorization();
  const web = new WebSession();

  // Not signed in: on to the sign-in page, the request kept for afterwards.
  await web.get(auth.url);

  const response = await web.post('/login', { email, password });
  const refused = await refusal(response);
  if (refused) return refused;

  const body = (await response.json().catch(() => null)) as { two_factor?: boolean } | null;
  if (body?.two_factor === true) {
    pending = { auth, web };
    return { status: 'twoFactor' };
  }
  return finish(auth, web);
}

/**
 * A new account, made through the platform's own web registration (the same
 * rules as the web: password strength, an address used once), then signed in
 * as with a password. The platform emails a link to confirm the address; a
 * business can be created once it is confirmed.
 */
async function withNewAccount(name: string, email: string, password: string): Promise<SignInResult> {
  pending = null;
  const auth = authorization();
  const web = new WebSession();

  await web.get(auth.url);

  const response = await web.post('/register', { name, email, password, password_confirmation: password });
  const refused = await refusal(response);
  if (refused) return refused;

  return finish(auth, web);
}

async function withCode(code: string, recovery: boolean): Promise<SignInResult> {
  if (!pending) return { status: 'refused', message: 'Start again with your email and password.' };
  const { auth, web } = pending;

  const response = await web.post('/two-factor-challenge', recovery ? { recovery_code: code } : { code });
  const refused = await refusal(response);
  if (refused) return refused;

  pending = null;
  return finish(auth, web);
}

/** Signed in: the authorization request again, now answered with the code. */
async function finish(auth: Authorization, web: WebSession): Promise<SignInResult> {
  const location = (await web.get(auth.url)).headers.get('location') ?? '';
  if (location.startsWith(`${redirectUri()}?`)) return redeem(auth, new URL(location).searchParams);

  // Somewhere else first: a new password to choose, an address to verify.
  return { status: 'refused', message: 'Your account needs something done on the web first. Sign in with the browser to finish.', browser: true };
}

async function refusal(response: Response): Promise<SignInResult | null> {
  if (response.ok) return null;
  const json = (await response.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
  if (response.status === 422) {
    const first = Object.values(json?.errors ?? {})[0]?.[0];
    return { status: 'refused', message: first ?? json?.message ?? 'Those details were not accepted.' };
  }
  if (response.status === 429) return { status: 'refused', message: 'Too many attempts. Wait a minute, then try again.' };
  // 419: the sign-in session ran out (a code entered long after the password).
  if (response.status === 419) {
    pending = null;
    return { status: 'refused', message: 'That took too long. Start again with your email and password.' };
  }
  throw new Error('Sign-in failed. Try again.');
}

/**
 * The cookies of one sign-in, sent back as a browser would. Laravel's CSRF
 * check takes the XSRF-TOKEN cookie back as a header. Redirects are read, not
 * followed: the last one carries the code.
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
    const response = await fetch(url, {
      ...init,
      redirect: 'manual',
      headers: {
        Origin: AUTH_URL,
        Referer: `${AUTH_URL}/login`,
        Cookie: [...this.cookies].map(([name, value]) => `${name}=${value}`).join('; '),
        ...(init.headers as Record<string, string> | undefined),
      },
    });
    for (const header of response.headers.getSetCookie()) {
      const pair = header.split(';')[0];
      const at = pair.indexOf('=');
      if (at > 0) this.cookies.set(pair.slice(0, at).trim(), pair.slice(at + 1).trim());
    }
    return response;
  }
}

/** Opens the browser and waits for its one request to the loopback address. */
function waitForRedirect(url: string, name: string): Promise<URLSearchParams> {
  return new Promise((resolve, reject) => {
    const server = createServer((req, res) => {
      const reply = new URL(req.url ?? '/', redirectUri());
      if (reply.pathname !== '/oauth') {
        res.writeHead(404).end();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(
        `<!doctype html><meta charset="utf-8"><title>${name}</title>` +
          '<body style="font-family:system-ui;text-align:center;padding:4rem">' +
          `<h2>You are signed in.</h2><p>Close this tab and go back to ${name}.</p></body>`,
      );
      done(() => resolve(reply.searchParams));
    });

    const timer = setTimeout(() => done(() => reject(new Error('Sign-in took too long. Try again.'))), SIGN_IN_TIMEOUT_MS);
    const done = (settle: () => void) => {
      clearTimeout(timer);
      server.close();
      settle();
    };

    server.once('error', (error: NodeJS.ErrnoException) =>
      done(() =>
        reject(new Error(error.code === 'EADDRINUSE' ? `Port ${OAUTH_PORT} is in use: close the other sign-in and try again.` : error.message)),
      ),
    );
    server.listen(OAUTH_PORT, '127.0.0.1', () => void shell.openExternal(url));
  });
}

export async function signOut() {
  await saveTokens(null);
}

export async function signedIn(): Promise<boolean> {
  return (await loadTokens()) !== null;
}

async function loadTokens(): Promise<Tokens | null> {
  if (loaded) return current;
  loaded = true;
  try {
    const raw = await readFile(tokensFile());
    const text = safeStorage.isEncryptionAvailable() ? safeStorage.decryptString(raw) : raw.toString('utf8');
    current = JSON.parse(text) as Tokens;
  } catch {
    current = null;
  }
  return current;
}

async function saveTokens(tokens: Tokens | null) {
  current = tokens;
  loaded = true;
  if (!tokens) {
    await rm(tokensFile(), { force: true });
    return;
  }
  const text = JSON.stringify(tokens);
  await writeFile(tokensFile(), safeStorage.isEncryptionAvailable() ? safeStorage.encryptString(text) : text);
}

/**
 * A usable access token: refreshed when it expires within half a minute, or
 * when the server just refused it. Concurrent callers share one refresh.
 */
export async function accessToken(force = false): Promise<string | null> {
  const tokens = await loadTokens();
  if (!tokens) return null;
  if (!force && tokens.expiresAt - Date.now() > 30_000) return tokens.access;

  refreshing ??= refresh().finally(() => {
    refreshing = null;
  });
  // Offline with an expired token: send it anyway, the call fails as offline.
  return (await refreshing)?.access ?? current?.access ?? null;
}

async function refresh(): Promise<Tokens | null> {
  if (!current?.refresh) return null;
  try {
    const next = fromResponse(await tokenRequest({ grant_type: 'refresh_token', client_id: CLIENT_ID, refresh_token: current.refresh, scope: 'openid profile email' }));
    await saveTokens(next);
    return next;
  } catch (error) {
    // Offline: keep the tokens, the next call tries again. Refused: signed out.
    if (error instanceof TokenRefused) await saveTokens(null);
    return null;
  }
}

interface TokenResponse {
  access_token: string;
  refresh_token?: string;
  id_token?: string;
  expires_in: number;
}

async function tokenRequest(body: Record<string, string>): Promise<TokenResponse> {
  const response = await fetch(`${AUTH_URL}/oauth/token`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: query(body),
  });
  const json = (await response.json().catch(() => null)) as any;
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
    return JSON.parse(Buffer.from(jwt.split('.')[1], 'base64url').toString('utf8'));
  } catch {
    return {};
  }
}

const base64Url = (bytes: Buffer) => bytes.toString('base64url');

function query(params: Record<string, string>): string {
  return new URLSearchParams(params).toString();
}
