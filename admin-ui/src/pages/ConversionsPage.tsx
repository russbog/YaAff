import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { ExternalLink, TrendingUp } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Select } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { spa, API_BASE } from '@/lib/api';
import { fmtDateTime, fmtMoney, toNumber } from '@/lib/format';
import type { Conversion } from '@/lib/types';

function humanize(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function ConversionsPage() {
  const { campaignsList } = useBootstrap();
  const [campId, setCampId] = useState(0);

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['conversions', campId],
    queryFn: () => spa.conversions({ campId, limit: 1000 }),
  });

  const rows = useMemo(() => data?.data ?? [], [data]);

  const columns = useMemo<ColumnDef<Conversion, unknown>[]>(() => {
    const keys = new Set<string>();
    rows.slice(0, 30).forEach((r) => Object.keys(r).forEach((k) => keys.add(k)));
    const priority = ['time', 'clickid', 'status', 'payout', 'revenue', 'currency'];
    const ordered = Array.from(keys).sort((a, b) => {
      const ia = priority.indexOf(a);
      const ib = priority.indexOf(b);
      return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    });
    return ordered.map((key) => ({
      id: key,
      header: humanize(key),
      accessorFn: (r) => (r as Record<string, unknown>)[key],
      cell: ({ row }) => {
        const v = (row.original as Record<string, unknown>)[key];
        if (key === 'time') return <span className="tabular-nums text-muted">{fmtDateTime(v)}</span>;
        if (key === 'payout' || key === 'revenue') return <span className="tabular-nums">{fmtMoney(toNumber(v))}</span>;
        if (key === 'status' && v)
          return <Badge tone={String(v) === 'Purchase' ? 'success' : String(v) === 'Reject' ? 'danger' : 'info'}>{String(v)}</Badge>;
        const str = v === null || v === undefined ? '—' : String(v);
        return <span className="truncate max-w-[220px] inline-block align-middle" title={str}>{str}</span>;
      },
    }));
  }, [rows]);

  return (
    <AppShell
      title="Conversions"
      toolbar={
        <div className="flex items-center gap-2">
          <Select value={campId} onChange={(e) => setCampId(Number(e.target.value))} className="h-8 w-auto text-xs">
            <option value={0}>All campaigns</option>
            {campaignsList.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <a href={`${API_BASE}conversions.php`} target="_blank" rel="noreferrer">
            <Button variant="secondary" size="sm">
              <ExternalLink size={13} /> Import / classic
            </Button>
          </a>
        </div>
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <Badge tone="neutral">{rows.length} conversions</Badge>
          </div>
          <DataTable
            data={rows}
            columns={columns}
            loading={isLoading}
            rowHeight={38}
            emptyState={
              <EmptyState
                icon={<TrendingUp size={26} />}
                title="No conversions"
                description="Conversions land here as postbacks and S2S events are received."
              />
            }
          />
        </div>
      )}
    </AppShell>
  );
}
