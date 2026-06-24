<?php
require_once __DIR__ . '/macros.php';
require_once __DIR__ . '/redirects/RedirectStrategy.php';

function redirect($url, $redirect_type = 302, $rep_macros = false)
{
    $url = urldecode($url);
    if ($rep_macros) {
        $mp = new MacrosProcessor();
        $url = $mp->replace_url_macros($url);
    }

    // Legacy: 'js' returns the script string for callers that echo it.
    if ($redirect_type === 'js') {
        return "<script type='text/javascript'>window.location='$url';</script>";
    }

    $mode = RedirectStrategy::normalizeMode($redirect_type);

    if (RedirectStrategy::isClientMode($mode)) {
        echo RedirectStrategy::render($mode, $url);
        return '';
    }

    if ($mode === 'http_404') {
        http_response_code(404);
        return '';
    }

    $code = is_int($redirect_type) ? $redirect_type : (ctype_digit((string)$redirect_type) ? (int)$redirect_type : 302);
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $url, true, $code);
    return '';
}
