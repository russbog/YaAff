<?php

/**
 * Embed mode helper.
 *
 * The modern SPA (app.php) is the single admin interface. A handful of deep
 * technical tools (campaign settings / TDS builder, blacklist feeds, data
 * utilities, file editor) are hosted inside the SPA shell in an iframe. When a
 * legacy page is requested with ?embed=1 it renders chrome-free: the classic
 * top bar and primary navigation are suppressed so the page blends into the new
 * shell. No business logic is affected — this only gates presentation.
 */

function yaaff_is_embed(): bool
{
    static $embed = null;
    if ($embed !== null) {
        return $embed;
    }
    $v = $_GET['embed'] ?? $_REQUEST['embed'] ?? null;
    $embed = $v !== null && $v !== '' && $v !== '0' && $v !== 'false';
    return $embed;
}
