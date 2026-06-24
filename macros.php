<?php
require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/db/db.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/tokens/TokenRegistry.php';

/**
 * Resolves {token} placeholders in landing HTML and outgoing URLs. Token
 * resolution is delegated to the shared {@see TokenRegistry} so every layer
 * (landings, URLs, postbacks, Conversion API) understands the same tokens.
 */
class MacrosProcessor
{
    private TokenRegistry $registry;

    public function __construct(?Campaign $campaign = null, ?array $clickParams = null, ?string $clickid = null, ?string $userid = null)
    {
        $cid = $clickid ?? get_clickid();
        $uid = $userid ?? get_userid();
        $click = is_array($clickParams) ? $clickParams : [];
        $loader = static function () use ($cid): ?array {
            global $db;
            if (empty($cid) || !isset($db)) {
                add_log("macros", "Couldn't load click for macros. Clickid not set!");
                return null;
            }
            return $db->get_click_by_clickid($cid);
        };
        $this->registry = new TokenRegistry($cid, $uid, $click, [], $loader);
    }

    public function replace_html_macros($html): string
    {
        $html = str_replace(
            ['{clickid}', '{userid}', '{px}'],
            [
                (string)($this->registry->resolve('clickid') ?? ''),
                (string)($this->registry->resolve('userid') ?? ''),
                (string)($this->registry->resolve('px') ?? ''),
            ],
            $html
        );
        return $this->registry->render($html);
    }

    public function replace_url_macros($url): string
    {
        if (empty($url)) return "";
        $url_components = parse_url($url);
        if ($url_components === false) {
            return $url;
        }
        parse_str($url_components['query'] ?? '', $query_array);

        // Replace query values that are exactly a {macro} with their token value.
        foreach ($query_array as $qk => $qv) {
            if (empty($qv))
                continue;
            if ($qv[0] !== '{' || $qv[strlen($qv) - 1] !== '}')
                continue; //we need only macroses

            $macro = substr($qv, 1, strlen($qv) - 2);
            $macroValue = $this->registry->resolve($macro);
            if ($macroValue === null) {
                add_log("macros", "Couldn't find macros: $macro for url $url");
                continue;
            }
            $query_array[$qk] = $macroValue;
        }

        // Build the new query string
        $new_query = http_build_query($query_array);

        // Rebuild the URL (supports both absolute and relative URLs)
        $new_url = '';
        if (isset($url_components['scheme'])) {
            $new_url .= $url_components['scheme'] . '://';
        }
        if (isset($url_components['user'])) {
            $new_url .= $url_components['user'];
            if (isset($url_components['pass'])) {
                $new_url .= ':' . $url_components['pass'];
            }
            $new_url .= '@';
        }
        if (isset($url_components['host'])) {
            $new_url .= $url_components['host'];
        }
        if (isset($url_components['port'])) {
            $new_url .= ':' . $url_components['port'];
        }
        if (isset($url_components['path'])) {
            $new_url .= $url_components['path'];
        }
        if ($new_query) {
            $new_url .= '?' . $new_query;
        }
        if (isset($url_components['fragment'])) {
            $new_url .= '#' . $url_components['fragment'];
        }

        return $new_url === '' ? $url : $new_url;
    }
}
