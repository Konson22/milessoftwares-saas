import type { ApiRequest, ApiResponse } from '../shared/ipc';
import { accessToken } from './auth';
import { PRODUCTS, workspaceUrl } from './config';

const TIMEOUT_MS = 15_000;

/**
 * A call to the workspace's pharmacy or shop, {workspace}/<its path>/api/<path>, with
 * the access token and the branch worked in. A rejected token is refreshed
 * once and the call retried. A call that never gets an answer (no network,
 * DNS, a timeout) comes back as offline, for the page to queue or use what it
 * saved.
 */
export async function apiRequest(request: ApiRequest, retried = false): Promise<ApiResponse> {
  const headers: Record<string, string> = {
    ...request.headers,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
  const token = await accessToken(request.product, retried);
  if (token) headers.Authorization = `Bearer ${token}`;
  if (request.branchId) headers['X-Branch-Id'] = request.branchId;

  let response: Response;
  try {
    response = await fetch(`${workspaceUrl(request.workspace)}/${PRODUCTS[request.product].path}/api/${request.path.replace(/^\//, '')}`, {
      method: request.method,
      headers,
      body: request.body === undefined ? undefined : JSON.stringify(request.body),
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

  if (response.status === 401 && !retried && json?.code === 'invalid_token') {
    return apiRequest(request, true);
  }
  return { offline: false, status: response.status, json };
}
