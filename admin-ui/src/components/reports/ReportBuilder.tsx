import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { ChevronRight, Plus, X, Layers, Columns3, Download } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { useBootstrap } from '@/providers/BootstrapProvider';
import { useRange } from '@/providers/RangeProvider';
import { spa } from '@/lib/api';
import { fmtStat, fmtStatFull, metricTone, toNumber } from '@/lib/format';
import { groupStatFields } from '@/lib/metrics';
import { computeReportTotals } from '@/lib/reportTotals';
import { downloadCsv } from '@/lib/csv';
import { cn } from '@/lib/cn';
import type { GroupByDim, ReportNode, StatField } from '@/lib/types';

const MAX_LEVELS = 5;
const DEFAULT_FIELDS = ['clicks', 'uniques', 'conversion', 'revenue', 'costs', 'profit', 'roi'];

/** A closeable popover anchored under its trigger button. */
function Popover({ label, icon, children }: { label: string; icon: React.ReactNode; children: React.ReactNode }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [open]);
  return (
    <div className="relative" ref={ref}>
      <Button variant="secondary" size="sm" onClick={() => setOpen((o) => !o)}>
        {icon}
        {label}
      </Button>
      {open && (
        <div className="absolute z-50 mt-1 w-64 card shadow-pop p-1 animate-fade-in max-h-[60vh] overflow-y-auto">
          {children}
        </div>
      )}
    </div>
  );
}

interface FlatRow {
  key: string;
  node: ReportNode;
  depth: number;
  hasChildren: boolean;
  expanded: boolean;
}

/** Flatten the report tree into the currently-visible rows (respecting expand state). */
function flatten(nodes: ReportNode[], expanded: Set<string>, depth = 0, prefix = ''): FlatRow[] {
  const out: FlatRow[] = [];
  nodes.forEach((node, i) => {
    const key = `${prefix}${i}:${String(node.group ?? '')}`;
    const children = node._children ?? [];
    const hasChildren = children.length > 0;
    const isOpen = expanded.has(key);
    out.push({ key, node, depth, hasChildren, expanded: isOpen });
    if (hasChildren && isOpen) out.push(...flatten(children, expanded, depth + 1, key + '/'));
  });
  return out;
}

