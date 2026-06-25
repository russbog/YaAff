// TDS filter field catalog — a 1:1 port of admin/js/filters.js (the jQuery
// QueryBuilder config). Drives the native FilterBuilder so the rules JSON it
// produces is byte-compatible with what core.php's match_filters() expects.

export type FilterInput = 'text' | 'number' | 'radio';

export type FilterCategory =
  | 'Device & client'
  | 'Geo & network'
  | 'Traffic & source'
  | 'Sub IDs & params'
  | 'Behaviour & limits'
  | 'Advanced';

export interface FilterField {
  id: string;
  label: string;
  input: FilterInput;
  type: 'string' | 'integer';
  operators: string[];
  placeholder?: string;
  values?: Record<string, string>;
  category?: FilterCategory;
}

/** Order in which categories appear in the field picker. */
export const FILTER_CATEGORY_ORDER: FilterCategory[] = [
  'Device & client',
  'Geo & network',
  'Traffic & source',
  'Sub IDs & params',
  'Behaviour & limits',
  'Advanced',
];

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

// Discrete sub_id_1..30 filters. The engine resolves any unknown field id from
// the click query string (core.php resolve_param_value → click_params['qs']),
// so these match the sub_id params carried on the click URL without any backend
// change. Same generic mechanism the freeform "URL Parameter" filter uses.
const SUB_ID_FIELDS: FilterField[] = Array.from({ length: 30 }, (_, i) => {
  const n = i + 1;
  return {
    id: `sub_id_${n}`,
    label: `Sub ID ${n}`,
    input: 'text',
    type: 'string',
    operators: ['in', 'not_in', 'contains', 'not_contains', 'equal', 'not_equal'],
    category: 'Sub IDs & params',
  } satisfies FilterField;
});

const UTM_FIELDS: FilterField[] = (
  [
    ['utm_source', 'UTM Source'],
    ['utm_medium', 'UTM Medium'],
    ['utm_campaign', 'UTM Campaign'],
    ['utm_content', 'UTM Content'],
    ['utm_term', 'UTM Term'],
  ] as const
).map(([id, label]) => ({
  id,
  label,
  input: 'text',
  type: 'string',
  operators: ['in', 'not_in', 'contains', 'not_contains', 'equal', 'not_equal'],
  category: 'Sub IDs & params',
}));

export const FILTER_FIELDS: FilterField[] = [
  { id: 'os', label: 'OS', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'Android,iOS,Windows,OS X', category: 'Device & client' },
  { id: 'osver', label: 'OS version', input: 'number', type: 'integer', operators: ['in', 'not_in', 'less_or_equal', 'greater_or_equal'], placeholder: '10', category: 'Device & client' },
  { id: 'device', label: 'Device', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'desktop,mobile', category: 'Device & client' },
  { id: 'brand', label: 'Brand', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in'], category: 'Device & client' },
  { id: 'model', label: 'Model', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in'], category: 'Device & client' },
  { id: 'client', label: 'Client', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in'], category: 'Device & client' },
  { id: 'clientver', label: 'ClientVer', input: 'text', type: 'string', operators: ['less_or_equal', 'greater_or_equal', 'in', 'not_in'], category: 'Device & client' },
  { id: 'x_requested_with', label: 'X-Requested-With', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains'], placeholder: 'com.android.chrome', category: 'Device & client' },
  { id: 'country', label: 'Country', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'RU,BY,UA', category: 'Geo & network' },
  { id: 'region', label: 'Region', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'], placeholder: 'California,Bavaria (requires GeoLite2-City)', category: 'Geo & network' },
  { id: 'city', label: 'City', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'], placeholder: 'Los Angeles,Berlin (requires GeoLite2-City)', category: 'Geo & network' },
  { id: 'lang', label: 'Language', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'en,ru', category: 'Geo & network' },
  { id: 'isp', label: 'ISP', input: 'text', type: 'string', operators: ['contains', 'not_contains'], placeholder: 'facebook,google,yandex,amazon,azure,digitalocean,microsoft', category: 'Geo & network' },
  { id: 'connection_type', label: 'Connection', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'cellular,corporate,cable/dsl', category: 'Geo & network' },
  { id: 'vpntor', label: 'VPN & Tor', input: 'radio', type: 'integer', operators: ['equal'], values: { '0': 'Detected', '1': 'NOT Detected' }, category: 'Geo & network' },
  { id: 'ipbase', label: 'IP Base', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'bots1.txt,bots2.txt', category: 'Geo & network' },
  { id: 'useragent', label: 'UserAgent', input: 'text', type: 'string', operators: ['contains', 'not_contains'], placeholder: 'facebook,facebot,curl,gce-spider,yandex.com,odklbot', category: 'Traffic & source' },
  { id: 'referer', label: 'Referer', input: 'text', type: 'string', operators: ['equal', 'not_equal', 'contains', 'not_contains'], category: 'Traffic & source' },
  { id: 'site', label: 'Site (referer host)', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'], category: 'Traffic & source' },
  { id: 'domain', label: 'Domain', input: 'text', type: 'string', operators: ['in', 'not_in'], category: 'Traffic & source' },
  { id: 'host', label: 'Host', input: 'text', type: 'string', operators: ['in', 'not_in'], category: 'Traffic & source' },
  { id: 'search_engine', label: 'Search Engine', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: 'Google,Bing,Yandex', category: 'Traffic & source' },
  { id: 'keyword', label: 'Keyword', input: 'text', type: 'string', operators: ['contains', 'not_contains', 'in', 'not_in', 'matches', 'not_matches'], category: 'Traffic & source' },
  { id: 'creative_id', label: 'Creative ID', input: 'text', type: 'string', operators: ['in', 'not_in', 'contains', 'not_contains'], category: 'Traffic & source' },
  ...SUB_ID_FIELDS,
  ...UTM_FIELDS,
  { id: 'bot', label: 'Bot', input: 'radio', type: 'integer', operators: ['equal'], values: { '0': 'Detected', '1': 'NOT Detected' }, category: 'Behaviour & limits' },
  { id: 'uniqueness', label: 'Uniqueness', input: 'radio', type: 'integer', operators: ['equal'], values: { '1': 'Unique (first click)', '0': 'Repeat' }, category: 'Behaviour & limits' },
  { id: 'timetable', label: 'Timetable (JSON)', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: '[{"days":[1,2,3,4,5],"from":9,"to":18}] (1=Mon..7=Sun)', category: 'Behaviour & limits' },
  { id: 'date_between', label: 'Date between', input: 'text', type: 'string', operators: ['in', 'not_in'], placeholder: '2021-01-01,2021-12-31', category: 'Behaviour & limits' },
  { id: 'click_limit', label: 'Click limit (JSON)', input: 'text', type: 'string', operators: ['in'], placeholder: '{"window":"day","limit":1000} window: hour|day|total', category: 'Behaviour & limits' },
  { id: 'urlparam', label: 'URL Parameter', input: 'text', type: 'string', operators: ['param_in', 'param_not_in', 'param_exists', 'param_not_exists'], category: 'Advanced' },
];

export const FILTER_FIELD_MAP: Record<string, FilterField> = Object.fromEntries(
  FILTER_FIELDS.map((f) => [f.id, f]),
);
