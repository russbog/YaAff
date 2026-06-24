<?php

require_once __DIR__ . '/Entity.php';

/**
 * Traffic source entity. Generic and data-driven.
 *
 * Common settings keys:
 *   param_map       array   list of { alias, token, macro } describing how
 *                           incoming query params map onto tracker tokens and
 *                           which source macro emits the click id back
 *   postback_url    string  outgoing S2S postback template sent to the source
 *   postback_statuses array list of internal statuses that trigger the postback
 *   cost_param      string  incoming query param carrying click cost
 *   cost_currency   string  currency of the cost value
 *   cost_updater    array   config for the scheduled cost-update hook
 *   note            string  free-form note
 */
class Source extends Entity
{
    public const TABLE = 'sources';

    /** @return list<array{alias?:string,token?:string,macro?:string}> */
    public function paramMap(): array
    {
        $map = $this->get('param_map', []);
        return is_array($map) ? array_values($map) : [];
    }

    public function postbackUrl(): string
    {
        return (string)$this->get('postback_url', '');
    }

    /** @return list<string> */
    public function postbackStatuses(): array
    {
        $s = $this->get('postback_statuses', []);
        return is_array($s) ? array_values(array_map('strval', $s)) : [];
    }

    public function costParam(): string
    {
        return (string)$this->get('cost_param', '');
    }
}
