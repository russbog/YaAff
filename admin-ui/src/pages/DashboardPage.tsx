import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  MousePointerClick,
  Users,
  Target,
  DollarSign,
  TrendingUp,
  ShieldX,
  type LucideIcon,
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { Card, CardHeader } from '@/components/ui/Card';
import { DateRangePicker } from '@/components/ui/DateRangePicker';
import { Select } from '@/components/ui/Field';
import { Skeleton, ErrorState } from '@/components/ui/States';
import { AreaChart, BarList } from '@/components/data/Charts';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { useRange } from '@/providers/RangeProvider';
import { spa } from '@/lib/api';
import { fmtCompact, fmtMoney, fmtPct, toNumber } from '@/lib/format';

interface Kpi {
  label: string;
  value: string;
  icon: LucideIcon;
  tone: string;
  sub?: string;
}

export function DashboardPage() {
  const { campaignsList, commonSettings } = useBootstrap();
  const { bounds } = useRange();
  const [campId, setCampId] = useState(0);
  const tz = commonSettings.statistics?.timezone ?? 'UTC';

  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['dashboard', campId, bounds.start, bounds.end],
    queryFn: () => spa.dashboard({ campId, start: bounds.start, end: bounds.end, tz }),
    refetchInterval: 30_000,
  });

  const s = data?.summary ?? {};
  const revenue = toNumber(s.revenue);
  const cost = toNumber(s.cost);
  const profit = s.profit !== undefined ? toNumber(s.profit) : revenue - cost;
  const clicks = toNumber(s.clicks);
  const blocked = toNumber(s.blocked ?? s.bots);
  const allowed = toNumber(s.allowed ?? clicks);
  const total = allowed + blocked;
  const botPct = total > 0 ? (blocked / total) * 100 : 0;

  const kpis: Kpi[] = [
    { label: 'Clicks', value: fmtCompact(clicks), icon: MousePointerClick, tone: 'text-brand' },
    { label: 'Uniques', value: fmtCompact(s.uniques), icon: Users, tone: 'text-info' },
    { label: 'Conversions', value: fmtCompact(s.conversions), icon: Target, tone: 'text-success' },
    { label: 'Revenue', value: fmtMoney(revenue), icon: DollarSign, tone: 'text-success' },
    {
      label: 'Profit',
      value: fmtMoney(profit),
      icon: TrendingUp,
      tone: profit >= 0 ? 'text-success' : 'text-danger',
      sub: `Cost ${fmtMoney(cost)}`,
    },
    { label: 'Bot traffic', value: fmtPct(botPct), icon: ShieldX, tone: 'text-warning', sub: `${fmtCompact(blocked)} blocked` },
  ];

  return (
    <AppShell
      title="Dashboard"
      toolbar={
        <div className="flex items-center gap-2">
          <Select
            value={campId}
            onChange={(e) => setCampId(Number(e.target.value))}
            className="h-8 w-auto text-xs"
          >
            <option value={0}>All campaigns</option>
            {campaignsList.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <DateRangePicker />
        </div>
      }
    >
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
            {kpis.map((k) => (
              <Card key={k.label} className="p-4">
                {isLoading ? (
                  <div className="space-y-2">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-6 w-20" />
                  </div>
                ) : (
                  <>
                    <div className="flex items-center justify-between">
                      <span className="text-2xs uppercase tracking-wide text-muted">{k.label}</span>
                      <k.icon size={15} className={k.tone} />
                    </div>
                    <div className="mt-2 text-xl font-semibold tabular-nums">{k.value}</div>
                    {k.sub && <div className="text-2xs text-faint mt-0.5">{k.sub}</div>}
                  </>
                )}
              </Card>
            ))}
          </div>

          <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <Card className="xl:col-span-2">
              <CardHeader
                title="Clicks & conversions"
                subtitle={isFetching ? 'Updating…' : `Live · auto-refresh 30s`}
                actions={
                  <div className="flex items-center gap-3 text-2xs text-muted">
                    <span className="flex items-center gap-1.5">
                      <i className="h-2 w-2 rounded-full bg-brand inline-block" /> Clicks
                    </span>
                    <span className="flex items-center gap-1.5">
                      <i className="h-2 w-2 rounded-full bg-success inline-block" /> Conversions
                    </span>
                  </div>
                }
              />
              <div className="p-4">
                {isLoading ? <Skeleton className="h-[220px]" /> : <AreaChart series={data?.series ?? []} />}
              </div>
            </Card>

            <Card>
              <CardHeader title="Traffic quality" />
              <div className="p-4 space-y-4">
                <QualityBar label="Allowed" value={allowed} total={total} tone="bg-success" />
                <QualityBar label="Blocked / bots" value={blocked} total={total} tone="bg-danger" />
                <div className="pt-2 border-t border-border grid grid-cols-2 gap-3 text-center">
                  <div>
                    <div className="text-lg font-semibold tabular-nums">{fmtCompact(allowed)}</div>
                    <div className="text-2xs text-muted">Allowed</div>
                  </div>
                  <div>
                    <div className="text-lg font-semibold tabular-nums text-danger">{fmtCompact(blocked)}</div>
                    <div className="text-2xs text-muted">Filtered</div>
                  </div>
                </div>
              </div>
            </Card>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Card>
              <CardHeader title="Top countries" />
              <div className="p-4">
                {isLoading ? <Skeleton className="h-32" /> : <BarList rows={data?.top_country ?? []} />}
              </div>
            </Card>
            <Card>
              <CardHeader title="Top flows" />
              <div className="p-4">
                {isLoading ? <Skeleton className="h-32" /> : <BarList rows={data?.top_flow ?? []} />}
              </div>
            </Card>
          </div>
        </div>
      )}
    </AppShell>
  );
}

function QualityBar({ label, value, total, tone }: { label: string; value: number; total: number; tone: string }) {
  const pct = total > 0 ? (value / total) * 100 : 0;
  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between text-xs">
        <span className="text-muted">{label}</span>
        <span className="tabular-nums">{fmtPct(pct)}</span>
      </div>
      <div className="h-2 rounded-full bg-surface-2 overflow-hidden">
        <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}
