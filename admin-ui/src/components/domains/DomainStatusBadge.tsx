import { Badge } from '@/components/ui/Badge';
import type { DomainHealth, DomainStatus } from '@/lib/api';

type Tone = 'neutral' | 'brand' | 'success' | 'warning' | 'danger' | 'info';

// Visual mapping for each health state: tone + the short label shown in the
// table. Kept here so the column and the tools modal render statuses identically.
const META: Record<DomainHealth, { tone: Tone; label: string }> = {
  ok: { tone: 'success', label: 'OK' },
  dns_await: { tone: 'warning', label: 'DNS await' },
  dns_error: { tone: 'danger', label: 'DNS error' },
  ssl_await: { tone: 'warning', label: 'SSL await' },
  ssl_error: { tone: 'danger', label: 'SSL error' },
  unreachable: { tone: 'neutral', label: 'Unreachable' },
  na: { tone: 'neutral', label: 'N/A' },
};

export function DomainStatusBadge({ status }: { status?: DomainStatus }) {
  if (!status) {
    return (
      <Badge tone="neutral" dot>
        Not checked
      </Badge>
    );
  }
  const meta = META[status.status] ?? META.unreachable;
  // OK but flagged for renewal reads better as a soft warning.
  const tone: Tone = status.status === 'ok' && status.needs_fix ? 'warning' : meta.tone;
  const label = status.status === 'ok' && status.needs_fix ? 'OK · renewing' : meta.label;
  return (
    <span className="inline-flex items-center gap-1.5" title={status.detail}>
      <Badge tone={tone} dot>
        {label}
      </Badge>
      {status.cloudflare && (
        <Badge tone="info" className="hidden sm:inline-flex">
          CF
        </Badge>
      )}
    </span>
  );
}
