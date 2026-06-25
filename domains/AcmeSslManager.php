<?php

/**
 * Issues and renews Let's Encrypt certificates for pool domains that point
 * directly at this server (i.e. not fronted by Cloudflare), then wires them
 * into nginx so the domain serves valid HTTPS.
 *
 * Strategy: acme.sh in HTTP-01 *webroot* mode against the app docroot (served
 * by the existing nginx on :80), then a tiny per-host nginx server block on
 * :443 that includes a shared app snippet and the issued certificate. The
 * command/vhost builders are pure and unit-tested; the run* methods shell out
 * and therefore only do real work when executed as root (the cron monitor).
 *
 * Nothing here is hardcoded to a particular domain — every path is derived from
 * the host and the configured directories.
 */
class AcmeSslManager
{
    private string $acmeBin;
    private string $webroot;
    private string $certDir;
    private string $nginxConfDir;
    private string $appSnippet;
    private string $reloadCmd;

    public function __construct(
        string $acmeBin = '/root/.acme.sh/acme.sh',
        string $webroot = '/var/www/yaaff',
        string $certDir = '/etc/yaaff-ssl',
        string $nginxConfDir = '/etc/nginx/conf.d',
        string $appSnippet = '/etc/nginx/snippets/yaaff-app.conf',
        string $reloadCmd = 'nginx -s reload'
    ) {
        $this->acmeBin = $acmeBin;
        $this->webroot = rtrim($webroot, '/');
        $this->certDir = rtrim($certDir, '/');
        $this->nginxConfDir = rtrim($nginxConfDir, '/');
        $this->appSnippet = $appSnippet;
        $this->reloadCmd = $reloadCmd;
    }

    /** Accept only plain, sane hostnames before they ever reach a shell. */
    public static function isSafeHost(string $host): bool
    {
        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i', $host) === 1;
    }

    public function keyFile(string $host): string
    {
        return $this->certDir . '/' . $host . '/key.pem';
    }

    public function fullchainFile(string $host): string
    {
        return $this->certDir . '/' . $host . '/fullchain.pem';
    }

    public function vhostFile(string $host): string
    {
        return $this->nginxConfDir . '/yaaff-ssl-' . $host . '.conf';
    }

    /** Pure: the acme.sh command to issue a certificate via webroot HTTP-01. */
    public function issueCommand(string $host): string
    {
        return implode(' ', [
            escapeshellarg($this->acmeBin),
            '--issue',
            '-d', escapeshellarg($host),
            '-w', escapeshellarg($this->webroot),
            '--keylength', '2048',
        ]);
    }

    /** Pure: the acme.sh command to install the issued cert and set the reload hook. */
    public function installCommand(string $host): string
    {
        return implode(' ', [
            escapeshellarg($this->acmeBin),
            '--install-cert',
            '-d', escapeshellarg($host),
            '--key-file', escapeshellarg($this->keyFile($host)),
            '--fullchain-file', escapeshellarg($this->fullchainFile($host)),
            '--reloadcmd', escapeshellarg($this->reloadCmd),
        ]);
    }

    /** Pure: the per-host nginx server block that terminates TLS for the domain. */
    public function vhostConfig(string $host): string
    {
        $key = $this->keyFile($host);
        $chain = $this->fullchainFile($host);
        $snippet = $this->appSnippet;
        $root = $this->webroot;
        return <<<NGINX
# Managed by YaAff — TLS for {$host}. Do not edit by hand.
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name {$host};

    ssl_certificate     {$chain};
    ssl_certificate_key {$key};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    root {$root};

    # Pool domains are traffic-only: the admin panel and the management REST API
    # must not be reachable over them (defense in depth — the app 404s too).
    location ^~ /admin { return 404; }
    location ^~ /api/rest.php { return 404; }
    location ^~ /api/openapi.php { return 404; }

    include {$snippet};
}

NGINX;
    }

    /**
     * Issue (or renew) and install a certificate, write the nginx vhost and
     * reload. Only performs work as root. Never throws.
     *
     * @return array{ok:bool,host:string,steps:list<string>,error:?string}
     */
    public function provision(string $host): array
    {
        $host = strtolower(trim($host));
        $steps = [];
        if (!self::isSafeHost($host)) {
            return ['ok' => false, 'host' => $host, 'steps' => $steps, 'error' => 'unsafe hostname'];
        }
        if (!$this->isRoot()) {
            return ['ok' => false, 'host' => $host, 'steps' => $steps, 'error' => 'not running as root'];
        }

        @mkdir($this->certDir . '/' . $host, 0700, true);

        [$code, $out] = $this->run($this->issueCommand($host));
        $steps[] = 'issue: ' . trim($out);
        // acme.sh returns non-zero (2) when the cert is still valid / skipped;
        // treat that as success and proceed to (re)install.
        if ($code !== 0 && stripos($out, 'next renewal') === false && stripos($out, 'Skipping') === false && stripos($out, 'Cert success') === false) {
            return ['ok' => false, 'host' => $host, 'steps' => $steps, 'error' => 'acme issue failed'];
        }

        [$code2, $out2] = $this->run($this->installCommand($host));
        $steps[] = 'install: ' . trim($out2);
        if ($code2 !== 0) {
            return ['ok' => false, 'host' => $host, 'steps' => $steps, 'error' => 'acme install failed'];
        }

        $desired = $this->vhostConfig($host);
        $current = is_file($this->vhostFile($host)) ? (string)@file_get_contents($this->vhostFile($host)) : '';
        if ($current !== $desired) {
            @file_put_contents($this->vhostFile($host), $desired);
            $steps[] = $current === '' ? 'vhost: written' : 'vhost: updated';
            [$rc] = $this->run($this->reloadCmd . ' 2>&1');
            $steps[] = 'reload: rc=' . $rc;
        }

        return ['ok' => true, 'host' => $host, 'steps' => $steps, 'error' => null];
    }

    /**
     * Keep an already-issued domain's nginx vhost in sync with the current
     * template (e.g. after the isolation deny rules were added) without forcing
     * a full certificate re-issue. No-op unless the vhost already exists, the
     * host is safe and we run as root; reloads nginx only when the file changed.
     *
     * @return array{ok:bool,changed:bool,error:?string}
     */
    public function ensureVhostCurrent(string $host): array
    {
        $host = strtolower(trim($host));
        if (!self::isSafeHost($host)) {
            return ['ok' => false, 'changed' => false, 'error' => 'unsafe hostname'];
        }
        if (!$this->isRoot()) {
            return ['ok' => false, 'changed' => false, 'error' => 'not running as root'];
        }
        // Only manage a vhost we already own; never create one here (issuance is
        // provision()'s job and needs a certificate first).
        if (!is_file($this->vhostFile($host))) {
            return ['ok' => true, 'changed' => false, 'error' => null];
        }
        $desired = $this->vhostConfig($host);
        $current = (string)@file_get_contents($this->vhostFile($host));
        if ($current === $desired) {
            return ['ok' => true, 'changed' => false, 'error' => null];
        }
        @file_put_contents($this->vhostFile($host), $desired);
        [$rc] = $this->run($this->reloadCmd . ' 2>&1');
        return ['ok' => $rc === 0, 'changed' => true, 'error' => $rc === 0 ? null : 'reload failed'];
    }

    protected function isRoot(): bool
    {
        return function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;
    }

    /** @return array{0:int,1:string} [exitCode, combinedOutput] */
    protected function run(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        return [$code, implode("\n", $output)];
    }
}
