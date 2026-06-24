import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import type { ColumnDef } from '@tanstack/react-table';
import { Search, ChevronLeft, ChevronRight, Table as TableIcon } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Select, Input } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Segmented } from '@/components/ui/Segmented';
import { DateRangePicker } from '@/components/ui/DateRangePicker';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { useRange } from '@/providers/RangeProvider';
import { spa } from '@/lib/api';
import { fmtDateTime } from '@/lib/format';
import type { ClicksQuery } from '@/lib/types';

type View = ClicksQuery['view'];
const VIEWS: { value: View; label: string }[] = [
  { value: 'allowed', label: 'Allowed' },
  { value: 'blocked', label: 'Blocked' },
  { value: 'leads', label: 'Leads' },
  { value: 'trafficback', label: 'Trafficback' },
];

const PAGE_SIZE = 200;

function humanize(key: string): string {
  return key.replace(/^param\./, '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const isView = (v: string | null): v is NonNullable<View> =>
  v === 'allowed' || v === 'blocked' || v === 'leads' || v === 'trafficback';

export function ReportsPage() {
  const { campaignsList } = useBootstrap();
  const { bounds } = useRange();
  const [searchParams] = useSearchParams();
  const initialView = searchParams.get('view');
  const [campId, setCampId] = useState<number>(campaignsList[0]?.id ?? 0);
  const [view, setView] = useState<View>(isView(initialView) ? initialView : 'allowed');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => setPage(1), [campId, view, debounced, bounds.start, bounds.end]);

  const needsCampaign = view !== 'trafficback';
  const enabled = !needsCampaign || campId > 0;

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['clicks', view, campId, page, debounced, bounds.start, bounds.end],
    queryFn: () =>
      spa.clicks({
        view,
        campId: needsCampaign ? campId : undefined,
        page,
        size: PAGE_SIZE,
        search: debounced || undefined,
        start: bounds.start,
        end: bounds.end,
      }),
    enabled,
    placeholderData: keepPreviousData,
  });

  const rows = useMemo(() => data?.data ?? [], [data]);
  const lastPage = data?.last_page ?? 1;

  const columns = useMemo<ColumnDef<Record<string, unknown>, unknown>[]>(() => {
    const keys = new Set<string>();
    rows.slice(0, 30).forEach((r) => Object.keys(r).forEach((k) => keys.add(k)));
    const ordered = Array.from(keys);
    // Surface a few important columns first.
    const priority = ['time', 'ip', 'country', 'flow', 'step', 'status', 'payout'];
    ordered.sort((a, b) => {
      const ia = priority.indexOf(a);
      const ib = priority.indexOf(b);
      return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    });
    return ordered.map((key) => ({
      id: key,
      header: humanize(key),
      accessorFn: (r) => r[key],
      enableSorting: false,
      cell: ({ row }) => {
        const v = row.original[key];
        if (key === 'time') return <span className="tabular-nums text-muted">{fmtDateTime(v)}</span>;
        if (key === 'status' && v)
          return <Badge tone={String(v) === 'Purchase' ? 'success' : 'info'}>{String(v)}</Badge>;
        const str = v === null || v === undefined ? '—' : String(v);
        return <span className="truncate max-w-[240px] inline-block align-middle" title={str}>{str}</span>;
      },
    }));
  }, [rows]);

  return (
    <AppShell
      title="Reports"
      toolbar={
        <div className="flex items-center gap-2">
          {needsCampaign && (
            <Select
              value={campId}
              onChange={(e) => setCampId(Number(e.target.value))}
              className="h-8 w-auto text-xs"
            >
              <option value={0}>Select campaign…</option>
              {campaignsList.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          )}
          <Segmented size="sm" value={view} options={VIEWS} onChange={setView} />
          <DateRangePicker />
        </div>
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-3">
            <div className="relative w-full max-w-xs">
              <Search size={15} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-faint" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search log…"
                className="pl-8"
                disabled={!enabled}
              />
            </div>
            <div className="flex items-center gap-3">
              {isFetching && <Badge tone="info" dot>Loading</Badge>}
              <div className="flex items-center gap-1">
                <Button
                  variant="secondary"
                  size="icon"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  <ChevronLeft size={16} />
                </Button>
                <span className="text-xs text-muted tabular-nums px-1">
                  {page} / {lastPage}
                </span>
                <Button
                  variant="secondary"
                  size="icon"
                  disabled={page >= lastPage}
                  onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                >
                  <ChevronRight size={16} />
                </Button>
              </div>
            </div>
          </div>

          {!enabled ? (
            <div className="card">
              <EmptyState
                icon={<TableIcon size={26} />}
                title="Pick a campaign"
                description="Select a campaign above to inspect its real-time traffic log."
              />
            </div>
          ) : (
            <DataTable
              data={rows}
              columns={columns}
              loading={isLoading}
              rowHeight={38}
              maxHeight={680}
              emptyState={
                <EmptyState title="No records" description="No traffic matched this view and range yet." />
              }
            />
          )}
        </div>
      )}
    </AppShell>
  );
}
