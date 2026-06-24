<?php

/**
 * Unified redirect/tracking modes (Keitaro parity).
 *
 * Server-side modes (handled by the caller, not here):
 *   - http_301 / http_302 / http_307 : Location header (numeric types kept).
 *   - http_404                        : status code.
 *   - curl / remote                   : reverse-proxy via directload.php.
 *
 * Client-side modes rendered here as self-contained HTML:
 *   - js             : window.location assignment.
 *   - meta           : <meta http-equiv="refresh">.
 *   - double_meta    : meta refresh through about:blank to drop the referrer.
 *   - blank_referrer : JS redirect that scrubs document.referrer.
 *   - formsubmit     : auto-submitting POST form (no referrer leak, passes data).
 *   - iframe         : target loaded full-screen in an iframe.
 *
 * Content modes:
 *   - inline         : raw body echoed as-is (Content-Type configurable).
 *   - custom_json    : caller-provided body with a configurable Content-Type.
 */
class RedirectStrategy
{
    public const CLIENT_MODES = ['js', 'meta', 'double_meta', 'blank_referrer', 'formsubmit', 'iframe'];

    /** Map a numeric/legacy redirect type to a normalized mode string. */
    public static function normalizeMode(int|string $type): string
    {
        if (is_int($type) || ctype_digit((string)$type)) {
            return 'http_' . (int)$type;
        }
        return (string)$type;
    }

    public static function isClientMode(string $mode): bool
    {
        return in_array($mode, self::CLIENT_MODES, true);
    }

    /** True when this mode produces HTML output rather than an HTTP header. */
    public static function isHtmlMode(string $mode): bool
    {
        return self::isClientMode($mode) || $mode === 'inline';
    }

    /**
     * Render a client-side redirect to HTML. $opts may carry:
     *   - 'data'  => array of key=>value posted by formsubmit
     *   - 'method'=> form method (default POST)
     */
    public static function render(string $mode, string $url, array $opts = []): string
    {
        $u = htmlspecialchars($url, ENT_QUOTES);
        $jsUrl = json_encode($url);
        switch ($mode) {
            case 'js':
                return "<script>window.location.href={$jsUrl};</script>";
            case 'meta':
                return "<!doctype html><html><head><meta http-equiv=\"refresh\" content=\"0;url={$u}\"></head><body></body></html>";
            case 'double_meta':
                return "<!doctype html><html><head>"
                    . "<meta http-equiv=\"refresh\" content=\"0;url=about:blank\">"
                    . "<script>window.location.replace({$jsUrl});</script>"
                    . "</head><body></body></html>";
            case 'blank_referrer':
                return "<!doctype html><html><head>"
                    . "<meta name=\"referrer\" content=\"no-referrer\">"
                    . "<script>window.location.replace({$jsUrl});</script>"
                    . "</head><body></body></html>";
            case 'iframe':
                return "<!doctype html><html><head><meta name=\"referrer\" content=\"no-referrer\">"
                    . "<style>html,body{margin:0;height:100%}iframe{border:0;width:100%;height:100%}</style></head>"
                    . "<body><iframe src=\"{$u}\" allowfullscreen></iframe></body></html>";
            case 'formsubmit':
                return self::renderForm($url, $opts);
            default:
                return "<script>window.location.href={$jsUrl};</script>";
        }
    }

    private static function renderForm(string $url, array $opts): string
    {
        $u = htmlspecialchars($url, ENT_QUOTES);
        $method = strtoupper((string)($opts['method'] ?? 'POST'));
        $method = in_array($method, ['POST', 'GET'], true) ? $method : 'POST';
        $fields = '';
        foreach (($opts['data'] ?? []) as $k => $v) {
            $fk = htmlspecialchars((string)$k, ENT_QUOTES);
            $fv = htmlspecialchars((string)$v, ENT_QUOTES);
            $fields .= "<input type=\"hidden\" name=\"{$fk}\" value=\"{$fv}\">";
        }
        return "<!doctype html><html><head><meta name=\"referrer\" content=\"no-referrer\"></head>"
            . "<body onload=\"document.forms[0].submit()\">"
            . "<form action=\"{$u}\" method=\"{$method}\">{$fields}</form></body></html>";
    }
}
