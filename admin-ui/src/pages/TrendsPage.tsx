import { useId, useMemo, useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { Download, Activity } from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card, CardHeader } from '@/components/ui/Card';
import { Select } from '@/components/ui/Field';
import { Button } from '@/components/ui/Button';
import { Segmented } from '@/components/ui/Segmented';
import { DateRangePicker } from '@/components/ui/DateRangePicker';
import { Skeleton, ErrorState, EmptyState } from '@/components/ui/States';
import { DataTable } from '@/components/data/DataTable';
import type { ColumnDef } from '@tanstack/react-table';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { useRange } from '@/providers/RangeProvider';
import { spa } from '@/lib/api';
import { fmtCompact, fmtMoney, fmtPct, fmtInt } from '@/lib/format';
import { downloadCsv } from '@/lib/csv';
import type { TrendGranularity, TrendPoint } from '@/lib/types';

type Kind = 'int' | 'money' | 'pct';
interface Metric {
  field: keyof TrendPoint;
  label: string;
  kind: Kind;
}

// Metrics plottable / tabulated on the Trends page, in display order.
const METRICS: Metric[] = [
  { field: 'clicks', label: 'Clicks', kind: 'int' },
  { field: 'uniques', label: 'Uniques', kind: 'int' },
  { field: 'conversions', label: 'Conversions', kind: 'int' },
  { field: 'leads', label: 'Leads', kind: 'int' },
  { field: 'purchases', label: 'Sales', kind: 'int' },
  { field: 'revenue', label: 'Revenue', kind: 'money' },
  { field: 'cost', label: 'Cost', kind: 'money' },
  { field: 'profit', label: 'Profit', kind: 'money' },
  { field: 'roi', label: 'ROI', kind: 'pct' },
  { field: 'cr', label: 'CR', kind: 'pct' },
  { field: 'epc', label: 'EPC', kind: 'money' },
  { field: 'cpc', label: 'CPC', kind: 'money' },
];

const GRANULARITIES: { value: TrendGranularity; label: string }[] = [
  { value: 'hour', label: 'Hour' },
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
];

function fmt(kind: Kind, v: number): string {
  if (kind === 'money') return fmtMoney(v);
  if (kind === 'pct') return fmtPct(v);
  return fmtInt(v);
}

export function TrendsPage() {
  const { campaignsList } = useBootstrap();
  const { bounds } = useRange();
  const [campId, setCampId] = useState<number>(campaignsList[0]?.id ?? 0);
  const [granularity, setGranularity] = useState<TrendGranularity>('day');
  const [metric, setMetric] = useState<keyof TrendPoint>('clicks');

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['trends', campId, granularity, bounds.start, bounds.end],
    queryFn: () => spa.trends({ campId, granularity, start: bounds.start, end: bounds.end }),
    enabled: campId > 0,
    placeholderData: keepPreviousData,
  });

  const series = useMemo(() => data?.series ?? [], [data]);
  const activeMetric = METRICS.find((m) => m.field === metric) ?? METRICS[0];

  const exportCsv = () => {
    if (series.length === 0) return;
    const headers = ['Period', ...METRICS.map((m) => m.label)];
    const body = series.map((p) => [
      p.bucket,
      ...METRICS.map((m) => String(p[m.field] ?? 0)),
    ]);
    downloadCsv(`trends-${granularity}-${new Date().toISOString().slice(0, 10)}.csv`, headers, body);
  };

  const columns = useMemo<ColumnDef<TrendPoint, unknown>[]>(() => {
    return [
      {
        id: 'bucket',
        header: 'Period',
        accessorKey: 'bucket',
        cell: ({ row }) => <span className="tabular-nums text-muted">{row.original.bucket}</span>,
      },
      ...METRICS.map((m) => ({
        id: m.field as string,
        header: m.label,
        accessorFn: (r: TrendPoint) => r[m.field],
        cell: ({ row }: { row: { original: TrendPoint } }) => (
          <span className="tabular-nums">{fmt(m.kind, Number(row.original[m.field] ?? 0))}</span>
        ),
      })),
    ];
  }, []);

  return (
    <AppShell
      title="Trends"
      toolbar={
        <div className="flex items-center gap-2">
          <Select value={campId} onChange={(e) => setCampId(Number(e.target.value))} className="h-8 w-auto text-xs">
            {campaignsList.length === 0 && <option value={0}>No campaigns</option>}
            {campaignsList.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <Segmented options={GRANULARITIES} value={granularity} onChange={setGranularity} size="sm" />
          <DateRangePicker />
          <Button variant="secondary" size="sm" onClick={exportCsv} disabled={series.length === 0}>
            <Download size={14} /> CSV
          </Button>
        </div>
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : campId <= 0 ? (
        <EmptyState icon={<Activity size={26} />} title="Select a campaign" description="Trends are computed per campaign over the selected time range." />
      ) : (
        <div className="space-y-5">
          <Card>
            <CardHeader
              title="Metric over time"
              subtitle={isFetching ? 'Updating…' : `${series.length} ${granularity} buckets`}
              actions={
                <Select
                  value={String(metric)}
                  onChange={(e) => setMetric(e.target.value as keyof TrendPoint)}
                  className="h-7 w-auto text-xs"
                >
                  {METRICS.map((m) => (
                    <option key={m.field as string} value={m.field as string}>
                      {m.label}
                    </option>
                  ))}
                </Select>
              }
            />
            <div className="p-4">
              {isLoading ? (
                <Skeleton className="h-[240px]" />
              ) : (
                <TrendChart series={series} metric={activeMetric} />
              )}
            </div>
          </Card>

          <Card>
            <CardHeader title="Breakdown by period" />
            <DataTable
              data={series}
              columns={columns}
              loading={isLoading}
              rowHeight={36}
              emptyState={
                <EmptyState icon={<Activity size={26} />} title="No data in range" description="No traffic recorded for this campaign in the selected window." />
              }
            />
          </Card>
        </div>
      )}
    </AppShell>
  );
}

