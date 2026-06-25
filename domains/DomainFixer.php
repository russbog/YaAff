<?php

require_once __DIR__ . '/CloudflareClient.php';
require_once __DIR__ . '/AcmeSslManager.php';

/**
 * Decides and applies the right remediation for an unhealthy pool {@see Domain},
 * routing by how the domain is fronted:
 *
 *   - Cloudflare (an API token + zone id are configured) → ensure the DNS
 *     record exists and Universal SSL is enabled, via the Cloudflare API. This
 *     is safe to run from a normal web request.
 *   - Direct (no Cloudflare) → issue/renew a Let's Encrypt certificate and wire
 *     it into nginx via {@see AcmeSslManager}. This needs root, so when invoked
 *     unprivileged (a web request) it is reported as "queued" for the cron
 *     monitor, which runs as root, to pick up.
 *
 * Nothing is hardcoded per-domain; the strategy is derived entirely from the
 * domain's own settings.
 */
class DomainFixer
{
    private AcmeSslManager $acme;

    public function __construct(?AcmeSslManager $acme = null)
    {
        $this->acme = $acme ?? new AcmeSslManager();
    }

    /** Whether this domain is fronted by Cloudflare (has API credentials). */
    public static function usesCloudflare(Domain $domain): bool
    {
        return $domain->cfApiToken() !== '' && $domain->cfZoneId() !== '';
    }

    /**
     * Apply remediation. $privileged should be true only when running as root
     * (the cron monitor), enabling the Let's Encrypt path to actually run.
     *
     * @return array{ok:bool,method:string,queued:bool,detail:string}
     */
    public function fix(Domain $domain, bool $privileged): array
    {
        if ($domain->type() !== Domain::TYPE_REGULAR) {
            return ['ok' => false, 'method' => 'none', 'queued' => false, 'detail' => 'Not applicable for wildcard/alias domains'];
        }

        if (self::usesCloudflare($domain)) {
            return $this->fixViaCloudflare($domain);
        }
        return $this->fixViaLetsEncrypt($domain, $privileged);
    }

    /** @return array{ok:bool,method:string,queued:bool,detail:string} */
    private function fixViaCloudflare(Domain $domain): array
    {
        $token = $domain->cfApiToken();
        $zone = $domain->cfZoneId();
        $steps = [];

        // 1) Ensure the DNS record exists (only when we have a value to set).
        if ($domain->dnsContent() !== '') {
            $list = CloudflareClient::listDnsRecords($token, $zone, $domain->host(), $domain->dnsType());
            $exists = $list['ok'] && is_array($list['result']) && count($list['result']) > 0;
            if (!$exists) {
                $create = CloudflareClient::createDnsRecord(
                    $token,
                    $zone,
                    $domain->dnsType(),
                    $domain->host(),
                    $domain->dnsContent(),
                    $domain->dnsProxied()
                );
                $steps[] = $create['ok'] ? 'DNS record created' : ('DNS create failed: ' . $create['error']);
            } else {
                $steps[] = 'DNS record present';
            }
        }

        // 2) Ensure Universal SSL is enabled for the zone.
        $ssl = CloudflareClient::getUniversalSsl($token, $zone);
        $enabled = $ssl['ok'] && (($ssl['result']['enabled'] ?? null) === true);
        if (!$enabled) {
            $set = CloudflareClient::setUniversalSsl($token, $zone, true);
            $steps[] = $set['ok'] ? 'Universal SSL enabled' : ('Universal SSL enable failed: ' . $set['error']);
            $enabled = $set['ok'];
        } else {
            $steps[] = 'Universal SSL already enabled';
        }

        return [
            'ok' => $enabled,
            'method' => 'cloudflare',
            'queued' => false,
            'detail' => implode('; ', $steps),
        ];
    }

    /** @return array{ok:bool,method:string,queued:bool,detail:string} */
    private function fixViaLetsEncrypt(Domain $domain, bool $privileged): array
    {
        if (!$privileged) {
            return [
                'ok' => true,
                'method' => 'letsencrypt',
                'queued' => true,
                'detail' => 'Certificate issuance queued — the server will provision it within a few minutes',
            ];
        }
        $res = $this->acme->provision($domain->host());
        return [
            'ok' => $res['ok'],
            'method' => 'letsencrypt',
            'queued' => false,
            'detail' => $res['ok'] ? ('Certificate provisioned: ' . implode('; ', $res['steps'])) : ('Provision failed: ' . (string)$res['error']),
        ];
    }
}
