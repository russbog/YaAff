// Typed campaign-settings model + (de)serialization for the native campaign
// editor. The shapes mirror the raw settings array stored by the PHP layer
// (see db/default.json) and the save contract the legacy form produced
// (admin/js/campsettings/form-submit.js → POST campeditor.php?action=save).
//
// Loading: spa.php?r=campaign&id=X returns { settings } in the stored shape.
// Saving: we POST a structured object that campeditor.php merges recursively
// into the existing settings. We intentionally omit `statistics` and `apikey`
// from the payload so the recursive merge preserves them untouched, exactly
// like the legacy form did.

export type Condition = 'AND' | 'OR';

export interface FilterRule {
  id: string;
  field?: string;
  type?: string;
  input?: string;
  operator: string;
  value: unknown;
}

export interface FilterGroup {
  condition: Condition;
  rules: FilterNode[];
}

export type FilterNode = FilterRule | FilterGroup;

export function isGroup(node: FilterNode): node is FilterGroup {
  return (node as FilterGroup).condition !== undefined && Array.isArray((node as FilterGroup).rules);
}

export type WhiteAction = 'folder' | 'redirect' | 'curl' | 'error';
export type LoadMode = 'base' | 'rewrite' | 'direct';

export interface DomainWhite {
  domain: string;
  action: WhiteAction;
  folders: string[];
  redirect: { urls: string[]; type: number };
  curls: string[];
  errorcodes: string[];
  loadmode: Record<string, LoadMode>;
}

export interface White {
  filters: FilterGroup;
  action: WhiteAction;
  folders: string[];
  redirect: { urls: string[]; type: number };
  curls: string[];
  errorcodes: string[];
  domainfilter: { use: boolean; domains: DomainWhite[] };
  loadmode: Record<string, LoadMode>;
}

export type StepAction = 'folder' | 'redirect';
export type StepRedirectType = number | string;

export interface RedirectEntry {
  url: string;
  label: string;
}

export interface Step {
  action: StepAction;
  /** Legacy raw folder names (back-compat editing of pre-catalog steps). */
  folders: string[];
  /** Local landing ids (catalog) served as prelander pages (action='folder'). */
  landings: number[];
  /**
   * Offer ids (catalog). In Offer mode (action='redirect') these are the A/B
   * redirect targets; in Landing mode (action='folder') the first is the offer
   * the landing's {offer} CTA links to.
   */
  offers: number[];
  redirect: { urls: RedirectEntry[]; type: StepRedirectType };
  weights: number[];
  folderloadtypes: Record<string, 'base' | 'direct'>;
}

export type Distribution = 'equal' | 'weighted' | 'thompson';
export type FlowType = 'regular' | 'forced' | 'default';

export interface Flow {
  name: string;
  filters: FilterGroup;
  type: FlowType;
  weight: number;
  distribution: Distribution;
  optimize_for: 'Lead' | 'Purchase';
  optimize_mode: 'funnels' | 'separate';
  steps: Step[];
  /** Free-form media-buyer annotation, shown only in the editor. */
  notes: string;
}

export interface JsBotDetection {
  enabled: boolean;
  events: string[];
  timeout: string;
  timezone: { min: string; max: string };
}

export type JsConnect = 'replace' | 'iframe' | 'redirect';

export interface Black {
  jsconnect: JsConnect;
  jsbotdetection: JsBotDetection;
  flows: Flow[];
}

export interface ScriptRule {
  flow: string;
  steps: '*' | number[];
  url: string;
}

export interface Scripts {
  backfix: { use: boolean; urls: string[] };
  nextredirect: { use: boolean; rules: ScriptRule[] };
  submitredirect: { use: boolean; rules: ScriptRule[] };
  events: {
    scroll: { use: boolean; thresholds: number[] };
    time: { use: boolean; thresholds: number[] };
  };
  imageslazyload: boolean;
}

export type DedupKey = 'clickid' | 'tid' | 'clickid_tid';

export interface S2sPostback {
  url: string;
  method: 'GET' | 'POST';
  events: string[];
}

export interface Postback {
  events: { lead: string; purchase: string; reject: string; trash: string };
  s2s: S2sPostback[];
  dedup_key: DedupKey;
  integrations: number[];
}