// Lightweight single-metric line chart (dependency-free inline SVG).
function TrendChart({ series, metric }: { series: TrendPoint[]; metric: Metric }) {
  const gid = useId();
  const [hover, setHover] = useState<number | null>(null);
  const width = 800;
  const height = 240;
  const padding = { top: 12, right: 12, bottom: 26, left: 44 };
  const innerW = width - padding.left - padding.right;
  const innerH = height - padding.top - padding.bottom;

  const { path, area, max, points } = useMemo(() => {
    const n = series.length;
    const vals = series.map((p) => Number(p[metric.field] ?? 0));
    const maxVal = Math.max(1, ...vals);
    const x = (i: number) => padding.left + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
    const y = (v: number) => padding.top + innerH - (v / maxVal) * innerH;
    const line = vals.map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
    const areaPath =
      n > 0
        ? `${line} L${x(n - 1).toFixed(1)},${(padding.top + innerH).toFixed(1)} L${x(0).toFixed(1)},${(padding.top + innerH).toFixed(1)} Z`
        : '';
    return {
      path: line,
      area: areaPath,
      max: maxVal,
      points: vals.map((v, i) => ({ x: x(i), y: y(v), v, bucket: series[i].bucket })),
    };
  }, [series, metric, innerH, innerW, padding.left, padding.top]);

  if (series.length === 0) {
    return <div className="grid place-items-center text-xs text-faint" style={{ height }}>No data in range</div>;
  }

  return (
    <div className="relative w-full">
      <svg viewBox={`0 0 ${width} ${height}`} className="w-full" style={{ height }} preserveAspectRatio="none">
        <defs>
          <linearGradient id={`tgrad-${gid}`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="rgb(var(--brand))" stopOpacity="0.35" />
            <stop offset="100%" stopColor="rgb(var(--brand))" stopOpacity="0" />
          </linearGradient>
        </defs>
        {[0, 0.5, 1].map((f) => (
          <g key={f}>
            <line
              x1={padding.left}
              x2={width - padding.right}
              y1={padding.top + innerH * f}
              y2={padding.top + innerH * f}
              stroke="rgb(var(--border))"
              strokeDasharray="3 4"
            />
            <text x={4} y={padding.top + innerH * f + 4} fill="rgb(var(--faint))" fontSize="10">
              {fmtCompact(max * (1 - f))}
            </text>
          </g>
        ))}
        <path d={area} fill={`url(#tgrad-${gid})`} />
        <path d={path} fill="none" stroke="rgb(var(--brand))" strokeWidth="2" />
        {hover !== null && points[hover] && (
          <>
            <line
              x1={points[hover].x}
              x2={points[hover].x}
              y1={padding.top}
              y2={padding.top + innerH}
              stroke="rgb(var(--border-strong))"
            />
            <circle cx={points[hover].x} cy={points[hover].y} r="3" fill="rgb(var(--brand))" />
          </>
        )}
        {points.map((pt, i) => (
          <rect
            key={i}
            x={pt.x - innerW / (points.length * 2 || 1)}
            y={0}
            width={innerW / (points.length || 1)}
            height={height}
            fill="transparent"
            onMouseEnter={() => setHover(i)}
            onMouseLeave={() => setHover(null)}
          />
        ))}
      </svg>
      {hover !== null && points[hover] && (
        <div
          className="pointer-events-none absolute -translate-x-1/2 -top-1 card px-2.5 py-1.5 text-2xs shadow-pop whitespace-nowrap"
          style={{ left: `${(points[hover].x / width) * 100}%` }}
        >
          <div className="text-faint">{points[hover].bucket}</div>
          <div className="text-brand font-medium">
            {fmt(metric.kind, points[hover].v)} {metric.label}
          </div>
        </div>
      )}
    </div>
  );
}
