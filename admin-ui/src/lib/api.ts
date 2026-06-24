// Thin fetch client for the YaAff admin JSON endpoints. All requests carry the
// session cookie (credentials: same-origin) and normalize the `{ok,error}`
// envelope the PHP layer returns.

declare global {
  interface Window {
    __YAAFF__?: { apiBase: string; version: string };
  }
}

export const API_BASE: string = window.__YAAFF__?.apiBase ?? '/admin/';
export const APP_VERSION: string = window.__YAAFF__?.version ?? 'dev';

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.status = status;
    this.name = 'ApiError';
  }
}

type Json = Record<string, unknown>;

async function parse<T>(res: Response): Promise<T> {
  const text = await res.text();
  let data: unknown = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    throw new ApiError(`Invalid JSON from server (HTTP ${res.status})`, res.status);
  }
  const obj = (data ?? {}) as Json;
  if (!res.ok || obj.ok === false) {
    const msg = (obj.error as string) || `Request failed (HTTP ${res.status})`;
    throw new ApiError(msg, res.status);
  }
  return obj as T;
}

function url(path: string, params?: Record<string, string | number | undefined>): string {
  const u = new URL(API_BASE + path, window.location.origin);
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null) u.searchParams.set(k, String(v));
    }
  }
  return u.toString();
}

export async function apiGet<T>(
  path: string,
  params?: Record<string, string | number | undefined>,
): Promise<T> {
  const res = await fetch(url(path, params), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  return parse<T>(res);
}

export async function apiSend<T>(
  path: string,
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  body?: unknown,
  params?: Record<string, string | number | undefined>,
): Promise<T> {
  const res = await fetch(url(path, params), {
    method,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  return parse<T>(res);
}

// --- Endpoint helpers -----------------------------------------------------

export const spa = {
  bootstrap: () => apiGet<import('./types').BootstrapResponse>('spa.php', { r: 'bootstrap' }),
  campaigns: (range?: { start: number; end: number }) =>
    apiGet<import('./types').CampaignsResponse>('spa.php', { r: 'campaigns', ...range }),
  campaign: (id: number) =>
    apiGet<{ ok: true; id: number; settings: import('./types').CampaignSettings }>('spa.php', {
      r: 'campaign',
      id,
    }),
  dashboard: (p: { campId: number; start: number; end: number; tz: string }) =>
    apiGet<import('./types').DashboardResponse>('spa.php', { r: 'dashboard', ...p }),
  clicks: (p: import('./types').ClicksQuery) =>
    apiGet<import('./types').ClicksResponse>('spa.php', { r: 'clicks', ...p }),
  conversions: (p: { campId?: number; limit?: number }) =>
    apiGet<{ ok: true; data: import('./types').Conversion[] }>('spa.php', {
      r: 'conversions',
      ...p,
    }),
  saveCommonSettings: (patch: Record<string, unknown>) =>
    apiSend<{ ok: true; settings: Record<string, unknown> }>('spa.php', 'POST', patch, {
      r: 'common-settings',
    }),
};

// Campaign CRUD reuses the existing campeditor.php transport.
export const campaignApi = {
  create: (name: string) =>
    apiGet<{ result: string; error?: boolean }>('campeditor.php', { action: 'add', name }),
  rename: (campId: number, name: string) =>
    apiGet<{ result: string; error?: boolean }>('campeditor.php', { action: 'ren', campId, name }),
  duplicate: (campId: number, name: string) =>
    apiGet<{ result: string; error?: boolean }>('campeditor.php', { action: 'dup', campId, name }),
  remove: (campId: number) =>
    apiGet<{ result: string; error?: boolean }>('campeditor.php', { action: 'del', campId }),
  save: (campId: number, settings: Record<string, unknown>) =>
    apiSend<{ result: string; error?: boolean }>('campeditor.php', 'POST', settings, {
      action: 'save',
      campId,
    }),
};

// First-class entity CRUD via entityapi.php.
export const entityApi = {
  list: (type: string) =>
    apiGet<{ ok: true; items: import('./types').EntityRecord[] }>('entityapi.php', {
      type,
      action: 'list',
    }),
  get: (type: string, id: number) =>
    apiGet<{ ok: true; item: import('./types').EntityRecord }>('entityapi.php', {
      type,
      action: 'get',
      id,
    }),
  save: (type: string, body: Record<string, unknown>) =>
    apiSend<{ ok: true; id: number }>('entityapi.php', 'POST', body, { type, action: 'save' }),
  remove: (type: string, id: number) =>
    apiSend<{ ok: boolean }>('entityapi.php', 'POST', { id }, { type, action: 'delete' }),
  templates: (type: string) =>
    apiGet<{ ok: true; items: { name: string; settings: Record<string, unknown> }[] }>(
      'entityapi.php',
      { type, action: 'templates' },
    ),
};