export interface CampaignModel {
  identifier: string;
  saveuserflow: boolean;
  domains: string[];
  apikey: string;
  white: White;
  black: Black;
  scripts: Scripts;
  postback: Postback;
}

// --- normalization helpers ------------------------------------------------

type Raw = Record<string, unknown>;

function asRecord(v: unknown): Raw {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Raw) : {};
}
function asArray(v: unknown): unknown[] {
  return Array.isArray(v) ? v : [];
}
function asStr(v: unknown, def = ''): string {
  return v === undefined || v === null ? def : String(v);
}
function asBool(v: unknown, def = false): boolean {
  if (typeof v === 'boolean') return v;
  if (typeof v === 'string') return v === 'true' || v === '1';
  if (typeof v === 'number') return v !== 0;
  return def;
}
function asInt(v: unknown, def: number): number {
  const n = typeof v === 'number' ? v : parseInt(String(v ?? ''), 10);
  return Number.isFinite(n) ? n : def;
}

function normFilters(v: unknown): FilterGroup {
  const o = asRecord(v);
  const cond = o.condition === 'OR' ? 'OR' : 'AND';
  const rules = asArray(o.rules).map(normFilterNode);
  return { condition: cond, rules };
}
function normFilterNode(v: unknown): FilterNode {
  const o = asRecord(v);
  if (o.condition !== undefined && Array.isArray(o.rules)) {
    return { condition: o.condition === 'OR' ? 'OR' : 'AND', rules: o.rules.map(normFilterNode) };
  }
  return {
    id: asStr(o.id),
    field: o.field === undefined ? asStr(o.id) : asStr(o.field),
    type: o.type === undefined ? undefined : asStr(o.type),
    input: o.input === undefined ? undefined : asStr(o.input),
    operator: asStr(o.operator, 'in'),
    value: o.value ?? '',
  };
}

function normLoadMode(v: unknown): Record<string, LoadMode> {
  const o = asRecord(v);
  const out: Record<string, LoadMode> = {};
  for (const [k, val] of Object.entries(o)) {
    const m = asStr(val);
    if (m === 'base' || m === 'rewrite' || m === 'direct') out[k] = m;
  }
  return out;
}

function normWhiteAction(v: unknown): WhiteAction {
  const a = asStr(v);
  return a === 'folder' || a === 'redirect' || a === 'curl' || a === 'error' ? a : 'folder';
}

function normDomainWhite(v: unknown): DomainWhite {
  const o = asRecord(v);
  // tolerate the legacy minimal shape { name, action: "folder:white" }
  let action = asStr(o.action ?? 'folder');
  let legacyFolder = '';
  if (action.includes(':')) {
    const [act, rest] = action.split(':', 2);
    action = act;
    if (act === 'folder') legacyFolder = rest;
  }
  const redirect = asRecord(o.redirect);
  const folders = asArray(o.folders).map((x) => asStr(x)).filter(Boolean);
  if (legacyFolder && folders.length === 0) folders.push(legacyFolder);
  return {
    domain: asStr(o.domain ?? o.name),
    action: normWhiteAction(action),
    folders,
    redirect: {
      urls: asArray(redirect.urls).map((x) => asStr(x)),
      type: asInt(redirect.type, 302),
    },
    curls: asArray(o.curls).map((x) => asStr(x)),
    errorcodes: asArray(o.errorcodes).map((x) => asStr(x)),
    loadmode: normLoadMode(o.loadmode),
  };
}

function normWhite(v: unknown): White {
  const o = asRecord(v);
  const redirect = asRecord(o.redirect);
  const df = asRecord(o.domainfilter);
  return {
    filters: normFilters(o.filters),
    action: normWhiteAction(o.action),
    folders: asArray(o.folders).map((x) => asStr(x)).filter(Boolean),
    redirect: {
      urls: asArray(redirect.urls).map((x) => asStr(x)),
      type: asInt(redirect.type, 302),
    },
    curls: asArray(o.curls).map((x) => asStr(x)),
    errorcodes: asArray(o.errorcodes).map((x) => asStr(x)),
    domainfilter: {
      use: asBool(df.use),
      domains: asArray(df.domains).map(normDomainWhite),
    },
    loadmode: normLoadMode(o.loadmode),
  };
}