export function ReportBuilder({ campId }: { campId: number }) {
  const { groupByDims, statFields } = useBootstrap();
  const { bounds } = useRange();

  const dims: GroupByDim[] = useMemo(() => groupByDims ?? [], [groupByDims]);
  const [groupBy, setGroupBy] = useState<string[]>(['date']);
  const [fields, setFields] = useState<string[]>(DEFAULT_FIELDS);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());

  const enabled = campId > 0;

  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['report', campId, groupBy.join(','), fields.join(','), bounds.start, bounds.end],
    queryFn: () => spa.report({ campId, groupBy, fields, start: bounds.start, end: bounds.end }),
    enabled,
    placeholderData: keepPreviousData,
  });

  // Collapse all when the grouping/campaign changes so the tree stays tidy.
  useEffect(() => setExpanded(new Set()), [groupBy, campId, bounds.start, bounds.end]);

  const tree = useMemo(() => data?.tree ?? [], [data]);
  const visibleFields = useMemo<StatField[]>(() => {
    const byField = new Map(statFields.map((f) => [f.field, f]));
    return fields.map((f) => byField.get(f)).filter((f): f is StatField => Boolean(f));
  }, [fields, statFields]);

  const rows = useMemo(() => flatten(tree, expanded), [tree, expanded]);
  const totals = useMemo(() => computeReportTotals(tree), [tree]);
  const dimByField = useMemo(() => new Map(dims.map((d) => [d.field, d])), [dims]);

  const toggleRow = (key: string) =>
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  const addDim = (field: string) => setGroupBy((g) => (g.includes(field) || g.length >= MAX_LEVELS ? g : [...g, field]));
  const removeDim = (field: string) => setGroupBy((g) => g.filter((f) => f !== field));
  const moveDim = (idx: number, dir: -1 | 1) =>
    setGroupBy((g) => {
      const t = idx + dir;
      if (t < 0 || t >= g.length) return g;
      const next = [...g];
      [next[idx], next[t]] = [next[t], next[idx]];
      return next;
    });
  const toggleField = (field: string) =>
    setFields((f) => (f.includes(field) ? f.filter((x) => x !== field) : [...f, field]));

  const exportCsv = () => {
    const dimLabel = groupBy.map((g) => dimByField.get(g)?.label ?? g).join(' / ') || 'Group';
    const headers = [dimLabel, ...visibleFields.map((f) => f.title)];
    const out = rows.map((r) => [
      '  '.repeat(r.depth) + String(r.node.group ?? '—'),
      ...visibleFields.map((f) => String(r.node[f.field] ?? '')),
    ]);
    out.push(['TOTAL', ...visibleFields.map((f) => String(totals[f.field] ?? ''))]);
    downloadCsv(`report-${new Date().toISOString().slice(0, 10)}.csv`, headers, out);
  };

  if (!enabled) {
    return (
      <div className="card">
        <EmptyState
          icon={<Layers size={26} />}
          title="Pick a campaign"
          description="Select a campaign to build a grouped performance report."
        />
      </div>
    );
  }
  if (error) return <ErrorState error={error} onRetry={() => refetch()} />;

  const availableDims = dims.filter((d) => !groupBy.includes(d.field));
  const grouped = groupStatFields(statFields);

  return (
    <div className="space-y-4">
      {/* Builder bar */}
      <div className="card p-3 space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-medium text-muted">Group by</span>
          {groupBy.map((field, idx) => {
            const d = dimByField.get(field);
            return (
              <span
                key={field}
                className="inline-flex items-center gap-1 rounded-md bg-brand/10 text-brand border border-brand/30 pl-2 pr-1 h-7 text-xs"
              >
                <span className="text-faint tabular-nums">{idx + 1}.</span>
                {d?.label ?? field}
                <button
                  className="ml-0.5 grid place-items-center h-5 w-4 text-brand/70 hover:text-brand disabled:opacity-30"
                  onClick={() => moveDim(idx, -1)}
                  disabled={idx === 0}
                  title="Move up a level"
                >
                  ↑
                </button>
                <button
                  className="grid place-items-center h-5 w-4 text-brand/70 hover:text-brand disabled:opacity-30"
                  onClick={() => moveDim(idx, 1)}
                  disabled={idx === groupBy.length - 1}
                  title="Move down a level"
                >
                  ↓
                </button>
                <button
                  className="grid place-items-center h-5 w-5 text-brand/70 hover:text-danger"
                  onClick={() => removeDim(field)}
                  title="Remove dimension"
                >
                  <X size={12} />
                </button>
              </span>
            );
          })}
          {groupBy.length === 0 && <span className="text-xs text-faint italic">No grouping — single summary row</span>}
          <Popover label="Add" icon={<Plus size={14} />}>
            {availableDims.length === 0 ? (
              <div className="px-3 py-2 text-xs text-faint">All dimensions added</div>
            ) : (
              availableDims.map((d) => (
                <button
                  key={d.field}
                  className="flex w-full flex-col items-start gap-0.5 px-3 py-1.5 text-left text-sm rounded hover:bg-surface-2 disabled:opacity-40"
                  onClick={() => addDim(d.field)}
                  disabled={groupBy.length >= MAX_LEVELS}
                  title={d.desc}
                >
                  <span className="text-fg">{d.label}</span>
                  {d.desc && <span className="text-2xs text-faint">{d.desc}</span>}
                </button>
              ))
            )}
            {groupBy.length >= MAX_LEVELS && (
              <div className="px-3 py-1.5 text-2xs text-faint border-t border-border mt-1">
                Max {MAX_LEVELS} nesting levels
              </div>
            )}
          </Popover>

          <div className="flex-1" />

          <Popover label="Metrics" icon={<Columns3 size={14} />}>
            {grouped.map(({ cat, fields: catFields }) => (
              <div key={cat} className="py-1">
                <div className="px-3 py-1 text-2xs font-semibold uppercase tracking-wide text-faint">{cat}</div>
                {catFields.map((f) => (
                  <label
                    key={f.field}
                    className="flex items-center gap-2 px-3 py-1 text-sm rounded hover:bg-surface-2 cursor-pointer"
                    title={f.desc}
                  >
                    <input
                      type="checkbox"
                      checked={fields.includes(f.field)}
                      onChange={() => toggleField(f.field)}
                      className="h-3.5 w-3.5 rounded border-border accent-brand"
                    />
                    <span className={fields.includes(f.field) ? 'text-fg' : 'text-muted'}>{f.title}</span>
                  </label>
                ))}
              </div>
            ))}
          </Popover>

          <Button variant="secondary" size="sm" onClick={exportCsv} disabled={rows.length === 0}>
            <Download size={14} /> CSV
          </Button>
          {isFetching && <Badge tone="info" dot>Loading</Badge>}
        </div>
      </div>

      {/* Report tree table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-surface-2/50">
                <th className="text-left font-medium text-muted px-3 py-2 sticky left-0 bg-surface-2/50 min-w-[220px]">
                  {groupBy.map((g) => dimByField.get(g)?.label ?? g).join(' › ') || 'Summary'}
                </th>
                {visibleFields.map((f) => (
                  <th key={f.field} className="text-right font-medium text-muted px-3 py-2 whitespace-nowrap" title={f.desc}>
                    {f.title}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                Array.from({ length: 8 }).map((_, i) => (
                  <tr key={i} className="border-b border-border/60">
                    <td className="px-3 py-2"><div className="h-4 w-32 rounded bg-surface-2 animate-pulse" /></td>
                    {visibleFields.map((f) => (
                      <td key={f.field} className="px-3 py-2"><div className="h-4 w-12 ml-auto rounded bg-surface-2 animate-pulse" /></td>
                    ))}
                  </tr>
                ))
              ) : rows.length === 0 ? (
                <tr>
                  <td colSpan={visibleFields.length + 1} className="px-3 py-10">
                    <EmptyState title="No data" description="No traffic matched this campaign and range yet." />
                  </td>
                </tr>
              ) : (
                rows.map((r) => (
                  <tr key={r.key} className="border-b border-border/60 hover:bg-surface-2/40">
                    <td className="px-3 py-1.5 sticky left-0 bg-surface group-hover:bg-surface-2/40">
                      <div className="flex items-center" style={{ paddingLeft: r.depth * 16 }}>
                        {r.hasChildren ? (
                          <button
                            onClick={() => toggleRow(r.key)}
                            className="grid place-items-center h-5 w-5 mr-1 text-muted hover:text-fg"
                          >
                            <ChevronRight size={14} className={cn('transition-transform', r.expanded && 'rotate-90')} />
                          </button>
                        ) : (
                          <span className="inline-block w-6" />
                        )}
                        <span className="truncate max-w-[260px] text-fg" title={String(r.node.group ?? '')}>
                          {String(r.node.group ?? '—')}
                        </span>
                      </div>
                    </td>
                    {visibleFields.map((f) => {
                      const v = r.node[f.field];
                      return (
                        <td
                          key={f.field}
                          className={cn('px-3 py-1.5 text-right tabular-nums', metricTone(f.field, v) || 'text-fg/90')}
                          title={fmtStatFull(v, f.kind)}
                        >
                          {fmtStat(v, f.kind)}
                        </td>
                      );
                    })}
                  </tr>
                ))
              )}
            </tbody>
            {rows.length > 0 && !isLoading && (
              <tfoot>
                <tr className="border-t-2 border-border bg-surface-2/60 font-semibold">
                  <td className="px-3 py-2 sticky left-0 bg-surface-2/60">TOTAL</td>
                  {visibleFields.map((f) => {
                    const v = totals[f.field];
                    return (
                      <td
                        key={f.field}
                        className={cn('px-3 py-2 text-right tabular-nums', metricTone(f.field, v) || 'text-fg')}
                        title={fmtStatFull(v, f.kind)}
                      >
                        {fmtStat(v, f.kind)}
                      </td>
                    );
                  })}
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      </div>
      <p className="text-2xs text-faint">
        {rows.length} visible row{rows.length === 1 ? '' : 's'} · {toNumber(totals.clicks).toLocaleString()} clicks total
      </p>
    </div>
  );
}
