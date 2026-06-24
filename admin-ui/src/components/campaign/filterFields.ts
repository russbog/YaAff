// TDS filter field catalog — a 1:1 port of admin/js/filters.js (the jQuery
// QueryBuilder config). Drives the native FilterBuilder so the rules JSON it
// produces is byte-compatible with what core.php's match_filters() expects.

export type FilterInput = 'text' | 'number' | 'radio';

export interface FilterField {
  id: string;
  label: string;
  input: FilterInput;
  type: 'string' | 'integer';
  operators: string[];
  placeholder?: string;
  values?: Record<string, string>;
}

// Operator → number of value inputs / human label.
export const OPERATOR_LABELS: Record<string, string> = {
  equal: 'equals',
  not_equal: 'not equals',
  in: 'in',
  not_in: 'not in',
  less_or_equal: '≤',
  greater_or_equal: '≥',
  contains: 'contains',
  not_contains: 'not contains',
  matches: 'matches (mask/regex)',
  not_matches: 'not matches (mask/regex)',
  param_in: 'param in',
  param_not_in: 'param not in',
  param_exists: 'param exists',
  param_not_exists: 'param not exists',
};

// Operators that take a second "name" input (URL parameter operators).
export const PARAM_OPERATORS = new Set(['param_in', 'param_not_in', 'param_exists', 'param_not_exists']);
// Operators with no value input.
export const NO_VALUE_OPERATORS = new Set(['param_exists', 'param_not_exists']);

export const FILTER_FIELDS: FilterField[] = [
  { id: 'os', label: 'OS', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'Android,iOS,Windows,OS X' },
  { id: 'osver', label: 'OS version', input: 'number', type: 'integer', operators: ['in', 'not_in', 'less_or_equal', 'greater_or_equal'], placeholder: '10' },
  { id: 'device', label: 'Device', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'desktop,mobile' },
  { id: 'brand', label: 'Brand', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in'] },
  { id: 'model', label: 'Model', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in'] },
  { id: 'client', label: 'Client', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in'] },
  { id: 'clientver', label: 'ClientVer', input: 'text', type: 'string', operators: ['less_or_equal', 'greater_or_equal', 'in', 'not_in'] },
  { id: 'country', label: 'Country', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'RU,BY,UA' },
  { id: 'lang', label: 'Language', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'en,ru' },
  { id: 'useragent', label: 'UserAgent', input: 'text', type: 'string', operators: ['contains', 'not_contains'], placeholder: 'facebook,facebot,curl,gce-spider,yandex.com,odklbot' },
  { id: 'isp', label: 'ISP', input: 'text', type: 'string', operators: ['contains', 'not_contains'], placeholder: 'facebook,google,yandex,amazon,azure,digitalocean,microsoft' },
  { id: 'referer', label: 'Referer', input: 'text', type: 'string', operators: ['equal', 'not_equal', 'contains', 'not_contains'] },
  { id: 'domain', label: 'Domain', input: 'text', type: 'string', operators: ['in', 'not_in'] },
  { id: 'host', label: 'Host', input: 'text', type: 'string', operators: ['in', 'not_in'] },
  { id: 'vpntor', label: 'VPN & Tor', input: 'radio', type: 'integer', operators: ['equal'], values: { '0': 'Detected', '1': 'NOT Detected' } },
  { id: 'ipbase', label: 'IP Base', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'bots1.txt,bots2.txt' },
  { id: 'region', label: 'Region', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'], placeholder: 'California,Bavaria (requires GeoLite2-City)' },
  { id: 'city', label: 'City', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'], placeholder: 'Los Angeles,Berlin (requires GeoLite2-City)' },
  { id: 'connection_type', label: 'Connection', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'cellular,corporate,cable/dsl' },
  { id: 'search_engine', label: 'Search Engine', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'Google,Bing,Yandex' },
  { id: 'keyword', label: 'Keyword', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in', 'matches', 'not_matches'] },
  { id: 'site', label: 'Site (referer host)', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'] },
  { id: 'creative_id', label: 'Creative ID', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains'] },
  { id: 'x_requested_with', label: 'X-Requested-With', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains'], placeholder: 'com.android.chrome' },
  { id: 'bot', label: 'Bot', input: 'radio', type: 'integer', operators: ['equal'], values: { '0': 'Detected', '1': 'NOT Detected' } },
  { id: 'uniqueness', label: 'Uniqueness', input: 'radio', type: 'integer', operators: ['equal'], values: { '1': 'Unique (first click)', '0': 'Repeat' } },
  { id: 'timetable', label: 'Timetable (JSON)', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: '[{"days":[1,2,3,4,5],"from":9,"to":18}] (1=Mon..7=Sun)' },
  { id: 'date_between', label: 'Date between', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: '2021-01-01,2021-12-31' },
  { id: 'click_limit', label: 'Click limit (JSON)', input: 'text', type: 'string', operators: ['in'], placeholder: '{"window":"day","limit":1000} window: hour|day|total' },
  { id: 'urlparam', label: 'URL Parameter', input: 'text', type: 'string', operators: ['param_in', 'param_not_in', 'param_exists', 'param_not_exists'] },
];

export const FILTER_FIELD_MAP: Record<string, FilterField> = Object.fromEntries(
  FILTER_FIELDS.map((f) => [f.id, f]),
);
