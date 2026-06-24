import { useId, useMemo, useState } from 'react';
import { cn } from '@/lib/cn';
import { fmtCompact } from '@/lib/format';
import type { SeriesPoint, TopRow } from '@/lib/types';

// Lightweight dependency-free dual-area chart rendered as inline SVG.
export function AreaChart({ series, height = 220 }: { series: SeriesPoint[]; height?: number }) {
  const gid = useId();
  const [hover, setHover] = useState<number | null>(null);
  const width = 800;
  const padding = { top: 12, right: 12, bottom: 22, left: 36 };
  const innerW = width - padding.left - padding.right;
  const innerH = height - padding.top - padding.bottom;

  const { clicksPath, convPath, clicksArea, max, points } = useMemo(() => {
    const n = series.length;
    const maxVal = Math.max(1, ...series.map((p) => Math.max(p.clicks, p.conversions)));
    const x = (i: number) => padding.left + (n <= 1 ? innerW / 2 : (i / (n - 1)) * innerW);
    const y = (v: number) => padding.top + innerH - (v / maxVal) * innerH;
    const line = (key: 'clicks' | 'conversions') =>
      series.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p[key]).toFixed(1)}`).join(' ');
    const area =
      n > 0
        ? `${line('clicks')} L${x(n - 1).toFixed(1)},${(padding.top + innerH).toFixed(1)} L${x(0).toFixed(1)},${(padding.top + innerH).toFixed(1)} Z`
        : '';
    return {
      clicksPath: line('clicks'),
      convPath: line('conversions'),
      clicksArea: area,
      max: maxVal,
      points: series.map((p, i) => ({ x: x(i), yc: y(p.clicks), yv: y(p.conversions), p })),
    };
  }, [series, innerH, innerW, padding.left, padding.top]);

  if (series.length === 0) {
    return <div className="grid place-items-center text-xs text-faint" style={{ height }}>No data in range</div>;
  }

  return (
    <div className="relative w-full">
      <svg viewBox={`0 0 ${width} ${height}`} className="w-full" style={{ height }} preserveAspectRatio="none">
        <defs>
          <linearGradient id={`grad-${gid}`} x1="0" y1="0" x2="0" y2="1">
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
        <path d={clicksArea} fill={`url(#grad-${gid})`} />
        <path d={clicksPath} fill="none" stroke="rgb(var(--brand))" strokeWidth="2" />
        <path d={convPath} fill="none" stroke="rgb(var(--success))" strokeWidth="2" />
        {hover !== null && points[hover] && (
          <line
            x1={points[hover].x}
            x2={points[hover].x}
            y1={padding.top}
            y2={padding.top + innerH}
            stroke="rgb(var(--border-strong))"
          />
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
          className="pointer-events-none absolute -translate-x-1/2 -top-1 card px-2.5 py-1.5 text-2xs shadow-pop"
          style={{ left: `${(points[hover].x / width) * 100}%` }}
        >
          <div className="text-brand font-medium">{fmtCompact(points[hover].p.clicks)} clicks</div>
          <div className="text-success font-medium">{fmtCompact(points[hover].p.conversions)} conv.</div>
        </div>
      )}
    </div>
  );
}

export function BarList({ rows }: { rows: TopRow[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  if (rows.length === 0) {
    return <div className="text-xs text-faint py-6 text-center">No data</div>;
  }
  return (
    <div className="space-y-2">
      {rows.map((r) => (
        <div key={r.label} className="space-y-1">
          <div className="flex items-center justify-between text-xs">
            <span className="truncate text-fg/90">{r.label || '—'}</span>
            <span className="text-muted tabular-nums">{fmtCompact(r.value)}</span>
          </div>
          <div className="h-1.5 rounded-full bg-surface-2 overflow-hidden">
            <div
              className={cn('h-full rounded-full bg-brand transition-all')}
              style={{ width: `${(r.value / max) * 100}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}