function normStep(v: unknown): Step {
  const o = asRecord(v);
  const action = asStr(o.action) === 'redirect' ? 'redirect' : 'folder';
  const redirect = asRecord(o.redirect);
  const urls = asArray(redirect.urls).map((r): RedirectEntry => {
    if (r && typeof r === 'object') {
      const ro = r as Raw;
      return { url: asStr(ro.url), label: asStr(ro.label || hostOf(asStr(ro.url))) };
    }
    const u = asStr(r);
    return { url: u, label: hostOf(u) };
  });
  const folderloadtypes: Record<string, 'base' | 'direct'> = {};
  for (const [k, val] of Object.entries(asRecord(o.folderloadtypes))) {
    folderloadtypes[k] = asStr(val) === 'direct' ? 'direct' : 'base';
  }
  return {
    action,
    folders: asArray(o.folders).map((x) => asStr(x)).filter(Boolean),
    landings: asArray(o.landings).map((x) => asInt(x, 0)).filter((n) => n > 0),
    offers: asArray(o.offers).map((x) => asInt(x, 0)).filter((n) => n > 0),
    redirect: { urls, type: normStepRedirectType(redirect.type) },
    weights: asArray(o.weights).map((x) => asInt(x, 0)),
    folderloadtypes,
  };
}

function normStepRedirectType(v: unknown): StepRedirectType {
  if (typeof v === 'number') return v;
  const s = asStr(v, '302');
  return /^\d+$/.test(s) ? parseInt(s, 10) : s;
}

function normFlow(v: unknown): Flow {
  const o = asRecord(v);
  const type = asStr(o.type, 'regular');
  const dist = asStr(o.distribution, 'equal');
  return {
    name: asStr(o.name, 'Flow'),
    filters: normFilters(o.filters),
    type: type === 'forced' || type === 'default' ? type : 'regular',
    weight: asInt(o.weight, 100),
    distribution: dist === 'weighted' || dist === 'thompson' ? dist : 'equal',
    optimize_for: asStr(o.optimize_for) === 'Purchase' ? 'Purchase' : 'Lead',
    optimize_mode: asStr(o.optimize_mode) === 'separate' ? 'separate' : 'funnels',
    steps: asArray(o.steps).map(normStep),
    notes: asStr(o.notes),
  };
}

function normBlack(v: unknown): Black {
  const o = asRecord(v);
  const jc = asStr(o.jsconnect, 'redirect');
  const jbd = asRecord(o.jsbotdetection);
  const tz = asRecord(jbd.timezone);
  return {
    jsconnect: jc === 'replace' || jc === 'iframe' ? jc : 'redirect',
    jsbotdetection: {
      enabled: asBool(jbd.enabled),
      events: asArray(jbd.events).map((x) => asStr(x)),
      timeout: asStr(jbd.timeout, '10000'),
      timezone: { min: asStr(tz.min, '0'), max: asStr(tz.max, '0') },
    },
    flows: asArray(o.flows).map(normFlow),
  };
}

function normScriptRule(v: unknown): ScriptRule {
  const o = asRecord(v);
  let steps: '*' | number[] = '*';
  if (Array.isArray(o.steps)) steps = o.steps.map((x) => asInt(x, 0));
  else if (typeof o.steps === 'string' && o.steps !== '*') {
    steps = o.steps.split(',').map((s) => parseInt(s, 10)).filter((n) => Number.isFinite(n));
    if (steps.length === 0) steps = '*';
  }
  return { flow: asStr(o.flow, '*'), steps, url: asStr(o.url) };
}

function normThresholds(v: unknown): number[] {
  return asArray(v).map((x) => asInt(x, 0)).filter((n) => n > 0);
}

function normScripts(v: unknown): Scripts {
  const o = asRecord(v);
  const backfix = asRecord(o.backfix);
  const next = asRecord(o.nextredirect);
  const submit = asRecord(o.submitredirect);
  const events = asRecord(o.events);
  const scroll = asRecord(events.scroll);
  const time = asRecord(events.time);
  return {
    backfix: { use: asBool(backfix.use), urls: asArray(backfix.urls).map((x) => asStr(x)) },
    nextredirect: { use: asBool(next.use), rules: asArray(next.rules).map(normScriptRule) },
    submitredirect: { use: asBool(submit.use), rules: asArray(submit.rules).map(normScriptRule) },
    events: {
      scroll: { use: asBool(scroll.use), thresholds: normThresholds(scroll.thresholds) },
      time: { use: asBool(time.use), thresholds: normThresholds(time.thresholds) },
    },
    imageslazyload: asBool(o.imageslazyload),
  };
}

