<?php

function set_cookie($name, $value, $sessionOnly = false): void
{
    if (!$sessionOnly) {
        $path = '/';
        $expires = time() + 60 * 60 * 24 * 5; //time to live for cookies - 5 days
        header("Set-Cookie: {$name}={$value}; Expires={$expires}; Path={$path}; SameSite=None; Secure", false);
    }
    session_write($name, $value);
}

function session_write($name, $value)
{
    get_session();
    $_SESSION[$name] = $value;
    session_write_close();
}

function session_read($name)
{
    get_session(true);
    return $_SESSION[$name] ?? null;
}

function session_remove($name){
    get_session();
    unset($_SESSION[$name]);
    session_write_close();
}

function get_cookie($name): string
{
    get_session(true);
    return $_COOKIE[$name] ?? $_SESSION[$name] ?? '';
}

function get_userid(): string
{
    return get_cookie('userid');
}

function set_userid(): string
{
    $uid = get_userid();
    if (empty($uid)) {
        $uid = uniqid();
    }
    set_cookie('userid', $uid);
    return $uid;
}

function generate_clickid(string $userid): string
{
    $raw = hash('xxh128', $userid . microtime(true) . random_bytes(8), true);
    return substr(strtr(rtrim(base64_encode($raw), '='), '+/', '-_'), 0, 12);
}

function set_clickid(string $clickid): void
{
    session_write('clickid', $clickid);
}

function get_clickid(): string
{
    return session_read('clickid') ?? '';
}

//if the user has already converted before with the same data return true
function has_conversion_cookies(array $data): bool
{
    $cdata = get_cookie('postmd5');
    $ctime = get_cookie('ctime');

    if (empty($ctime) || empty($cdata)) {
        set_conversion_cookies($data);
        return false;
    }

    $curmd5 = md5(json_encode($data));
    if ($cdata !== $curmd5) {
        set_conversion_cookies($data);
        return false;
    }

    $currentTimestamp = (new DateTime())->getTimestamp();
    $secondsDiff = $currentTimestamp - $ctime;
    if ($secondsDiff < 24 * 60 * 60) {
        set_cookie('ctime', $currentTimestamp);
        return true;
    }

    set_conversion_cookies($data);
    return false;
}

function set_conversion_cookies(array $data): void
{
    $curmd5 = md5(json_encode($data));
    set_cookie('postmd5', $curmd5);
    set_cookie('ctime', (new DateTime())->getTimestamp());
}

function get_session($readOnly = false)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        "SameSite" => $isSecure ? "None" : "Lax",
        "Secure"   => $isSecure,
        "HttpOnly" => false,
    ]);
    
    if ($readOnly) {
        session_start(['read_and_close' => true]);
    } else {
        session_start();
    }
}