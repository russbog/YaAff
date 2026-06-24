const nf0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const nf2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function toNumber(v: unknown): number {
  if (typeof v === 'number') return v;
  if (typeof v === 'string' && v.trim() !== '') {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  }
  return 0;
}

export function fmtInt(v: unknown): string {
  return nf0.format(toNumber(v));
}

export function fmtMoney(v: unknown): string {
  return '$' + nf2.format(toNumber(v));
}

export function fmtPct(v: unknown, digits = 1): string {
  return toNumber(v).toFixed(digits) + '%';
}

export function fmtStat(v: unknown, kind: 'int' | 'pct' | 'money'): string {
  if (v === null || v === undefined || v === '') return '—';
  switch (kind) {
    case 'money':
      return fmtMoney(v);
    case 'pct':
      return fmtPct(v);
    default:
      return fmtInt(v);
  }
}

export function fmtCompact(v: unknown): string {
  const n = toNumber(v);
  if (Math.abs(n) >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (Math.abs(n) >= 1_000) return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'k';
  return nf0.format(n);
}

export function fmtDateTime(epochSeconds: unknown): string {
  const n = toNumber(epochSeconds);
  if (!n) return '—';
  const d = new Date(n * 1000);
  return d.toLocaleString(undefined, {
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function startEndForRange(rangeSeconds: number): { start: number; end: number } {
  const end = Math.floor(Date.now() / 1000);
  return { start: end - rangeSeconds, end };
}
