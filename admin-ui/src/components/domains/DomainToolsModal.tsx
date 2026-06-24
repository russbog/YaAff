import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Globe, ShieldCheck, CloudCog, CheckCircle2, XCircle } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/States';
import { useToast } from '@/providers/ToastProvider';
import { domainApi, cloudflareApi, type DomainCheck } from '@/lib/api';
import type { EntityRecord } from '@/lib/types';

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1.5 border-b border-border/60 last:border-0">
      <span className="text-xs text-muted">{label}</span>
      <span className="text-sm font-medium text-right">{children}</span>
    </div>
  );
}

// Native DNS + Cloudflare tools for a pool Domain (domaincheck.php /
// cloudflare.php). The Cloudflare actions use the per-domain token/zone stored
// in the domain's settings.
export function DomainToolsModal({
  domain,
  onClose,
}: {
  domain: EntityRecord;
  onClose: () => void;
}) {
  const toast = useToast();
  const settings = (domain.settings ?? {}) as { cf_api_token?: string; cf_zone_id?: string };
  const hasCf = Boolean(settings.cf_api_token);
  const [check, setCheck] = useState<DomainCheck | null>(null);

  const checkMut = useMutation({
    mutationFn: () => domainApi.check(domain.name),
    onSuccess: (r) => {
      setCheck(r);
      if (r.error) toast.error(r.error);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const verifyMut = useMutation({
    mutationFn: () => cloudflareApi.verifyToken(domain.id),
    onSuccess: (r) => (r.ok ? toast.success('Cloudflare token is valid') : toast.error(r.error || 'Token invalid')),
    onError: (e: Error) => toast.error(e.message),
  });

  const createMut = useMutation({
    mutationFn: () => cloudflareApi.createRecord(domain.id),
    onSuccess: (r) => (r.ok ? toast.success('DNS record created') : toast.error(r.error || 'Create failed')),
    onError: (e: Error) => toast.error(e.message),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={
        <span className="flex items-center gap-2">
          <Globe size={16} /> {domain.name}
        </span>
      }
      description="DNS resolution and Cloudflare automation for this domain."
      footer={
        <Button variant="ghost" onClick={onClose}>
          Close
        </Button>
      }
    >
      <div className="space-y-4">
        <section className="space-y-2">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold">DNS check</h3>
            <Button variant="secondary" size="sm" loading={checkMut.isPending} onClick={() => checkMut.mutate()}>
              <ShieldCheck size={14} /> Check DNS
            </Button>
          </div>
          {checkMut.isPending ? (
            <div className="grid place-items-center py-4">
              <Spinner />
            </div>
          ) : check ? (
            <div className="rounded-md border border-border bg-surface-2/40 px-3 py-1">
              <Row label="Resolves">
                {check.wildcard ? (
                  <Badge tone="neutral">Wildcard (skipped)</Badge>
                ) : check.resolves ? (
                  <Badge tone="success">Yes</Badge>
                ) : (
                  <Badge tone="danger">No</Badge>
                )}
              </Row>
              <Row label="A record IP">{check.ip ?? '—'}</Row>
              <Row label="Server IP">{check.serverIp ?? '—'}</Row>
              <Row label="Points to server">
                {check.ip && check.serverIp ? (
                  check.ip === check.serverIp ? (
                    <Badge tone="success">Match</Badge>
                  ) : (
                    <Badge tone="warning">Different</Badge>
                  )
                ) : (
                  '—'
                )}
              </Row>
              <Row label="Cloudflare">
                {check.cloudflare ? <Badge tone="info">Detected</Badge> : <Badge tone="neutral">No</Badge>}
              </Row>
              {check.error && <p className="py-1 text-xs text-danger">{check.error}</p>}
            </div>
          ) : (
            <p className="text-xs text-faint">Run a check to resolve the domain and detect Cloudflare.</p>
          )}
        </section>

        <section className="space-y-2">
          <h3 className="text-sm font-semibold flex items-center gap-1.5">
            <CloudCog size={15} /> Cloudflare
          </h3>
          {hasCf ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" size="sm" loading={verifyMut.isPending} onClick={() => verifyMut.mutate()}>
                <CheckCircle2 size={14} /> Verify token
              </Button>
              <Button
                variant="secondary"
                size="sm"
                loading={createMut.isPending}
                disabled={!settings.cf_zone_id}
                onClick={() => createMut.mutate()}
                title={settings.cf_zone_id ? undefined : 'Set a Cloudflare zone id first'}
              >
                <CloudCog size={14} /> Create DNS record
              </Button>
            </div>
          ) : (
            <p className="text-xs text-faint flex items-center gap-1.5">
              <XCircle size={13} /> No Cloudflare API token set for this domain. Add one in the domain editor to enable automation.
            </p>
          )}
        </section>
      </div>
    </Modal>
  );
}
