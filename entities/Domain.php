<?php

require_once __DIR__ . '/Entity.php';

/**
 * Domain entity — a first-class, reusable domain in the domain pool. Generic and
 * data-driven: a domain can be a plain hostname, a wildcard, or an alias that
 * points at a canonical domain. All Cloudflare/DNS attributes live in the
 * settings JSON bag so nothing is hardcoded.
 *
 * Common settings keys:
 *   type            string  regular | wildcard | alias
 *   alias_of        string  canonical host this alias resolves to (alias type)
 *   campaign_id     int     default ("index page") campaign for this domain:
 *                           served at the domain root and used as the 404 target
 *   intercept_404   bool    when true, unmatched paths fall back to the default
 *                           campaign instead of showing the not-found stub
 *   index_allowed   bool    when true, the domain is indexable (robots.txt
 *                           allows crawling); defaults to false (disallow all)
 *   cf_zone_id      string  Cloudflare zone id (for DNS automation)
 *   cf_api_token    string  Cloudflare API token (secret; never logged)
 *   dns_type        string  A | CNAME (record created via Cloudflare)
 *   dns_content     string  record value (IP for A, target host for CNAME)
 *   dns_proxied     bool    whether the Cloudflare record is proxied (orange)
 *   note            string  free-form note
 */
class Domain extends Entity
{
    public const TABLE = 'domains';

    public const TYPE_REGULAR  = 'regular';
    public const TYPE_WILDCARD = 'wildcard';
    public const TYPE_ALIAS    = 'alias';

    /** Hostname (stored in the core name column), normalized to lower case. */
    public function host(): string
    {
        return strtolower(trim((string)$this->name));
    }

    public function type(): string
    {
        $t = (string)$this->get('type', self::TYPE_REGULAR);
        return in_array($t, [self::TYPE_REGULAR, self::TYPE_WILDCARD, self::TYPE_ALIAS], true)
            ? $t
            : self::TYPE_REGULAR;
    }

    public function isAlias(): bool
    {
        return $this->type() === self::TYPE_ALIAS;
    }

    /** Canonical host this alias resolves to, lower-cased ('' when not an alias). */
    public function aliasOf(): string
    {
        return $this->isAlias() ? strtolower(trim((string)$this->get('alias_of', ''))) : '';
    }

    public function campaignId(): ?int
    {
        $id = $this->get('campaign_id');
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        return (int)$id;
    }

    /**
     * Default ("index page") campaign id for this domain, or null when unset.
     * Served at the domain root and used as the target for 404 interception.
     * Backed by the same `campaign_id` key as {@see campaignId()}.
     */
    public function defaultCampaignId(): ?int
    {
        return $this->campaignId();
    }

    /** Whether unmatched paths fall back to the default campaign (vs 404 stub). */
    public function intercept404(): bool
    {
        return (bool)$this->get('intercept_404', false);
    }

    /** Whether crawlers may index this domain (robots.txt allow vs disallow). */
    public function indexAllowed(): bool
    {
        return (bool)$this->get('index_allowed', false);
    }

    public function cfZoneId(): string
    {
        return (string)$this->get('cf_zone_id', '');
    }

    public function cfApiToken(): string
    {
        return (string)$this->get('cf_api_token', '');
    }

    public function dnsType(): string
    {
        $t = strtoupper((string)$this->get('dns_type', 'A'));
        return in_array($t, ['A', 'CNAME', 'AAAA', 'TXT'], true) ? $t : 'A';
    }

    public function dnsContent(): string
    {
        return (string)$this->get('dns_content', '');
    }

    public function dnsProxied(): bool
    {
        return (bool)$this->get('dns_proxied', false);
    }
}
