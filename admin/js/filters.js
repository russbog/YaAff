
var tdsFilters = [
    {
        id: 'os',
        label: 'OS',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'Android,iOS,Windows,OS X',
        size: 50
    },
    {
        id: 'osver',
        label: 'OS version',
        input: 'number',
        type: 'integer',
        operators: ['in', 'not_in','less_or_equal','greater_or_equal'],
        placeholder: 10,
        size: 50
    },
    {
        id: 'device',
        label: 'Device',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'desktop,mobile',
        size: 70
    },
    {
        id: 'brand',
        label: 'Brand',
        input: 'text',
        type: 'string',
        operators: ['contains','not_contains','in', 'not_in'],
        size: 70
    },
    {
        id: 'model',
        label: 'Model',
        input: 'text',
        type: 'string',
        operators: ['contains','not_contains','in', 'not_in'],
        size: 70
    },
    {
        id: 'client',
        label: 'Client',
        input: 'text',
        type: 'string',
        operators: ['contains', 'not_contains','in', 'not_in'],
        size: 70
    },
    {
        id: 'clientver',
        label: 'ClientVer',
        input: 'text',
        type: 'string',
        operators: ['less_or_equal','greater_or_equal','in', 'not_in'],
        size: 50
    },
    {
        id: 'country',
        label: 'Country',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'RU,BY,UA'

    },
    {
        id: 'lang',
        label: 'Language',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'en,ru'
    },
    {
        id: 'useragent',
        label: 'UserAgent',
        input: 'text',
        type: 'string',
        operators: ['contains', 'not_contains'],
        size: 70,
        placeholder: 'facebook,facebot,curl,gce-spider,yandex.com,odklbot'
    },
    {
        id: 'isp',
        label: 'ISP',
        input: 'text',
        type: 'string',
        operators: ['contains', 'not_contains'],
        size: 70,
        placeholder: 'facebook,google,yandex,amazon,azure,digitalocean,microsoft'
    },
    {
        id: 'referer',
        label: 'Referer',
        input: 'text',
        type: 'string',
        operators: ['equal', 'not_equal', 'contains', 'not_contains'],
        validation: {
            allow_empty_value: true
        },
        size: 70
    },
    {
        id: 'domain',
        label: 'Domain',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        size: 70
    },
    {
        id: 'host',
        label: 'Host',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        size: 70
    },
    {
        id: 'vpntor',
        label: 'VPN&Tor',
        type: 'integer',
        input: 'radio',
        values: {
            0: 'Detected',
            1: 'NOT Detected'
        },
        operators: ['equal']
    },
    {
        id: 'ipbase',
        label: 'IP Base',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'path to base file(s) in bases folder: bots1.txt,bots2.txt',
        size: 70
    },
    {
        id: 'region',
        label: 'Region',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'],
        placeholder: 'California,Bavaria (requires GeoLite2-City)',
        size: 70
    },
    {
        id: 'city',
        label: 'City',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'],
        placeholder: 'Los Angeles,Berlin (requires GeoLite2-City)',
        size: 70
    },
    {
        id: 'connection_type',
        label: 'Connection',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'cellular,corporate,cable/dsl'
    },
    {
        id: 'search_engine',
        label: 'Search Engine',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in'],
        placeholder: 'Google,Bing,Yandex'
    },
    {
        id: 'keyword',
        label: 'Keyword',
        input: 'text',
        type: 'string',
        operators: ['contains', 'not_contains', 'in', 'not_in', 'matches', 'not_matches'],
        size: 70
    },
    {
        id: 'site',
        label: 'Site (referer host)',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in', 'contains', 'not_contains', 'matches', 'not_matches'],
        size: 70
    },
    {
        id: 'creative_id',
        label: 'Creative ID',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in', 'contains', 'not_contains'],
        size: 50
    },
    {
        id: 'x_requested_with',
        label: 'X-Requested-With',
        input: 'text',
        type: 'string',
        operators: ['in', 'not_in', 'contains', 'not_contains'],
        placeholder: 'com.android.chrome',
        size: 70
    },
    {
        id: 'bot',
        label: 'Bot',
        type: 'integer',
        input: 'radio',
        values: {
            0: 'Detected',
            1: 'NOT Detected'
        },
        operators: ['equal']
    },
    {
        id: 'uniqueness',
        label: 'Uniqueness',
        type: 'integer',
        input: 'radio',
        values: {
            1: 'Unique (first click)',
            0: 'Repeat'
        },
        operators: ['equal']
    },
    {
        id: 'timetable',
        label: 'Timetable (JSON)',
        type: 'string',
        input: 'text',
        operators: ['in', 'not_in'],
        placeholder: '[{"days":[1,2,3,4,5],"from":9,"to":18}] (1=Mon..7=Sun)',
        size: 70
    },
    {
        id: 'date_between',
        label: 'Date between',
        type: 'string',
        input: 'text',
        operators: ['in', 'not_in'],
        placeholder: '2021-01-01,2021-12-31',
        size: 50
    },
    {
        id: 'click_limit',
        label: 'Click limit (JSON)',
        type: 'string',
        input: 'text',
        operators: ['in'],
        placeholder: '{"window":"day","limit":1000} window: hour|day|total',
        size: 50
    },
    {
        id: 'urlparam',
        label: 'URL Parameter',
        type: 'string',
        input: 'text',                
        placeholder: ['URL parameter name', 'value(s) separated by comma'],
        operators: [
            'param_in',
            'param_not_in',
            'param_exists',
            'param_not_exists'
        ],
        size: 30
    }
];

var paramOperators = [
  {
    type: 'param_in',
    nb_inputs: 2,
    multiple: false,
    apply_to: ['string'],
    label: 'in'
  },
  {
    type: 'param_not_in',
    nb_inputs: 2,
    multiple: false,
    apply_to: ['string'],
    label: 'not in'
  },
  {
    type: 'param_exists',
    nb_inputs: 1,
    multiple: false,
    apply_to: ['string'],
    label: 'exists'
  },
  {
    type: 'param_not_exists',
    nb_inputs: 1,
    multiple: false,
    apply_to: ['string'],
    label: 'not exists'
  },
  {
    type: 'matches',
    nb_inputs: 1,
    multiple: false,
    apply_to: ['string'],
    label: 'matches (mask/regex)'
  },
  {
    type: 'not_matches',
    nb_inputs: 1,
    multiple: false,
    apply_to: ['string'],
    label: 'not matches (mask/regex)'
  }
];
