import { useMemo } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, ShieldCheck, ShieldAlert } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner, EmptyState, ErrorState } from '@/components/ui/States';
import { useToast } from '@/providers/ToastProvider';
import { blacklistApi, type BlacklistFeed } from '@/lib/api';

function fmtIso(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function FeedRow({ f }: { f: BlacklistFeed }) {
  return (
    <tr className="border-t border-border hover:bg-surface-2/40">
      <td className="px-3 py-2 font-medium">{f.name}</td>
      <td className="px-3 py-2 text-muted uppercase text-2xs tracking-wide">{f.type}</td>
      <td className="px-3 py-2">{f.tag ? <Badge tone="neutral">{f.tag}</Badge> : <span className="text-faint">—</span>}</td>
      <td className="px-3 py-2">
        <Badge tone={f.enabled ? 'success' : 'neutral'}>{f.enabled ? 'enabled' : 'off'}</Badge>
      </td>
      <td className="px-3 py-2">
        {f.cached ? (
          <span className="inline-flex items-center gap-1 text-success">
            <ShieldCheck size={14} /> cached
          </span>
        ) : (
          <span className="inline-flex items-center gap-1 text-warning">
            <ShieldAlert size={14} /> none
          </span>
        )}
      </td>
      <td className="px-3 py-2 tabular-nums text-right">{f.entries.toLocaleString()}</td>
      <td className="px-3 py-2 text-muted tabular-nums">{fmtIso(f.updated_at)}</td>
    </tr>
  );
}

export function BotProtectionPage() {
  const toast = useToast();
  const qc = useQueryClient();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['blacklists'],
    queryFn: () => blacklistApi.status(),
  });

  const updateMut = useMutation({
    mutationFn: () => blacklistApi.update(),
    onSuccess: (r) => {
      const ok = r.results.filter((x) => x.ok && !x.skipped).length;
      const fail = r.results.filter((x) => !x.ok && !x.skipped).length;
      qc.setQueryData(['blacklists'], { ok: true, feeds: r.feeds });
      if (fail) toast.error(`Updated ${ok} feed(s), ${fail} failed`);
      else toast.success(`Updated ${ok} feed(s)`);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const feeds = useMemo(() => data?.feeds ?? [], [data]);
  const totalEntries = feeds.reduce((s, f) => s + f.entries, 0);

  return (
    <AppShell
      title="Bot Protection"
      toolbar={
        <Button variant="primary" size="sm" loading={updateMut.isPending} onClick={() => updateMut.mutate()}>
          <RefreshCw size={14} /> Update now
        </Button>
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-muted">
            Offline IP/UA blacklist feeds — matched without per-click network calls. Feeds are configured in{' '}
            <code className="text-fg">bases/blacklists/feeds.json</code>; schedule refresh via cron{' '}
            <code className="text-fg">php bases/update_blacklists.php</code>.
          </p>
          <div className="flex items-center gap-2">
            <Badge tone="neutral">{feeds.length} feeds</Badge>
            <Badge tone="info">{totalEntries.toLocaleString()} cached entries</Badge>
          </div>
          <div className="rounded-lg border border-border bg-surface overflow-hidden">
            {isLoading ? (
              <div className="py-16 grid place-items-center">
                <Spinner className="h-6 w-6" />
              </div>
            ) : feeds.length === 0 ? (
              <EmptyState icon={<ShieldCheck size={26} />} title="No feeds" description="Configure feeds in bases/blacklists/feeds.json." />
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-surface-2/40 text-2xs uppercase tracking-wide text-muted">
                  <tr>
                    <th className="px-3 py-2 text-left font-semibold">Feed</th>
                    <th className="px-3 py-2 text-left font-semibold">Type</th>
                    <th className="px-3 py-2 text-left font-semibold">Tag</th>
                    <th className="px-3 py-2 text-left font-semibold">Enabled</th>
                    <th className="px-3 py-2 text-left font-semibold">Cache</th>
                    <th className="px-3 py-2 text-right font-semibold">Entries</th>
                    <th className="px-3 py-2 text-left font-semibold">Updated</th>
                  </tr>
                </thead>
                <tbody>
                  {feeds.map((f) => (
                    <FeedRow key={`${f.name}:${f.type}`} f={f} />
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}
    </AppShell>
  );
}
