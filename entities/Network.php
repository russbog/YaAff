<?php

require_once __DIR__ . '/Entity.php';

/**
 * Affiliate network entity. Generic, data-driven: every network attribute is a
 * key in the schemaless settings bag so any network can be modelled without
 * code changes.
 *
 * Common settings keys:
 *   currency        string  default payout currency (e.g. "USD")
 *   postback_url    string  incoming postback URL template handed to the network
 *   status_map      array   external status name => internal status
 *                           (one of: lead, sale, rejected, hold)
 *   offer_param     string  template for offer URLs provided by the network
 *   note            string  free-form note
 */
class Network extends Entity
{
    public const TABLE = 'networks';

    public function currency(): string
    {
        return (string)$this->get('currency', 'USD');
    }

    /** @return array<string,string> external status => internal status */
    public function statusMap(): array
    {
        $map = $this->get('status_map', []);
        return is_array($map) ? $map : [];
    }

    /** Map a network's raw status string to an internal status, or null. */
    public function mapStatus(string $external): ?string
    {
        $map = $this->statusMap();
        if (isset($map[$external])) {
            return (string)$map[$external];
        }
        foreach ($map as $key => $internal) {
            if (strcasecmp((string)$key, $external) === 0) {
                return (string)$internal;
            }
        }
        return null;
    }
}
