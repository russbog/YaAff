// Thin fetch client for the YaAff admin JSON endpoints. All requests carry the
// session cookie (credentials: same-origin) and normalize the `{ok,error}`
// envelope the PHP layer returns.

declare global {
  interface Window {
    __YAAFF__?: { apiBase: string; version: string };
  }
}

export const API_BASE: string = window.__YAAFF__?.apiBase ?? '/admin/';
// Version format: YY.MM.DD.mm (mm = minutes since midnight, UTC), e.g. "26.06.24.728".
// Sourced from admin/version.txt; see admin/autoupdate.php for parsing/comparison.
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

// Some legacy PHP endpoints can prepend a warning/notice (e.g. a failed
// file_get_contents to GitHub, or display_errors=On) before the JSON body,
// producing responses like `<br /><b>Warning</b>: ...{"success":true}`.
// Parse the whole body first; on failure, retry from the first `{`/`[` so a
// leaked warning doesn't surface to the user as "Invalid JSON".
function parseJsonLoose(text: string): unknown {
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    const start = text.search(/[[{]/);
    if (start > 0) {
      try {
        return JSON.parse(text.slice(start));
      } catch {
        /* fall through */
      }
    }
    throw new SyntaxError('Invalid JSON');
  }
}

async function parse<T>(res: Response): Promise<T> {
  const text = await res.text();
  let data: unknown = null;
  try {
    data = parseJsonLoose(text);
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
  report: (p: { campId: number; groupBy: string[]; fields: string[]; start?: number; end?: number }) =>
    apiGet<import('./types').ReportResponse>('spa.php', {
      r: 'report',
      campId: p.campId,
      groupBy: p.groupBy.join(','),
      fields: p.fields.join(','),
      start: p.start,
      end: p.end,
    }),
  trends: (p: { campId: number; start?: number; end?: number; granularity: import('./types').TrendGranularity }) =>
    apiGet<import('./types').TrendsResponse>('spa.php', { r: 'trends', ...p }),
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
  status: () => apiGet<import('./types').StatusResponse>('spa.php', { r: 'status' }),
};

// --- System maintenance (app auto-update / geobase update / timezone) -------
// autoupdate.php and commonseditor.php speak form-encoded POST and a
// {success|result|error} envelope rather than the {ok} one; bases/update.php
// lives outside admin/ and returns {result, error}.

export interface UpdateCheck {
  hasUpdate: boolean;
  version: string;
  message?: string;
}

async function autoupdate(action: 'check' | 'update'): Promise<Record<string, unknown>> {
  const res = await fetch(url('autoupdate.php'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: form({ action }),
  });
  const text = await res.text();
  let data: Record<string, unknown>;
  try {
    data = (parseJsonLoose(text) ?? {}) as Record<string, unknown>;
  } catch {
    throw new ApiError(`Invalid JSON from server (HTTP ${res.status})`, res.status);
  }
  if (!res.ok || data.success === false) {
    throw new ApiError((data.message as string) || `Request failed (HTTP ${res.status})`, res.status);
  }
  return data;
}

export const systemApi = {
  checkUpdate: async (): Promise<UpdateCheck> => {
    const d = await autoupdate('check');
    return { hasUpdate: Boolean(d.hasUpdate), version: String(d.version ?? ''), message: d.message as string | undefined };
  },
  applyUpdate: async (): Promise<string> => {
    const d = await autoupdate('update');
    return String(d.message ?? 'Update complete');
  },
  // bases/update.php is one level above admin/; returns { result, error }.
  updateGeobases: async (): Promise<string> => {
    const res = await fetch(url('../bases/update.php'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = await fileParse<{ error?: boolean; result?: string }>(res);
    return data.result ?? 'GeoBases updated';
  },
  saveTimezone: (timezone: string) =>
    filePost<{ error?: boolean; result?: string }>('commonseditor.php?action=savetimezone', form({ timezone })),
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

// --- Landing file management (zipupload.php / listfolders.php / fileeditor.php)
// These legacy endpoints speak form-encoded/multipart bodies and return a
// `{error: bool, result?: string, ...}` envelope rather than the `{ok}` one.

export interface FileNode {
  name: string;
  path: string;
  type: 'file' | 'dir';
  size?: number;
  children?: FileNode[];
}

type FileEnvelope = { error?: boolean; result?: string; [k: string]: unknown };

async function fileParse<T extends FileEnvelope>(res: Response): Promise<T> {
  const text = await res.text();
  let data: FileEnvelope;
  try {
    data = (parseJsonLoose(text) ?? {}) as FileEnvelope;
  } catch {
    throw new ApiError(`Invalid JSON from server (HTTP ${res.status})`, res.status);
  }
  if (!res.ok || data.error) {
    throw new ApiError(data.result || `Request failed (HTTP ${res.status})`, res.status);
  }
  return data as T;
}

function form(obj: Record<string, string>): URLSearchParams {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(obj)) p.set(k, v);
  return p;
}

async function filePost<T extends FileEnvelope>(
  path: string,
  body: URLSearchParams | FormData,
): Promise<T> {
  const res = await fetch(url(path), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    body,
  });
  return fileParse<T>(res);
}

export interface ConversionImportResult {
  imported: number;
  skipped: number;
  errors: string[];
}

export const conversionsApi = {
  // POST conversions.php?action=import with a multipart CSV (clickid, status
  // required; payout, currency, revenue, tid optional).
  importCsv: async (file: File): Promise<ConversionImportResult> => {
    const fd = new FormData();
    fd.append('csv_file', file);
    const res = await fetch(url('conversions.php', { action: 'import' }), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: fd,
    });
    const text = await res.text();
    let data: { error?: string } & Partial<ConversionImportResult>;
    try {
      data = (text ? JSON.parse(text) : {}) as typeof data;
    } catch {
      throw new ApiError(`Invalid JSON from server (HTTP ${res.status})`, res.status);
    }
    if (!res.ok || data.error) {
      throw new ApiError(data.error || `Import failed (HTTP ${res.status})`, res.status);
    }
    return {
      imported: data.imported ?? 0,
      skipped: data.skipped ?? 0,
      errors: data.errors ?? [],
    };
  },
};

export interface DomainCheck {
  domain: string;
  wildcard: boolean;
  resolves: boolean;
  cloudflare: boolean;
  ip: string | null;
  serverIp: string | null;
  error: string | null;
}

export const domainApi = {
  // DNS / Cloudflare detection (domaincheck.php). Returns the resolved A
  // record and whether it points at Cloudflare / the server.
  check: async (domain: string): Promise<DomainCheck> => {
    const res = await fetch(url('domaincheck.php', { domain }), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const data = (await res.json()) as DomainCheck & { error?: string };
    if (!res.ok) throw new ApiError(data.error || `HTTP ${res.status}`, res.status);
    return data;
  },
};

// --- Domain health status (domainstatus.php) ------------------------------

export type DomainHealth = 'ok' | 'dns_await' | 'dns_error' | 'ssl_await' | 'ssl_error' | 'unreachable' | 'na';

export interface DomainStatus {
  status: DomainHealth;
  detail: string;
  ip: string | null;
  cloudflare: boolean;
  ssl_expires_at: number | null;
  days_left: number | null;
  needs_fix: boolean;
  host: string;
  checked_at: number;
}

export interface DomainFixResult {
  ok: boolean;
  method: 'cloudflare' | 'letsencrypt' | 'none';
  queued: boolean;
  detail: string;
}

export type DomainStatusMap = Record<string, DomainStatus>;

export const domainStatusApi = {
  // Cached status map for every domain (no live probing) — drives the table.
  all: () => apiGet<{ ok: true; serverIp: string; statuses: DomainStatusMap }>('domainstatus.php', { action: 'all' }),
  // Live re-check of a single domain.
  check: (id: number) => apiSend<{ ok: true; status: DomainStatus }>('domainstatus.php', 'POST', { id }, { action: 'check' }),
  // Live re-check of every domain.
  checkAll: () =>
    apiSend<{ ok: true; serverIp: string; statuses: DomainStatusMap }>('domainstatus.php', 'POST', {}, { action: 'check_all' }),
  // Remediate one domain (CF inline, Let's Encrypt queued for the server cron), then re-check.
  fix: (id: number) =>
    apiSend<{ ok: boolean; fix: DomainFixResult; status: DomainStatus }>('domainstatus.php', 'POST', { id }, { action: 'fix' }),
};

export interface CloudflareResult {
  ok: boolean;
  http_code?: number;
  error: string | null;
}

// cloudflare.php returns {ok:false} for legitimate verification failures, so we
// surface the envelope instead of throwing on ok:false (network/HTTP errors
// still throw).
async function cfCall(action: string, id: number): Promise<CloudflareResult> {
  const res = await fetch(url('cloudflare.php', { action, id }), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const text = await res.text();
  let data: CloudflareResult;
  try {
    data = JSON.parse(text) as CloudflareResult;
  } catch {
    throw new ApiError(text.trim() || `Cloudflare request failed (HTTP ${res.status})`, res.status);
  }
  return data;
}

export const cloudflareApi = {
  verifyToken: (id: number) => cfCall('verify_token', id),
  createRecord: (id: number) => cfCall('create_record', id),
};

export type FolderType = 'landing' | 'white';

export const folderApi = {
  list: (type: FolderType = 'landing') =>
    apiGet<{ error: boolean; folders: string[] }>('listfolders.php', { type }),
  uploadZip: (folder: string, file: File, type: FolderType = 'landing', overwrite = false) => {
    const fd = new FormData();
    fd.append('folder', folder);
    fd.append('type', type);
    if (overwrite) fd.append('overwrite', '1');
    fd.append('zipfile', file);
    return filePost<{ error?: boolean; result?: string }>('zipupload.php', fd);
  },
};

export const fileApi = {
  list: (folder: string, type: FolderType = 'landing') =>
    apiGet<{ error: boolean; tree: FileNode[] }>('fileeditor.php', {
      action: 'list',
      folder,
      type,
    }),
  read: (folder: string, file: string, type: FolderType = 'landing') =>
    apiGet<{ error: boolean; content: string; file: string }>('fileeditor.php', {
      action: 'read',
      folder,
      file,
      type,
    }),
  save: (folder: string, file: string, content: string, type: FolderType = 'landing') =>
    filePost('fileeditor.php?action=save', form({ folder, type, file, content })),
  // create overloads the legacy `type` param as the file/dir kind; for landing
  // folders get_lcache_dir falls back to the landing dir for any non-"white"
  // value, so passing the kind here keeps both behaviours correct.
  create: (folder: string, file: string, kind: 'file' | 'dir') =>
    filePost('fileeditor.php?action=create', form({ folder, file, type: kind })),
  remove: (folder: string, file: string, type: FolderType = 'landing') =>
    filePost('fileeditor.php?action=delete', form({ folder, type, file })),
  rename: (folder: string, file: string, newName: string, type: FolderType = 'landing') =>
    filePost('fileeditor.php?action=rename', form({ folder, type, file, newName })),
  upload: (
    folder: string,
    file: File,
    subpath: string,
    type: FolderType = 'landing',
  ) => {
    const fd = new FormData();
    fd.append('folder', folder);
    fd.append('type', type);
    fd.append('subpath', subpath);
    fd.append('file', file);
    return filePost('fileeditor.php?action=upload', fd);
  },
};

export interface BlacklistFeed {
  name: string;
  type: string;
  tag: string;
  url: string;
  enabled: boolean;
  cached: boolean;
  entries: number;
  updated_at: string | null;
}

export interface BlacklistUpdateResult {
  name: string;
  ok: boolean;
  skipped?: boolean;
  entries?: number;
  error?: string;
}

// Bot-protection blacklist feeds (blacklists.php). status() lists feed cache
// state; update() triggers an on-demand refresh and returns per-feed results.
export const blacklistApi = {
  status: () => apiGet<{ ok: true; feeds: BlacklistFeed[] }>('blacklists.php', { action: 'status' }),
  update: () =>
    apiGet<{ ok: true; results: BlacklistUpdateResult[]; feeds: BlacklistFeed[] }>('blacklists.php', {
      action: 'update',
    }),
};

export interface DataInfo {
  ok: true;
  driver: string;
  retentionDays: number;
  retentionTables: string[];
  tables: { name: string; rows: number }[];
}

// Backup / restore / retention (data.php).
export const dataApi = {
  info: () => apiGet<DataInfo>('data.php', { action: 'info' }),
  backupUrl: () => url('data.php', { action: 'backup' }),
  restore: async (file: File): Promise<{ ok: true; restored: Record<string, number> }> => {
    const fd = new FormData();
    fd.append('archive', file);
    const res = await fetch(url('data.php', { action: 'restore' }), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: fd,
    });
    return parse(res);
  },
  prune: (days: number) =>
    apiGet<{ ok: true; days: number; cutoff: string; deleted: Record<string, number> }>('data.php', {
      action: 'prune',
      days,
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
