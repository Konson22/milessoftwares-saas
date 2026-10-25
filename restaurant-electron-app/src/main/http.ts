import type { ApiRequest, ApiResponse, PlatformRequest } from '../shared/ipc';
import { accessToken } from './auth';
import { AUTH_URL, PRODUCT_PATH, workspaceUrl } from './config';

const TIMEOUT_MS = 15_000;

/**
 * A call to the workspace's restaurant, {workspace}/restaurant/api/<path>, with
 * the access token and the branch worked in. A rejected token is refreshed
 * once and the call retried. A call that never gets an answer (no network,
 * DNS, a timeout) comes back as offline.
 */
export async function apiRequest(request: ApiRequest, retried = false): Promise<ApiResponse> {
  const headers: Record<string, string> = { ...request.headers };
  const token = await accessToken(retried);
  if (token) headers.Authorization = `Bearer ${token}`;
  if (request.branchId) headers['X-Branch-Id'] = request.branchId;

  const response = await send(`${workspaceUrl(request.workspace)}/${PRODUCT_PATH}/api/${request.path.replace(/^\//, '')}`, request.method, headers, request.body);

  if (!response.offline && response.status === 401 && !retried && response.json?.code === 'invalid_token') {
    return apiRequest(request, true);
  }
  return response;
}

/** The platform as the person signed in: {auth host}/api/v1/account/<path>, refreshed and retried as above. */
export async function platformRequest(request: PlatformRequest, retried = false): Promise<ApiResponse> {
  const token = await accessToken(retried);
  const response = await send(
    `${AUTH_URL}/api/v1/account/${request.path.replace(/^\//, '')}`,
    request.method,
    token ? { Authorization: `Bearer ${token}` } : {},
    request.body,
  );

  if (!response.offline && response.status === 401 && !retried && response.json?.error === 'invalid_token') {
    return platformRequest(request, true);
  }
  return response;
}

async function send(url: string, method: string, headers: Record<string, string>, body: unknown): Promise<ApiResponse> {
  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers: { ...headers, Accept: 'application/json', 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
  } catch {
    return { offline: true };
  }

  const text = await response.text().catch(() => '');
  let json: any = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }
  return { offline: false, status: response.status, json };
}