function normPostback(v: unknown): Postback {
  const o = asRecord(v);
  const ev = asRecord(o.events);
  const dk = asStr(o.dedup_key, 'clickid_tid');
  return {
    events: {
      lead: asStr(ev.lead, 'Lead'),
      purchase: asStr(ev.purchase, 'Purchase'),
      reject: asStr(ev.reject, 'Reject'),
      trash: asStr(ev.trash, 'Trash'),
    },
    s2s: asArray(o.s2s).map((s): S2sPostback => {
      const so = asRecord(s);
      return {
        url: asStr(so.url),
        method: asStr(so.method) === 'POST' ? 'POST' : 'GET',
        events: asArray(so.events).map((x) => asStr(x)),
      };
    }),
    dedup_key: dk === 'tid' || dk === 'clickid' ? dk : 'clickid_tid',
    integrations: asArray(o.integrations).map((x) => asInt(x, 0)).filter((n) => n > 0),
  };
}

export function hostOf(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return 'redirect';
  }
}

export function normalizeCampaign(raw: unknown): CampaignModel {
  const o = asRecord(raw);
  return {
    identifier: asStr(o.identifier),
    saveuserflow: asBool(o.saveuserflow),
    domains: asArray(o.domains).map((x) => asStr(x)).filter(Boolean),
    apikey: asStr(o.apikey),
    white: normWhite(o.white),
    black: normBlack(o.black),
    scripts: normScripts(o.scripts),
    postback: normPostback(o.postback),
  };
}

// --- serialization (save payload) -----------------------------------------

export function serializeCampaign(m: CampaignModel): Record<string, unknown> {
  return {
    identifier: m.identifier,
    saveuserflow: m.saveuserflow,
    domains: m.domains.map((d) => d.trim()).filter(Boolean),
    white: serializeWhite(m.white),
    black: {
      jsconnect: m.black.jsconnect,
      jsbotdetection: {
        enabled: m.black.jsbotdetection.enabled,
        events: m.black.jsbotdetection.events,
        timeout: m.black.jsbotdetection.timeout,
        timezone: { min: m.black.jsbotdetection.timezone.min, max: m.black.jsbotdetection.timezone.max },
      },
      flows: m.black.flows.map(serializeFlow),
    },
    scripts: {
      backfix: { use: m.scripts.backfix.use, urls: m.scripts.backfix.urls.filter(Boolean) },
      nextredirect: { use: m.scripts.nextredirect.use, rules: m.scripts.nextredirect.rules.filter((r) => r.url.trim()) },
      submitredirect: {
        use: m.scripts.submitredirect.use,
        rules: m.scripts.submitredirect.rules.map((r) => ({ ...r, steps: '*' as const })).filter((r) => r.url.trim()),
      },
      events: {
        scroll: { use: m.scripts.events.scroll.use, thresholds: m.scripts.events.scroll.thresholds },
        time: { use: m.scripts.events.time.use, thresholds: m.scripts.events.time.thresholds },
      },
      imageslazyload: m.scripts.imageslazyload,
    },
    postback: {
      events: m.postback.events,
      s2s: m.postback.s2s.filter((s) => s.url.trim()),
      dedup_key: m.postback.dedup_key,
      integrations: m.postback.integrations,
    },
  };
}

function serializeWhite(w: White): Record<string, unknown> {
  const loadmode: Record<string, LoadMode> = {};
  for (const f of w.folders) if (f) loadmode[f] = w.loadmode[f] ?? 'base';
  for (const u of w.curls) if (u) loadmode[u] = w.loadmode[u] ?? 'rewrite';
  return {
    filters: w.filters,
    action: w.action,
    folders: w.folders.filter(Boolean),
    redirect: { urls: w.redirect.urls.filter(Boolean), type: w.redirect.type },
    curls: w.curls.filter(Boolean),
    errorcodes: w.errorcodes.filter((c) => String(c).trim()),
    domainfilter: { use: w.domainfilter.use, domains: w.domainfilter.domains.map(serializeDomainWhite) },
    loadmode,
  };
}

