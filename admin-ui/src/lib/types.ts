export interface NavItem {
  key: string;
  label: string;
  icon: string;
  perm: string | null;
}

export interface StatField {
  field: string;
  title: string;
  kind: 'int' | 'pct' | 'money';
}

export interface SchemaField {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  help?: string;
  default?: unknown;
  options?: Record<string, string>;
  entity?: string;
}

export interface EntitySchema {
  title: string;
  singular: string;
  icon?: string;
  fields: SchemaField[];
}

export type EntitySchemas = Record<string, EntitySchema>;

export interface TimezoneOption {
  value: string;
  label: string;
}

export interface BootstrapResponse {
  ok: true;
  // Version format: YY.MM.DD.mm (mm = minutes since midnight, UTC). See admin/autoupdate.php.
  version: string;
  multiuser: boolean;
  user: { name: string; role: string } | null;
  permissions: Record<string, boolean>;
  nav: NavItem[];
  entitySchemas: EntitySchemas;
  commonSettings: CommonSettings;
  campaignsList: { id: number; name: string }[];
  timezones: TimezoneOption[] | Record<string, string>;
  statFields: StatField[];
  trafficBackUrl: string;
  geoBases?: GeoBasesInfo;
}

export interface GeoBasesInfo {
  version: string;
  missing: string[];
  source: string | null;
}

export interface CommonSettings {
  statistics?: {
    timezone?: string;
    table?: unknown[];
    campaignsFilters?: unknown;
    campaignsColumns?: string[];
  };
  trafficBackUrl?: string;
  [k: string]: unknown;
}

export interface CampaignRow {
  id: number;
  name: string;
  settings?: Record<string, unknown> | string | null;
  [stat: string]: unknown;
}

export interface CampaignsResponse {
  ok: true;
  rows: CampaignRow[];
  statFields: StatField[];
  range: { start: number; end: number; tz: string };
  filters: unknown;
}

export type CampaignSettings = Record<string, unknown>;

export interface DashboardSummary {
  clicks?: number;
  uniques?: number;
  conversions?: number;
  revenue?: number;
  cost?: number;
  profit?: number;
  bots?: number;
  allowed?: number;
  blocked?: number;
  [k: string]: number | undefined;
}

export interface SeriesPoint {
  t: number | string;
  clicks: number;
  conversions: number;
  [k: string]: number | string;
}

export interface TopRow {
  label: string;
  value: number;
}

export interface DashboardResponse {
  ok: true;
  summary: DashboardSummary;
  series: SeriesPoint[];
  top_country: TopRow[];
  top_flow: TopRow[];
}

export interface ClicksQuery {
  campId?: number;
  view: 'allowed' | 'blocked' | 'leads' | 'trafficback';
  page: number;
  size: number;
  sort?: string;
  dir?: 'asc' | 'desc';
  search?: string;
  start?: number;
  end?: number;
}

export interface ClicksResponse {
  ok?: boolean;
  last_page: number;
  data: Record<string, unknown>[];
}

export interface Conversion {
  [k: string]: unknown;
}

export interface EntityRecord {
  id: number;
  name: string;
  group?: string;
  settings?: Record<string, unknown>;
  [k: string]: unknown;
}
