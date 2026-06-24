<?php

require_once __DIR__ . '/Entity.php';

/**
 * Offer entity. Generic and data-driven.
 *
 * Common settings keys:
 *   network_id    int     linked affiliate network (optional)
 *   type          string  "redirect" (remote URL) or "local" (local landing)
 *   url           string  target URL with tokens (for redirect offers)
 *   redirect_type string  http_302 | js | meta | curl | ... (see Phase 2)
 *   payout        float   fixed payout value
 *   payout_type   string  cpa | cpc | cpl | revshare | ...
 *   payout_param  string  query param to read dynamic payout from (optional)
 *   currency      string  payout currency
 *   geo           string  geo note / restriction (free-form)
 *   cap_daily     int     daily conversion cap (0 = unlimited)
 *   cap_total     int     total conversion cap (0 = unlimited)
 *   multi_values  array   list of { name, value } extra token pairs
 *   note          string  free-form note
 */
class Offer extends Entity
{
    public const TABLE = 'offers';

    public function type(): string
    {
        return (string)$this->get('type', 'redirect');
    }

    public function url(): string
    {
        return (string)$this->get('url', '');
    }

    public function networkId(): ?int
    {
        $id = $this->get('network_id');
        return ($id === null || $id === '') ? null : (int)$id;
    }

    public function payout(): float
    {
        return (float)$this->get('payout', 0);
    }

    public function currency(): string
    {
        return (string)$this->get('currency', 'USD');
    }

    public function capDaily(): int
    {
        return (int)$this->get('cap_daily', 0);
    }

    public function capTotal(): int
    {
        return (int)$this->get('cap_total', 0);
    }
}