function serializeDomainWhite(d: DomainWhite): Record<string, unknown> {
  const loadmode: Record<string, LoadMode> = {};
  for (const f of d.folders) if (f) loadmode[f] = d.loadmode[f] ?? 'base';
  for (const u of d.curls) if (u) loadmode[u] = d.loadmode[u] ?? 'rewrite';
  return {
    domain: d.domain,
    action: d.action,
    folders: d.folders.filter(Boolean),
    redirect: { urls: d.redirect.urls.filter(Boolean), type: d.redirect.type },
    curls: d.curls.filter(Boolean),
    errorcodes: d.errorcodes.map((c) => parseInt(String(c), 10) || 0).filter(Boolean),
    loadmode,
  };
}

function serializeFlow(f: Flow): Record<string, unknown> {
  return {
    name: f.name || 'Flow',
    type: f.type,
    weight: f.weight,
    filters: f.filters,
    distribution: f.distribution,
    optimize_for: f.optimize_for,
    optimize_mode: f.optimize_mode,
    steps: f.steps.map(serializeStep),
    notes: f.notes,
  };
}

function serializeStep(s: Step): Record<string, unknown> {
  if (s.action === 'redirect') {
    // Offer mode: A/B split across catalog offers (resolved to redirect URLs).
    if (s.offers.length > 0) {
      return {
        action: 'redirect',
        folders: [],
        landings: [],
        offers: s.offers,
        redirect: { urls: [], type: s.redirect.type },
        weights: s.offers.map((_, i) => s.weights[i] ?? 0),
        folderloadtypes: {},
      };
    }
    // Direct URL mode: raw redirect targets typed inline.
    const urls = s.redirect.urls.filter((u) => u.url.trim());
    return {
      action: 'redirect',
      folders: [],
      landings: [],
      offers: [],
      redirect: {
        urls: urls.map((u) => ({ url: u.url.trim(), label: hostOf(u.url) })),
        type: s.redirect.type,
      },
      weights: urls.map((_, i) => s.weights[i] ?? 0),
      folderloadtypes: {},
    };
  }
  // Landing mode: A/B across catalog landings (+ any legacy folders), with an
  // optional bound offer the landing's {offer} CTA links to. Weights are
  // positional over the runtime item list, which FlowEntityResolver builds as
  // legacy folders first, then resolved landing folders (in landings[] order).
  const folders = s.folders.filter(Boolean);
  const folderloadtypes: Record<string, 'base' | 'direct'> = {};
  for (const f of folders) folderloadtypes[f] = s.folderloadtypes[f] ?? 'base';
  const itemCount = folders.length + s.landings.length;
  return {
    action: 'folder',
    folders,
    landings: s.landings,
    offers: s.offers,
    redirect: { urls: [], type: 302 },
    weights: Array.from({ length: itemCount }, (_, i) => s.weights[i] ?? 0),
    folderloadtypes,
  };
}

// --- factory helpers for the editor ---------------------------------------

export function emptyFilters(): FilterGroup {
  return { condition: 'AND', rules: [] };
}

export function newFlow(name: string): Flow {
  return {
    name,
    filters: emptyFilters(),
    type: 'regular',
    weight: 100,
    distribution: 'equal',
    optimize_for: 'Lead',
    optimize_mode: 'funnels',
    steps: [newStep('folder')],
    notes: '',
  };
}

export function newStep(action: StepAction): Step {
  return {
    action,
    folders: [],
    landings: [],
    offers: [],
    redirect: { urls: [], type: 302 },
    weights: [],
    folderloadtypes: {},
  };
}

export function newDomainWhite(domain: string): DomainWhite {
  return {
    domain,
    action: 'folder',
    folders: [],
    redirect: { urls: [], type: 302 },
    curls: [],
    errorcodes: [],
    loadmode: {},
  };
}

export function flowHasMultipleSteps(f: Flow): boolean {
  if (f.steps.length > 1) return true;
  return f.steps.some((s) =>
    (s.action === 'redirect'
      ? Math.max(s.offers.length, s.redirect.urls.length)
      : s.folders.length + s.landings.length) > 1,
  );
}
