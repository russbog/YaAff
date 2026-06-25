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
        // Substitute {token} placeholders anywhere in the URL (path + query
        // values), resolving custom passthrough params by bare name and
        // dropping unknown tokens to empty — Keitaro-style. Resolution is
        // centralized in the shared TokenRegistry.
        return $this->registry->renderUrl((string)$url);
    }
}
